<?php

namespace App\Services\Rotacion;

use App\Enums\EstatusBaja;
use App\Models\Baja;
use App\Models\BajaHistorica;
use App\Models\CausalAnalitica;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\ImportacionExcel;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as FechaExcel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Throwable;

/**
 * Carga de bajas históricas INDIVIDUALES (una fila por evento de baja) con el
 * formato que genera People. analizar() deja solo la vista previa con sus
 * hallazgos; aplicar() guarda todo o nada. Nunca crea colaboradores,
 * sucursales, puestos ni bajas del sistema; nunca cambia un expediente.
 *
 *  - Sucursal desconocida, nombre o último día faltante, o el mismo evento dos
 *    veces en el archivo → error (bloquea).
 *  - Mismo evento ya cargado → «sin cambios».
 *  - Mismo evento que una baja del sistema (misma persona, ±7 días) → se
 *    guarda como duplicada y ligada: no cuenta dos veces.
 *  - Ingreso posterior a la baja, puesto o causal no reconocidos → aviso; el
 *    dato se carga y queda para revisión.
 */
class BajasHistoricasImportService
{
    public const TIPO = 'bajas_historicas';

    private const ENCABEZADOS = [
        'nombre' => 'nombre completo',
        'numero' => 'numero de empleado',
        'sucursal' => 'sucursal',
        'puesto' => 'puesto',
        'area' => 'area',
        'ingreso' => 'fecha de ingreso',
        'baja' => 'ultimo dia laborado',
        'motivo' => 'motivo original',
        'causal' => 'causal homologada',
    ];

    private const OBLIGATORIOS = ['nombre', 'sucursal', 'baja'];

    /** @var Collection<string, Collection<int, Colaborador>> */
    private Collection $porNombre;

    /** @var Collection<string, Colaborador> */
    private Collection $porNumero;

    public function __construct()
    {
        $this->porNombre = collect();
        $this->porNumero = collect();
    }

    public function analizar(UploadedFile $archivo, User $usuario): ImportacionExcel
    {
        $hash = (string) hash_file('sha256', (string) $archivo->getRealPath());

        if (ImportacionExcel::query()->where('archivo_hash', $hash)->where('estado', 'aplicado')->exists()) {
            throw ValidationException::withMessages(['archivo' => 'Este mismo archivo ya se aplicó antes.']);
        }

        try {
            $renglones = array_values(IOFactory::load((string) $archivo->getRealPath())->getSheet(0)->toArray(null, true, false, false));
        } catch (Throwable) {
            throw ValidationException::withMessages(['archivo' => 'No se pudo leer el archivo. Verifica que sea un Excel válido.']);
        }

        [$columnas, $inicio] = $this->encabezados($renglones);
        $filas = $this->resolverFilas($renglones, $columnas, $inicio);

        if ($filas === []) {
            throw ValidationException::withMessages(['archivo' => 'El archivo no trae bajas.']);
        }

        $cuenta = fn (string $estado): int => count(array_filter($filas, fn (array $f): bool => $f['estado'] === $estado));

        return ImportacionExcel::query()->updateOrCreate(['archivo_hash' => $hash], [
            'nombre_archivo' => mb_substr($archivo->getClientOriginalName(), 0, 255),
            'tipo' => self::TIPO,
            'subido_por' => $usuario->id,
            'estado' => 'previsualizado',
            'total_filas' => count($filas),
            'filas_aplicadas' => 0,
            'filas_revision' => $cuenta('nuevo') + $cuenta('duplicado_sistema'),
            'filas_sin_cambios' => $cuenta('sin_cambio'),
            'filas_no_encontradas' => 0,
            'filas_error' => $cuenta('error'),
            'detalle' => $filas,
            'metadatos' => ['columnas' => array_keys($columnas)],
        ]);
    }

    public function aplicar(ImportacionExcel $importacion, string $motivo, User $usuario): int
    {
        return DB::transaction(function () use ($importacion, $motivo, $usuario): int {
            $registro = ImportacionExcel::query()->lockForUpdate()->findOrFail($importacion->id);

            if ($registro->tipo !== self::TIPO) {
                throw ValidationException::withMessages(['importacion' => 'Esta importación no es de bajas históricas.']);
            }

            if ($registro->estado === 'aplicado') {
                throw ValidationException::withMessages(['importacion' => 'Esta importación ya se aplicó.']);
            }

            if ($registro->filas_error > 0) {
                throw ValidationException::withMessages(['importacion' => 'Corrige las filas con error y vuelve a subir el archivo.']);
            }

            $guardadas = 0;

            foreach ($registro->detalle ?? [] as $fila) {
                if (! in_array($fila['estado'], ['nuevo', 'duplicado_sistema'], true)) {
                    continue;
                }

                // Entre la vista previa y la aplicación alguien pudo cargar lo mismo.
                if (BajaHistorica::query()->where('llave', $fila['llave'])->exists()) {
                    continue;
                }

                BajaHistorica::query()->create([
                    'importacion_excel_id' => $registro->id,
                    'llave' => $fila['llave'],
                    'nombre' => $fila['nombre'],
                    'numero_empleado' => $fila['numero_empleado'],
                    'colaborador_id' => $fila['colaborador_id'],
                    'baja_id' => $fila['baja_id'],
                    'empresa_id' => $fila['empresa_id'],
                    'sucursal_id' => $fila['sucursal_id'],
                    'puesto_id' => $fila['puesto_id'],
                    'departamento_id' => $fila['departamento_id'],
                    'fecha_ingreso' => $fila['fecha_ingreso'],
                    'fecha_baja' => $fila['fecha_baja'],
                    'motivo_original' => $fila['motivo_original'],
                    'causal_analitica_id' => $fila['causal_analitica_id'],
                    'estado' => $fila['estado'] === 'duplicado_sistema' ? BajaHistorica::DUPLICADA : BajaHistorica::VALIDA,
                    'nota' => $fila['estado'] === 'duplicado_sistema' ? 'Ligada al cargar: ya existe como baja del sistema.' : null,
                    'registrado_por' => $usuario->id,
                ]);
                $guardadas++;
            }

            $registro->update([
                'estado' => 'aplicado',
                'filas_aplicadas' => $guardadas,
                'aplicado_en' => now(),
                'aplicado_por' => $usuario->id,
                'metadatos' => [...($registro->metadatos ?? []), 'motivo' => $motivo],
            ]);

            return $guardadas;
        });
    }

    /** Formato vacío: encabezados y una hoja con los catálogos válidos. */
    public function plantillaExcel(): Spreadsheet
    {
        $libro = new Spreadsheet;
        $libro->getActiveSheet()->setTitle('Bajas')->fromArray(['Nombre completo', 'Número de empleado', 'Sucursal', 'Puesto', 'Área', 'Fecha de ingreso', 'Último día laborado', 'Motivo original', 'Causal homologada']);

        $catalogos = $libro->createSheet()->setTitle('Catálogos');
        $catalogos->fromArray(['Sucursales', 'Causales homologadas']);
        $sucursales = Sucursal::query()->orderBy('nombre')->pluck('nombre')->values();
        $causales = CausalAnalitica::query()->where('activo', true)->ordenadas()->pluck('nombre')->values();

        foreach ($sucursales as $i => $nombre) {
            $catalogos->setCellValue(sprintf('A%d', $i + 2), $nombre);
        }

        foreach ($causales as $i => $nombre) {
            $catalogos->setCellValue(sprintf('B%d', $i + 2), $nombre);
        }

        return $libro;
    }

    /**
     * @param  array<int, array<int|string, mixed>>  $renglones
     * @return array{0: array<string, int|string>, 1: int}
     */
    private function encabezados(array $renglones): array
    {
        foreach ($renglones as $indice => $renglon) {
            $textos = array_map(fn (mixed $v): string => BajaHistorica::normalizar($this->texto($v)), $renglon);

            if (array_filter($textos) === []) {
                continue;
            }

            $columnas = [];

            foreach (self::ENCABEZADOS as $clave => $encabezado) {
                $posicion = array_search($encabezado, $textos, true);

                if ($posicion === false) {
                    if (in_array($clave, self::OBLIGATORIOS, true)) {
                        throw ValidationException::withMessages(['archivo' => 'Faltan columnas. Descarga el formato: Nombre completo, Sucursal y Último día laborado son obligatorias.']);
                    }

                    continue;
                }

                $columnas[$clave] = $posicion;
            }

            return [$columnas, $indice + 1];
        }

        throw ValidationException::withMessages(['archivo' => 'El archivo está vacío.']);
    }

    /**
     * @param  array<int, array<int|string, mixed>>  $renglones
     * @param  array<string, int|string>  $columnas
     * @return list<array<string, mixed>>
     */
    private function resolverFilas(array $renglones, array $columnas, int $inicio): array
    {
        $sucursales = $this->catalogoSucursales();
        $puestos = Puesto::query()->get(['id', 'nombre'])->keyBy(fn (Puesto $p): string => BajaHistorica::normalizar($p->nombre));
        $areas = Departamento::query()->get(['id', 'nombre'])->keyBy(fn (Departamento $d): string => BajaHistorica::normalizar($d->nombre));
        $causales = CausalAnalitica::query()->get()->flatMap(fn (CausalAnalitica $c): array => [BajaHistorica::normalizar($c->nombre) => $c, BajaHistorica::normalizar($c->clave) => $c]);
        $sinCausal = CausalAnalitica::query()->where('clave', CausalAnalitica::SIN_CAUSAL)->value('id');
        $existentes = BajaHistorica::query()->pluck('llave')->flip();
        $this->porNombre = Colaborador::query()->withTrashed()->get(['id', 'name', 'apellidos', 'numero_empleado'])
            ->groupBy(fn (Colaborador $c): string => BajaHistorica::normalizar($c->nombreCompleto()));
        $this->porNumero = $this->porNombre->flatten(1)->filter(fn (Colaborador $c): bool => $c->numero_empleado !== null)->keyBy(fn (Colaborador $c): string => (string) $c->numero_empleado);
        $valor = fn (array $renglon, string $clave): mixed => isset($columnas[$clave]) ? ($renglon[$columnas[$clave]] ?? null) : null;
        $filas = [];
        $vistas = [];

        foreach (array_slice($renglones, $inicio, null, true) as $indice => $renglon) {
            $nombre = $this->texto($valor($renglon, 'nombre'));
            $numero = $this->texto($valor($renglon, 'numero'));
            $textoSucursal = $this->texto($valor($renglon, 'sucursal'));
            $baja = $this->fecha($valor($renglon, 'baja'));
            $ingreso = $this->fecha($valor($renglon, 'ingreso'));
            $motivo = $this->texto($valor($renglon, 'motivo'));
            $textoCausal = $this->texto($valor($renglon, 'causal'));
            $textoPuesto = $this->texto($valor($renglon, 'puesto'));
            $textoArea = $this->texto($valor($renglon, 'area'));

            if ($nombre === '' && $textoSucursal === '' && $baja === null) {
                continue;
            }

            $errores = [];
            $avisos = [];
            $sucursal = $sucursales->get(BajaHistorica::normalizar($textoSucursal));

            if ($nombre === '') {
                $errores[] = 'Falta el nombre.';
            }

            if ($sucursal === null) {
                $errores[] = $textoSucursal === '' ? 'Falta la sucursal.' : sprintf('La sucursal «%s» no existe en People: no se crea ninguna.', $textoSucursal);
            }

            if ($baja === null) {
                $errores[] = 'El último día laborado falta o no es una fecha válida.';
            } elseif (CarbonImmutable::parse($baja)->isAfter(CarbonImmutable::today())) {
                $errores[] = 'El último día laborado es futuro.';
            }

            if ($ingreso === null) {
                $avisos[] = $this->texto($valor($renglon, 'ingreso')) === '' ? 'Sin fecha de ingreso: se excluye de la antigüedad.' : 'La fecha de ingreso no es válida: se carga sin ella.';
            } elseif ($baja !== null && $ingreso > $baja) {
                $avisos[] = 'La fecha de ingreso es posterior al último día: queda para revisión.';
            }

            $puesto = $textoPuesto !== '' ? $puestos->get(BajaHistorica::normalizar($textoPuesto)) : null;

            if ($textoPuesto !== '' && $puesto === null) {
                $avisos[] = sprintf('Puesto «%s» no está en el catálogo: se carga sin puesto.', $textoPuesto);
            }

            $area = $textoArea !== '' ? $areas->get(BajaHistorica::normalizar($textoArea)) : null;

            if ($textoArea !== '' && $area === null) {
                $avisos[] = sprintf('Área «%s» no está en el catálogo: se carga sin área.', $textoArea);
            }

            $causal = $textoCausal !== '' ? $causales->get(BajaHistorica::normalizar($textoCausal)) : null;

            if ($textoCausal !== '' && $causal === null) {
                $avisos[] = sprintf('Causal «%s» no reconocida: queda «Sin causal registrada» para corregirla.', $textoCausal);
            }

            $llave = $errores === [] && $baja !== null ? BajaHistorica::llaveDe($nombre, $numero, $baja, $sucursal?->id) : null;
            $colaborador = $errores === [] ? $this->colaborador($nombre, $numero) : null;
            $duplicadoSistema = $errores === [] && $baja !== null ? $this->bajaDelSistema($colaborador, $nombre, $baja) : null;

            if ($llave !== null && isset($vistas[$llave])) {
                $errores[] = sprintf('El mismo evento ya viene en la fila %d.', $vistas[$llave]);
            } elseif ($llave !== null) {
                $vistas[$llave] = $indice + 1;
            }

            $estado = match (true) {
                $errores !== [] => 'error',
                $llave !== null && $existentes->has($llave) => 'sin_cambio',
                $duplicadoSistema !== null => 'duplicado_sistema',
                default => 'nuevo',
            };

            if ($duplicadoSistema !== null && $estado === 'duplicado_sistema') {
                $avisos[] = sprintf('Ya existe como baja del sistema (%s): se liga y no cuenta dos veces.', $duplicadoSistema->folio);
            }

            $filas[] = [
                'fila' => $indice + 1,
                'llave' => $llave,
                'nombre' => $nombre,
                'numero_empleado' => $numero !== '' ? $numero : null,
                'colaborador_id' => $colaborador?->id,
                'baja_id' => $duplicadoSistema?->id,
                'empresa_id' => $sucursal?->empresa_id,
                'sucursal_id' => $sucursal?->id,
                'sucursal' => $sucursal->nombre ?? $textoSucursal,
                'puesto_id' => $puesto?->id,
                'departamento_id' => $area?->id,
                'fecha_ingreso' => $ingreso,
                'fecha_baja' => $baja,
                'motivo_original' => $motivo !== '' ? mb_substr($motivo, 0, 500) : null,
                'causal_analitica_id' => $causal->id ?? $sinCausal,
                'causal' => $causal->nombre ?? 'Sin causal registrada',
                'estado' => $estado,
                'errores' => $errores,
                'avisos' => $avisos,
            ];
        }

        return $filas;
    }

    /**
     * Nombre normalizado (y clave) → sucursal, con los alias confirmados de
     * config/rotacion.php.
     *
     * @return Collection<string, Sucursal>
     */
    private function catalogoSucursales(): Collection
    {
        $todas = Sucursal::query()->get(['id', 'nombre', 'clave', 'empresa_id']);
        $mapa = $todas->flatMap(fn (Sucursal $s): array => array_filter([
            BajaHistorica::normalizar($s->nombre) => $s,
            $s->clave !== null ? BajaHistorica::normalizar($s->clave) : '' => $s,
        ], fn ($v, $k): bool => $k !== '', ARRAY_FILTER_USE_BOTH));

        foreach ((array) config('rotacion.alias_sucursales', []) as $alias => $destino) {
            $sucursal = $mapa->get(BajaHistorica::normalizar((string) $destino));

            if ($sucursal !== null) {
                $mapa->put(BajaHistorica::normalizar((string) $alias), $sucursal);
            }
        }

        return $mapa;
    }

    /** Solo liga a un colaborador existente; nunca lo crea. */
    private function colaborador(string $nombre, string $numero): ?Colaborador
    {
        if ($numero !== '' && $this->porNumero->has($numero)) {
            return $this->porNumero->get($numero);
        }

        /** @var Collection<int, Colaborador> $coincidencias */
        $coincidencias = $this->porNombre->get(BajaHistorica::normalizar($nombre), collect());

        // Dos personas con el mismo nombre: no se adivina cuál es.
        return $coincidencias->count() === 1 ? $coincidencias->first() : null;
    }

    private function bajaDelSistema(?Colaborador $colaborador, string $nombre, string $fecha): ?Baja
    {
        $dia = CarbonImmutable::parse($fecha);

        return Baja::query()
            ->whereIn('estatus', [EstatusBaja::EnRevisionRh->value, EstatusBaja::EnRevisionJuridico->value, EstatusBaja::Cerrada->value])
            ->whereBetween('fecha_baja', [$dia->subDays(7)->toDateString(), $dia->addDays(7)->toDateString()])
            ->get()
            ->first(fn (Baja $b): bool => ($colaborador !== null && $b->colaborador_id === $colaborador->id)
                || BajaHistorica::normalizar($b->nombre_colaborador) === BajaHistorica::normalizar($nombre));
    }

    private function texto(mixed $valor): string
    {
        return is_scalar($valor) ? trim((string) $valor) : '';
    }

    private function fecha(mixed $valor): ?string
    {
        try {
            if (is_numeric($valor)) {
                return CarbonImmutable::instance(FechaExcel::excelToDateTimeObject((float) $valor))->toDateString();
            }

            $texto = $this->texto($valor);

            if ($texto === '') {
                return null;
            }

            foreach (['!d/m/Y', '!Y-m-d', '!d-m-Y', '!d/m/y'] as $formato) {
                $fecha = CarbonImmutable::createFromFormat($formato, $texto);

                if ($fecha !== false && $fecha->format(ltrim($formato, '!')) === $texto) {
                    return $fecha->toDateString();
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }
}
