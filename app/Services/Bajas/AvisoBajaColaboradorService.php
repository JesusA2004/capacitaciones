<?php

namespace App\Services\Bajas;

use App\Enums\CategoriaNotificacion;
use App\Enums\ModalidadBaja;
use App\Models\CierreLaboral;
use App\Models\Colaborador;
use App\Notifications\Bajas\AvisoBajaColaboradorNotification;
use App\Services\Notificaciones\Aviso;
use App\Services\Notificaciones\NotificacionEventosService;
use App\Support\FechaTexto;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Aviso a la PERSONA sobre su propia baja (interruptor «Avisar al
 * colaborador» al registrarla, `cierres_laborales.avisar_colaborador`).
 * Por defecto NO se le avisa (como hasta ahora): RH decide cuándo y cómo
 * comunicarlo, sobre todo en una baja programada en la que la persona sigue
 * laborando.
 *
 * Con el interruptor encendido le llega: al registrar (inmediata o
 * programada), al cambiar la fecha de la programada / ejecutarla antes y al
 * cancelarla. Campana web/app + correo (o solo correo si no tiene cuenta).
 * Sin causal, motivo, montos ni quién la registró. Nunca lanza: un fallo al
 * avisar jamás deshace la baja (Log::warning).
 */
class AvisoBajaColaboradorService
{
    public const EVENTO = 'baja_aviso_colaborador';

    public function __construct(
        private readonly NotificacionEventosService $eventos,
    ) {}

    public function alRegistrar(CierreLaboral $cierre): void
    {
        $fecha = FechaTexto::larga($cierre->fecha_efectiva);

        if ($cierre->modalidad_baja === ModalidadBaja::Programada) {
            $this->entregarAviso($cierre, 'registro', 'Tu baja quedó programada', sprintf(
                'Tu baja laboral quedó programada para el %s. Hasta ese día conservas tu acceso a PEOPLE; ese día se suspende. Recursos Humanos te contactará para tu finiquito y tus documentos de salida.',
                $fecha,
            ));

            return;
        }

        $this->entregarAviso($cierre, 'registro', 'Se registró tu baja', sprintf(
            'Se registró tu baja laboral con último día el %s y tu acceso a PEOPLE se suspendió. Recursos Humanos te contactará para tu finiquito y tus documentos de salida.',
            $fecha,
        ));
    }

    public function alReprogramar(CierreLaboral $cierre, bool $ejecutadaAhora): void
    {
        $fecha = FechaTexto::larga($cierre->fecha_efectiva);

        if ($ejecutadaAhora) {
            $this->entregarAviso($cierre, 'ejecutada', 'Tu baja se aplicó hoy', sprintf('Tu baja laboral se adelantó y quedó aplicada hoy, %s. Tu acceso a PEOPLE se suspendió.', $fecha));

            return;
        }

        $this->entregarAviso($cierre, 'reprogramada', 'Cambió la fecha de tu baja', sprintf('La fecha de tu baja laboral cambió: ahora es el %s. Hasta ese día conservas tu acceso a PEOPLE.', $fecha));
    }

    public function alCancelar(CierreLaboral $cierre): void
    {
        $this->entregarAviso($cierre, 'cancelada', 'Tu baja se canceló', 'Tu baja laboral se canceló: sigues laborando con normalidad y conservas tu acceso a PEOPLE.');
    }

    private function entregarAviso(CierreLaboral $cierre, string $momento, string $titulo, string $mensaje): void
    {
        if (! $cierre->avisar_colaborador) {
            return;
        }

        try {
            $colaborador = Colaborador::withTrashed()->with('user')->where('id', $cierre->colaborador_id)->first();

            if ($colaborador === null) {
                return;
            }

            $notificacion = new AvisoBajaColaboradorNotification($titulo, $mensaje);
            $cuenta = $colaborador->user;

            if ($cuenta !== null) {
                $this->eventos->entregar($cuenta, CategoriaNotificacion::Resultado, new Aviso(
                    self::EVENTO,
                    'baja_colaborador',
                    $titulo,
                    $mensaje,
                    $cierre,
                    $momento,
                    'alta',
                    ocurrencia: sprintf('%s:%s:%s', $momento, $cierre->fecha_efectiva->toDateString(), now()->toDateTimeString()),
                    notificacion: $notificacion,
                    directo: true,
                    pushTitulo: $titulo,
                    payloadSinRecurso: true,
                ));

                return;
            }

            $correo = $colaborador->correo_personal ?: $colaborador->correo_corporativo;

            if (filled($correo)) {
                Notification::route('mail', (string) $correo)->notify($notificacion);
            }
        } catch (Throwable $e) {
            Log::warning('AvisoBajaColaboradorService: no se pudo avisar a la persona de su baja.', ['cierre_id' => $cierre->id, 'momento' => $momento, 'error' => $e->getMessage()]);
        }
    }
}
