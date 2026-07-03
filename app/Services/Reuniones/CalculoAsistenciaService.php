<?php

namespace App\Services\Reuniones;

use App\Enums\CriterioCumplimientoAsistencia;
use App\Enums\EstadoAsistencia;
use App\Enums\EstadoIdentificacionParticipante;
use App\Enums\EstadoSincronizacion;
use App\Models\Asistencia;
use App\Models\RegistroSesion;
use App\Models\SesionEnVivo;
use App\Models\SesionParticipante;
use App\Services\Capacitacion\ProgresoService;

/**
 * Aplica las reglas de asistencia configurables de una SesionEnVivo
 * (porcentaje mínimo, minutos mínimos, tolerancia, criterio de
 * cumplimiento, si se considera tiempo previo/posterior al horario
 * programado) a los `SesionParticipante` recuperados del proveedor, y
 * traslada el resultado a la `Asistencia` de cada colaborador identificado.
 *
 * Nunca sobrescribe una asistencia que ya recibió una corrección manual
 * (`correccion_origen = 'manual'`): la sincronización automática no debe
 * pisar el criterio de un humano. Ver docs/AUDITORIA_CUMPLIMIENTO.md
 * sección 2 y 6.
 */
class CalculoAsistenciaService
{
    public function __construct(private readonly ProgresoService $progresoService) {}

    public function calcularParaSesion(SesionEnVivo $sesion, RegistroSesion $registro): void
    {
        $registro->loadMissing('participantes.entradasSalidas');

        foreach ($registro->participantes as $participante) {
            $this->procesarParticipante($sesion, $participante);
        }

        if ($registro->estado_sincronizacion === EstadoSincronizacion::Sincronizado) {
            $this->marcarAusentesSinParticipacion($sesion);
        }
    }

    private function procesarParticipante(SesionEnVivo $sesion, SesionParticipante $participante): void
    {
        $minutos = $this->minutosAjustados($sesion, $participante);
        $porcentaje = $sesion->duracion_minutos > 0
            ? min(100, (int) round(($minutos / $sesion->duracion_minutos) * 100))
            : 0;

        $estado = $this->determinarEstado($sesion, $participante, $minutos, $porcentaje);
        $motivo = $this->explicar($sesion, $participante, $minutos, $porcentaje, $estado);

        $participante->update([
            'porcentaje_sesion' => $porcentaje,
            'resultado_calculado' => $estado->value,
        ]);

        if ($participante->user_id !== null) {
            $this->aplicarAAsistencia($sesion, $participante, $estado, $minutos, $porcentaje, $motivo);
        }
    }

    /**
     * Minutos reales dentro de la ventana que las reglas de la sesión
     * consideran válida: si `considerar_tiempo_previo`/`considerar_tiempo_posterior`
     * son falsos (por defecto), el tiempo conectado antes del inicio o
     * después del cierre programado no cuenta, aunque el proveedor lo haya
     * reportado como parte de la sesión.
     */
    private function minutosAjustados(SesionEnVivo $sesion, SesionParticipante $participante): int
    {
        $inicioVentana = $sesion->considerar_tiempo_previo ? null : $sesion->fecha_inicio;
        $finVentana = $sesion->considerar_tiempo_posterior ? null : $sesion->fecha_inicio->clone()->addMinutes($sesion->duracion_minutos);

        $segundos = 0;

        foreach ($participante->entradasSalidas as $tramo) {
            if ($tramo->fin === null) {
                continue;
            }

            $inicio = $inicioVentana && $tramo->inicio->lt($inicioVentana) ? $inicioVentana : $tramo->inicio;
            $fin = $finVentana && $tramo->fin->gt($finVentana) ? $finVentana : $tramo->fin;

            if ($fin->gt($inicio)) {
                $segundos += $inicio->diffInSeconds($fin);
            }
        }

        return (int) round($segundos / 60);
    }

    private function determinarEstado(SesionEnVivo $sesion, SesionParticipante $participante, int $minutos, int $porcentaje): EstadoAsistencia
    {
        if ($participante->estado_identificacion === EstadoIdentificacionParticipante::PendienteRevision) {
            return EstadoAsistencia::PendienteRevision;
        }

        $minutosPorPorcentaje = (int) ceil($sesion->duracion_minutos * $sesion->porcentaje_minimo_asistencia / 100);
        $minutosPorPorcentajeConTolerancia = max(0, $minutosPorPorcentaje - $sesion->tolerancia_minutos);
        $minutosAbsolutosConTolerancia = $sesion->minutos_minimos_asistencia !== null
            ? max(0, $sesion->minutos_minimos_asistencia - $sesion->tolerancia_minutos)
            : null;

        $cumplePorcentaje = $minutos >= $minutosPorPorcentajeConTolerancia;
        $cumpleMinutos = $minutosAbsolutosConTolerancia !== null && $minutos >= $minutosAbsolutosConTolerancia;

        $cumple = match ($sesion->criterio_cumplimiento) {
            CriterioCumplimientoAsistencia::Porcentaje => $cumplePorcentaje,
            CriterioCumplimientoAsistencia::Minutos => $minutosAbsolutosConTolerancia !== null ? $cumpleMinutos : $cumplePorcentaje,
            CriterioCumplimientoAsistencia::Cualquiera => $cumplePorcentaje || $cumpleMinutos,
        };

        if ($cumple) {
            return EstadoAsistencia::Presente;
        }

        return $minutos > 0 ? EstadoAsistencia::AsistenciaParcial : EstadoAsistencia::Ausente;
    }

    private function explicar(SesionEnVivo $sesion, SesionParticipante $participante, int $minutos, int $porcentaje, EstadoAsistencia $estado): string
    {
        if ($estado === EstadoAsistencia::PendienteRevision) {
            return 'El participante no pudo identificarse con un correo confiable; requiere revisión manual.';
        }

        $base = "Conectado {$minutos} minuto(s) ({$porcentaje}% de la sesión), {$participante->numero_reconexiones} reconexión(es), según el registro de {$sesion->proveedor->etiqueta()}.";

        return match ($estado) {
            EstadoAsistencia::Presente => $base.' Cumple el criterio de asistencia configurado.',
            EstadoAsistencia::AsistenciaParcial => $base.' No alcanza el mínimo configurado, pero registró participación.',
            EstadoAsistencia::Ausente => $base.' No se detectó participación suficiente.',
            default => $base,
        };
    }

    private function aplicarAAsistencia(SesionEnVivo $sesion, SesionParticipante $participante, EstadoAsistencia $estado, int $minutos, int $porcentaje, string $motivo): void
    {
        $asistencia = Asistencia::query()
            ->where('sesion_en_vivo_id', $sesion->id)
            ->where('user_id', $participante->user_id)
            ->first();

        if ($asistencia === null || $asistencia->correccion_origen === 'manual') {
            // Sin asistencia materializada (no inscrito) o ya corregida a
            // mano: la sincronización automática nunca pisa una decisión
            // humana previa.
            return;
        }

        $entradasSalidas = $participante->entradasSalidas;

        $asistencia->update([
            'sesion_participante_id' => $participante->id,
            'estado' => $estado->value,
            'unido_en' => $entradasSalidas->min('inicio'),
            'salido_en' => $entradasSalidas->max('fin'),
            'duracion_segundos' => $minutos * 60,
            'minutos_totales' => $minutos,
            'porcentaje_sesion' => $porcentaje,
            'numero_reconexiones' => $participante->numero_reconexiones,
            'motivo_estado' => $motivo,
            'sincronizado_en' => now(),
            'correccion_origen' => 'automatica',
        ]);

        if ($estado->completaLeccion()) {
            $asistencia->loadMissing(['sesion.leccion', 'usuario']);

            try {
                $this->progresoService->completarLeccion($asistencia->usuario, $asistencia->sesion->leccion);
            } catch (\RuntimeException) {
                // La leccion se bloqueo por requisitos entre la sesion y su calculo (caso raro).
            }
        }
    }

    /**
     * Los colaboradores materializados para la sesión (inscritos al curso)
     * que nunca aparecieron en el registro de participantes del proveedor
     * quedan como "ausente" — solo cuando la sincronización ya es completa,
     * para no marcar ausente por error mientras todavía falta información.
     */
    private function marcarAusentesSinParticipacion(SesionEnVivo $sesion): void
    {
        Asistencia::query()
            ->where('sesion_en_vivo_id', $sesion->id)
            ->where('estado', EstadoAsistencia::Pendiente->value)
            ->whereNull('correccion_origen')
            ->update([
                'estado' => EstadoAsistencia::Ausente->value,
                'motivo_estado' => 'No se detectó su participación en el registro de la reunión.',
                'sincronizado_en' => now(),
                'correccion_origen' => 'automatica',
            ]);
    }
}
