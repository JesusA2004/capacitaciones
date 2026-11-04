<?php

use App\Enums\EstadoCandidato;
use App\Models\Candidato;
use App\Models\User;
use App\Services\Permisos\AlineacionPermisosRolService;
use App\Services\Reclutamiento\CandidatoWorkflowService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Reclutamiento (decisión del negocio 2026-10-22 y 2026-10-26): el GERENTE
 * (gerente, gerente_sucursal, gerente_regional) SOLO aprueba la entrevista y
 * sube el contrato firmado. Psicométricas, socioeconómico, referencias, cerrar
 * el proceso y adjuntar son de Reclutamiento/RH (solicitar intervención sobre
 * un rechazo de RH se conserva: es de quien entrevistó).
 *
 *  1) Revoca a esos tres roles SOLO los permisos de esos pasos (lo demás que
 *     un administrador les haya configurado se respeta) y les asegura
 *     candidatos.aprobar_entrevista.
 *  2) Reclutamiento (rh_auxiliar) recibe la revisión de psicométricas y de
 *     socioeconómico: así el paso nunca queda huérfano (CandidatoWorkflowService
 *     lo manda a quien tenga el permiso cuando el gerente ya no lo tiene).
 *  3) Quita a TODOS los roles los permisos retirados (candidatos.evaluar,
 *     candidatos.rechazar): ya repartieron su herencia y no habilitan nada.
 *  4) Recalcula los pendientes de candidatos asignados a quien ya no puede
 *     atenderlos y marca como leídos los avisos de esos pasos que les llegaron
 *     (y los de «Candidato por entrevistar» de candidatos que ya pasaron la
 *     entrevista). Nada se borra.
 *
 * Idempotente y segura sin datos: un rol inexistente se omite.
 */
return new class extends Migration
{
    private const GERENCIAS = ['gerente', 'gerente_sucursal', 'gerente_regional'];

    private const REVOCAR_A_GERENCIAS = [
        'candidatos.aprobar',
        'candidatos.revisar_psicometricas',
        'candidatos.registrar_socioeconomico',
        'candidatos.revisar_socioeconomico',
        'candidatos.validar_referencias',
        'candidatos.cerrar_proceso',
        'candidatos.adjuntar',
        // candidatos.solicitar_intervencion se conserva: quien entrevistó puede
        // pedir que Regional / Dirección Comercial revise un rechazo de RH.
    ];

    private const RETIRADOS = ['candidatos.evaluar', 'candidatos.rechazar'];

    /** Avisos de pasos que la gerencia ya no atiende (y su versión del reconciliador). */
    private const EVENTOS = [
        'candidato_revision_psicometricas', 'candidato_socioeconomico', 'candidato_referencias', 'candidato_preautorizacion',
        'pendiente_candidato_revision_psicometricas', 'pendiente_candidato_socioeconomico', 'pendiente_candidato_referencias', 'pendiente_candidato_preautorizacion',
    ];

    public function up(): void
    {
        $otorgar = array_fill_keys(self::GERENCIAS, ['candidatos.aprobar_entrevista']);
        $otorgar['rh_auxiliar'] = ['candidatos.revisar_psicometricas', 'candidatos.revisar_socioeconomico'];

        app(AlineacionPermisosRolService::class)->ajustar(
            $otorgar,
            array_fill_keys(self::GERENCIAS, self::REVOCAR_A_GERENCIAS),
            'El gerente solo aprueba la entrevista y sube el contrato firmado (2026-10-22/26).',
            self::RETIRADOS,
        );

        try {
            app(CandidatoWorkflowService::class)->reconciliarPendientesAsignados();
        } catch (Throwable $e) {
            Log::warning('Migración gerente_solo_aprueba_entrevista: no se pudieron recalcular pendientes.', ['error' => $e->getMessage()]);
        }

        $this->marcarAvisosComoLeidos();
    }

    /** Sin reversa automática: los permisos se devuelven desde «Roles y permisos». */
    public function down(): void {}

    private function marcarAvisosComoLeidos(): void
    {
        $usuarios = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->whereIn('roles.name', self::GERENCIAS)
            ->where('model_has_roles.model_type', (new User)->getMorphClass())
            ->pluck('model_has_roles.model_id');

        if ($usuarios->isEmpty()) {
            return;
        }

        $relacionado = class_basename(Candidato::class);

        $ids = DB::table('notificacion_entregas')
            ->whereIn('user_id', $usuarios)
            ->whereNotNull('notification_id')
            ->where(fn ($q) => $q
                ->whereIn('evento', self::EVENTOS)
                ->orWhere(fn ($e) => $e
                    ->whereIn('evento', ['candidato_entrevista', 'pendiente_candidato_entrevista'])
                    ->where('related_type', $relacionado)
                    ->whereIn('related_id', DB::table('candidatos')->where('estado', '!=', EstadoCandidato::EntrevistaPendiente->value)->select('id'))))
            ->pluck('notification_id');

        foreach ($ids->chunk(500) as $lote) {
            DB::table('notifications')->whereIn('id', $lote->all())->whereNull('read_at')->update(['read_at' => now()]);
        }
    }
};
