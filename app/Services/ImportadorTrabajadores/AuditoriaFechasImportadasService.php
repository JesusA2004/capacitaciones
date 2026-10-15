<?php

namespace App\Services\ImportadorTrabajadores;

use App\Models\Colaborador;
use App\Models\ImportacionExcel;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Diagnóstico de las fechas de ingreso que el importador de
 * REPORTE_TRABAJADORES_VAC.xlsx llegó a sobrescribir mientras la regla vieja
 * («la fecha del reporte es la fuente», 2026-10-13) estuvo activa. Desde el
 * 2026-10-15 el importador ya no toca fecha_ingreso; este servicio solo
 * revisa lo que quedó aplicado antes.
 *
 * Nunca inventa una fecha: solo propone RESTAURAR cuando el detalle de la
 * importación guardó la fecha anterior y el expediente sigue teniendo
 * exactamente la que puso la importación. Todo lo demás es REVISIÓN MANUAL.
 */
class AuditoriaFechasImportadasService
{
    public const RESTAURAR = 'restaurar';

    public const REVISION_MANUAL = 'revision_manual';

    public const YA_CORREGIDA = 'ya_corregida';

    public function __construct(private readonly AuditoriaService $auditoria) {}

    /**
     * @return list<array{importacion_id: int, archivo: string, fila: int, colaborador_id: int, colaborador: string, fecha_anterior: string|null, fecha_importada: string, fecha_actual: string|null, dictamen: string, motivo: string}>
     */
    public function diagnosticar(): array
    {
        $resultado = [];

        $importaciones = ImportacionExcel::query()
            ->where('estado', 'aplicado')
            ->orderBy('id')
            ->get();

        foreach ($importaciones as $importacion) {
            /** @var list<array<string, mixed>> $detalle */
            $detalle = $importacion->detalle ?? [];

            foreach ($detalle as $fila) {
                if (! ($fila['aplicado'] ?? false) || ! ($fila['corrige_fecha_ingreso'] ?? false) || ! is_string($fila['fecha_ingreso_excel'] ?? null)) {
                    continue;
                }

                $colaborador = Colaborador::withTrashed()->where('id', (int) ($fila['colaborador_id'] ?? 0))->first();

                if ($colaborador === null) {
                    continue;
                }

                $anterior = is_string($fila['fecha_ingreso_anterior'] ?? null) ? $fila['fecha_ingreso_anterior'] : null;
                $importada = $fila['fecha_ingreso_excel'];
                $actual = $colaborador->fecha_ingreso?->toDateString();

                [$dictamen, $motivo] = match (true) {
                    $actual !== $importada && $actual === $anterior && $anterior !== null => [self::YA_CORREGIDA, 'El expediente ya tiene de nuevo la fecha anterior.'],
                    $actual !== $importada => [self::REVISION_MANUAL, 'La fecha cambió después de la importación: alguien la editó; no se toca.'],
                    $anterior === null => [self::REVISION_MANUAL, 'PEOPLE no tenía fecha antes de la importación: RH debe validarla con el expediente físico.'],
                    default => [self::RESTAURAR, 'La importación guardó la fecha anterior y el expediente sigue con la del archivo.'],
                };

                $resultado[] = [
                    'importacion_id' => $importacion->id,
                    'archivo' => (string) $importacion->nombre_archivo,
                    'fila' => (int) ($fila['fila'] ?? 0),
                    'colaborador_id' => $colaborador->id,
                    'colaborador' => trim($colaborador->name.' '.($colaborador->apellidos ?? '')),
                    'fecha_anterior' => $anterior,
                    'fecha_importada' => $importada,
                    'fecha_actual' => $actual,
                    'dictamen' => $dictamen,
                    'motivo' => $motivo,
                ];
            }
        }

        return $resultado;
    }

    /**
     * Restaura SOLO los casos sin ambigüedad (dictamen RESTAURAR) y vuelve a
     * comprobar con bloqueo que el expediente siga con la fecha importada.
     *
     * @param  list<array{importacion_id: int, archivo: string, fila: int, colaborador_id: int, colaborador: string, fecha_anterior: string|null, fecha_importada: string, fecha_actual: string|null, dictamen: string, motivo: string}>  $casos
     */
    public function restaurar(array $casos, ?User $actor): int
    {
        $restaurados = 0;

        foreach ($casos as $caso) {
            if ($caso['dictamen'] !== self::RESTAURAR || $caso['fecha_anterior'] === null) {
                continue;
            }

            $hecho = DB::transaction(function () use ($caso): bool {
                $colaborador = Colaborador::withTrashed()->where('id', $caso['colaborador_id'])->lockForUpdate()->first();

                if ($colaborador === null || $colaborador->fecha_ingreso?->toDateString() !== $caso['fecha_importada']) {
                    return false;
                }

                $colaborador->forceFill(['fecha_ingreso' => $caso['fecha_anterior']])->save();

                return true;
            });

            if (! $hecho) {
                continue;
            }

            $restaurados++;

            try {
                $colaborador = Colaborador::withTrashed()->where('id', $caso['colaborador_id'])->first();

                if ($colaborador !== null) {
                    $this->auditoria->registrar('fecha_ingreso_restaurada_importacion', $colaborador, $actor, [
                        'importacion_id' => $caso['importacion_id'],
                        'fila' => $caso['fila'],
                        'fecha_importada' => $caso['fecha_importada'],
                        'fecha_restaurada' => $caso['fecha_anterior'],
                    ]);
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $restaurados;
    }
}
