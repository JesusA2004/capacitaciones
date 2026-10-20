<?php

namespace App\Services\Bajas;

use App\Enums\AccionBitacoraBaja;
use App\Enums\CategoriaNotificacion;
use App\Models\Baja;
use App\Models\Colaborador;
use App\Models\User;
use App\Notifications\Bajas\AlertaBajaCorreoNotification;
use App\Services\Configuracion\WorkflowRoutingService;
use App\Services\Notificaciones\Aviso;
use App\Services\Notificaciones\NotificacionEventosService;
use App\Services\Tareas\NotificadorRhService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Avisos del módulo de bajas por la capa única de notificaciones
 * (NotificacionEventosService / NotificadorRhService, reglas de ruteo en
 * config/configuracion_sistema.php). Un fallo al notificar NUNCA deshace la
 * acción: se captura y se registra con Log::warning.
 *
 * Alertas de la especificación (RN-08, RN-12), una sola vez por baja
 * (`bajas.alertas` guarda cuáles ya salieron):
 *  - RV-04 → correo a RH (entrevista de salida por RH + NOM-035).
 *  - RE-02 / RE-03 → aviso a Contraloría; RE-03 también a Jurídico.
 *  - antigüedad < 90 días → rotación temprana (RH).
 *  - aviso ante el Tribunal fuera de los 5 días hábiles → Jurídico.
 */
class BajaAlertasService
{
    public function __construct(
        private readonly NotificadorRhService $notificador,
        private readonly NotificacionEventosService $eventos,
        private readonly WorkflowRoutingService $routing,
        private readonly BajaReglasService $reglas,
        private readonly BajaRegistroService $registro,
    ) {}

    /**
     * La baja llegó a RH (envío del gerente o atajo): tarea para RH,
     * confirmación al actor y las alertas que apliquen.
     */
    public function alEnviar(Baja $baja, User $actor): void
    {
        $this->seguro(function () use ($baja, $actor): void {
            $colaborador = $this->colaborador($baja);
            $texto = $this->resumen($baja);

            $this->notificador->notificarEvento('baja_por_revisar', $colaborador, ['solicitante' => $actor, 'excluir' => [$actor->id]], sprintf('Baja por revisar: %s', $baja->folio), sprintf('%s envió la baja de %s. Revisa causal, documentos y fechas.', $actor->name, $texto), $baja, 'revisar_baja', 'alta');

            $this->eventos->confirmarAlActor($actor, new Aviso('baja_enviada', 'baja', 'Baja enviada a RH', sprintf('La baja %s de %s quedó «En revisión RH». Puedes seguir adjuntando documentos.', $baja->folio, $baja->nombre_colaborador), $baja, 'registrada'));
        });

        $this->alertas($baja, $actor);
    }

    /**
     * Alertas por causal, antigüedad y plazo del aviso (idempotente).
     */
    public function alertas(Baja $baja, User $actor): void
    {
        $baja->loadMissing('causal');
        $clave = $baja->causal?->clave;
        $destinos = $clave !== null ? (array) config('bajas.alertas.'.$clave, []) : [];
        $texto = $this->resumen($baja);

        foreach ($destinos as $destino) {
            match ($destino) {
                'rh_correo' => $this->unaVez($baja, $actor, 'clima_laboral', fn () => $this->correoRh($baja, $actor, $texto)),
                'contraloria' => $this->unaVez($baja, $actor, 'contraloria', fn () => $this->notificador->notificarEvento('baja_alerta_contraloria', $this->colaborador($baja), ['excluir' => [$actor->id]], sprintf('Aviso a Contraloría: %s', $baja->folio), sprintf('Baja por %s: %s.', $baja->causal?->nombre, $texto), $baja, 'ver_baja', 'alta')),
                'juridico' => $this->unaVez($baja, $actor, 'juridico_causal', fn () => $this->notificador->notificarEvento('baja_alerta_juridico', $this->colaborador($baja), ['excluir' => [$actor->id]], sprintf('Aviso a Jurídico: %s', $baja->folio), sprintf('Baja por %s: %s.', $baja->causal?->nombre, $texto), $baja, 'ver_baja', 'alta')),
                default => null,
            };
        }

        if ($this->reglas->rotacionTemprana($baja)) {
            $this->unaVez($baja, $actor, 'rotacion_temprana', fn () => $this->notificador->notificarEvento('baja_rotacion_temprana', $this->colaborador($baja), ['excluir' => [$actor->id]], sprintf('Rotación temprana: %s', $baja->folio), sprintf('%s tenía %d días de antigüedad (menos de %d): %s.', $baja->nombre_colaborador, (int) $baja->antiguedad_dias, (int) config('bajas.reglas.dias_rotacion_temprana', 90), $texto), $baja, 'ver_baja'));
        }

        if ($this->reglas->avisoTribunalFueraDePlazo($baja)) {
            $this->unaVez($baja, $actor, 'tribunal', fn () => $this->notificador->notificarEvento('baja_alerta_juridico', $this->colaborador($baja), ['excluir' => [$actor->id]], sprintf('Aviso de rescisión fuera de plazo: %s', $baja->folio), sprintf('El aviso de rescisión de %s se entregó ante el Tribunal el %s, más de %d días hábiles después de la baja (%s).', $baja->nombre_colaborador, $baja->fecha_entrega_aviso?->format('d/m/Y'), (int) config('bajas.reglas.dias_habiles_aviso_tribunal', 5), $baja->fecha_baja?->format('d/m/Y')), $baja, 'revisar_baja', 'alta'));
        }
    }

    public function alEnviarJuridico(Baja $baja, User $actor): void
    {
        $this->seguro(fn () => $this->notificador->notificarEvento('baja_revision_juridico', $this->colaborador($baja), ['solicitante' => $actor, 'excluir' => [$actor->id]], sprintf('Baja por revisar (Jurídico): %s', $baja->folio), sprintf('RH envió a Jurídico la baja de %s. Revisa el acta y el aviso.', $this->resumen($baja)), $baja, 'revisar_baja', 'alta'));
    }

    public function alAprobarJuridico(Baja $baja, User $actor): void
    {
        $this->seguro(fn () => $this->notificador->notificarEvento('baja_por_revisar', $this->colaborador($baja), ['excluir' => [$actor->id]], sprintf('Jurídico aprobó: %s', $baja->folio), sprintf('Jurídico revisó la baja de %s: ya puede cerrarse.', $this->resumen($baja)), $baja, 'cerrar_baja', 'alta'));
    }

    /**
     * Resultado al gerente que la solicitó (rechazo, cierre, no procede).
     */
    public function resultado(Baja $baja, User $actor, string $titulo, string $mensaje): void
    {
        $this->seguro(function () use ($baja, $actor, $titulo, $mensaje): void {
            $solicitante = $baja->solicitado_por !== null ? User::query()->where('id', $baja->solicitado_por)->first() : null;

            if ($solicitante !== null && $solicitante->id !== $actor->id) {
                $this->eventos->avisarResultado($solicitante, new Aviso('baja_resuelta', 'baja', $titulo, $mensaje, $baja, 'ver_baja'));
            }
        });
    }

    private function correoRh(Baja $baja, User $actor, string $texto): void
    {
        $colaborador = $this->colaborador($baja);
        $titulo = sprintf('Alerta de clima laboral: %s', $baja->folio);
        $mensaje = sprintf('Baja por «Relación con el jefe o clima laboral» (RV-04): %s. Entrevista de salida obligatoria por RH y revisión conforme a NOM-035.', $texto);
        $notificacion = new AlertaBajaCorreoNotification('baja', $titulo, $mensaje, $baja->id, route('rh.bajas.show', $baja));
        $aviso = new Aviso('baja_alerta_clima_laboral', 'baja', $titulo, $mensaje, $baja, 'revisar_baja', 'alta', notificacion: $notificacion);

        foreach ($this->routing->resolver('baja_alerta_clima_laboral', $colaborador, ['excluir' => [$actor->id]]) as $destino) {
            $this->eventos->entregar($destino['usuario'], CategoriaNotificacion::Pendiente, $aviso);
        }
    }

    /**
     * @param  callable(): mixed  $enviar
     */
    private function unaVez(Baja $baja, User $actor, string $clave, callable $enviar): void
    {
        $enviadas = $baja->alertas ?? [];

        if (isset($enviadas[$clave])) {
            return;
        }

        $this->seguro($enviar);

        $baja->forceFill(['alertas' => [...$enviadas, $clave => now()->toDateTimeString()]])->save();

        try {
            $this->registro->bitacora($baja, $actor, AccionBitacoraBaja::Alerta, 'alerta', null, $clave);
        } catch (Throwable $e) {
            Log::warning('BajaAlertasService: no se pudo registrar la alerta en la bitácora.', ['baja_id' => $baja->id, 'alerta' => $clave, 'error' => $e->getMessage()]);
        }
    }

    private function colaborador(Baja $baja): ?Colaborador
    {
        return $baja->colaborador_id !== null ? Colaborador::query()->withTrashed()->where('id', $baja->colaborador_id)->first() : null;
    }

    private function resumen(Baja $baja): string
    {
        $baja->loadMissing(['causal', 'sucursal']);

        return trim(sprintf('%s (%s%s · %s)', $baja->nombre_colaborador, $baja->sucursal?->nombre !== null ? $baja->sucursal->nombre.' · ' : '', $baja->forma_salida?->etiqueta() ?? 'sin forma', $baja->causal?->etiquetaConClave() ?? 'causal pendiente'));
    }

    private function seguro(callable $accion): void
    {
        try {
            $accion();
        } catch (Throwable $e) {
            Log::warning('BajaAlertasService: no se pudo notificar.', ['error' => $e->getMessage()]);
        }
    }
}
