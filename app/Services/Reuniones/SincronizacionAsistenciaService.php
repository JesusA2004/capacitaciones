<?php

namespace App\Services\Reuniones;

use App\Enums\EstadoSincronizacion;
use App\Enums\EstadoSincronizacionReunion;
use App\Enums\ProveedorSesion;
use App\Enums\TipoSincronizacionReunion;
use App\Integrations\Reuniones\GoogleMeetAsistenciaSincronizador;
use App\Integrations\Reuniones\SincronizadorAsistencia;
use App\Integrations\Reuniones\ZoomAsistenciaSincronizador;
use App\Models\RegistroSesion;
use App\Models\SesionEnVivo;
use App\Models\SincronizacionReunion;
use App\Models\User;

/**
 * Orquesta un intento de sincronización de asistencia de principio a fin:
 * resuelve el proveedor, recupera los datos externos, los asocia con
 * colaboradores, calcula el resultado de asistencia, y deja un rastro
 * completo en `registros_sesion` y `sincronizaciones_reunion` — con o sin
 * éxito. La usan tanto los Jobs automáticos como la sincronización manual
 * desde el panel administrativo (mismo código, mismo comportamiento). Ver
 * docs/AUDITORIA_CUMPLIMIENTO.md secciones 1-5.
 */
class SincronizacionAsistenciaService
{
    private const MAX_INTENTOS = 5;

    public function __construct(
        private readonly AsociadorParticipanteService $asociador,
        private readonly CalculoAsistenciaService $calculo,
    ) {}

    public function sincronizar(SesionEnVivo $sesion, TipoSincronizacionReunion $tipo, ?User $iniciadoPor = null, ?string $jobId = null): SincronizacionReunion
    {
        $sincronizacion = SincronizacionReunion::create([
            'sesion_en_vivo_id' => $sesion->id,
            'proveedor' => $sesion->proveedor->value,
            'tipo_sincronizacion' => $tipo->value,
            'estado' => EstadoSincronizacionReunion::EnProgreso->value,
            'iniciado_en' => now(),
            'iniciado_por' => $iniciadoPor?->id,
            'job_id' => $jobId,
        ]);

        $registro = RegistroSesion::firstOrCreate(
            ['sesion_en_vivo_id' => $sesion->id, 'proveedor' => $sesion->proveedor->value],
            ['estado_sincronizacion' => EstadoSincronizacion::Pendiente->value],
        );

        try {
            if ($sesion->proveedor === ProveedorSesion::Manual) {
                throw new \RuntimeException('La sincronización automática de asistencia no aplica a sesiones con enlace manual.');
            }

            $sincronizador = $this->resolverSincronizador($sesion->proveedor);

            if (! $sincronizador->estaDisponible()) {
                throw new \RuntimeException('La integración no está configurada o le faltan credenciales.');
            }

            $datos = $sincronizador->obtenerDatosAsistencia($sesion);

            $registro->update([
                'identificador_externo' => $datos->identificadorExterno,
                'registro_conferencia_externo' => $datos->registroConferenciaExterno,
                'inicio_real' => $datos->inicioReal,
                'fin_real' => $datos->finReal,
                'duracion_real_segundos' => $datos->duracionRealSegundos,
                'respuesta_normalizada' => $datos->respuestaNormalizada,
                'estado_sincronizacion' => EstadoSincronizacion::Sincronizado->value,
                'intentos' => $registro->intentos + 1,
                'ultimo_error' => null,
                'consultado_en' => now(),
            ]);

            $participantes = $this->asociador->asociar($registro, $datos);
            $this->calculo->calcularParaSesion($sesion, $registro->fresh());

            $sincronizacion->update([
                'estado' => EstadoSincronizacionReunion::Completada->value,
                'finalizado_en' => now(),
                'cantidad_participantes' => count($participantes),
                'resumen' => [
                    'participantes' => count($participantes),
                    'identificados' => count(array_filter($participantes, fn ($p) => $p->user_id !== null)),
                ],
            ]);
        } catch (\Throwable $excepcion) {
            $intentos = $registro->intentos + 1;
            $agotado = $intentos >= self::MAX_INTENTOS;

            $registro->update([
                'estado_sincronizacion' => $agotado ? EstadoSincronizacion::Agotado->value : EstadoSincronizacion::Error->value,
                'intentos' => $intentos,
                'ultimo_error' => $excepcion->getMessage(),
                'consultado_en' => now(),
            ]);

            $sincronizacion->update([
                'estado' => $agotado ? EstadoSincronizacionReunion::Agotada->value : EstadoSincronizacionReunion::Error->value,
                'finalizado_en' => now(),
                'intentos' => $intentos,
                'error' => $excepcion->getMessage(),
            ]);

            report($excepcion);

            if (! $agotado) {
                throw $excepcion;
            }
        }

        return $sincronizacion->fresh();
    }

    private function resolverSincronizador(ProveedorSesion $proveedor): SincronizadorAsistencia
    {
        return match ($proveedor) {
            ProveedorSesion::GoogleMeet => app(GoogleMeetAsistenciaSincronizador::class),
            ProveedorSesion::Zoom => app(ZoomAsistenciaSincronizador::class),
            ProveedorSesion::Manual => throw new \LogicException('El proveedor manual no tiene sincronizador de asistencia.'),
        };
    }
}
