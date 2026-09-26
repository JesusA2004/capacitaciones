<?php

namespace Database\Seeders;

use App\Models\SolicitudInterna;
use App\Models\User;
use App\Services\Solicitudes\AprobacionJerarquicaService;
use App\Services\Solicitudes\SolicitudesService;
use App\Services\Solicitudes\VistoBuenoService;
use Closure;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Gate;

/**
 * Datos de demostración del módulo unificado de Solicitudes
 * (docs/SOLICITUDES_UNIFICADAS.md) — sin este seeder, el sistema se veía
 * "vacío" en el módulo central de esta reestructuración: cero vacaciones,
 * préstamos, incapacidades o bajas de ejemplo. Usa siempre
 * SolicitudesService (nunca crea el modelo a mano) para que también quede
 * probado el saldo de vacaciones, el folio consecutivo, el historial y —
 * desde que existe— la generación automática de formato oficial al aprobar.
 *
 * Idempotente por escenario (cada bloque se salta si ya existe una
 * solicitud con su motivo característico), no por un único flag global: así
 * una base ya sembrada con una versión anterior de este seeder recibe los
 * escenarios nuevos (requiere_correccion, cerrada) sin duplicar los viejos.
 */
class SolicitudesDemoSeeder extends Seeder
{
    private const MARCA = '(demo)';

    public function run(): void
    {
        $servicio = app(SolicitudesService::class);

        // jefe.directo es, de los usuarios demo, el que tiene fecha_ingreso
        // más antigua (~1 año 5 meses) — el único con saldo de vacaciones
        // real generado (VacacionesService::diasPorAntiguedad() da 0 días
        // a cualquiera con menos de 1 año de antigüedad).
        $colaboradorVacaciones = User::where('email', 'jefe.directo@mrlana.test')->first();
        $colaborador2 = User::where('email', 'colaborador2@mrlana.test')->first();
        $colaborador3 = User::where('email', 'colaborador3@mrlana.test')->first();
        $colaborador4 = User::where('email', 'colaborador4@mrlana.test')->first();
        $colaborador5 = User::where('email', 'colaborador5@mrlana.test')->first();
        $colaborador6 = User::where('email', 'colaborador6@mrlana.test')->first();
        $colaborador7 = User::where('email', 'colaborador7@mrlana.test')->first();
        $rhAdmin = User::where('email', 'rh.admin@mrlana.test')->first();
        $gerente = User::where('email', 'gerente.sucursal@mrlana.test')->first();

        if ($colaboradorVacaciones === null || $rhAdmin === null) {
            return;
        }

        $this->crearSiNoExiste('Vacaciones familiares de fin de año', function () use ($servicio, $colaboradorVacaciones) {
            // Enviada, sin resolver todavía — columna "Enviada" del tablero.
            $servicio->crear($colaboradorVacaciones, [
                'tipo' => 'vacaciones',
                'motivo' => 'Vacaciones familiares de fin de año '.self::MARCA,
                'fecha_inicio' => now()->addDays(15)->toDateString(),
                'fecha_fin' => now()->addDays(20)->toDateString(),
                'dias_solicitados' => 5,
            ]);
        });

        if ($colaborador2 !== null) {
            $this->crearSiNoExiste('Cita médica familiar', function () use ($servicio, $colaborador2, $rhAdmin) {
                // Aprobada — columna "Aprobada"; dispara el formato oficial
                // automático de permiso (config/solicitudes.php) si el
                // formato ya está configurado.
                $solicitud = $servicio->crear($colaborador2, [
                    'tipo' => 'permiso_con_goce',
                    'motivo' => 'Cita médica familiar '.self::MARCA,
                    'fecha_inicio' => now()->subDays(5)->toDateString(),
                    'fecha_fin' => now()->subDays(5)->toDateString(),
                ]);
                $servicio->aprobar($solicitud, $rhAdmin, 'Aprobado sin observaciones.');
            });
        }

        if ($colaborador3 !== null) {
            $this->crearSiNoExiste('Incapacidad por gripe', function () use ($servicio, $colaborador3, $rhAdmin) {
                // En revisión — columna "En revisión".
                $solicitud = $servicio->crear($colaborador3, [
                    'tipo' => 'incapacidad',
                    'motivo' => 'Incapacidad por gripe '.self::MARCA,
                    'fecha_inicio' => now()->subDays(2)->toDateString(),
                    'fecha_fin' => now()->addDays(1)->toDateString(),
                ]);
                $servicio->marcarEnRevision($solicitud, $rhAdmin, 'Esperando el certificado del IMSS.');
            });
        }

        if ($colaborador4 !== null) {
            $this->crearSiNoExiste('Gastos escolares de los hijos', function () use ($servicio, $colaborador4, $rhAdmin) {
                // Aprobada, con monto y plazo — dispara el contrato de
                // crédito automático si el formato ya está configurado.
                $solicitud = $servicio->crear($colaborador4, [
                    'tipo' => 'prestamo',
                    'motivo' => 'Gastos escolares de los hijos '.self::MARCA,
                    'monto_solicitado' => 8000,
                    'plazo_meses' => 6,
                ]);

                // Mismo flujo real que la app: el préstamo requiere el visto
                // bueno del jefe inmediato antes de la autorización de RH
                // (config solicitudes.visto_bueno_jefe). Sin jefe con cuenta,
                // la solicitud se queda en trámite en vez de saltarse el paso.
                if (app(AprobacionJerarquicaService::class)->requiereVistoBuenoJefe($solicitud)) {
                    $jefe = $solicitud->personaSolicitante()?->jefe?->user;

                    if ($jefe === null) {
                        return;
                    }

                    app(VistoBuenoService::class)->registrar($solicitud, $jefe, true, 'Visto bueno del jefe inmediato.');
                }

                $servicio->aprobar($solicitud->refresh(), $rhAdmin, 'Aprobado; el descuento lo aplica el área de nómina.');
            });
        }

        if ($colaborador5 !== null) {
            $this->crearSiNoExiste('Cambio de horario de entrada', function () use ($servicio, $colaborador5, $rhAdmin) {
                // Rechazada, con motivo — columna "Rechazada".
                $solicitud = $servicio->crear($colaborador5, [
                    'tipo' => 'solicitud_general',
                    'motivo' => 'Cambio de horario de entrada '.self::MARCA,
                ]);
                $servicio->rechazar($solicitud, $rhAdmin, 'No es posible por cobertura de sucursal en ese horario.');
            });
        }

        if ($colaborador6 !== null) {
            $this->crearSiNoExiste('Cambio de domicilio', function () use ($servicio, $colaborador6) {
                // Recién enviada, sin tocar — columna "Enviada" (segunda
                // tarjeta, para no depender de una sola).
                $servicio->crear($colaborador6, [
                    'tipo' => 'actualizacion_datos',
                    'motivo' => 'Cambio de domicilio '.self::MARCA,
                ]);
            });
        }

        if ($gerente !== null && $colaborador6 !== null && $colaborador6->colaborador !== null) {
            // Quien pide la baja debe tenerla en su alcance (policy
            // crearBaja): el gerente demo es de otra sucursal, así que si no
            // la ve la registra RH — antes el seeder tronaba aquí y
            // `db:seed` no terminaba.
            $solicitanteBaja = Gate::forUser($gerente)->allows('crearBaja', [SolicitudInterna::class, $colaborador6->colaborador]) ? $gerente : $rhAdmin;

            $this->crearSiNoExiste('Renuncia voluntaria, último día pactado a fin de mes', function () use ($servicio, $solicitanteBaja, $colaborador6) {
                // Baja PENDIENTE de aprobar a propósito (para poder probar
                // el flujo completo de aprobación + bloqueo de acceso
                // manualmente). El colaborador objetivo sigue activo.
                $servicio->crear($solicitanteBaja, [
                    'tipo' => 'baja_colaborador',
                    'motivo' => 'Renuncia voluntaria, último día pactado a fin de mes '.self::MARCA,
                    'colaborador_objetivo_id' => $colaborador6->colaborador_id,
                ]);
            });
        }

        if ($colaborador7 !== null) {
            $this->crearSiNoExiste('Corrección de horario de comida', function () use ($servicio, $colaborador7, $rhAdmin) {
                // Requiere corrección — columna "Requiere corrección".
                $solicitud = $servicio->crear($colaborador7, [
                    'tipo' => 'permiso_sin_goce',
                    'motivo' => 'Corrección de horario de comida '.self::MARCA,
                    'fecha_inicio' => now()->addDays(3)->toDateString(),
                    'fecha_fin' => now()->addDays(3)->toDateString(),
                ]);
                $servicio->requerirCorreccion($solicitud, $rhAdmin, 'Falta la fecha exacta del permiso, indícala de nuevo.');
            });

            $this->crearSiNoExiste('Constancia laboral para trámite bancario', function () use ($servicio, $colaborador7, $rhAdmin) {
                // Aprobada y luego cerrada — columna "Cerrada".
                $solicitud = $servicio->crear($colaborador7, [
                    'tipo' => 'constancia_laboral',
                    'motivo' => 'Constancia laboral para trámite bancario '.self::MARCA,
                ]);
                $servicio->aprobar($solicitud, $rhAdmin, 'Constancia entregada.');
                $servicio->cerrar($solicitud->fresh(), $rhAdmin, 'Trámite concluido.');
            });
        }
    }

    private function crearSiNoExiste(string $motivoUnico, Closure $crear): void
    {
        if (SolicitudInterna::where('motivo', 'like', "%{$motivoUnico}%".self::MARCA)->exists()) {
            return;
        }

        $crear();
    }
}
