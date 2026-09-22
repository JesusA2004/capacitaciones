<?php

namespace App\Services\Solicitudes;

use App\Enums\EstadoAltaColaborador;
use App\Enums\EstadoUsuario;
use App\Models\Colaborador;
use App\Models\User;
use App\Services\MovimientosLaborales\MovimientoLaboralService;
use App\Services\Vacantes\VacanteAutoGenerationService;
use Illuminate\Support\Facades\DB;

/**
 * Ejecuta la baja REAL de un colaborador cuando una solicitud interna de
 * tipo BajaColaborador queda aprobada (ver
 * App\Services\Solicitudes\SolicitudesService::aprobar()). Distinto del
 * flujo directo de administración (App\Http\Controllers\Rh\ExpedienteController::darDeBaja(),
 * que sigue existiendo para una baja administrativa inmediata sin pasar por
 * aprobación): aquí el bloqueo de acceso viene aparejado a la aprobación de
 * la solicitud, no antes.
 *
 * Nunca borra al colaborador ni su expediente — solo bloquea acceso (si
 * tiene cuenta) y actualiza headcount/vacantes, sección 2 del encargo ("Baja
 * colaborador").
 */
class BajaColaboradorService
{
    public function __construct(
        private readonly MovimientoLaboralService $movimientos,
        private readonly VacanteAutoGenerationService $vacantes,
    ) {}

    public function ejecutar(Colaborador $colaborador, User $actor, ?string $motivo = null): void
    {
        DB::transaction(function () use ($colaborador, $actor, $motivo): void {
            $sucursalId = $colaborador->sucursal_principal_id;
            $puestoId = $colaborador->puesto_id;

            // Historial laboral + auditoría (MovimientoLaboral guarda
            // motivo/fecha/quién la registró): misma fuente de verdad que
            // la baja administrativa directa, sin crear una vacante manual
            // aquí — la vacante automática se sincroniza abajo a partir del
            // headcount real.
            $this->movimientos->registrarBaja($colaborador, $actor, $motivo, false);

            // $colaborador tiene LogsActivity (App\Models\Colaborador) con
            // 'estatus' en logOnly(): este cambio ya queda auditado solo.
            $colaborador->update([
                'estatus' => EstadoUsuario::Inactivo,
                'estado_alta' => EstadoAltaColaborador::Baja,
                'fecha_baja' => $colaborador->fecha_baja ?? now()->toDateString(),
            ]);

            // Bloquear login si tiene cuenta: revocar todos los tokens
            // Sanctum (API/app móvil) y marcar sus dispositivos móviles como
            // revocados. Un colaborador sin cuenta no tiene nada que
            // revocar aquí.
            $cuenta = $colaborador->user;

            if ($cuenta !== null) {
                $cuenta->tokens()->delete();
                $cuenta->mobileDevices()->whereNull('revoked_at')->update(['revoked_at' => now()]);
            }

            // Headcount/vacantes: la plantilla actual ya bajó (el
            // colaborador dejó de estar activo), así que se sincroniza la
            // vacante automática de su (sucursal, puesto) — la abre si la
            // plantilla autorizada sigue exigiéndola.
            if ($sucursalId !== null && $puestoId !== null) {
                $this->vacantes->sincronizar($sucursalId, $puestoId);
            }
        });
    }

    /**
     * Reactiva la relación laboral de un colaborador dado de baja: revierte
     * el soft-delete y su estatus. Nunca reactiva el acceso al sistema por su
     * cuenta — si tiene un `User` bloqueado, sigue bloqueado hasta que
     * alguien lo restablezca explícitamente desde Administración > Usuarios
     * (ver sección 24 del encargo: "reactivar relación laboral" y
     * "reactivar acceso" son decisiones separadas).
     */
    public function reactivar(Colaborador $colaborador): void
    {
        DB::transaction(function () use ($colaborador): void {
            $colaborador->restore();
            $colaborador->update([
                'estatus' => EstadoUsuario::Activo,
                'estado_alta' => EstadoAltaColaborador::Activo,
                'fecha_baja' => null,
                'expediente_cerrado_en' => null,
                'expediente_cerrado_por' => null,
            ]);
        });
    }
}
