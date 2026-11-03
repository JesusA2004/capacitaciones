<?php

namespace App\Services\Onboarding;

use App\Enums\EstadoCargaMultimedia;
use App\Enums\EstadoMultimedia;
use App\Enums\TipoMaterialInduccion;
use App\Enums\TipoModuloOnboarding;
use App\Models\CargaMultimedia;
use App\Models\InduccionMaterialExplicacion;
use App\Models\OnboardingAvance;
use App\Models\OnboardingModulo;
use App\Models\RecursoMultimedia;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Multimedia\ProcesamientoVideoService;
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

    /** Subir / reemplazar / retirar material exige además configurar onboarding. */
    public const PERMISO_CONFIGURAR = 'onboarding.configurar';

    public function __construct(
        private readonly AuditoriaService $auditoria,
        private readonly ProcesamientoVideoService $procesamiento,
    ) {}

    /** Puede subir, reemplazar, retirar o reprocesar el material. */
    public function puedeConfigurar(User $actor): bool
    {
        return $actor->can(self::PERMISO) && $actor->can(self::PERMISO_CONFIGURAR);
    }

    /**
     * @throws AuthorizationException
     */
    public function exigirPermisoConfigurar(User $actor): void
    {
        if (! $this->puedeConfigurar($actor)) {
            throw new AuthorizationException('Solo Recursos Humanos gestiona el material de inducción.');
        }
    }

    /**
     * Módulo institucional donde vive el material, o un error de validación
     * claro si no está configurado.
     *
     * @throws ValidationException
     */
    public function moduloInstitucionalObligatorio(): OnboardingModulo
    {
        return $this->moduloInstitucional() ?? throw ValidationException::withMessages([
            'modulo' => 'No hay un módulo de inducción institucional activo donde colocar el material.',
        ]);
    }

    /**
     * Estado del material vigente para la pantalla de RH (tarjeta «2 · Video
     * o audio de explicación» y su sondeo automático): tipo, estado real del
     * procesamiento, el error si falló y si parece que la cola de video no
     * está corriendo. null si no hay material activo.
     *
     * @return array{tipo: string, tipo_etiqueta: string, recurso_id: int|null, recurso_nombre: string|null, recurso_estado: string, recurso_estado_etiqueta: string, error: string|null, tamano_bytes: int|null, duracion_segundos: int|null, minutos_en_estado: int, cola_detenida: bool, puede_reprocesar: bool, listo: bool}|null
     *
     * @throws AuthorizationException sin permiso de gestión de onboarding
     */
    public function estadoParaRh(User $actor): ?array
    {
        $this->exigirPermiso($actor);

        $modulo = $this->moduloInstitucional();
        $material = $modulo !== null ? $this->materialActivo($modulo) : null;

        if ($material === null) {
            return null;
        }

        $recurso = $material->recurso;
        $esVideo = $material->tipo->bloqueaEvaluacion();

        return [
            'tipo' => $material->tipo->value,
            'tipo_etiqueta' => $material->tipo->etiqueta(),
            'recurso_id' => $recurso?->id,
            'recurso_nombre' => $recurso?->nombre_original,
            'recurso_estado' => $recurso?->estado->value ?? 'desconocido',
            'recurso_estado_etiqueta' => $recurso?->estado->etiqueta() ?? 'Desconocido',
            'error' => $recurso?->estado === EstadoMultimedia::Error ? $recurso->error_procesamiento : null,
            'tamano_bytes' => $recurso?->tamano_bytes,
            'duracion_segundos' => $recurso?->duracion_segundos,
            'minutos_en_estado' => $recurso !== null ? $this->procesamiento->minutosEnEstado($recurso) : 0,
            'cola_detenida' => $recurso !== null && $esVideo && $this->procesamiento->pareceColaDetenida($recurso),
            'puede_reprocesar' => $recurso !== null && $esVideo && $this->puedeConfigurar($actor) && $this->procesamiento->puedeReprocesarse($recurso)
                && ($recurso->estado === EstadoMultimedia::Error || $this->procesamiento->pareceColaDetenida($recurso)),
            'listo' => $recurso !== null && ($esVideo ? $recurso->estaDisponible() && $recurso->ruta_hls_manifiesto !== null : true),
        ];
    }

    /**
     * Al completarse una carga por bloques del material (video), activa el
     * recurso resultante como material vigente. null si la carga todavía no
     * terminó o terminó con error (el ensamblado ya dejó el motivo en la carga).
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function activarCargaCompletada(CargaMultimedia $carga, User $actor): ?InduccionMaterialExplicacion
    {
        $this->exigirPermisoConfigurar($actor);

        if ($carga->estado !== EstadoCargaMultimedia::Completada || $carga->recurso_multimedia_id === null) {
            return null;
        }

        $recurso = RecursoMultimedia::query()->where('id', $carga->recurso_multimedia_id)->firstOrFail();

        return $this->activar($this->moduloInstitucionalObligatorio(), $recurso, $actor);
    }

    /**
     * Vuelve a lanzar la conversión del video vigente (falló, o la cola no lo
     * tomó) sin volver a subirlo.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function reprocesarVideo(User $actor): void
    {
        $this->exigirPermisoConfigurar($actor);

        $recurso = $this->recursoVistaPreviaRh($actor, true);

        if ($recurso === null) {
            throw ValidationException::withMessages(['recurso' => 'El material vigente no es un video.']);
        }

        $this->procesamiento->reencolar($recurso);

        $this->auditoria->registrar('induccion_material_reprocesado', $recurso, $actor, [
            'recurso_multimedia_id' => $recurso->id,
        ]);
    }

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
     * Mientras el video no está listo (en carga, en cola, procesando o con
     * error) `video_disponible` es false y `video_estado`/`video_mensaje`
     * (aditivos) dicen por qué, para que la app muestre «Se está preparando»
     * en vez de intentar reproducir un manifiesto que aún no existe.
     *
     * @return array{tipo: string|null, audio_url: string|null, video_disponible: bool, video_estado: string|null, video_mensaje: string|null}
     */
    public function datosParaColaborador(OnboardingAvance $avance): array
    {
        $material = $this->materialActivoDeAvance($avance);

        if ($material === null) {
            return ['tipo' => null, 'audio_url' => null, 'video_disponible' => false, 'video_estado' => null, 'video_mensaje' => null];
        }

        if ($material->tipo->bloqueaEvaluacion()) {
            $listo = $this->videoListo($material->recurso);

            return [
                'tipo' => $material->tipo->value,
                'audio_url' => null,
                'video_disponible' => $listo,
                'video_estado' => $listo ? 'listo' : 'preparando',
                'video_mensaje' => $listo ? null : 'El video de la inducción se está preparando. Vuelve a intentarlo en unos minutos.',
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
            'video_estado' => null,
            'video_mensaje' => null,
        ];
    }

    /**
     * El video se puede reproducir por HLS: ya se convirtió («disponible») y
     * tiene manifiesto. Un video en carga, en cola, procesando, con error o
     * «disponible» sin manifiesto NO está listo (el reproductor fallaría).
     */
    public function videoListo(?RecursoMultimedia $recurso): bool
    {
        return $recurso !== null && $recurso->estaDisponible() && $recurso->ruta_hls_manifiesto !== null;
    }

    /**
     * Módulo institucional de la Inducción Mr. Lana (el que lleva la
     * evaluación formal FO-RH-EI), o null si no está configurado.
     */
    public function moduloInstitucional(): ?OnboardingModulo
    {
        return OnboardingModulo::query()
            ->whereNotNull('evaluacion_formal_clave')
            ->where('tipo', TipoModuloOnboarding::Institucional->value)
            ->where('activo', true)
            ->orderBy('orden')
            ->first();
    }

    /**
     * Vista previa para RH del material vigente (lo que verá/escuchará el
     * colaborador). RH no está sujeto al anti-adelanto ni registra avance:
     * las URLs firmadas apuntan a endpoints de RH que sirven el video HLS
     * completo y la narración original, sin tocar OnboardingAvance ni
     * induccion_video_intervalos.
     *
     * Mientras el HLS del video no está listo (en cola, procesando o con
     * error), `original_url` deja a RH ver el ARCHIVO ORIGINAL que subió
     * (solo RH: el colaborador siempre ve HLS con anti-adelanto).
     *
     * @return array{tipo: string, tipo_etiqueta: string, disponible: bool, audio_url: string|null, video_url: string|null, original_url: string|null, estado: string, error: string|null}|null
     *
     * @throws AuthorizationException sin permiso de gestión de onboarding
     */
    public function vistaPreviaRh(User $actor): ?array
    {
        $this->exigirPermiso($actor);

        $modulo = $this->moduloInstitucional();
        $material = $modulo !== null ? $this->materialActivo($modulo) : null;

        if ($material === null) {
            return null;
        }

        $recurso = $material->recurso;
        $esVideo = $material->tipo->bloqueaEvaluacion();
        $disponible = $recurso !== null && ($esVideo ? $recurso->estaDisponible() && $recurso->ruta_hls_manifiesto !== null : true);
        $caduca = now()->addSeconds((int) config('media.token_ttl'));

        return [
            'tipo' => $material->tipo->value,
            'tipo_etiqueta' => $material->tipo->etiqueta(),
            'disponible' => $disponible,
            'audio_url' => $disponible && ! $esVideo ? URL::temporarySignedRoute('rh.onboarding.induccion.material.vista-previa.audio', $caduca) : null,
            'video_url' => $disponible && $esVideo ? URL::temporarySignedRoute('rh.onboarding.induccion.material.vista-previa.maestro', $caduca) : null,
            'original_url' => ! $disponible && $esVideo && $recurso !== null ? URL::temporarySignedRoute('rh.onboarding.induccion.material.vista-previa.original', $caduca) : null,
            'estado' => $recurso?->estado->value ?? 'desconocido',
            'error' => $recurso?->estado === EstadoMultimedia::Error ? $recurso->error_procesamiento : null,
        ];
    }

    /**
     * Recurso del material vigente para la vista previa de RH: el video
     * ($video = true) o el audio; null si el vigente es de otro tipo o no hay.
     *
     * @throws AuthorizationException sin permiso de gestión de onboarding
     */
    public function recursoVistaPreviaRh(User $actor, bool $video): ?RecursoMultimedia
    {
        $this->exigirPermiso($actor);

        $modulo = $this->moduloInstitucional();
        $material = $modulo !== null ? $this->materialActivo($modulo) : null;

        if ($material === null || $material->tipo->bloqueaEvaluacion() !== $video) {
            return null;
        }

        return $material->recurso;
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
