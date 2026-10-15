<?php

namespace App\Services\Rotacion;

use App\Enums\AccionPeriodoRotacion;
use App\Enums\AlcanceDatos;
use App\Enums\EstadoCargaRotacion;
use App\Enums\NivelHallazgoRotacion;
use App\Models\RotacionCarga;
use App\Models\RotacionPeriodo;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Permisos\AlcanceModuloService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Carga del Excel de rotación de personal: subir → analizar (vista previa
 * con hallazgos) → confirmar (aplicar) o descartar. Mismo Service para
 * web y, si algún día se necesita, para la API (los controladores solo
 * delegan).
 *
 * - El archivo original se guarda en el disco `nas` bajo `rotacion/`.
 * - Cada bloque del Excel se asigna a una sucursal del catálogo; los que
 *   no coinciden se reportan y NO se importan.
 * - Aplicar hace upsert por sucursal + año + mes: volver a subir un mes lo
 *   reemplaza; qué cambió (antes/después) queda en la carga y en la
 *   bitácora de auditoría.
 *
 * @phpstan-import-type Hallazgo from RotacionExcelLector
 */
class RotacionImportService
{
    /** Campos que se comparan para decidir si un mes cambió. */
    private const CAMPOS = [
        'plantilla_inicial', 'altas', 'bajas', 'bajas_voluntarias', 'bajas_involuntarias',
        'plantilla_final', 'indice', 'indice_excel',
    ];

    public function __construct(
        private readonly RotacionExcelLector $lector,
        private readonly AlcanceModuloService $alcance,
        private readonly AuditoriaService $auditoria,
    ) {}

    public function analizar(UploadedFile $archivo, User $actor): RotacionCarga
    {
        $ruta = (string) $archivo->getRealPath();

        try {
            $lectura = $this->lector->leer($ruta);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['archivo' => $e->getMessage()]);
        }

        /** @var list<Hallazgo> $hallazgos */
        $hallazgos = $lectura['hallazgos'];
        $catalogo = Sucursal::query()->get(['id', 'nombre', 'clave']);
        $permitidas = $this->sucursalesPermitidas($actor);
        $periodos = [];
        $sinCatalogo = [];

        foreach ($lectura['bloques'] as $bloque) {
            $nombre = (string) $bloque['sucursal'];
            $hallazgoBase = ['hoja' => $bloque['hoja'], 'celda' => 'B'.$bloque['fila'], 'sucursal' => $nombre];

            if ($bloque['anio'] === null) {
                continue; // ya reportado por el lector (hoja sin año)
            }

            [$sucursal, $coincidencia] = $this->resolverSucursal($nombre, $catalogo);

            if ($sucursal === null) {
                $sinCatalogo[$nombre] = true;
                $hallazgos[] = [...$hallazgoBase, 'nivel' => NivelHallazgoRotacion::Error->value, 'mensaje' => $coincidencia === 'ambigua'
                    ? sprintf('«%s» coincide con más de una sucursal del catálogo: no se importa. Escribe el nombre exacto.', $nombre)
                    : sprintf('«%s» no existe en el catálogo de sucursales: sus %d mes(es) no se importan. Da de alta la sucursal o corrige el nombre y vuelve a subir el archivo.', $nombre, count($bloque['meses']))];

                continue;
            }

            if ($coincidencia === 'aproximada') {
                $hallazgos[] = [...$hallazgoBase, 'nivel' => NivelHallazgoRotacion::Advertencia->value, 'mensaje' => sprintf('«%s» se asignó a la sucursal «%s» por coincidencia aproximada del nombre. Revisa que sea correcta antes de confirmar.', $nombre, $sucursal->nombre)];
            }

            if ($permitidas !== null && ! in_array($sucursal->id, $permitidas, true)) {
                $hallazgos[] = [...$hallazgoBase, 'nivel' => NivelHallazgoRotacion::Error->value, 'mensaje' => sprintf('La sucursal «%s» está fuera de tu alcance: no se importa.', $sucursal->nombre)];

                continue;
            }

            foreach ($bloque['meses'] as $mes) {
                $clave = sprintf('%d-%d-%d', $sucursal->id, $bloque['anio'], $mes['mes']);

                if (isset($periodos[$clave])) {
                    $hallazgos[] = [...$hallazgoBase, 'nivel' => NivelHallazgoRotacion::Advertencia->value, 'mensaje' => sprintf('%s %d de «%s» viene repetido (ya estaba en la hoja «%s»): se usa el primero.', RotacionExcelLector::NOMBRES_MES[$mes['mes']], $bloque['anio'], $sucursal->nombre, $periodos[$clave]['hoja'])];

                    continue;
                }

                $periodos[$clave] = [
                    'sucursal_id' => $sucursal->id,
                    'sucursal' => $sucursal->nombre,
                    'sucursal_excel' => $nombre,
                    'hoja' => $bloque['hoja'],
                    'anio' => $bloque['anio'],
                    'mes' => $mes['mes'],
                    'plantilla_inicial' => $mes['plantilla_inicial'],
                    'altas' => $mes['altas'],
                    'bajas' => $mes['bajas'],
                    'bajas_voluntarias' => $mes['bajas_voluntarias'],
                    'bajas_involuntarias' => $mes['bajas_involuntarias'],
                    'plantilla_final' => $mes['plantilla_final'],
                    'indice' => $mes['indice'],
                    'indice_excel' => $mes['indice_excel'],
                ];
            }
        }

        $periodos = $this->compararConLoCargado(array_values($periodos));
        $hash = (string) hash_file('sha256', $ruta);

        $previa = RotacionCarga::query()->where('archivo_hash', $hash)->where('estado', EstadoCargaRotacion::Aplicada->value)->latest('id')->first();
        if ($previa !== null) {
            $hallazgos[] = ['nivel' => NivelHallazgoRotacion::Informativo->value, 'hoja' => '', 'celda' => null, 'sucursal' => null, 'mensaje' => sprintf('Este mismo archivo ya se aplicó el %s.', $previa->aplicado_en?->format('d/m/Y H:i') ?? '—')];
        }

        $archivoRuta = $this->guardarOriginal($archivo);
        $conteo = $this->contarAcciones($periodos);

        $carga = RotacionCarga::query()->create([
            'nombre_archivo' => mb_substr($archivo->getClientOriginalName(), 0, 255),
            'archivo_ruta' => $archivoRuta,
            'archivo_hash' => $hash,
            'estado' => EstadoCargaRotacion::Analizada,
            'subido_por' => $actor->id,
            'periodos_detectados' => count($periodos),
            'periodos_nuevos' => $conteo[AccionPeriodoRotacion::Nuevo->value],
            'periodos_reemplazados' => $conteo[AccionPeriodoRotacion::Reemplaza->value],
            'periodos_sin_cambios' => $conteo[AccionPeriodoRotacion::SinCambios->value],
            'hallazgos' => count($hallazgos),
            'resumen' => [
                'hojas' => $lectura['hojas'],
                'anios' => array_values(array_unique(array_column($periodos, 'anio'))),
                'sucursales' => array_values(array_unique(array_column($periodos, 'sucursal'))),
                'sucursales_sin_catalogo' => array_keys($sinCatalogo),
                'por_nivel' => array_count_values(array_column($hallazgos, 'nivel')),
            ],
            'detalle' => ['periodos' => $periodos, 'hallazgos' => $hallazgos],
        ]);

        $this->auditoria->registrar('rotacion.carga_analizada', $carga, $actor, [
            'archivo' => $carga->nombre_archivo,
            'periodos' => $carga->periodos_detectados,
            'hallazgos' => $carga->hallazgos,
        ]);

        return $carga;
    }

    /**
     * Aplica lo que se vio en la vista previa. Vuelve a comparar contra lo
     * cargado en ese momento (otra carga pudo aplicarse entre el análisis
     * y la confirmación).
     *
     * @return array{nuevos: int, reemplazados: int, sin_cambios: int}
     */
    public function aplicar(RotacionCarga $carga, User $actor): array
    {
        if ($carga->estado !== EstadoCargaRotacion::Analizada) {
            throw ValidationException::withMessages(['carga' => 'Esta carga ya no está por confirmar.']);
        }

        $periodos = $carga->detalle['periodos'] ?? [];
        if ($periodos === []) {
            throw ValidationException::withMessages(['carga' => 'El archivo no tiene ningún mes que se pueda importar.']);
        }

        $permitidas = $this->sucursalesPermitidas($actor);

        $resultado = DB::transaction(function () use ($carga, $actor, $periodos, $permitidas): array {
            $periodos = $this->compararConLoCargado($periodos);
            $reemplazos = [];

            foreach ($periodos as $periodo) {
                if ($permitidas !== null && ! in_array((int) $periodo['sucursal_id'], $permitidas, true)) {
                    throw ValidationException::withMessages(['carga' => sprintf('La sucursal «%s» está fuera de tu alcance.', $periodo['sucursal'])]);
                }

                if ($periodo['accion'] === AccionPeriodoRotacion::SinCambios->value) {
                    continue;
                }

                RotacionPeriodo::query()->updateOrCreate(
                    ['sucursal_id' => $periodo['sucursal_id'], 'anio' => $periodo['anio'], 'mes' => $periodo['mes']],
                    [
                        'sucursal_excel' => mb_substr((string) $periodo['sucursal_excel'], 0, 255),
                        'hoja_excel' => mb_substr((string) $periodo['hoja'], 0, 255),
                        ...array_intersect_key($periodo, array_flip(self::CAMPOS)),
                        'carga_id' => $carga->id,
                    ],
                );

                if ($periodo['accion'] === AccionPeriodoRotacion::Reemplaza->value) {
                    $reemplazos[] = [
                        'sucursal' => $periodo['sucursal'],
                        'anio' => $periodo['anio'],
                        'mes' => $periodo['mes'],
                        'antes' => $periodo['anterior'],
                        'despues' => array_intersect_key($periodo, array_flip(self::CAMPOS)),
                    ];
                }
            }

            $conteo = $this->contarAcciones($periodos);
            $detalle = $carga->detalle ?? [];
            $detalle['periodos'] = $periodos;

            $carga->forceFill([
                'estado' => EstadoCargaRotacion::Aplicada,
                'aplicado_por' => $actor->id,
                'aplicado_en' => now(),
                'periodos_nuevos' => $conteo[AccionPeriodoRotacion::Nuevo->value],
                'periodos_reemplazados' => $conteo[AccionPeriodoRotacion::Reemplaza->value],
                'periodos_sin_cambios' => $conteo[AccionPeriodoRotacion::SinCambios->value],
                'detalle' => $detalle,
            ])->save();

            return ['conteo' => $conteo, 'reemplazos' => $reemplazos];
        });

        $this->auditoria->registrar('rotacion.carga_aplicada', $carga, $actor, [
            'archivo' => $carga->nombre_archivo,
            'nuevos' => $carga->periodos_nuevos,
            'reemplazados' => $carga->periodos_reemplazados,
            'sin_cambios' => $carga->periodos_sin_cambios,
            'cambios' => $resultado['reemplazos'],
        ]);

        return [
            'nuevos' => $carga->periodos_nuevos,
            'reemplazados' => $carga->periodos_reemplazados,
            'sin_cambios' => $carga->periodos_sin_cambios,
        ];
    }

    /** Descarta una carga por confirmar. El archivo y el registro se conservan (auditoría). */
    public function descartar(RotacionCarga $carga, User $actor): void
    {
        if ($carga->estado !== EstadoCargaRotacion::Analizada) {
            throw ValidationException::withMessages(['carga' => 'Solo se puede descartar una carga por confirmar.']);
        }

        $carga->forceFill(['estado' => EstadoCargaRotacion::Descartada])->save();
        $this->auditoria->registrar('rotacion.carga_descartada', $carga, $actor, ['archivo' => $carga->nombre_archivo]);
    }

    /**
     * IDs de sucursal que el usuario puede cargar/consultar; null = todas.
     *
     * @return list<int>|null
     */
    public function sucursalesPermitidas(User $usuario): ?array
    {
        $nivel = $this->alcance->nivel($usuario, 'rotacion');

        if ($nivel === AlcanceDatos::Todos) {
            return null;
        }

        return array_values($this->alcance->sucursalesPara($usuario, $nivel === AlcanceDatos::Region ? AlcanceDatos::Region : AlcanceDatos::Sucursal)->all());
    }

    /**
     * Marca cada periodo como nuevo / reemplaza / sin cambios frente a lo
     * que ya está cargado, guardando los valores anteriores.
     *
     * @param  list<array<string, mixed>>  $periodos
     * @return list<array<string, mixed>>
     */
    private function compararConLoCargado(array $periodos): array
    {
        if ($periodos === []) {
            return [];
        }

        $existentes = RotacionPeriodo::query()
            ->whereIn('sucursal_id', array_unique(array_column($periodos, 'sucursal_id')))
            ->whereIn('anio', array_unique(array_column($periodos, 'anio')))
            ->get()
            ->keyBy(fn (RotacionPeriodo $p): string => sprintf('%d-%d-%d', $p->sucursal_id, $p->anio, $p->mes));

        return array_map(function (array $periodo) use ($existentes): array {
            $actual = $existentes->get(sprintf('%d-%d-%d', (int) $periodo['sucursal_id'], (int) $periodo['anio'], (int) $periodo['mes']));

            if ($actual === null) {
                return [...$periodo, 'accion' => AccionPeriodoRotacion::Nuevo->value, 'anterior' => null];
            }

            $anterior = $this->valores($actual);
            $iguales = $anterior === $this->valores($periodo);

            return [...$periodo, 'accion' => ($iguales ? AccionPeriodoRotacion::SinCambios : AccionPeriodoRotacion::Reemplaza)->value, 'anterior' => $anterior];
        }, $periodos);
    }

    /**
     * Valores comparables de un periodo (modelo o arreglo del detalle).
     *
     * @param  RotacionPeriodo|array<string, mixed>  $periodo
     * @return array<string, int|float|null>
     */
    private function valores(RotacionPeriodo|array $periodo): array
    {
        $valores = [];
        foreach (self::CAMPOS as $campo) {
            $valor = $periodo instanceof RotacionPeriodo ? $periodo->getAttribute($campo) : ($periodo[$campo] ?? null);

            $valores[$campo] = match (true) {
                $valor === null => null,
                in_array($campo, ['indice', 'indice_excel'], true) => round((float) $valor, 2),
                default => (int) $valor,
            };
        }

        return $valores;
    }

    /**
     * @param  list<array<string, mixed>>  $periodos
     * @return array<string, int>
     */
    private function contarAcciones(array $periodos): array
    {
        $conteo = [];
        foreach (AccionPeriodoRotacion::cases() as $accion) {
            $conteo[$accion->value] = 0;
        }
        foreach ($periodos as $periodo) {
            $accion = (string) ($periodo['accion'] ?? '');
            if (isset($conteo[$accion])) {
                $conteo[$accion]++;
            }
        }

        return $conteo;
    }

    /**
     * Sucursal del catálogo para el nombre del Excel: exacta por nombre o
     * clave (sin acentos ni mayúsculas), alias confirmado en
     * config/rotacion.php, o aproximada si solo una sucursal empieza igual
     * («SAN LUIS» → «San Luis Potosí»), que se reporta para revisión.
     *
     * @param  Collection<int, Sucursal>  $catalogo
     * @return array{0: Sucursal|null, 1: string}
     */
    private function resolverSucursal(string $nombre, Collection $catalogo): array
    {
        $buscado = $this->normalizar($nombre);

        $exactas = $catalogo->filter(fn (Sucursal $s): bool => $this->normalizar($s->nombre) === $buscado || $this->normalizar($s->clave) === $buscado);
        if ($exactas->count() === 1) {
            return [$exactas->first(), 'exacta'];
        }

        $alias = config('rotacion.alias_sucursales.'.$buscado);
        if (is_string($alias) && $alias !== '') {
            $destino = $this->normalizar($alias);
            $porAlias = $catalogo->filter(fn (Sucursal $s): bool => $this->normalizar($s->nombre) === $destino || $this->normalizar($s->clave) === $destino);
            if ($porAlias->count() === 1) {
                return [$porAlias->first(), 'alias'];
            }
        }

        $aproximadas = $catalogo->filter(function (Sucursal $s) use ($buscado): bool {
            $candidato = $this->normalizar($s->nombre);

            return $buscado !== '' && (str_starts_with($candidato, $buscado.' ') || str_starts_with($buscado, $candidato.' '));
        });

        if ($aproximadas->count() === 1) {
            return [$aproximadas->first(), 'aproximada'];
        }

        return [null, $exactas->count() > 1 || $aproximadas->count() > 1 ? 'ambigua' : 'ninguna'];
    }

    private function guardarOriginal(UploadedFile $archivo): string
    {
        $directorio = sprintf('%s/%s', trim((string) config('rotacion.prefijo', 'rotacion'), '/'), now()->format('Y/m'));
        $base = Str::slug(pathinfo($archivo->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'rotacion';
        $nombre = sprintf('%s-%s-%s.%s', now()->format('Ymd-His'), Str::lower(Str::random(6)), mb_substr($base, 0, 80), $archivo->getClientOriginalExtension() ?: 'xlsx');

        $ruta = Storage::disk((string) config('rotacion.disk', 'nas'))->putFileAs($directorio, $archivo, $nombre);

        if (! is_string($ruta) || $ruta === '') {
            throw ValidationException::withMessages(['archivo' => 'No se pudo guardar el archivo en el NAS. Intenta de nuevo o avisa a Sistemas.']);
        }

        return $ruta;
    }

    private function normalizar(string $texto): string
    {
        $texto = Str::lower(Str::ascii(trim($texto)));
        $texto = (string) preg_replace('/[^a-z0-9 ]+/', ' ', $texto);

        return trim((string) preg_replace('/\s+/', ' ', $texto));
    }
}
