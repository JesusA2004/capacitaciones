<?php

namespace App\Services\Salarios;

use App\Models\ImportacionExcel;
use App\Models\Puesto;
use App\Models\TabuladorSalarial;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as FechaExcel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Throwable;

/**
 * Importación del tabulador desde Excel. analizar() solo deja la vista previa;
 * aplicar() crea una versión nueva por puesto, todo o nada. Nunca toca sueldos
 * reales, nunca crea puestos: lo que no cuadra se reporta y bloquea.
 */
class TabuladorImportService
{
    public const TIPO = 'tabulador_salarial';

    private const ENCABEZADOS = ['puesto' => 'puesto', 'minimo' => 'minimo', 'medio' => 'medio', 'maximo' => 'maximo', 'fuente' => 'fuente', 'fecha' => 'fecha de actualizacion'];

    public function __construct(private readonly TabuladorSalarialService $tabulador) {}

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
            throw ValidationException::withMessages(['archivo' => 'El archivo no trae puestos.']);
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
            'metadatos' => [],
        ]);
    }

    public function aplicar(ImportacionExcel $importacion, string $motivo, User $usuario): int
    {
        return DB::transaction(function () use ($importacion, $motivo, $usuario): int {
            $registro = ImportacionExcel::query()->lockForUpdate()->findOrFail($importacion->id);

            if ($registro->tipo !== self::TIPO) {
                throw ValidationException::withMessages(['importacion' => 'Esta importación no es de tabulador.']);
            }

            if ($registro->estado === 'aplicado') {
                throw ValidationException::withMessages(['importacion' => 'Esta importación ya se aplicó.']);
            }

            if ($registro->filas_error > 0) {
                throw ValidationException::withMessages(['importacion' => 'Corrige las filas con error y vuelve a subir el archivo.']);
            }

            $filas = array_values(array_filter($registro->detalle ?? [], fn (array $f): bool => in_array($f['estado'], ['nuevo', 'cambio'], true)));

            foreach ($filas as $fila) {
                if ($this->versionActual((int) $fila['puesto_id']) !== $fila['version_anterior']) {
                    throw ValidationException::withMessages(['importacion' => sprintf('«%s» cambió desde la vista previa. Vuelve a subir el archivo.', $fila['puesto'])]);
                }
            }

            foreach ($filas as $fila) {
                $this->tabulador->guardar(Puesto::query()->whereKey((int) $fila['puesto_id'])->firstOrFail(), [
                    'minimo' => $fila['minimo'],
                    'medio' => $fila['medio'],
                    'maximo' => $fila['maximo'],
                    'fuente' => (string) $fila['fuente'],
                    'fecha_actualizacion' => (string) $fila['fecha_actualizacion'],
                    'motivo' => $motivo,
                ], $usuario, 'excel', $registro->id);
            }

            $registro->update([
                'estado' => 'aplicado',
                'filas_aplicadas' => count($filas),
                'aplicado_en' => now(),
                'aplicado_por' => $usuario->id,
                'metadatos' => ['motivo' => $motivo],
            ]);

            return count($filas);
        });
    }

    /** Formato vacío con el catálogo real de puestos, sin importes de ejemplo. */
    public function plantillaExcel(): Spreadsheet
    {
        $libro = new Spreadsheet;
        $libro->getActiveSheet()->setTitle('Tabulador')->fromArray(['Puesto', 'Mínimo', 'Medio', 'Máximo', 'Fuente', 'Fecha de actualización']);

        foreach (Puesto::query()->where('activo', true)->orderBy('nombre')->pluck('nombre')->values() as $i => $nombre) {
            $libro->getActiveSheet()->setCellValue(sprintf('A%d', $i + 2), $nombre);
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
            $textos = array_map(fn (mixed $v): string => $this->normalizar($this->texto($v)), $renglon);

            if (array_filter($textos) === []) {
                continue;
            }

            $columnas = [];

            foreach (self::ENCABEZADOS as $clave => $encabezado) {
                $posicion = array_search($encabezado, $textos, true);

                if ($posicion === false) {
                    throw ValidationException::withMessages(['archivo' => 'Faltan columnas. El primer renglón debe decir: Puesto, Mínimo, Medio, Máximo, Fuente y Fecha de actualización.']);
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
        $puestos = Puesto::query()->get(['id', 'nombre', 'activo'])->keyBy(fn (Puesto $p): string => $this->normalizar($p->nombre));
        $vigentes = $this->tabulador->vigentes();
        $filas = [];
        $primeraPorPuesto = [];

        foreach (array_slice($renglones, $inicio, null, true) as $indice => $renglon) {
            $textoPuesto = $this->texto($renglon[$columnas['puesto']] ?? null);
            $minimo = $this->importe($renglon[$columnas['minimo']] ?? null);
            $medio = $this->importe($renglon[$columnas['medio']] ?? null);
            $maximo = $this->importe($renglon[$columnas['maximo']] ?? null);
            $fuente = $this->texto($renglon[$columnas['fuente']] ?? null);
            $fecha = $this->fecha($renglon[$columnas['fecha']] ?? null);

            // Un puesto del formato sin importes es un puesto que aún no se captura.
            if ($minimo === null && $medio === null && $maximo === null && $fuente === '' && $fecha === null) {
                continue;
            }

            $errores = [];
            $puesto = $puestos->get($this->normalizar($textoPuesto));

            if ($puesto === null) {
                $errores[] = sprintf('Puesto «%s» no existe en el catálogo.', $textoPuesto);
            } elseif (! $puesto->activo) {
                $errores[] = sprintf('El puesto «%s» está inactivo.', $puesto->nombre);
            }

            if ($minimo === null || $medio === null || $maximo === null) {
                $errores[] = 'Mínimo, medio y máximo deben ser importes numéricos.';
            } else {
                $errorRango = TabuladorSalarialService::errorDeRango($minimo, $medio, $maximo);

                if ($errorRango !== null) {
                    $errores[] = $errorRango;
                }
            }

            if ($fuente === '') {
                $errores[] = 'Falta la fuente del estudio.';
            }

            if ($fecha === null) {
                $errores[] = 'La fecha de actualización no es válida.';
            }

            if ($puesto !== null) {
                if (isset($primeraPorPuesto[$puesto->id])) {
                    $errores[] = sprintf('Duplicado: «%s» ya viene en la fila %d.', $puesto->nombre, $primeraPorPuesto[$puesto->id]);
                } else {
                    $primeraPorPuesto[$puesto->id] = $indice + 1;
                }
            }

            $actual = $puesto !== null ? $vigentes->get($puesto->id) : null;

            $filas[] = [
                'fila' => $indice + 1,
                'puesto_id' => $puesto?->id,
                'puesto' => $puesto->nombre ?? $textoPuesto,
                'minimo' => $minimo,
                'medio' => $medio,
                'maximo' => $maximo,
                'fuente' => $fuente,
                'fecha_actualizacion' => $fecha,
                'version_anterior' => $actual?->version,
                'estado' => $errores !== [] ? 'error' : $this->estadoDe($actual, (float) $minimo, (float) $medio, (float) $maximo, $fuente, (string) $fecha),
                'errores' => $errores,
            ];
        }

        return $filas;
    }

    private function estadoDe(?TabuladorSalarial $actual, float $minimo, float $medio, float $maximo, string $fuente, string $fecha): string
    {
        if ($actual === null) {
            return 'nuevo';
        }

        $igual = (float) $actual->minimo === $minimo && (float) $actual->medio === $medio && (float) $actual->maximo === $maximo
            && $actual->fuente === $fuente && $actual->fecha_actualizacion->toDateString() === $fecha;

        return $igual ? 'sin_cambio' : 'cambio';
    }

    private function versionActual(int $puestoId): ?int
    {
        return TabuladorSalarial::query()->where('puesto_id', $puestoId)->whereNull('vigente_hasta')->first()?->version;
    }

    private function importe(mixed $valor): ?float
    {
        if (is_string($valor)) {
            $valor = str_replace(['$', ',', ' '], '', $valor);
        }

        return is_numeric($valor) ? round((float) $valor, 2) : null;
    }

    private function fecha(mixed $valor): ?string
    {
        try {
            if (is_numeric($valor)) {
                return Carbon::instance(FechaExcel::excelToDateTimeObject((float) $valor))->toDateString();
            }

            $texto = $this->texto($valor);

            if ($texto === '') {
                return null;
            }

            return preg_match('#^\d{1,2}/\d{1,2}/\d{4}$#', $texto) === 1
                ? Carbon::createFromFormat('d/m/Y', $texto)?->toDateString()
                : Carbon::parse($texto)->toDateString();
        } catch (Throwable) {
            return null;
        }
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
