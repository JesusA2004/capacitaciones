<?php

namespace Database\Seeders;

use App\Models\SolicitudInterna;
use App\Models\User;
use App\Services\Solicitudes\SolicitudesService;
use Illuminate\Database\Seeder;

/**
 * Datos de demostración del módulo unificado de Solicitudes
 * (docs/SOLICITUDES_UNIFICADAS.md) — sin este seeder, el sistema se veía
 * "vacío" en el módulo central de esta reestructuración: cero vacaciones,
 * préstamos, incapacidades o bajas de ejemplo. Usa siempre
 * SolicitudesService (nunca crea el modelo a mano) para que también quede
 * probado el saldo de vacaciones, el folio consecutivo y el historial.
 *
 * Idempotente por folio implícito: si ya hay solicitudes de este seeder
 * (detectadas por el motivo característico), no vuelve a crearlas.
 */
class SolicitudesDemoSeeder extends Seeder
{
    private const MARCA = '(demo)';

    public function run(): void
    {
        if (SolicitudInterna::where('motivo', 'like', '%'.self::MARCA)->exists()) {
            return;
        }

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
        $rhAdmin = User::where('email', 'rh.admin@mrlana.test')->first();
        $gerente = User::where('email', 'gerente.sucursal@mrlana.test')->first();

        if ($colaboradorVacaciones === null || $rhAdmin === null) {
            return;
        }

        // Vacaciones: enviada, sin resolver todavía.
        $servicio->crear($colaboradorVacaciones, [
            'tipo' => 'vacaciones',
            'motivo' => 'Vacaciones familiares de fin de año '.self::MARCA,
            'fecha_inicio' => now()->addDays(15)->toDateString(),
            'fecha_fin' => now()->addDays(20)->toDateString(),
            'dias_solicitados' => 5,
        ]);

        // Permiso con goce: aprobado.
        if ($colaborador2 !== null) {
            $solicitud = $servicio->crear($colaborador2, [
                'tipo' => 'permiso_con_goce',
                'motivo' => 'Cita médica familiar '.self::MARCA,
                'fecha_inicio' => now()->subDays(5)->toDateString(),
                'fecha_fin' => now()->subDays(5)->toDateString(),
            ]);
            $servicio->aprobar($solicitud, $rhAdmin, 'Aprobado sin observaciones.');
        }

        // Incapacidad: en revisión.
        if ($colaborador3 !== null) {
            $solicitud = $servicio->crear($colaborador3, [
                'tipo' => 'incapacidad',
                'motivo' => 'Incapacidad por gripe '.self::MARCA,
                'fecha_inicio' => now()->subDays(2)->toDateString(),
                'fecha_fin' => now()->addDays(1)->toDateString(),
            ]);
            $servicio->marcarEnRevision($solicitud, $rhAdmin, 'Esperando el certificado del IMSS.');
        }

        // Préstamo interno: aprobado, con monto y plazo.
        if ($colaborador4 !== null) {
            $solicitud = $servicio->crear($colaborador4, [
                'tipo' => 'prestamo',
                'motivo' => 'Gastos escolares de los hijos '.self::MARCA,
                'monto_solicitado' => 8000,
                'plazo_meses' => 6,
            ]);
            $servicio->aprobar($solicitud, $rhAdmin, 'Aprobado, se descuenta vía nómina.');
        }

        // Solicitud general: rechazada, con motivo — para ver ese estado
        // también representado.
        if ($colaborador5 !== null) {
            $solicitud = $servicio->crear($colaborador5, [
                'tipo' => 'solicitud_general',
                'motivo' => 'Cambio de horario de entrada '.self::MARCA,
            ]);
            $servicio->rechazar($solicitud, $rhAdmin, 'No es posible por cobertura de sucursal en ese horario.');
        }

        // Actualización de datos: recién enviada, sin tocar.
        if ($colaborador6 !== null) {
            $servicio->crear($colaborador6, [
                'tipo' => 'actualizacion_datos',
                'motivo' => 'Cambio de domicilio '.self::MARCA,
            ]);
        }

        // Baja de colaborador: PENDIENTE de aprobar (a propósito, para
        // poder probar el flujo completo de aprobación + bloqueo de acceso
        // manualmente desde la pantalla de Solicitudes). El colaborador
        // objetivo sigue activo mientras esto no se apruebe.
        if ($gerente !== null && $colaborador6 !== null) {
            $servicio->crear($gerente, [
                'tipo' => 'baja_colaborador',
                'motivo' => 'Renuncia voluntaria, último día pactado a fin de mes '.self::MARCA,
                'colaborador_objetivo_id' => $colaborador6->id,
            ]);
        }
    }
}
