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
 * aplica dos veces.
 *
 * La sucursal del reporte es solo CONTEXTO para desambiguar homónimos,
 * nunca identidad obligatoria (CLAUDE.md §66-73): personal corporativo,
 * remoto o de una sucursal ya cerrada se encuentra igual por nombre. El
 * match es siempre por nombre EXACTO normalizado (nunca fuzzy/automático,
 * §60); cuando no hay match exacto, RH puede asignar manualmente
 * (asignarManual()) o, si la persona de verdad no existe en PEOPLE, crear
 * su colaborador desde la fila (registrarColaboradorDesdeFila()).
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
        private readonly ImportacionColaboradorService $altaDesdeImportacion,
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
        $porNombre = $this->agruparColaboradores();

        $detalle = [];
        $contadores = ['aplicable' => 0, 'revision' => 0, 'sin_cambios' => 0, 'no_encontrado' => 0, 'error' => 0];

        foreach ($filas as $numeroFila => $fila) {
            $resultado = $this->resolverFila($numeroFila, $fila, $sucursales, $porNombre, $fechaCorte);
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

                    try {
                        $this->auditoria->registrar('importacion_trabajadores_fila_aplicada', $colaborador, $actor, [
                            'importacion_id' => $importacion->id,
                            'fila' => $fila['fila'],
                            'match_tipo' => $fila['match_tipo'] ?? 'exacto',
                            'fecha_ingreso_anterior' => $fila['fecha_ingreso_anterior'] ?? null,
                            'fecha_ingreso_nueva' => ($fila['corrige_fecha_ingreso'] ?? false) ? ($fila['fecha_ingreso_excel'] ?? null) : null,
                            'salario_diario_anterior' => $fila['salario_diario_actual'] ?? null,
                            'salario_diario_nuevo' => $fila['cambia_salario'] ? ($fila['salario_diario_excel'] ?? null) : null,
                            'ajuste_vacaciones' => $fila['ajuste_vacaciones_propuesto'] ?? 0.0,
                        ]);
                    } catch (Throwable $e) {
                        // La auditoría nunca deshace la aplicación de la fila.
                        report($e);
                    }
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
     * Asignación manual: RH elige el colaborador correcto para una fila que
     * no tuvo match automático (nombre no exacto o ambiguo). Recalcula esa
     * fila con los valores del Excel YA EXTRAÍDOS la primera vez (nunca se
     * vuelve a leer el archivo).
     */
    public function asignarManual(ImportacionExcel $importacion, int $indiceFila, int $colaboradorId, User $actor): void
    {
        if ($importacion->estado === 'aplicado') {
            throw ValidationException::withMessages(['importacion' => 'Esta importación ya fue aplicada.']);
        }

        $colaborador = Colaborador::query()->where('estatus', 'activo')->findOrFail($colaboradorId);

        DB::transaction(function () use ($importacion, $indiceFila, $colaborador, $actor): void {
            $importacion = ImportacionExcel::query()->lockForUpdate()->findOrFail($importacion->id);

            if ($importacion->estado === 'aplicado') {
                throw ValidationException::withMessages(['importacion' => 'Esta importación ya fue aplicada.']);
            }

            /** @var list<array<string, mixed>> $detalle */
            $detalle = $importacion->detalle ?? [];

            if (! array_key_exists($indiceFila, $detalle)) {
                throw ValidationException::withMessages(['fila' => 'Esa fila no existe en esta importación.']);
            }

            $fechaCorte = Carbon::parse((string) $detalle[$indiceFila]['fecha_corte']);
            $detalle[$indiceFila] = $this->calcularCambios($detalle[$indiceFila], $colaborador, $fechaCorte, 'manual', $actor->id);

            $importacion->update(['detalle' => $detalle]);
        });
    }

    /**
     * Crea el colaborador que el Excel menciona pero PEOPLE no tiene
     * (CLAUDE.md §74-81) y, en la misma operación, recalcula esa fila sobre
     * la persona recién creada. `$datosExtra` cubre la estructura que el
     * Excel no trae (puesto_id obligatorio; departamento_id/numero_empleado
     * opcionales) — nunca se inventa, RH la captura en un formulario corto.
     *
     * @param  array<string, mixed>  $datosExtra
     */
    public function registrarColaboradorDesdeFila(ImportacionExcel $importacion, int $indiceFila, array $datosExtra, User $actor): Colaborador
    {
        if ($importacion->estado === 'aplicado') {
            throw ValidationException::withMessages(['importacion' => 'Esta importación ya fue aplicada.']);
        }

        /** @var list<array<string, mixed>> $detalleActual */
        $detalleActual = $importacion->detalle ?? [];

        if (! array_key_exists($indiceFila, $detalleActual)) {
            throw ValidationException::withMessages(['fila' => 'Esa fila no existe en esta importación.']);
        }

        $filaDetalle = $detalleActual[$indiceFila];
        [$nombre, $apellidos] = $this->partirNombre((string) $filaDetalle['nombre_excel']);

        $colaborador = $this->altaDesdeImportacion->crear([
            'name' => $nombre,
            'apellidos' => $apellidos,
            'fecha_ingreso' => $filaDetalle['fecha_ingreso_excel'] ?? Carbon::parse((string) $filaDetalle['fecha_corte'])->toDateString(),
            'salario_diario' => $filaDetalle['salario_diario_excel'],
            'sucursal_principal_id' => $datosExtra['sucursal_principal_id'] ?? null,
            'departamento_id' => $datosExtra['departamento_id'] ?? null,
            'puesto_id' => $datosExtra['puesto_id'],
            'numero_empleado' => $datosExtra['numero_empleado'] ?? null,
        ], $actor);

        DB::transaction(function () use ($importacion, $indiceFila, $colaborador, $actor): void {
            $importacion = ImportacionExcel::query()->lockForUpdate()->findOrFail($importacion->id);

            if ($importacion->estado === 'aplicado') {
                throw ValidationException::withMessages(['importacion' => 'Esta importación ya fue aplicada.']);
            }

            /** @var list<array<string, mixed>> $detalle */
            $detalle = $importacion->detalle ?? [];
            $fechaCorte = Carbon::parse((string) $detalle[$indiceFila]['fecha_corte']);
            // El salario y la fecha de ingreso ya quedaron correctos al crear
            // al colaborador (ImportacionColaboradorService): no "cambian",
            // así que esta fila recalculada normalmente sale 'sin_cambios'
            // salvo que también traiga días tomados antes de PEOPLE.
            $detalle[$indiceFila] = $this->calcularCambios($detalle[$indiceFila], $colaborador, $fechaCorte, 'creado', $actor->id);

            $importacion->update(['detalle' => $detalle]);
        });

        return $colaborador;
    }

    /**
     * @param  array<string, mixed>  $fila
     * @param  Collection<int, Sucursal>  $sucursales
     * @param  array<string, list<Colaborador>>  $porNombre
     * @return array<string, mixed>
     */
    private function resolverFila(int $numeroFila, array $fila, Collection $sucursales, array $porNombre, Carbon $fechaCorte): array
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
            'fecha_ingreso_excel' => null,
            'fecha_ingreso_anterior' => null,
            'corrige_fecha_ingreso' => false,
            'antiguedad_anios' => null,
            'cumple_un_anio' => null,
            'dias_tomados_excel' => null,
            'ajuste_vacaciones_propuesto' => 0.0,
            'motivo_ajuste_vacaciones' => null,
            'motivo_vacaciones_no_aplican' => null,
            'motivo' => null,
            'aplicado' => false,
            'match_tipo' => null,
            'match_actor_id' => null,
            'match_en' => null,
            'sugerencias' => [],
        ];

        $nombreExcel = trim((string) ($fila['nombre_completo'] ?? ''));
        $sucursalExcel = trim((string) ($fila['sucursal'] ?? ''));
        $salarioDiarioExcel = $fila['salario_diario'] ?? null;

        if ($nombreExcel === '' || ! is_numeric($salarioDiarioExcel) || (float) $salarioDiarioExcel <= 0) {
            return [...$base, 'estado' => 'error', 'motivo' => 'Faltan datos obligatorios (nombre o salario diario inválido).'];
        }

        $base['salario_diario_excel'] = round((float) $salarioDiarioExcel, 2);
        $base['fecha_ingreso_excel'] = $this->parsearFecha($fila['fecha_ingreso'] ?? null)?->toDateString();
        $base['dias_tomados_excel'] = is_numeric($fila['dias_tomados'] ?? null) ? (float) $fila['dias_tomados'] : null;

        // La sucursal del reporte, si es válida en el catálogo, SOLO sirve
        // para desambiguar homónimos — nunca como filtro obligatorio: puede
        // venir vacía, "REMOTO", "CORPORATIVO" o una sucursal ya cerrada, y
        // la fila sigue siendo válida (CLAUDE.md §66-69).
        $sucursal = $sucursalExcel !== '' ? $sucursales->first(fn (Sucursal $s) => $this->mismaSucursal($s->nombre, $sucursalExcel)) : null;
        $base['sucursal_sistema'] = $sucursal?->nombre;

        $nombreNormalizado = $this->normalizar($nombreExcel);
        $candidatos = $porNombre[$nombreNormalizado] ?? [];

        if (count($candidatos) > 1 && $sucursal !== null) {
            $delaSucursal = array_values(array_filter($candidatos, fn (Colaborador $c) => (int) $c->sucursal_principal_id === $sucursal->id));

            if (count($delaSucursal) === 1) {
                $candidatos = $delaSucursal;
            }
        }

        if (count($candidatos) === 0) {
            return [...$base, 'estado' => 'no_encontrado', 'motivo' => "Ningún colaborador activo coincide con «{$nombreExcel}».", 'sugerencias' => $this->sugerencias($nombreNormalizado, $porNombre)];
        }

        if (count($candidatos) > 1) {
            return [...$base, 'estado' => 'revision', 'motivo' => "Nombre ambiguo: «{$nombreExcel}» coincide con ".count($candidatos).' colaboradores activos.', 'sugerencias' => array_map($this->colaboradorResumen(...), $candidatos)];
        }

        return $this->calcularCambios($base, $candidatos[0], $fechaCorte, 'exacto', null);
    }

    /**
     * Fecha/salario/vacaciones para UN colaborador ya identificado (match
     * exacto, manual o recién creado) sobre los valores del Excel que
     * resolverFila() ya extrajo en $base — nunca vuelve a leer el archivo.
     *
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function calcularCambios(array $base, Colaborador $colaborador, Carbon $fechaCorte, string $matchTipo, ?int $matchActorId): array
    {
        $base['colaborador_id'] = $colaborador->id;
        $base['nombre_sistema'] = trim($colaborador->name.' '.($colaborador->apellidos ?? ''));
        $base['match_tipo'] = $matchTipo;
        $base['match_actor_id'] = $matchActorId;
        $base['match_en'] = $matchActorId !== null ? now()->toIso8601String() : null;
        $base['sugerencias'] = [];

        $fechaIngresoExcelParsed = is_string($base['fecha_ingreso_excel'] ?? null) ? Carbon::parse($base['fecha_ingreso_excel'])->startOfDay() : null;
        $fechaIngreso = $fechaIngresoExcelParsed ?? $colaborador->fecha_ingreso;

        if ($fechaIngreso === null) {
            return [...$base, 'estado' => 'revision', 'motivo' => 'Ni el reporte ni PEOPLE tienen fecha de ingreso: no se puede calcular antigüedad.'];
        }

        // La fecha de ingreso del REPORTE es la fuente (decisión de RH,
        // 2026-10-13): si PEOPLE tiene otra (o ninguna), al aplicar se
        // corrige con la del Excel y queda registrado en el detalle
        // (fecha_ingreso_anterior → fecha_ingreso_excel). Sin fecha válida en
        // el reporte se conserva la de PEOPLE.
        $base['fecha_ingreso_anterior'] = $colaborador->fecha_ingreso?->toDateString();
        $base['corrige_fecha_ingreso'] = $fechaIngresoExcelParsed !== null
            && ($colaborador->fecha_ingreso === null || ! $fechaIngresoExcelParsed->isSameDay($colaborador->fecha_ingreso));

        $antiguedadAnios = (int) $fechaIngreso->diffInYears($fechaCorte);
        $cumpleUnAnio = $antiguedadAnios >= 1;
        $base['antiguedad_anios'] = $antiguedadAnios;
        $base['cumple_un_anio'] = $cumpleUnAnio;

        $diarioActual = $this->compensacion->diarioDe($colaborador);
        $diarioExcel = (float) $base['salario_diario_excel'];
        $base['salario_diario_actual'] = $diarioActual;
        $base['cambia_salario'] = abs($diarioActual - $diarioExcel) > 0.01;

        $diasTomadosExcel = $base['dias_tomados_excel'];
        $base['ajuste_vacaciones_propuesto'] = 0.0;
        $base['motivo_vacaciones_no_aplican'] = null;
        $base['motivo_ajuste_vacaciones'] = null;

        if (! $cumpleUnAnio) {
            // Regla crítica (CLAUDE.md §24-29): sin 1 año cumplido, NUNCA se
            // acredita/descuenta el bloque anual, sin importar lo que diga
            // DIAS OTORGADOS/TOMADOS — ese bloque todavía no existe.
            $base['motivo_vacaciones_no_aplican'] = 'No ha cumplido 1 año de antigüedad: las vacaciones anuales no aplican aún.';
        } elseif ($diasTomadosExcel !== null && $diasTomadosExcel > 0) {
            $base['ajuste_vacaciones_propuesto'] = -$diasTomadosExcel;
            $base['motivo_ajuste_vacaciones'] = "Días tomados antes de PEOPLE según reporte (fila {$base['fila']}).";
        }

        $hayCambios = $base['corrige_fecha_ingreso'] || $base['cambia_salario'] || $base['ajuste_vacaciones_propuesto'] !== 0.0;

        return [...$base, 'estado' => $hayCambios ? 'aplicable' : 'sin_cambios', 'motivo' => null];
    }

    /**
     * Sugerencias NO autoaplicables (CLAUDE.md §63-65): nombres con al menos
     * el 60% de sus palabras en común con el del Excel (acentos/mayúsculas
     * ya normalizados) — para que RH elija con asignarManual(), nunca para
     * asignar solo.
     *
     * @param  array<string, list<Colaborador>>  $porNombre
     * @return list<array<string, mixed>>
     */
    private function sugerencias(string $nombreNormalizado, array $porNombre): array
    {
        $tokensExcel = array_values(array_filter(explode(' ', $nombreNormalizado)));

        if ($tokensExcel === []) {
            return [];
        }

        $sugeridos = [];

        foreach ($porNombre as $nombreSistema => $colaboradores) {
            if ($nombreSistema === $nombreNormalizado) {
                continue;
            }

            $tokensSistema = array_values(array_filter(explode(' ', $nombreSistema)));
            $comunes = count(array_intersect($tokensExcel, $tokensSistema));
            $minTokens = min(count($tokensExcel), count($tokensSistema));

            if ($minTokens > 0 && $comunes / $minTokens >= 0.6) {
                foreach ($colaboradores as $colaborador) {
                    $sugeridos[] = $this->colaboradorResumen($colaborador);
                }
            }
        }

        return array_slice($sugeridos, 0, 5);
    }

    /**
     * @return array{id: int, nombre: string, sucursal_id: int|null}
     */
    private function colaboradorResumen(Colaborador $colaborador): array
    {
        return [
            'id' => $colaborador->id,
            'nombre' => trim($colaborador->name.' '.($colaborador->apellidos ?? '')),
            'sucursal_id' => $colaborador->sucursal_principal_id !== null ? (int) $colaborador->sucursal_principal_id : null,
        ];
    }

    /**
     * Separa "NOMBRE(S) APELLIDOS" del Excel en name/apellidos: la primera
     * palabra es el nombre, el resto los apellidos — mismo criterio simple
     * usado al capturar manualmente cuando no se tiene el desglose real.
     *
     * @return array{0: string, 1: string|null}
     */
    private function partirNombre(string $nombreCompleto): array
    {
        $partes = array_values(array_filter(explode(' ', trim($nombreCompleto))));

        if (count($partes) <= 1) {
            return [$nombreCompleto, null];
        }

        return [array_shift($partes), implode(' ', $partes)];
    }

    /**
     * @return array<string, list<Colaborador>>
     */
    private function agruparColaboradores(): array
    {
        $colaboradores = Colaborador::query()
            ->where('estatus', 'activo')
            ->get(['id', 'name', 'apellidos', 'sucursal_principal_id', 'fecha_ingreso', 'salario_diario', 'sueldo_mensual']);

        $porNombre = [];

        foreach ($colaboradores as $colaborador) {
            $nombreNormalizado = $this->normalizar(trim($colaborador->name.' '.($colaborador->apellidos ?? '')));
            $porNombre[$nombreNormalizado][] = $colaborador;
        }

        return $porNombre;
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
