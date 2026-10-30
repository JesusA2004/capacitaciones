<?php

namespace App\Services\Headcount;

use App\Models\ImportacionExcel;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Throwable;

/**
 * Conciliación e importación auditable del catálogo de plazas autorizadas por
 * (sucursal, puesto), con Corporativo como una ubicación más. Lee el formato
 * simple «Sucursal | Puesto | Plazas autorizadas» (no el Excel por bloques de
 * HeadcountImportService, que no cubre los puestos de Corporativo).
 *
 * Flujo: analizar() solo guarda la vista previa en importaciones_excel; nada
 * cambia hasta aplicar(), que escribe con PlantillaAutorizadaService (bitácora
 * en headcount_target_historial y sincronización de vacantes). Nunca crea
 * sucursales ni puestos, nunca adivina: una fila que no cuadra se reporta y
 * bloquea la aplicación. Una plaza que no viene en el archivo no se toca.
 */
class PlazasAutorizadasImportService
{
    public const TIPO = 'plazas_autorizadas';

    private const COLUMNA_SUCURSAL = 'sucursal';

    private const COLUMNA_PUESTO = 'puesto';

    private const COLUMNA_PLAZAS = 'plazas autorizadas';

    /** Tope de sanidad por par: un número mayor es un error de captura. */
    private const PLAZAS_MAXIMAS = 1000;

    public function __construct(
        private readonly HeadcountService $headcount,
        private readonly PlantillaAutorizadaService $plantilla,
        private readonly PuestosPlantillaService $puestos,
    ) {}

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

        [$columnas, $ignoradas, $inicio] = $this->encabezados($renglones);
        $filas = $this->resolverFilas($renglones, $columnas, $inicio);

        if ($filas === []) {
            throw ValidationException::withMessages(['archivo' => 'El archivo no trae filas de plazas.']);
        }

        $cuenta = fn (string $estado): int => count(array_filter($filas, fn (array $f): bool => $f['estado'] === $estado));

        return ImportacionExcel::query()->updateOrCreate(['archivo_hash' => $hash], [
            'nombre_archivo' => mb_substr($archivo->getClientOriginalName(), 0, 255),
            'tipo' => self::TIPO,
            'subido_por' => $usuario->id,
            'estado' => 'previsualizado',
            'total_filas' => count($filas),
            'filas_aplicadas' => 0,
            'filas_revision' => $cuenta('nuevo') + $cuenta('cambio'),
            'filas_sin_cambios' => $cuenta('sin_cambio'),
            'filas_no_encontradas' => 0,
            'filas_error' => $cuenta('error'),
            'detalle' => $filas,
            'metadatos' => [
                'columnas_ignoradas' => $ignoradas,
                'no_incluidas' => $this->plazasNoIncluidas($filas),
                'conciliacion' => $this->conciliacionDe($filas),
            ],
        ]);
    }

    /**
     * Aplica la vista previa completa o nada. Se rechaza si tiene errores o si
     * alguna plaza cambió desde que se analizó (la previa ya no es lo que se
     * revisó). El bloqueo de la fila evita aplicar dos veces por doble clic.
     *
     * @return array{creadas: int, actualizadas: int}
     */
    public function aplicar(ImportacionExcel $importacion, string $motivo, User $usuario): array
    {
        return DB::transaction(function () use ($importacion, $motivo, $usuario): array {
            $registro = ImportacionExcel::query()->lockForUpdate()->findOrFail($importacion->id);

            if ($registro->tipo !== self::TIPO) {
                throw ValidationException::withMessages(['importacion' => 'Esta importación no es de plazas autorizadas.']);
            }

            if ($registro->estado === 'aplicado') {
                throw ValidationException::withMessages(['importacion' => 'Esta importación ya se aplicó.']);
            }

            if ($registro->filas_error > 0) {
                throw ValidationException::withMessages(['importacion' => 'Corrige las filas con error y vuelve a subir el archivo.']);
            }

            $filas = array_values(array_filter($registro->detalle ?? [], fn (array $f): bool => in_array($f['estado'], ['nuevo', 'cambio'], true)));

            foreach ($filas as $fila) {
                if ($this->autorizadaActual((int) $fila['sucursal_id'], (int) $fila['puesto_id']) !== $fila['anterior']) {
                    throw ValidationException::withMessages(['importacion' => sprintf('%s / %s cambió desde la vista previa. Vuelve a subir el archivo.', $fila['sucursal'], $fila['puesto'])]);
                }
            }

            $nota = mb_substr(sprintf('%s (archivo: %s)', $motivo, $registro->nombre_archivo), 0, 500);
            $conteo = ['creadas' => 0, 'actualizadas' => 0];

            foreach ($filas as $fila) {
                $this->plantilla->actualizar(
                    Sucursal::query()->whereKey((int) $fila['sucursal_id'])->firstOrFail(),
                    Puesto::query()->whereKey((int) $fila['puesto_id'])->firstOrFail(),
                    (int) $fila['nuevo'],
                    $nota,
                    $usuario,
                    'excel:plazas',
                );
                $fila['estado'] === 'nuevo' ? $conteo['creadas']++ : $conteo['actualizadas']++;
            }

            $registro->update([
                'estado' => 'aplicado',
                'filas_aplicadas' => count($filas),
                'aplicado_en' => now(),
                'aplicado_por' => $usuario->id,
                'metadatos' => [...($registro->metadatos ?? []), 'motivo' => $motivo, 'resultado' => $conteo],
            ]);

            activity('headcount')
                ->event('plazas_importadas')
                ->causedBy($usuario)
                ->performedOn($registro)
                ->withProperties(['archivo' => $registro->nombre_archivo, 'hash' => $registro->archivo_hash, 'motivo' => $motivo, ...$conteo])
                ->log('Importación de plazas autorizadas aplicada');

            return $conteo;
        });
    }

    /** Formato vacío para capturar: encabezados y catálogos reales, sin plazas de ejemplo. */
    public function plantillaExcel(): Spreadsheet
    {
        $libro = new Spreadsheet;
        $libro->getActiveSheet()->setTitle('Plazas')->fromArray(['Sucursal', 'Puesto', 'Plazas autorizadas']);

        $catalogos = $libro->createSheet()->setTitle('Catálogos');
        $catalogos->fromArray(['Sucursales válidas', 'Puestos válidos']);

        foreach (Sucursal::query()->where('activo', true)->orderBy('nombre')->pluck('nombre')->values() as $i => $nombre) {
            $catalogos->setCellValue(sprintf('A%d', $i + 2), $nombre);
        }

        foreach ($this->plantilla->puestosCapturables()->values() as $i => $puesto) {
            $catalogos->setCellValue(sprintf('B%d', $i + 2), $puesto['nombre']);
        }

        return $libro;
    }

    /**
     * @param  array<int, array<int|string, mixed>>  $renglones
     * @return array{0: array{sucursal: int|string, puesto: int|string, plazas: int|string}, 1: list<string>, 2: int}
     */
    private function encabezados(array $renglones): array
    {
        foreach ($renglones as $indice => $renglon) {
            $textos = array_map(fn (mixed $v): string => $this->normalizar($this->texto($v)), $renglon);

            if (array_filter($textos) === []) {
                continue;
            }

            $sucursal = array_search(self::COLUMNA_SUCURSAL, $textos, true);
            $puesto = array_search(self::COLUMNA_PUESTO, $textos, true);
            $plazas = array_search(self::COLUMNA_PLAZAS, $textos, true);

            if ($sucursal === false || $puesto === false || $plazas === false) {
                throw ValidationException::withMessages(['archivo' => 'Faltan columnas. El primer renglón debe decir: Sucursal, Puesto y Plazas autorizadas.']);
            }

            $ignoradas = array_values(array_filter(
                array_map(fn (mixed $v): string => $this->texto($v), array_diff_key($renglon, array_flip([$sucursal, $puesto, $plazas]))),
                fn (string $t): bool => $t !== '',
            ));

            return [['sucursal' => $sucursal, 'puesto' => $puesto, 'plazas' => $plazas], $ignoradas, $indice + 1];
        }

        throw ValidationException::withMessages(['archivo' => 'El archivo está vacío.']);
    }

    /**
     * @param  array<int, array<int|string, mixed>>  $renglones
     * @param  array{sucursal: int|string, puesto: int|string, plazas: int|string}  $columnas
     * @return list<array<string, mixed>>
     */
    private function resolverFilas(array $renglones, array $columnas, int $inicio): array
    {
        $sucursales = Sucursal::query()->get(['id', 'nombre', 'activo', 'es_corporativo'])->keyBy(fn (Sucursal $s): string => $this->normalizar($s->nombre));
        $puestos = Puesto::query()->get(['id', 'nombre', 'activo'])->keyBy(fn (Puesto $p): string => $this->normalizar($p->nombre));
        $nombresPuesto = $puestos->mapWithKeys(fn (Puesto $p): array => [$p->id => $p->nombre]);

        $alias = [];
        foreach ((array) config('headcount.alias_sucursales', []) as $nombreExcel => $nombreCatalogo) {
            $alias[$this->normalizar((string) $nombreExcel)] = $this->normalizar((string) $nombreCatalogo);
        }

        $filas = [];
        $vistos = [];
        $porRenglon = [];
        $duplicadas = [];

        foreach (array_slice($renglones, $inicio, null, true) as $indice => $renglon) {
            $textoSucursal = $this->texto($renglon[$columnas['sucursal']] ?? null);
            $textoPuesto = $this->texto($renglon[$columnas['puesto']] ?? null);
            $valorPlazas = $renglon[$columnas['plazas']] ?? null;

            if ($textoSucursal === '' && $textoPuesto === '' && $this->texto($valorPlazas) === '') {
                continue;
            }

            $errores = [];
            $avisos = [];
            $claveSucursal = $this->normalizar($textoSucursal);
            $sucursal = $sucursales->get($claveSucursal) ?? $sucursales->get($alias[$claveSucursal] ?? '');
            $puesto = $puestos->get($this->normalizar($textoPuesto));
            $plazas = $this->plazasDe($valorPlazas);

            if ($sucursal === null) {
                $errores[] = sprintf('Sucursal «%s» no existe en el catálogo.', $textoSucursal);
            } elseif (! $sucursal->activo) {
                $errores[] = sprintf('La sucursal «%s» está inactiva.', $sucursal->nombre);
            }

            if ($puesto === null) {
                $errores[] = sprintf('Puesto «%s» no existe en el catálogo.', $textoPuesto);
            } elseif (! $puesto->activo) {
                $errores[] = sprintf('El puesto «%s» está inactivo.', $puesto->nombre);
            }

            if ($plazas === null) {
                $errores[] = sprintf('Plazas «%s» no es un número entero entre 0 y %d.', $this->texto($valorPlazas), self::PLAZAS_MAXIMAS);
            }

            $puestoPlantillaId = $puesto !== null ? $this->puestos->canonico($puesto->id) : null;

            if ($puesto !== null && $puestoPlantillaId !== $puesto->id) {
                $avisos[] = sprintf('«%s» se integra a su puesto de plantilla.', $puesto->nombre);
            }

            $fila = [
                'fila' => $indice + 1,
                'sucursal_id' => $sucursal?->id,
                'sucursal' => $sucursal->nombre ?? $textoSucursal,
                'puesto_id' => $puestoPlantillaId,
                'puesto' => $puestoPlantillaId !== null ? (string) ($nombresPuesto[$puestoPlantillaId] ?? $textoPuesto) : $textoPuesto,
                'anterior' => null,
                'nuevo' => $plazas,
                'ocupadas' => null,
                'estado' => 'error',
                'errores' => $errores,
                'avisos' => $avisos,
            ];

            if ($sucursal !== null && $puestoPlantillaId !== null) {
                $clave = sprintf('%d:%d', $sucursal->id, $puestoPlantillaId);

                // Un par repetido nunca se resuelve eligiendo uno: ambas filas quedan en error.
                if (isset($vistos[$clave])) {
                    $previa = $vistos[$clave];
                    $mensaje = sprintf('Duplicado: «%s / %s» viene en las filas %d y %d.', $fila['sucursal'], $fila['puesto'], $previa + 1, $indice + 1);
                    $fila['errores'][] = $mensaje;
                    $duplicadas[$porRenglon[$previa]] = $mensaje;
                } else {
                    $vistos[$clave] = $indice;
                    $porRenglon[$indice] = count($filas);
                }

                $fila['anterior'] = $this->autorizadaActual($sucursal->id, $puestoPlantillaId);
                $fila['ocupadas'] = $this->headcount->plantillaDePar($sucursal->id, $puestoPlantillaId)['ocupada'];
            }

            if ($fila['errores'] === []) {
                $fila['estado'] = match (true) {
                    $fila['anterior'] === null => 'nuevo',
                    $fila['anterior'] === $plazas => 'sin_cambio',
                    default => 'cambio',
                };

                if ($fila['ocupadas'] > $plazas) {
                    $fila['avisos'][] = sprintf('Hay %d persona(s) vigentes: quedarían %d sin plaza. No se oculta a nadie.', $fila['ocupadas'], $fila['ocupadas'] - $plazas);
                }
            }

            $filas[] = $fila;
        }

        return array_map(function (array $fila, int $posicion) use ($duplicadas): array {
            if (! isset($duplicadas[$posicion])) {
                return $fila;
            }

            return [...$fila, 'estado' => 'error', 'errores' => [...(array) $fila['errores'], $duplicadas[$posicion]]];
        }, $filas, array_keys($filas));
    }

    /** Null si el par nunca se ha capturado (distinto de capturado en 0). */
    private function autorizadaActual(int $sucursalId, int $puestoId): ?int
    {
        $filas = DB::table('headcount_targets')
            ->where('sucursal_id', $sucursalId)
            ->whereIn('puesto_id', $this->puestos->equivalentes($puestoId))
            ->pluck('plantilla_autorizada');

        return $filas->isEmpty() ? null : (int) $filas->sum();
    }

    /**
     * Plazas vigentes que el archivo no menciona: no se tocan, pero RH debe verlas.
     *
     * @param  list<array<string, mixed>>  $filas
     * @return list<array{sucursal: string, puesto: string, plazas: int}>
     */
    private function plazasNoIncluidas(array $filas): array
    {
        $incluidas = collect($filas)
            ->filter(fn (array $f): bool => $f['sucursal_id'] !== null && $f['puesto_id'] !== null)
            ->map(fn (array $f): string => sprintf('%d:%d', $f['sucursal_id'], $f['puesto_id']));

        $sucursales = Sucursal::query()->pluck('nombre', 'id');
        $puestos = Puesto::query()->pluck('nombre', 'id');
        $lista = [];

        foreach ($this->headcount->plantillaAutorizadaPorSucursalPuesto() as $clave => $plazas) {
            if ($plazas > 0 && ! $incluidas->contains($clave)) {
                [$sucursalId, $puestoId] = array_map('intval', explode(':', (string) $clave));
                $lista[] = ['sucursal' => (string) ($sucursales[$sucursalId] ?? '—'), 'puesto' => (string) ($puestos[$puestoId] ?? '—'), 'plazas' => $plazas];
            }
        }

        return $lista;
    }

    /**
     * Antes y después con la misma fórmula de HeadcountService: lo que se ve en la
     * vista previa es lo que mostrarán Tablero, Directorio y Reportes al aplicar.
     *
     * @param  list<array<string, mixed>>  $filas
     * @return array{antes: array<string, mixed>, despues: array<string, mixed>}
     */
    private function conciliacionDe(array $filas): array
    {
        $simuladas = $this->headcount->plantillaAutorizadaPorSucursalPuesto();

        foreach ($filas as $fila) {
            if ($fila['estado'] !== 'error') {
                $simuladas->put(sprintf('%d:%d', $fila['sucursal_id'], $fila['puesto_id']), (int) $fila['nuevo']);
            }
        }

        return ['antes' => $this->headcount->poblacion(), 'despues' => $this->headcount->conciliar($simuladas)];
    }

    private function plazasDe(mixed $valor): ?int
    {
        if (! is_numeric($valor)) {
            return null;
        }

        $numero = (float) $valor;

        return floor($numero) === $numero && $numero >= 0 && $numero <= self::PLAZAS_MAXIMAS ? (int) $numero : null;
    }

    private function texto(mixed $valor): string
    {
        return is_scalar($valor) ? trim((string) $valor) : '';
    }

    private function normalizar(string $texto): string
    {
        $texto = mb_strtolower(trim($texto));
        $texto = str_replace(['á', 'é', 'í', 'ó', 'ú'], ['a', 'e', 'i', 'o', 'u'], $texto);

        return preg_replace('/\s+/', ' ', $texto) ?? $texto;
    }
}
