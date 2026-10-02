<?php

namespace App\Services\Administracion;

use App\Enums\EstadoUsuario;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Baja de CUENTA (acceso al sistema) — distinta de la baja de COLABORADOR
 * (relación laboral, App\Services\Solicitudes\BajaColaboradorService y el
 * cierre laboral):
 *
 * - Cuenta inactiva = `acceso_bloqueado_en` con motivo y quién la bloqueó;
 *   se cierran sesiones de la app (tokens) y dispositivos. No toca estatus
 *   laboral, headcount ni expediente.
 * - Nunca se borra nada: cuenta, roles, historial, contratos y documentos se
 *   conservan para un posible reingreso.
 *
 * Única regla para web (Usuarios, pestaña Cuenta del expediente), cierre
 * laboral y baja laboral.
 */
class AccesoCuentaService
{
    public function __construct(private readonly AuditoriaService $auditoria) {}

    public function revocar(User $cuenta, ?User $actor, string $motivo): void
    {
        DB::transaction(function () use ($cuenta, $actor, $motivo): void {
            // forceFill: estos campos no son asignables en masa a propósito
            // (ningún formulario los manda directo).
            $cuenta->forceFill([
                'acceso_bloqueado_en' => $cuenta->acceso_bloqueado_en ?? now(),
                'acceso_bloqueado_motivo' => mb_substr($motivo, 0, 255),
                'acceso_bloqueado_por' => $actor?->id,
            ])->save();

            $cuenta->tokens()->delete();
            $cuenta->mobileDevices()->whereNull('revoked_at')->update(['revoked_at' => now()]);
        });

        $this->auditoria->registrar('acceso_revocado', $cuenta, $actor, [
            'colaborador_id' => $cuenta->colaborador_id,
            'motivo' => $motivo,
        ]);
    }

    public function restablecer(User $cuenta, ?User $actor, ?string $motivo = null): void
    {
        if ($cuenta->acceso_bloqueado_en === null) {
            return;
        }

        $antes = $cuenta->acceso_bloqueado_motivo;

        $cuenta->forceFill([
            'acceso_bloqueado_en' => null,
            'acceso_bloqueado_motivo' => null,
            'acceso_bloqueado_por' => null,
        ])->save();

        $this->auditoria->registrar('acceso_restablecido', $cuenta, $actor, [
            'colaborador_id' => $cuenta->colaborador_id,
            'motivo_bloqueo' => $antes,
            'motivo' => $motivo,
        ]);
    }

    /**
     * Cuenta activa = acceso no revocado Y colaborador vigente (no dado de
     * baja). Misma regla que EnsureCuentaActiva aplica al iniciar sesión.
     *
     * @param  Builder<User>  $consulta
     * @return Builder<User>
     */
    public function soloActivas(Builder $consulta): Builder
    {
        return $consulta
            ->whereNull('acceso_bloqueado_en')
            ->whereHas('colaborador', fn ($c) => $c->whereNotIn('estatus', [EstadoUsuario::Inactivo->value, EstadoUsuario::Suspendido->value]));
    }

    public function cuentaActiva(User $u): bool
    {
        $colaborador = $u->colaborador;

        return $u->acceso_bloqueado_en === null
            && $colaborador !== null
            && $colaborador->deleted_at === null
            && ! in_array($colaborador->estatus, [EstadoUsuario::Inactivo, EstadoUsuario::Suspendido], true);
    }

    public function motivoInactiva(User $u): ?string
    {
        if ($u->acceso_bloqueado_en !== null) {
            return $u->acceso_bloqueado_motivo ?? 'Acceso revocado';
        }

        return $this->cuentaActiva($u) ? null : 'Colaborador dado de baja';
    }

    /**
     * Estado LABORAL (independiente de la cuenta).
     *
     * @return array{clave: string, etiqueta: string}
     */
    public function estadoColaborador(User $u, bool $bajaEnTramite): array
    {
        $colaborador = $u->colaborador;

        if ($colaborador === null) {
            return ['clave' => 'sin_colaborador', 'etiqueta' => 'Sin expediente'];
        }

        if ($colaborador->deleted_at !== null || $colaborador->estatus === EstadoUsuario::Inactivo) {
            return ['clave' => 'baja', 'etiqueta' => 'Baja laboral'];
        }

        if ($bajaEnTramite) {
            return ['clave' => 'baja_en_tramite', 'etiqueta' => 'Baja en trámite'];
        }

        return ['clave' => $colaborador->estatus->value, 'etiqueta' => $colaborador->estatus->etiqueta()];
    }
}
