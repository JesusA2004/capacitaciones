<?php

namespace App\Services\Onboarding;

use App\Enums\EstadoAvanceOnboarding;
use App\Enums\EstadoEntregaActivo;
use App\Enums\EstadoOnboarding;
use App\Enums\PrioridadTarea;
use App\Enums\TipoModuloOnboarding;
use App\Enums\TipoTarea;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\EntregaActivo;
use App\Models\OnboardingAvance;
use App\Models\OnboardingIntento;
use App\Models\OnboardingModulo;
use App\Models\OnboardingProceso;
use App\Models\Reingreso;
use App\Models\TipoActivo;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Auditoria\AuditoriaService;
use App\Services\CicloLaboral\ReingresoService;
use App\Services\Colaboradores\AltaColaboradorService;
use App\Services\DocumentosLaborales\MotorDocumentalService;
use App\Services\Tareas\NotificadorRhService;
use App\Services\Tareas\TareaService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Onboarding (Etapa 3). Condición de entrada: contratos requeridos firmados
 * (lo dispara AltaColaboradorService::recalcularEstado()).
 *
 *   A. Inducción institucional: material + evaluación, mínimo 8.
 *      < 8 → no avanza, se registra el intento, RH recibe tarea, da
 *      retroalimentación y habilita la reevaluación. Se conservan TODOS los
 *      intentos.
 *   B. Inducción al puesto: módulos del puesto en orden, mínimo 8 cada uno,
 *      sin saltar módulos obligatorios.
 *   C. El gerente registra la entrega presencial de los activos
 *      configurados (catálogo tipos_activo) y se generan las cartas
 *      responsivas (motor documental, plantilla de Jurídico/RH).
 *   → Onboarding completado → activación: inicia operación en campo.
 *
 * No es capacitación continua (Etapa 5): no usa el módulo de cursos.
 */
class OnboardingService
{
    public const PERMISO_VER = 'onboarding.ver';

    /** RH: retroalimentación, reevaluación, catálogo de módulos y activos. */
    public const PERMISO_GESTIONAR = 'onboarding.gestionar';

    /** Gerente: entrega presencial de activos y cierre del onboarding. */
    public const PERMISO_ENTREGAR = 'onboarding.entregar_activos';

    public function __construct(
        private readonly TareaService $tareas,
        private readonly NotificadorRhService $notificador,
        private readonly AuditoriaService $auditoria,
        private readonly MotorDocumentalService $motor,
        private readonly AlcanceOrganizacionalService $alcance,
    ) {}

    /**
     * Abre el onboarding del contrato (idempotente: un solo proceso por
     * contrato y uno solo abierto por persona).
     */
    public function iniciar(Colaborador $colaborador, ContratoLaboral $contrato, ?User $actor = null, ?Reingreso $reingreso = null): OnboardingProceso
    {
        $existente = OnboardingProceso::query()
            ->where('colaborador_id', $colaborador->id)
            ->where('contrato_laboral_id', $contrato->id)
            ->first();

        if ($existente !== null) {
            return $existente;
        }

        // Un onboarding que nace de un reingreso autorizado queda ligado a él.
        $reingreso ??= Reingreso::query()
            ->where('colaborador_abierto_id', $colaborador->id)
            ->where('contrato_laboral_id', $contrato->id)
            ->first();

        try {
            $proceso = DB::transaction(function () use ($colaborador, $contrato, $reingreso): OnboardingProceso {
                $proceso = OnboardingProceso::query()->create([
                    'colaborador_id' => $colaborador->id,
                    'contrato_laboral_id' => $contrato->id,
                    'reingreso_id' => $reingreso?->id,
                    'estado' => EstadoOnboarding::InduccionInstitucional,
                    'iniciado_en' => now(),
                    'colaborador_abierto_id' => $colaborador->id,
                ]);

                $repiteInstitucional = $reingreso === null || (bool) config('ciclo_laboral.reingreso.repite_induccion_institucional', true);
                $modulos = $this->modulosPara($colaborador, $repiteInstitucional);

                foreach ($modulos->values() as $indice => $modulo) {
                    OnboardingAvance::query()->create([
                        'onboarding_proceso_id' => $proceso->id,
                        'onboarding_modulo_id' => $modulo->id,
                        'tipo' => $modulo->tipo,
                        'orden' => $indice + 1,
                        'estado' => $indice === 0 ? EstadoAvanceOnboarding::Disponible : EstadoAvanceOnboarding::Bloqueado,
                    ]);
                }

                $proceso->update(['estado' => $this->estadoSegunAvances($proceso)]);

                return $proceso;
            });
        } catch (QueryException) {
            // Otro disparo simultáneo ya abrió el onboarding de esta persona.
            return OnboardingProceso::query()->where('colaborador_abierto_id', $colaborador->id)->firstOrFail();
        }

        $this->auditoria->registrar('onboarding_iniciado', $proceso, $actor, ['colaborador_id' => $colaborador->id, 'contrato_id' => $contrato->id]);
        $this->sincronizarPendientes($proceso->refresh());

        $usuario = $colaborador->user;

        if ($usuario !== null) {
            $this->notificador->notificar([$usuario], 'onboarding_habilitado', 'Tu inducción está habilitada', 'Revisa el material y presenta tu evaluación desde "Mi proceso".', $proceso, 'ver_onboarding', 'alta');
        }

        return $proceso;
    }

    /**
     * Califica en el servidor (la respuesta correcta nunca sale al cliente).
     *
     * @param  array<int|string, int|string>  $respuestas  índice de pregunta => índice de opción elegida
     */
    public function presentarEvaluacion(OnboardingAvance $avance, User $usuario, array $respuestas): OnboardingIntento
    {
        $intento = DB::transaction(function () use ($avance, $usuario, $respuestas): OnboardingIntento {
            $avance = OnboardingAvance::query()->lockForUpdate()->with(['modulo', 'proceso'])->findOrFail($avance->id);
            $proceso = $avance->proceso;

            if ($usuario->colaborador_id === null || $usuario->colaborador_id !== $proceso->colaborador_id) {
                throw new AuthorizationException('Solo el colaborador titular presenta su evaluación.');
            }

            if ($proceso->estado === EstadoOnboarding::Completado || $proceso->estado === EstadoOnboarding::Cancelado) {
                throw ValidationException::withMessages(['onboarding' => 'Este onboarding ya está cerrado.']);
            }

            if (! $avance->estado->permiteIntento()) {
                $mensaje = match ($avance->estado) {
                    EstadoAvanceOnboarding::Bloqueado => 'Primero aprueba el módulo anterior.',
                    EstadoAvanceOnboarding::RequiereRefuerzo => 'RH debe darte retroalimentación y habilitar la reevaluación antes de un nuevo intento.',
                    default => 'Este módulo ya está aprobado.',
                };

                throw ValidationException::withMessages(['onboarding' => $mensaje]);
            }

            $preguntas = $avance->modulo->preguntas ?? [];

            if ($preguntas === []) {
                throw ValidationException::withMessages(['onboarding' => 'Este módulo no tiene evaluación configurada. Contacta a RH.']);
            }

            $correctas = 0;

            foreach ($preguntas as $indice => $pregunta) {
                $elegida = $respuestas[$indice] ?? $respuestas[(string) $indice] ?? null;

                if ($elegida !== null && (int) $elegida === (int) ($pregunta['correcta'] ?? -1)) {
                    $correctas++;
                }
            }

            $calificacion = round(($correctas / count($preguntas)) * 10, 2);
            $minima = (float) $avance->modulo->calificacion_minima;
            $aprobado = $calificacion >= $minima;
            $numero = $avance->intentos_count + 1;

            $intento = OnboardingIntento::query()->create([
                'onboarding_avance_id' => $avance->id,
                'numero' => $numero,
                'calificacion' => $calificacion,
                'aprobado' => $aprobado,
                'respuestas' => array_map('intval', $respuestas),
                'retroalimentacion_previa' => $avance->retroalimentacion,
                'presentado_por' => $usuario->id,
            ]);

            $avance->update([
                'intentos_count' => $numero,
                'ultima_calificacion' => $calificacion,
                'mejor_calificacion' => max((float) ($avance->mejor_calificacion ?? 0), $calificacion),
                'estado' => $aprobado ? EstadoAvanceOnboarding::Aprobado : EstadoAvanceOnboarding::RequiereRefuerzo,
                'aprobado_en' => $aprobado ? now() : null,
            ]);

            if ($aprobado) {
                $siguiente = OnboardingAvance::query()
                    ->where('onboarding_proceso_id', $proceso->id)
                    ->where('estado', EstadoAvanceOnboarding::Bloqueado->value)
                    ->orderBy('orden')
                    ->first();

                $siguiente?->update(['estado' => EstadoAvanceOnboarding::Disponible]);
            }

            $proceso->update(['estado' => $this->estadoSegunAvances($proceso)]);

            return $intento;
        });

        $avance->refresh()->loadMissing(['modulo', 'proceso.colaborador']);
        $this->auditoria->registrar($intento->aprobado ? 'onboarding_modulo_aprobado' : 'onboarding_intento_reprobado', $avance, $usuario, [
            'colaborador_id' => $avance->proceso->colaborador_id,
            'modulo' => $avance->modulo->titulo,
            'intento' => $intento->numero,
            'calificacion' => (float) $intento->calificacion,
        ]);

        if (! $intento->aprobado) {
            $colaborador = $avance->proceso->colaborador;
            $this->notificador->notificar(
                $this->notificador->responsablesDe($colaborador, self::PERMISO_GESTIONAR),
                'onboarding_refuerzo',
                'Onboarding con resultado menor al mínimo',
                sprintf('%s obtuvo %s en «%s». Da retroalimentación y habilita la reevaluación.', $colaborador->nombreCompleto(), number_format((float) $intento->calificacion, 1), $avance->modulo->titulo),
                $avance,
                'retroalimentar_onboarding',
                'alta',
            );
        }

        $this->sincronizarPendientes($avance->proceso->refresh());

        return $intento;
    }

    /**
     * RH da retroalimentación/refuerzo y habilita la reevaluación.
     */
    public function retroalimentar(OnboardingAvance $avance, User $actor, string $retroalimentacion): OnboardingAvance
    {
        $avance->loadMissing('proceso.colaborador');
        $this->exigirPermiso($actor, self::PERMISO_GESTIONAR, $avance->proceso->colaborador);

        if (trim($retroalimentacion) === '') {
            throw ValidationException::withMessages(['retroalimentacion' => 'Escribe la retroalimentación para el colaborador.']);
        }

        $avance = DB::transaction(function () use ($avance, $actor, $retroalimentacion): OnboardingAvance {
            $avance = OnboardingAvance::query()->lockForUpdate()->findOrFail($avance->id);

            if ($avance->estado !== EstadoAvanceOnboarding::RequiereRefuerzo) {
                throw ValidationException::withMessages(['onboarding' => 'Este módulo no está esperando retroalimentación.']);
            }

            $avance->update([
                'estado' => EstadoAvanceOnboarding::ReevaluacionHabilitada,
                'retroalimentacion' => $retroalimentacion,
                'retroalimentado_por' => $actor->id,
                'retroalimentado_en' => now(),
            ]);

            return $avance;
        });

        $avance->loadMissing(['modulo', 'proceso.colaborador.user']);
        $this->auditoria->registrar('onboarding_retroalimentacion', $avance, $actor, ['colaborador_id' => $avance->proceso->colaborador_id, 'modulo' => $avance->modulo->titulo]);
        $this->sincronizarPendientes($avance->proceso);

        $usuario = $avance->proceso->colaborador->user;

        if ($usuario !== null) {
            $this->notificador->notificar([$usuario], 'onboarding_reevaluacion', 'Reevaluación habilitada', sprintf('RH dejó retroalimentación en «%s». Ya puedes presentar de nuevo.', $avance->modulo->titulo), $avance, 'ver_onboarding');
        }

        return $avance;
    }

    /**
     * Entrega presencial de un activo + carta responsiva.
     *
     * @param  array{tipo_activo_id: int, identificador?: string|null, descripcion?: string|null, entregado_en?: string|null, observaciones?: string|null}  $datos
     */
    public function entregarActivo(OnboardingProceso $proceso, User $actor, array $datos): EntregaActivo
    {
        $proceso->loadMissing('colaborador');
        $this->exigirPermiso($actor, self::PERMISO_ENTREGAR, $proceso->colaborador);

        $tipo = TipoActivo::query()->where('activo', true)->findOrFail($datos['tipo_activo_id']);

        if ($tipo->requiere_identificador && trim((string) ($datos['identificador'] ?? '')) === '') {
            throw ValidationException::withMessages(['identificador' => "Captura la serie/identificador de {$tipo->nombre}."]);
        }

        $entrega = DB::transaction(function () use ($proceso, $actor, $datos, $tipo): EntregaActivo {
            $proceso = OnboardingProceso::query()->lockForUpdate()->findOrFail($proceso->id);

            if ($proceso->estado !== EstadoOnboarding::EntregaActivos) {
                throw ValidationException::withMessages(['onboarding' => 'Los activos se entregan cuando el colaborador aprobó toda su inducción.']);
            }

            if (EntregaActivo::query()->where('onboarding_proceso_id', $proceso->id)->where('tipo_activo_id', $tipo->id)->exists()) {
                throw ValidationException::withMessages(['tipo_activo_id' => "{$tipo->nombre} ya fue entregado."]);
            }

            return EntregaActivo::query()->create([
                'colaborador_id' => $proceso->colaborador_id,
                'onboarding_proceso_id' => $proceso->id,
                'tipo_activo_id' => $tipo->id,
                'identificador' => $datos['identificador'] ?? null,
                'descripcion' => $datos['descripcion'] ?? null,
                'entregado_en' => $datos['entregado_en'] ?? now()->toDateString(),
                'entregado_por' => $actor->id,
                'estado' => EstadoEntregaActivo::Entregado,
                'observaciones' => $datos['observaciones'] ?? null,
            ]);
        });

        $this->auditoria->registrar('activo_entregado', $entrega, $actor, ['colaborador_id' => $entrega->colaborador_id, 'activo' => $tipo->nombre, 'identificador' => $entrega->identificador]);
        $this->generarResponsiva($entrega, $actor);
        $this->sincronizarPendientes($proceso->refresh());

        return $entrega->refresh();
    }

    /**
     * Genera (o reintenta) la carta responsiva. Sin plantilla cargada no se
     * inventa el documento: queda un pendiente explícito para RH/Jurídico.
     */
    public function generarResponsiva(EntregaActivo $entrega, User $actor): ?EntregaActivo
    {
        if ($entrega->generated_document_id !== null) {
            return $entrega;
        }

        $entrega->loadMissing(['tipo', 'colaborador']);

        try {
            $documento = $this->motor->generar($entrega->colaborador, $entrega->tipo->plantilla_responsiva, $actor, [
                'activo' => $entrega->tipo->nombre,
                'activo_identificador' => (string) $entrega->identificador,
                'activo_descripcion' => (string) $entrega->descripcion,
                'fecha_entrega' => $entrega->entregado_en->format('d/m/Y'),
            ], $entrega, sprintf('Carta responsiva · %s', $entrega->tipo->nombre));

            $entrega->update(['generated_document_id' => $documento->id]);
            $this->tareas->resolver(TipoTarea::PlantillaFaltante, $entrega, $actor);

            return $entrega;
        } catch (ValidationException $e) {
            $this->tareas->abrir(TipoTarea::PlantillaFaltante, $entrega, [
                'titulo' => sprintf('Falta la plantilla «%s» (responsiva de %s)', $entrega->tipo->plantilla_responsiva, $entrega->tipo->nombre),
                'descripcion' => collect($e->errors())->flatten()->implode(' '),
                'prioridad' => PrioridadTarea::Alta,
                'colaborador' => $entrega->colaborador,
                'permiso' => 'plantillas_documentales.administrar',
                'accion' => 'cargar_plantilla',
                'datos' => ['clave' => $entrega->tipo->plantilla_responsiva],
            ]);

            return null;
        }
    }

    /**
     * Cierra el onboarding: toda la inducción aprobada, todos los activos
     * obligatorios entregados con su responsiva. Activa al colaborador.
     */
    public function completar(OnboardingProceso $proceso, User $actor): OnboardingProceso
    {
        $proceso->loadMissing('colaborador');

        if (! $actor->can(self::PERMISO_ENTREGAR) && ! $actor->can(self::PERMISO_GESTIONAR)) {
            throw new AuthorizationException('No tienes permiso para cerrar el onboarding.');
        }

        $this->exigirAlcance($actor, $proceso->colaborador);

        $proceso = DB::transaction(function () use ($proceso, $actor): OnboardingProceso {
            $proceso = OnboardingProceso::query()->lockForUpdate()->findOrFail($proceso->id);

            if ($proceso->estado === EstadoOnboarding::Completado) {
                throw ValidationException::withMessages(['onboarding' => 'El onboarding ya está completado.']);
            }

            $bloqueos = $this->bloqueos($proceso);

            if ($bloqueos !== []) {
                throw ValidationException::withMessages(['onboarding' => implode(' ', $bloqueos)]);
            }

            $proceso->update([
                'estado' => EstadoOnboarding::Completado,
                'completado_en' => now(),
                'completado_por' => $actor->id,
                'colaborador_abierto_id' => null,
            ]);

            return $proceso;
        });

        $this->auditoria->registrar('onboarding_completado', $proceso, $actor, ['colaborador_id' => $proceso->colaborador_id]);
        $this->sincronizarPendientes($proceso);

        $colaborador = $proceso->colaborador->refresh();
        $altas = app(AltaColaboradorService::class);
        $altas->recalcularEstado($colaborador, $actor);
        $altas->activar($colaborador, $actor);

        if ($proceso->reingreso_id !== null) {
            app(ReingresoService::class)->alCompletarOnboarding($proceso, $actor);
        }

        return $proceso->refresh();
    }

    /**
     * Motivos concretos por los que el onboarding no puede cerrarse aún.
     *
     * @return list<string>
     */
    public function bloqueos(OnboardingProceso $proceso): array
    {
        $proceso->loadMissing(['colaborador', 'avances.modulo', 'entregas.tipo']);
        $bloqueos = [];

        $pendientes = $proceso->avances->filter(fn (OnboardingAvance $a) => $a->modulo->obligatorio && $a->estado !== EstadoAvanceOnboarding::Aprobado);

        if ($pendientes->isNotEmpty()) {
            $bloqueos[] = sprintf('Faltan módulos de inducción por aprobar: %s.', $pendientes->map(fn (OnboardingAvance $a) => $a->modulo->titulo)->implode(', '));
        }

        $entregados = $proceso->entregas->pluck('tipo_activo_id')->all();
        $faltantes = $this->activosRequeridos($proceso->colaborador)->reject(fn (TipoActivo $t) => in_array($t->id, $entregados, true));

        if ($faltantes->isNotEmpty()) {
            $bloqueos[] = sprintf('Faltan activos por entregar: %s.', $faltantes->pluck('nombre')->implode(', '));
        }

        $sinResponsiva = $proceso->entregas->filter(fn (EntregaActivo $e) => $e->generated_document_id === null);

        if ($sinResponsiva->isNotEmpty()) {
            $bloqueos[] = sprintf('Faltan cartas responsivas: %s (revisa que la plantilla esté cargada).', $sinResponsiva->map(fn (EntregaActivo $e) => $e->tipo->nombre)->implode(', '));
        }

        return $bloqueos;
    }

    /**
     * @return Collection<int, TipoActivo>
     */
    public function activosRequeridos(Colaborador $colaborador): Collection
    {
        return TipoActivo::query()
            ->where('activo', true)
            ->where('obligatorio', true)
            ->orderBy('nombre')
            ->get()
            ->filter(fn (TipoActivo $t) => $t->aplicaAPuesto($colaborador->puesto_id))
            ->values()
            ->toBase();
    }

    public function procesoActual(Colaborador $colaborador): ?OnboardingProceso
    {
        return OnboardingProceso::query()->where('colaborador_id', $colaborador->id)->latest('id')->first();
    }

    /**
     * DTO estable del onboarding para web, app del colaborador y app de RH.
     *
     * @return array<string, mixed>
     */
    public function aArray(OnboardingProceso $proceso, ?User $viewer = null, bool $incluirContenido = false): array
    {
        $proceso->loadMissing(['colaborador', 'avances.modulo', 'avances.intentos', 'entregas.tipo', 'entregas.responsiva', 'entregas.entregadoPor']);
        $esTitular = $viewer !== null && $viewer->colaborador_id === $proceso->colaborador_id;
        $puedeGestionar = $viewer !== null && $viewer->can(self::PERMISO_GESTIONAR) && $this->alcance->alcanzaColaborador($viewer, $proceso->colaborador);
        $puedeEntregar = $viewer !== null && $viewer->can(self::PERMISO_ENTREGAR) && $this->alcance->alcanzaColaborador($viewer, $proceso->colaborador);

        $modulos = $proceso->avances->map(function (OnboardingAvance $a) use ($esTitular, $puedeGestionar, $incluirContenido): array {
            $visible = $incluirContenido && ($a->estado !== EstadoAvanceOnboarding::Bloqueado);

            return [
                'avance_id' => $a->id,
                'modulo_id' => $a->onboarding_modulo_id,
                'titulo' => $a->modulo->titulo,
                'descripcion' => $a->modulo->descripcion,
                'tipo' => $a->tipo->value,
                'tipo_etiqueta' => $a->tipo->etiqueta(),
                'orden' => $a->orden,
                'obligatorio' => $a->modulo->obligatorio,
                'estado' => $a->estado->value,
                'estado_etiqueta' => $a->estado->etiqueta(),
                'calificacion_minima' => (float) $a->modulo->calificacion_minima,
                'ultima_calificacion' => $a->ultima_calificacion !== null ? (float) $a->ultima_calificacion : null,
                'mejor_calificacion' => $a->mejor_calificacion !== null ? (float) $a->mejor_calificacion : null,
                'intentos' => $a->intentos->map(fn (OnboardingIntento $i) => [
                    'numero' => $i->numero,
                    'calificacion' => (float) $i->calificacion,
                    'aprobado' => $i->aprobado,
                    'fecha' => $i->created_at?->toIso8601String(),
                    'retroalimentacion_previa' => $i->retroalimentacion_previa,
                ])->values()->all(),
                'retroalimentacion' => $a->retroalimentacion,
                'retroalimentado_en' => $a->retroalimentado_en?->toIso8601String(),
                'contenido_url' => $visible ? $a->modulo->contenido_url : null,
                'contenido' => $visible ? $a->modulo->contenido : null,
                'preguntas' => $visible && $esTitular && $a->estado->permiteIntento() ? $a->modulo->preguntasPublicas() : [],
                'puede_presentar' => $esTitular && $a->estado->permiteIntento(),
                'puede_retroalimentar' => $puedeGestionar && $a->estado === EstadoAvanceOnboarding::RequiereRefuerzo,
            ];
        })->values()->all();

        $entregados = $proceso->entregas->keyBy('tipo_activo_id');
        $activos = $this->activosRequeridos($proceso->colaborador)->map(function (TipoActivo $t) use ($entregados): array {
            /** @var EntregaActivo|null $entrega */
            $entrega = $entregados->get($t->id);

            return [
                'tipo_activo_id' => $t->id,
                'nombre' => $t->nombre,
                'requiere_identificador' => $t->requiere_identificador,
                'entregado' => $entrega !== null,
                'entrega' => $entrega !== null ? [
                    'id' => $entrega->id,
                    'identificador' => $entrega->identificador,
                    'entregado_en' => $entrega->entregado_en->toDateString(),
                    'entregado_por' => $entrega->entregadoPor?->nombreCompleto(),
                    'estado' => $entrega->estado->value,
                    'responsiva_documento_id' => $entrega->generated_document_id,
                    'responsiva_estado' => $entrega->responsiva?->estado_flujo?->etiqueta(),
                ] : null,
            ];
        })->values()->all();

        $institucional = $proceso->avances->where('tipo', TipoModuloOnboarding::Institucional);
        $puesto = $proceso->avances->where('tipo', TipoModuloOnboarding::Puesto);
        $aprobados = fn (Collection $c) => $c->every(fn (OnboardingAvance $a) => $a->estado === EstadoAvanceOnboarding::Aprobado);
        $bloqueos = $proceso->estado === EstadoOnboarding::Completado ? [] : $this->bloqueos($proceso);

        return [
            'id' => $proceso->id,
            'colaborador_id' => $proceso->colaborador_id,
            'estado' => $proceso->estado->value,
            'estado_etiqueta' => $proceso->estado->etiqueta(),
            'iniciado_en' => $proceso->iniciado_en->toIso8601String(),
            'completado_en' => $proceso->completado_en?->toIso8601String(),
            'checklist' => [
                ['clave' => 'contratos', 'etiqueta' => 'Contratos firmados', 'completado' => true],
                ['clave' => 'induccion_institucional', 'etiqueta' => 'Inducción institucional', 'completado' => $aprobados($institucional)],
                ['clave' => 'induccion_puesto', 'etiqueta' => 'Inducción al puesto', 'completado' => $aprobados($puesto)],
                ['clave' => 'activos', 'etiqueta' => 'Activos entregados', 'completado' => collect($activos)->every(fn (array $a) => $a['entregado'])],
                ['clave' => 'responsivas', 'etiqueta' => 'Cartas responsivas', 'completado' => $proceso->entregas->every(fn (EntregaActivo $e) => $e->generated_document_id !== null)],
            ],
            'modulos' => $modulos,
            'activos' => $activos,
            'bloqueos' => $bloqueos,
            'acciones' => [
                'entregar_activos' => $puedeEntregar && $proceso->estado === EstadoOnboarding::EntregaActivos,
                'completar' => ($puedeEntregar || $puedeGestionar) && $proceso->estado === EstadoOnboarding::EntregaActivos && $bloqueos === [],
            ],
        ];
    }

    /**
     * @return Collection<int, OnboardingModulo>
     */
    private function modulosPara(Colaborador $colaborador, bool $incluirInstitucional): Collection
    {
        $institucional = $incluirInstitucional
            ? OnboardingModulo::query()->where('activo', true)->where('tipo', TipoModuloOnboarding::Institucional->value)->orderBy('orden')->orderBy('id')->get()
            : collect();

        $puesto = OnboardingModulo::query()
            ->where('activo', true)
            ->where('tipo', TipoModuloOnboarding::Puesto->value)
            ->where(fn ($q) => $q->where('puesto_id', $colaborador->puesto_id)->orWhereNull('puesto_id'))
            ->orderBy('orden')
            ->orderBy('id')
            ->get();

        return $institucional->toBase()->merge($puesto->toBase());
    }

    private function estadoSegunAvances(OnboardingProceso $proceso): EstadoOnboarding
    {
        if ($proceso->estado === EstadoOnboarding::Completado) {
            return EstadoOnboarding::Completado;
        }

        $avances = OnboardingAvance::query()->where('onboarding_proceso_id', $proceso->id)->with('modulo:id,obligatorio')->get();
        $pendiente = fn (TipoModuloOnboarding $tipo) => $avances->contains(fn (OnboardingAvance $a) => $a->tipo === $tipo && $a->modulo->obligatorio && $a->estado !== EstadoAvanceOnboarding::Aprobado);

        return match (true) {
            $pendiente(TipoModuloOnboarding::Institucional) => EstadoOnboarding::InduccionInstitucional,
            $pendiente(TipoModuloOnboarding::Puesto) => EstadoOnboarding::InduccionPuesto,
            default => EstadoOnboarding::EntregaActivos,
        };
    }

    /**
     * Bandeja: un pendiente para el colaborador mientras tenga módulos por
     * presentar, para RH por cada módulo esperando refuerzo, y para el
     * gerente cuando toca entregar activos.
     */
    private function sincronizarPendientes(OnboardingProceso $proceso): void
    {
        try {
            $proceso->loadMissing(['colaborador.user', 'avances.modulo']);
            $colaborador = $proceso->colaborador;

            if ($proceso->estado === EstadoOnboarding::Completado || $proceso->estado === EstadoOnboarding::Cancelado) {
                $this->tareas->resolver([TipoTarea::OnboardingModuloPendiente, TipoTarea::OnboardingEntregaActivos], $proceso);

                return;
            }

            $porPresentar = $proceso->avances->contains(fn (OnboardingAvance $a) => $a->estado->permiteIntento());

            if ($porPresentar && $colaborador->user !== null) {
                $this->tareas->abrir(TipoTarea::OnboardingModuloPendiente, $proceso, [
                    'titulo' => 'Presenta tu inducción',
                    'colaborador' => $colaborador,
                    'usuario' => $colaborador->user,
                    'accion' => 'ver_onboarding',
                ]);
            } else {
                $this->tareas->resolver(TipoTarea::OnboardingModuloPendiente, $proceso);
            }

            foreach ($proceso->avances as $avance) {
                if ($avance->estado === EstadoAvanceOnboarding::RequiereRefuerzo) {
                    $this->tareas->abrir(TipoTarea::OnboardingRefuerzo, $avance, [
                        'titulo' => sprintf('Refuerzo de onboarding: %s · %s', $colaborador->nombreCompleto(), $avance->modulo->titulo),
                        'descripcion' => sprintf('Última calificación %s (mínimo %s).', number_format((float) $avance->ultima_calificacion, 1), number_format((float) $avance->modulo->calificacion_minima, 1)),
                        'prioridad' => PrioridadTarea::Alta,
                        'colaborador' => $colaborador,
                        'permiso' => self::PERMISO_GESTIONAR,
                        'accion' => 'retroalimentar_onboarding',
                    ]);
                } else {
                    $this->tareas->resolver(TipoTarea::OnboardingRefuerzo, $avance);
                }
            }

            if ($proceso->estado === EstadoOnboarding::EntregaActivos) {
                $jefe = $colaborador->jefe?->user;
                $tarea = $this->tareas->abrir(TipoTarea::OnboardingEntregaActivos, $proceso, [
                    'titulo' => sprintf('Entregar activos y responsivas: %s', $colaborador->nombreCompleto()),
                    'prioridad' => PrioridadTarea::Alta,
                    'colaborador' => $colaborador,
                    'usuario' => $jefe !== null && $jefe->can(self::PERMISO_ENTREGAR) ? $jefe : null,
                    'permiso' => $jefe !== null && $jefe->can(self::PERMISO_ENTREGAR) ? null : self::PERMISO_ENTREGAR,
                    'accion' => 'entregar_activos',
                ]);

                if ($tarea !== null && $tarea->wasRecentlyCreated) {
                    $destinatarios = $jefe !== null && $jefe->can(self::PERMISO_ENTREGAR) ? collect([$jefe]) : $this->notificador->responsablesDe($colaborador, self::PERMISO_ENTREGAR);
                    $this->notificador->notificar($destinatarios, 'onboarding_activos', 'Activos por entregar', sprintf('%s aprobó su inducción: entrega uniforme, equipo y responsivas.', $colaborador->nombreCompleto()), $proceso, 'entregar_activos', 'alta');
                }
            } else {
                $this->tareas->resolver(TipoTarea::OnboardingEntregaActivos, $proceso);
            }
        } catch (Throwable $e) {
            Log::warning('OnboardingService: no se pudo sincronizar la bandeja.', ['proceso_id' => $proceso->id, 'error' => $e->getMessage()]);
        }
    }

    private function exigirPermiso(User $actor, string $permiso, Colaborador $colaborador): void
    {
        if (! $actor->can($permiso)) {
            throw new AuthorizationException('No tienes permiso para esta acción de onboarding.');
        }

        $this->exigirAlcance($actor, $colaborador);
    }

    private function exigirAlcance(User $actor, Colaborador $colaborador): void
    {
        if (! $this->alcance->alcanzaColaborador($actor, $colaborador) || $actor->colaborador_id === $colaborador->id) {
            throw new AuthorizationException('Este colaborador está fuera de tu alcance.');
        }
    }
}
