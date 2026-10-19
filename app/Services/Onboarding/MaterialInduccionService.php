<?php

namespace App\Services\Onboarding;

use App\Enums\TipoMaterialInduccion;
use App\Models\InduccionMaterialExplicacion;
use App\Models\OnboardingAvance;
use App\Models\OnboardingModulo;
use App\Models\RecursoMultimedia;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/**
 * Material de explicación de la Inducción Mr. Lana (audio XOR video) que el
 * colaborador ve ENTRE las hojas del PDF y su evaluación. Reglas de negocio
 * centralizadas (CLAUDE.md): un solo material ACTIVO a la vez por módulo
 * institucional; al activar uno nuevo se retira el anterior (no se borra, se
 * conserva como historial con activo=NULL). Mismo Service para web y API.
 */
class MaterialInduccionService
{
    public const PERMISO = OnboardingService::PERMISO_GESTIONAR;

    public function __construct(
        private readonly AuditoriaService $auditoria,
    ) {}

    /** El material vigente del módulo, o null si no hay ninguno activo. */
    public function materialActivo(OnboardingModulo $modulo): ?InduccionMaterialExplicacion
    {
        return InduccionMaterialExplicacion::query()
            ->where('onboarding_modulo_id', $modulo->id)
            ->where('activo', true)
            ->with('recurso')
            ->first();
    }

    /** El material vigente del módulo de un avance institucional. */
    public function materialActivoDeAvance(OnboardingAvance $avance): ?InduccionMaterialExplicacion
    {
        $avance->loadMissing('modulo');

        return $this->materialActivo($avance->modulo);
    }

    /**
     * DTO estable del material activo para el colaborador (web y API): tipo y,
     * para audio, la URL firmada de la narración; para video, si ya está listo
     * para reproducirse (el reproductor usa los endpoints de video). El video
     * se reproduce con ReproduccionVideoInduccionService, no con una URL suelta.
     *
     * @return array{tipo: string|null, audio_url: string|null, video_disponible: bool}
     */
    public function datosParaColaborador(OnboardingAvance $avance): array
    {
        $material = $this->materialActivoDeAvance($avance);

        if ($material === null) {
            return ['tipo' => null, 'audio_url' => null, 'video_disponible' => false];
        }

        if ($material->tipo->bloqueaEvaluacion()) {
            return [
                'tipo' => $material->tipo->value,
                'audio_url' => null,
                'video_disponible' => $material->recurso?->estaDisponible() ?? false,
            ];
        }

        return [
            'tipo' => $material->tipo->value,
            'audio_url' => $material->recurso !== null ? URL::temporarySignedRoute(
                'portal.induccion.audio',
                now()->addSeconds((int) config('media.token_ttl')),
                ['avance' => $avance->id],
            ) : null,
            'video_disponible' => false,
        ];
    }

    /**
     * Activa un recurso (audio/video) como el material vigente del módulo:
     * valida que el tipo del RecursoMultimedia sea coherente (audio XOR video),
     * retira el material anterior y audita el cambio. Idempotente para el mismo
     * recurso (si ya es el activo lo devuelve sin duplicar).
     *
     * @throws AuthorizationException sin permiso de gestión de onboarding
     * @throws ValidationException recurso de un tipo no soportado como material
     */
    public function activar(OnboardingModulo $modulo, RecursoMultimedia $recurso, User $actor): InduccionMaterialExplicacion
    {
        $this->exigirPermiso($actor);

        $tipo = TipoMaterialInduccion::desdeRecurso($recurso->tipo);

        if ($tipo === null) {
            throw ValidationException::withMessages([
                'recurso_multimedia_id' => sprintf('El material de inducción debe ser audio o video (se recibió %s).', $recurso->tipo->etiqueta()),
            ]);
        }

        $material = DB::transaction(function () use ($modulo, $recurso, $tipo, $actor): InduccionMaterialExplicacion {
            $activo = InduccionMaterialExplicacion::query()
                ->where('onboarding_modulo_id', $modulo->id)
                ->where('activo', true)
                ->lockForUpdate()
                ->first();

            if ($activo !== null && $activo->recurso_multimedia_id === $recurso->id) {
                return $activo;
            }

            // Retirar el anterior (activo = NULL, historial) para no chocar con
            // el índice único (onboarding_modulo_id, activo).
            $activo?->update(['activo' => null]);

            return InduccionMaterialExplicacion::query()->create([
                'onboarding_modulo_id' => $modulo->id,
                'recurso_multimedia_id' => $recurso->id,
                'tipo' => $tipo,
                'activo' => true,
                'subido_por' => $actor->id,
            ]);
        });

        $this->auditoria->registrar('induccion_material_activado', $material, $actor, [
            'onboarding_modulo_id' => $modulo->id,
            'recurso_multimedia_id' => $recurso->id,
            'tipo' => $tipo->value,
        ]);

        return $material->refresh();
    }

    /**
     * Retira el material vigente del módulo (lo conserva como historial).
     *
     * @throws AuthorizationException
     */
    public function desactivar(OnboardingModulo $modulo, User $actor): void
    {
        $this->exigirPermiso($actor);

        $activo = $this->materialActivo($modulo);

        if ($activo === null) {
            return;
        }

        $activo->update(['activo' => null]);
        $this->auditoria->registrar('induccion_material_retirado', $activo, $actor, [
            'onboarding_modulo_id' => $modulo->id,
        ]);
    }

    /**
     * @throws AuthorizationException
     */
    private function exigirPermiso(User $actor): void
    {
        if (! $actor->can(self::PERMISO)) {
            throw new AuthorizationException('No tienes permiso para gestionar el material de inducción.');
        }
    }
}
