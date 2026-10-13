<?php

namespace App\Services\ImportadorTrabajadores;

use App\Models\Colaborador;
use App\Models\ImportacionExcel;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Compensacion\CompensacionService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as FechaExcel;
use Throwable;

/**
 * Importador de docs/onboarding/REPORTE_TRABAJADORES_VAC.xlsx: la hoja
 * real trae sus encabezados en la fila 4 (NOMBRE COMPLETO, SUCURSAL,
 * FECHA INGRESO, DIAS OTORGADOS, DIAS TOMADOS, DÍAS RESTANTES, SALARIO
 * DIARIO) — SIN clave de empleado, RFC, CURP ni salario integrado. Nunca
 * se asume otra estructura: si las columnas esperadas no están en esa
 * fila, se reporta en vez de adivinar.
 *
 * Flujo obligatorio: analizar() (preview, nunca escribe en colaboradores)
 * → RH revisa → aplicar() (confirma). El mismo archivo (por hash) nunca se
 * aplica dos veces. NUNCA crea colaboradores: toda fila sin match
 * confiable queda para revisión manual.
 */
class ImportadorTrabajadoresService
{
    private const FILA_ENCABEZADOS = 4;

    private const COLUMNAS_REQUERIDAS = [
        'nombre_completo', 'sucursal', 'fecha_ingreso', 'dias_otorgados', 'dias_tomados', 'dias_restantes', 'salario_diario',
    ];

    public function __construct(
        private readonly CompensacionService $compensacion,
        private readonly AuditoriaService $auditoria,
    ) {}

    /**
     * Quita una importación que se analizó pero ya no se aplicará. Solo
     * guardaba el análisis (el Excel no se conserva) y no tocó a ningún
     * colaborador. Una importación APLICADA no se quita: es el historial de
     * los salarios y ajustes de vacaciones que cambió.
     */
    public function eliminar(ImportacionExcel $importacion, User $actor): void
    {
        if ($importacion->estado === 'aplicado') {
            throw ValidationException::withMessages(['importacion' => 'Esta importación ya se aplicó: se conserva como historial de los cambios que hizo.']);
        }

        $datos = ['importacion_id' => $importacion->id, 'archivo' => $importacion->nombre_archivo, 'total_filas' => $importacion->total_filas];
        $importacion->delete();
        $this->auditoria->registrar('importacion_trabajadores_eliminada', null, $actor, $datos);
    }

    /**
     * Lee y resuelve el archivo (match, antigüedad, propuesta de salario y
     * de ajuste de vacaciones) SIN escribir nada en colaboradores — solo
     * crea el registro de previsualización.
     */
    public function analizar(UploadedFile $archivo, User $actor, ?Carbon $fechaCorte = null): ImportacionExcel
    {
        $hash = hash_file('sha256', $archivo->getRealPath());

        $yaAplicado = ImportacionExcel::query()->where('archivo_hash', $hash)->where('estado', 'aplicado')->first();

        if ($yaAplicado !== null) {
            throw ValidationException::withMessages([
                'archivo' => "Este mismo archivo ya se aplicó el {$yaAplicado->aplicado_en?->toDateString()} (importación #{$yaAplicado->id}). No se vuelve a aplicar.",
            ]);
        }

        $fechaCorte ??= Carbon::today('America/Mexico_City');
        $filas = $this->leer($archivo);

        $sucursales = Sucursal::query()->where('activo', true)->get(['id', 'nombre']);
        $porSucursalYNombre = $this->agruparColaboradores();

        $detalle = [];
        $contadores = ['aplicable' => 0, 'revision' => 0, 'sin_cambios' => 0, 'no_encontrado' => 0, 'error' => 0];

        foreach ($filas as $numeroFila => $fila) {
            $resultado = $this->resolverFila($numeroFila, $fila, $sucursales, $porSucursalYNombre, $fechaCorte);
            $detalle[] = $resultado;
            $contadores[$resultado['estado']]++;
        }

        // archivo_hash es único: volver a previsualizar el mismo archivo
        // (aún sin aplicar) recalcula ESE registro en vez de crear otro — así
        // un archivo solo puede tener una importación y aplicarse una vez.
        return ImportacionExcel::query()->updateOrCreate(['archivo_hash' => $hash], [
            'nombre_archivo' => $archivo->getClientOriginalName() ?: 'REPORTE_TRABAJADORES_VAC.xlsx',
            'subido_por' => $actor->id,
            'estado' => 'previsualizado',
            'total_filas' => count($detalle),
            'filas_aplicadas' => 0,
            'filas_revision' => $contadores['revision'],
            'filas_sin_cambios' => $contadores['sin_cambios'],
            'filas_no_encontradas' => $contadores['no_encontrado'],
            'filas_error' => $contadores['error'],
            'detalle' => $detalle,
        ]);
    }

    /**
     * Aplica las filas con match confiable y cambios reales (estado
     * 'aplicable'); `$filasSeleccionadas` (índices 0-based de `detalle`)
     * permite que RH excluya filas puntuales. Nunca se reaplica una
     * importación ya aplicada.
     *
     * @param  list<int>|null  $filasSeleccionadas
     * @return array{filas_aplicadas: int, errores: list<string>}
     */
    public function aplicar(ImportacionExcel $importacion, User $actor, ?array $filasSeleccionadas = null): array
    {
        if ($importacion->estado === 'aplicado') {
            throw ValidationException::withMessages(['importacion' => 'Esta importación ya fue aplicada.']);
        }

        return DB::transaction(function () use ($importacion, $actor, $filasSeleccionadas): array {
            $importacion = ImportacionExcel::query()->lockForUpdate()->findOrFail($importacion->id);

            if ($importacion->estado === 'aplicado') {
                throw ValidationException::withMessages(['importacion' => 'Esta importación ya fue aplicada.']);
            }

            /** @var list<array<string, mixed>> $detalle */
            $detalle = $importacion->detalle ?? [];
            $aplicadas = 0;
            $errores = [];
            $hoy = Carbon::today('America/Mexico_City')->toDateString();

            foreach ($detalle as $indice => &$fila) {
                if ($fila['estado'] !== 'aplicable') {
                    continue;
                }

                if ($filasSeleccionadas !== null && ! in_array($indice, $filasSeleccionadas, true)) {
                    continue;
                }

                $colaborador = Colaborador::query()->where('id', $fila['colaborador_id'])->first();

                if ($colaborador === null) {
                    $errores[] = "Fila {$fila['fila']}: el colaborador ya no existe.";

                    continue;
                }

                try {
                    // Fecha de ingreso: la del reporte corrige la de PEOPLE.
                    if (($fila['corrige_fecha_ingreso'] ?? false) && is_string($fila['fecha_ingreso_excel'] ?? null)) {
                        $colaborador->forceFill(['fecha_ingreso' => $fila['fecha_ingreso_excel']])->save();
                    }

                    if ($fila['cambia_salario']) {
                        $this->compensacion->actualizarSalario(
                            $colaborador,
                            (float) $fila['salario_diario_excel'],
                            null,
                            $actor,
                            [
                                'fuente' => 'importacion_excel',
                                'importacion_id' => $importacion->id,
                                'fecha_efectiva' => $hoy,
                                'motivo' => "Importación {$importacion->nombre_archivo} (fila {$fila['fila']}).",
                            ],
                        );
                    }

                    if ((float) $fila['ajuste_vacaciones_propuesto'] !== 0.0) {
                        $colaborador->ajustesVacaciones()->create([
                            'tipo' => 'ajuste_acumulado',
                            'dias' => $fila['ajuste_vacaciones_propuesto'],
                            'fecha_corte' => $fila['fecha_corte'],
                            'fuente' => 'importacion_excel',
                            'importacion_id' => $importacion->id,
                            'motivo' => $fila['motivo_ajuste_vacaciones'] ?? 'Días tomados antes de PEOPLE (reporte de vacaciones).',
                            'actor_id' => $actor->id,
                        ]);
                    }

                    $fila['aplicado'] = true;
                    $aplicadas++;
                } catch (Throwable $e) {
                    $errores[] = "Fila {$fila['fila']} ({$fila['nombre_excel']}): {$e->getMessage()}";
                }
            }
            unset($fila);

            $importacion->update([
                'detalle' => $detalle,
                'estado' => 'aplicado',
                'filas_aplicadas' => $aplicadas,
                'aplicado_en' => now(),
                'aplicado_por' => $actor->id,
            ]);

            return ['filas_aplicadas' => $aplicadas, 'errores' => $errores];
        });
    }

    /**
     * @param  array<string, mixed>  $fila
     * @param  Collection<int, Sucursal>  $sucursales
     * @param  array<int, array<string, list<Colaborador>>>  $porSucursalYNombre
     * @return array<string, mixed>
     */
    private function resolverFila(int $numeroFila, array $fila, Collection $sucursales, array $porSucursalYNombre, Carbon $fechaCorte): array
    {
        $base = [
            'fila' => $numeroFila,
            'nombre_excel' => (string) ($fila['nombre_completo'] ?? ''),
            'sucursal_excel' => (string) ($fila['sucursal'] ?? ''),
            'fecha_corte' => $fechaCorte->toDateString(),
            'colaborador_id' => null,
            'nombre_sistema' => null,
            'sucursal_sistema' => null,
            'cambia_salario' => false,
            'salario_diario_actual' => null,
            'salario_diario_excel' => null,
            'antiguedad_anios' => null,
            'cumple_un_anio' => null,
            'dias_tomados_excel' => null,
            'ajuste_vacaciones_propuesto' => 0.0,
            'motivo_ajuste_vacaciones' => null,
            'motivo_vacaciones_no_aplican' => null,
            'motivo' => null,
            'aplicado' => false,
        ];

        $nombreExcel = trim((string) ($fila['nombre_completo'] ?? ''));
        $sucursalExcel = trim((string) ($fila['sucursal'] ?? ''));
        $fechaIngresoExcel = $fila['fecha_ingreso'] ?? null;
        $salarioDiarioExcel = $fila['salario_diario'] ?? null;

        if ($nombreExcel === '' || $sucursalExcel === '' || ! is_numeric($salarioDiarioExcel) || (float) $salarioDiarioExcel <= 0) {
            return [...$base, 'estado' => 'error', 'motivo' => 'Faltan datos obligatorios (nombre, sucursal o salario diario inválido).'];
        }

        $sucursalesCandidatas = $sucursales->filter(fn (Sucursal $s) => $this->mismaSucursal($s->nombre, $sucursalExcel));

        if ($sucursalesCandidatas->count() !== 1) {
            return [...$base, 'estado' => 'no_encontrado', 'motivo' => $sucursalesCandidatas->isEmpty()
                ? "No existe la sucursal «{$sucursalExcel}» en el catálogo."
                : "«{$sucursalExcel}» coincide con más de una sucursal del catálogo: revisar manualmente."];
        }

        $sucursal = $sucursalesCandidatas->first();
        $base['sucursal_sistema'] = $sucursal->nombre;

        $nombreNormalizado = $this->normalizar($nombreExcel);
        $candidatos = $porSucursalYNombre[$sucursal->id][$nombreNormalizado] ?? [];

        if (count($candidatos) === 0) {
            return [...$base, 'estado' => 'no_encontrado', 'motivo' => "Ningún colaborador activo de «{$sucursal->nombre}» coincide con «{$nombreExcel}»."];
        }

        if (count($candidatos) > 1) {
            return [...$base, 'estado' => 'revision', 'motivo' => "Nombre ambiguo: {$nombreExcel} coincide con ".count($candidatos)." colaboradores activos de «{$sucursal->nombre}»."];
        }

        $colaborador = $candidatos[0];
        $base['colaborador_id'] = $colaborador->id;
        $base['nombre_sistema'] = trim($colaborador->name.' '.($colaborador->apellidos ?? ''));

        // La fecha de ingreso del REPORTE es la fuente (decisión de RH,
        // 2026-10-13): si PEOPLE tiene otra (o ninguna), al aplicar se
        // corrige con la del Excel y queda registrado en el detalle
        // (fecha_ingreso_anterior → fecha_ingreso_excel). Sin fecha válida en
        // el reporte se conserva la de PEOPLE.
        $fechaIngresoExcelParsed = $this->parsearFecha($fechaIngresoExcel);
        $fechaIngreso = $fechaIngresoExcelParsed ?? $colaborador->fecha_ingreso;

        if ($fechaIngreso === null) {
            return [...$base, 'estado' => 'revision', 'motivo' => 'Ni el reporte ni PEOPLE tienen fecha de ingreso: no se puede calcular antigüedad.'];
        }

        $base['fecha_ingreso_anterior'] = $colaborador->fecha_ingreso?->toDateString();
        $base['fecha_ingreso_excel'] = $fechaIngresoExcelParsed?->toDateString();
        $base['corrige_fecha_ingreso'] = $fechaIngresoExcelParsed !== null
            && ($colaborador->fecha_ingreso === null || ! $fechaIngresoExcelParsed->isSameDay($colaborador->fecha_ingreso));

        // Antigüedad desde la fecha de ingreso del reporte.
        $antiguedadAnios = (int) $fechaIngreso->diffInYears($fechaCorte);
        $cumpleUnAnio = $antiguedadAnios >= 1;
        $base['antiguedad_anios'] = $antiguedadAnios;
        $base['cumple_un_anio'] = $cumpleUnAnio;

        $diarioActual = $this->compensacion->diarioDe($colaborador);
        $diarioExcel = round((float) $salarioDiarioExcel, 2);
        $base['salario_diario_actual'] = $diarioActual;
        $base['salario_diario_excel'] = $diarioExcel;
        $base['cambia_salario'] = abs($diarioActual - $diarioExcel) > 0.01;

        $diasTomadosExcel = is_numeric($fila['dias_tomados'] ?? null) ? (float) $fila['dias_tomados'] : null;
        $base['dias_tomados_excel'] = $diasTomadosExcel;

        if (! $cumpleUnAnio) {
            // Regla crítica (CLAUDE.md §24-29): sin 1 año cumplido, NUNCA se
            // acredita/descuenta el bloque anual, sin importar lo que diga
            // DIAS OTORGADOS/TOMADOS — ese bloque todavía no existe.
            $base['motivo_vacaciones_no_aplican'] = 'No ha cumplido 1 año de antigüedad: las vacaciones anuales no aplican aún.';
        } elseif ($diasTomadosExcel !== null && $diasTomadosExcel > 0) {
            $base['ajuste_vacaciones_propuesto'] = -$diasTomadosExcel;
            $base['motivo_ajuste_vacaciones'] = "Días tomados antes de PEOPLE según reporte (fila {$numeroFila}).";
        }

        $hayCambios = $base['cambia_salario'] || $base['ajuste_vacaciones_propuesto'] !== 0.0;

        return [...$base, 'estado' => $hayCambios ? 'aplicable' : 'sin_cambios'];
    }

    /**
     * @return array<int, array<string, list<Colaborador>>>
     */
    private function agruparColaboradores(): array
    {
        $colaboradores = Colaborador::query()
            ->where('estatus', 'activo')
            ->whereNotNull('sucursal_principal_id')
            ->get(['id', 'name', 'apellidos', 'sucursal_principal_id', 'fecha_ingreso', 'salario_diario', 'sueldo_mensual']);

        $porSucursalYNombre = [];

        foreach ($colaboradores as $colaborador) {
            $nombreNormalizado = $this->normalizar(trim($colaborador->name.' '.($colaborador->apellidos ?? '')));
            $porSucursalYNombre[(int) $colaborador->sucursal_principal_id][$nombreNormalizado][] = $colaborador;
        }

        return $porSucursalYNombre;
    }

    /**
     * Fecha del Excel: puede venir como número de serie (celda con formato
     * de fecha) o como texto "m/d/Y" — nunca se asume un solo formato.
     */
    private function parsearFecha(mixed $valor): ?Carbon
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        if (is_numeric($valor)) {
            try {
                return Carbon::instance(FechaExcel::excelToDateTimeObject((float) $valor))->startOfDay();
            } catch (Throwable) {
                return null;
            }
        }

        try {
            return Carbon::parse((string) $valor)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Nombre normalizado para comparar (mayúsculas, sin acentos, sin
     * espacios repetidos) — comparación EXACTA, nunca "parecido" (fuzzy
     * match peligroso, CLAUDE.md §60): un nombre que no cuadra exacto
     * siempre cae a revisión manual, nunca se autoaplica por similitud.
     */
    private function normalizar(string $valor): string
    {
        return Str::of($valor)->ascii()->upper()->squish()->toString();
    }

    /**
     * Coincidencia EXACTA (normalizada) contra el catálogo de sucursales; el
     * reporte puede traer el nombre truncado (p. ej. "ATLACOMULC" por
     * "Atlacomulco"), pero solo se toleran los truncamientos de la lista
     * cerrada config('importador_trabajadores.alias_sucursales') — nunca un
     * prefijo/LIKE abierto que pudiera mandar una fila a otra sucursal.
     */
    private function mismaSucursal(string $nombreCatalogo, string $nombreExcel): bool
    {
        $catalogo = $this->normalizar($nombreCatalogo);
        $excel = $this->normalizar($nombreExcel);

        if ($catalogo === $excel) {
            return true;
        }

        /** @var array<string, string> $alias */
        $alias = config('importador_trabajadores.alias_sucursales', []);

        foreach ($alias as $truncado => $nombreReal) {
            if ($this->normalizar($truncado) === $excel && $this->normalizar($nombreReal) === $catalogo) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function leer(UploadedFile $archivo): array
    {
        try {
            $libro = IOFactory::load((string) $archivo->getRealPath());
            $hoja = $libro->getActiveSheet()->toArray(null, true, true, false);
        } catch (Throwable) {
            throw ValidationException::withMessages(['archivo' => 'No se pudo leer el archivo. Debe ser el XLSX real de REPORTE_TRABAJADORES_VAC.']);
        }

        // La hoja real trae 3 filas de título/leyenda antes de los
        // encabezados (confirmado al leer el archivo oficial) — nunca se
        // asume la fila 1 como en otros importadores de este sistema.
        for ($i = 1; $i < self::FILA_ENCABEZADOS; $i++) {
            array_shift($hoja);
        }

        $encabezados = array_map(
            fn ($h) => Str::of((string) $h)->lower()->ascii()->trim()->replace([' ', '-'], '_')->toString(),
            array_shift($hoja) ?? [],
        );

        $faltantes = array_diff(self::COLUMNAS_REQUERIDAS, $encabezados);

        if ($faltantes !== []) {
            throw ValidationException::withMessages(['archivo' => 'El archivo no tiene la estructura esperada de REPORTE_TRABAJADORES_VAC. Faltan columnas: '.implode(', ', $faltantes).'.']);
        }

        $filas = [];

        foreach ($hoja as $indice => $valores) {
            if (count(array_filter($valores, fn ($v) => $v !== null && trim((string) $v) !== '')) === 0) {
                continue;
            }

            $fila = [];

            foreach ($encabezados as $posicion => $columna) {
                $valor = $valores[$posicion] ?? null;
                $fila[$columna] = is_string($valor) ? trim($valor) : $valor;
            }

            // +5: self::FILA_ENCABEZADOS (4) es la fila de encabezados reales
            // y el índice de $hoja ya trae restadas las 3 filas previas más
            // el encabezado; +1 adicional porque el índice empieza en 0.
            $filas[(int) $indice + self::FILA_ENCABEZADOS + 1] = $fila;
        }

        return $filas;
    }
}
