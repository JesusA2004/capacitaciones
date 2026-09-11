<?php

namespace App\Services\Solicitudes;

use App\Enums\EstadoUsuario;
use App\Models\User;
use App\Services\MovimientosLaborales\MovimientoLaboralService;
use App\Services\Vacantes\VacanteAutoGenerationService;
use Illuminate\Support\Facades\DB;

/**
 * Ejecuta la baja REAL de un colaborador cuando una solicitud interna de
 * tipo BajaColaborador queda aprobada (ver
 * App\Services\Solicitudes\SolicitudesService::aprobar()). Distinto del
 * flujo directo de administración (App\Http\Controllers\Administracion\
 * UsuarioController::destroy(), que sigue existiendo para una baja
 * administrativa inmediata sin pasar por aprobación): aquí el bloqueo de
 * acceso viene aparejado a la aprobación de la solicitud, no antes.
 *
 * Nunca borra al usuario ni su expediente — solo bloquea acceso y actualiza
 * headcount/vacantes, sección 2 del encargo ("Baja colaborador").
 */
class BajaColaboradorService
{
    public function __construct(
        private readonly MovimientoLaboralService $movimientos,
        private readonly VacanteAutoGenerationService $vacantes,
    ) {}

    public function ejecutar(User $colaborador, User $actor, ?string $motivo = null): void
    {
        DB::transaction(function () use ($colaborador, $actor, $motivo): void {
            $sucursalId = $colaborador->sucursal_principal_id;
            $puestoId = $colaborador->puesto_id;

            // Historial laboral + auditoría (MovimientoLaboral guarda
            // motivo/fecha/quién la registró): misma fuente de verdad que
            // la baja administrativa directa (UsuarioController::destroy),
            // sin crear una vacante manual aquí — la vacante automática se
            // sincroniza abajo a partir del headcount real.
            $this->movimientos->registrarBaja($colaborador, $actor, $motivo, false);

            // $colaborador tiene LogsActivity (App\Models\User) con
            // 'estatus' en logOnly(): este cambio ya queda auditado solo.
            $colaborador->update(['estatus' => EstadoUsuario::Inactivo]);

            // Bloquear login: revocar todos los tokens Sanctum (API/app
            // móvil) y marcar sus dispositivos móviles como revocados.
            $colaborador->tokens()->delete();
            $colaborador->mobileDevices()->whereNull('revoked_at')->update(['revoked_at' => now()]);

            // Headcount/vacantes: la plantilla actual ya bajó (el
            // colaborador dejó de estar activo), así que se sincroniza la
            // vacante automática de su (sucursal, puesto) — la abre si la
            // plantilla autorizada sigue exigiéndola.
            if ($sucursalId !== null && $puestoId !== null) {
                $this->vacantes->sincronizar($sucursalId, $puestoId);
            }
        });
    }
}
