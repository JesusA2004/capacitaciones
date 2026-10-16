<?php

namespace App\Services\Onboarding;

use App\Enums\EstadoEvaluacionInduccion;
use App\Enums\EstadoOnboarding;
use App\Enums\EstadoSesionInduccion;
use App\Enums\NivelEvaluacionInduccion;
use App\Enums\PrioridadTarea;
use App\Enums\TipoModuloOnboarding;
use App\Enums\TipoTarea;
use App\Models\Colaborador;
use App\Models\EvaluacionInduccion;
use App\Models\EvaluacionInduccionIntento;
use App\Models\OnboardingAvance;
use App\Models\OnboardingModulo;
use App\Models\OnboardingProceso;
use App\Models\SesionInduccion;
use App\Models\SesionInduccionColaborador;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Calendario\CalendarioLaboralService;
use App\Services\Tareas\NotificadorRhService;
use App\Services\Tareas\TareaService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Evaluación oficial del Programa de Inducción (FO-RH-EI, 30 puntos —
 * docs/onboarding/Evaluacion_Induccion_Mr_Lana 2026.pdf). Reemplaza, para los
 * módulos institucionales marcados con evaluacion_formal_clave, el quiz
 * genérico de OnboardingModulo/OnboardingService::presentarEvaluacion().
 *
 *   Contenido institucional visto (todos los avances obligatorios) →
 *   evaluación DISPONIBLE → el colaborador presenta (A/B/C/D + opinión) →
 *   A/B/C se califican en el servidor de inmediato → RH califica D con la
 *   rúbrica oficial → total/30:
 *     - >= 24  → Aprobada: aprueba TODOS los avances institucionales de esta
 *       clave y el onboarding avanza a inducción de puesto.
 *     - < 24   → Requiere reforzamiento: RH retroalimenta y habilita
 *       reevaluación; debe reaplicarse en máximo 5 días hábiles.
 *
 * La clave de respuestas (config/induccion_evaluacion.php) nunca sale de
 * este Service hacia un prop/response — ver preguntasPublicas().
 */
class EvaluacionInduccionService
{
    public function __construct(
        private readonly TareaService $tareas,
        private readonly NotificadorRhService $notificador,
        private readonly AuditoriaService $auditoria,
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly OnboardingService $onboarding,
        private readonly CalendarioLaboralService $calendario,
        private readonly InduccionPdfService $pdf,
        private readonly AccesoInduccionColaboradorService $acceso,
    ) {}

    public const TITULO_AVISO_PRESENTADA = 'Contesta la evaluación de la Inducción Mr. Lana';

    /**
     * Idempotente: crea (o devuelve) la evaluación formal del proceso cuando
     * todos los avances institucionales obligatorios de esa clave ya tienen
     * su contenido visto — por autoestudio (visor del PDF) o porque RH se la
     * PRESENTÓ en una sesión (SesionInduccionService, camino principal).
     * null si el proceso no tiene ningún módulo institucional con
     * evaluación formal configurada, o si falta contenido por ver.
     *
     * $sesion: la sesión de RH que acaba de presentarla (solo cambia el
     * texto del aviso al colaborador).
     */
    public function habilitarPara(OnboardingProceso $proceso, ?SesionInduccion $sesion = null): ?EvaluacionInduccion
    {
        $avances = $proceso->avances()
            ->whereHas('modulo', fn (Builder $q) => $q
                ->where('tipo', TipoModuloOnboarding::Institucional->value)
                ->whereNotNull('evaluacion_formal_clave'))
            ->with('modulo')
            ->get();

        if ($avances->isEmpty()) {
            return null;
        }

        // Hoy solo existe una evaluación formal institucional (la de
        // inducción); si en el futuro hay varias, esto itera por clave.
        $clave = (string) $avances->first()->modulo->evaluacion_formal_clave;

        $existente = EvaluacionInduccion::query()
            ->where('onboarding_proceso_id', $proceso->id)
            ->where('clave', $clave)
            ->first();

        if ($existente !== null) {
            return $existente;
        }

        $obligatorios = $avances->filter(fn (OnboardingAvance $a) => $a->modulo->obligatorio);
        $contenidoCompleto = $obligatorios->isNotEmpty() && $obligatorios->every(fn (OnboardingAvance $a) => $a->contenido_visto_en !== null);

        // RH ya le presentó la inducción (p. ej. ANTES de que su onboarding
        // existiera: asistencia «sin proceso de ingreso»): se aplica ahora
        // esa presentación, una sola vez y de forma auditable.
        if (! $contenidoCompleto && $obligatorios->isNotEmpty()) {
            $previa = $sesion ?? $this->sesionPresentadaPara($proceso->colaborador_id);

            if ($previa !== null) {
                $this->registrarPresentacionRh($proceso, $previa);
                $sesion = $previa;
                $contenidoCompleto = true;
            }
        }

        if (! $contenidoCompleto) {
            return null;
        }

        $evaluacion = EvaluacionInduccion::query()->create([
            'onboarding_proceso_id' => $proceso->id,
            'colaborador_id' => $proceso->colaborador_id,
            'clave' => $clave,
            'version' => (string) config('induccion_evaluacion.version'),
            'estado' => EstadoEvaluacionInduccion::Disponible,
        ]);

        $proceso->loadMissing('colaborador.user');
        $usuario = $proceso->colaborador->user;

        if ($usuario !== null) {
            $this->tareas->abrir(TipoTarea::InduccionEvaluacionPendiente, $evaluacion, [
                'titulo' => $sesion !== null ? self::TITULO_AVISO_PRESENTADA : 'Presenta tu evaluación de inducción',
                'colaborador' => $proceso->colaborador,
                'usuario' => $usuario,
                'accion' => 'presentar_evaluacion_induccion',
            ]);
            $this->notificarDisponible($usuario, $evaluacion, $sesion !== null);
        }

        return $evaluacion;
    }

    /**
     * Aviso (notificación + push) de que ya puede contestar su evaluación.
     * La capa de notificaciones nunca lanza ni deshace la acción principal.
     */
    public function notificarDisponible(User $usuario, EvaluacionInduccion $evaluacion, bool $presentadaPorRh): void
    {
        $this->notificador->notificar(
            [$usuario],
            'induccion_evaluacion_disponible',
            $presentadaPorRh ? self::TITULO_AVISO_PRESENTADA : 'Ya puedes presentar tu evaluación de inducción',
            $presentadaPorRh
                ? 'Recursos Humanos te presentó la Inducción Mr. Lana. Entra a tu app y contesta tu evaluación: al aprobarla quedas listo para empezar a laborar.'
                : 'Entra a "Mis pendientes" para responderla.',
            $evaluacion,
            'presentar_evaluacion_induccion',
            'alta',
        );
    }

    /**
     * Da por cumplido (contenido visto) el contenido institucional con
     * evaluación formal de este proceso porque RH se lo PRESENTÓ. Idempotente
     * y auditable: deja en progreso_contenido.presentada_por_rh la sesión,
     * quién la presentó y cuándo. Nunca aprueba el avance (eso lo hace la
     * evaluación). Devuelve cuántos avances cambiaron.
     */
    public function registrarPresentacionRh(OnboardingProceso $proceso, SesionInduccion $sesion): int
    {
        $avances = $proceso->avances()
            ->whereHas('modulo', fn (Builder $q) => $q
                ->where('tipo', TipoModuloOnboarding::Institucional->value)
                ->whereNotNull('evaluacion_formal_clave'))
            ->get();
        $marcados = 0;

        foreach ($avances as $avance) {
            $progreso = (array) ($avance->progreso_contenido ?? []);

            if (isset($progreso['presentada_por_rh']) && $avance->contenido_visto_en !== null) {
                continue;
            }

            $progreso['presentada_por_rh'] = [
                'sesion_id' => $sesion->id,
                'presentada_por' => $sesion->presentada_por,
                'presentada_en' => ($sesion->presentada_en ?? now('America/Mexico_City'))->toIso8601String(),
            ];

            $avance->update([
                'contenido_visto_en' => $avance->contenido_visto_en ?? now(),
                'progreso_contenido' => $progreso,
            ]);
            $marcados++;
        }

        return $marcados;
    }

    /**
     * Última sesión PRESENTADA en la que estuvo este colaborador.
     */
    private function sesionPresentadaPara(int $colaboradorId): ?SesionInduccion
    {
        $asistencia = SesionInduccionColaborador::query()
            ->where('colaborador_id', $colaboradorId)
            ->whereHas('sesion', fn (Builder $q) => $q->where('estado', EstadoSesionInduccion::Presentada->value))
            ->with('sesion')
            ->latest('id')
            ->first();

        return $asistencia?->sesion;
    }

    /**
     * Resumen del contenido (capítulos) de la inducción institucional para
     * "Mi proceso" — null si este proceso no tiene el módulo institucional
     * con evaluación formal (hoy solo 'induccion_mr_lana_2026').
     *
     * El avance se mide por PÁGINAS del PDF oficial (visor); los campos de
     * capítulos se conservan para versiones anteriores de la app.
     *
     * @return array{avance_id: int, titulo: string, total_paginas: int, paginas_vistas: list<int>, ultima_pagina: int|null, total_capitulos: int, capitulos_vistos: int, claves_vistas: list<string>, porcentaje: int, ultima_capitulo: string|null, completado: bool, presentada_por_rh_en: string|null}|null
     */
    public function resumenContenido(OnboardingProceso $proceso): ?array
    {
        $avance = $proceso->avances()
            ->whereHas('modulo', fn (Builder $q) => $q->whereNotNull('evaluacion_formal_clave'))
            ->with('modulo')
            ->first();

        if ($avance === null) {
            return null;
        }

        $totalCapitulos = count($avance->modulo->contenido_estructurado);
        $progreso = (array) ($avance->progreso_contenido ?? []);

        $completado = $avance->contenido_visto_en !== null;
        $presentada = is_array($progreso['presentada_por_rh'] ?? null) ? $progreso['presentada_por_rh'] : null;

        return [
            'avance_id' => $avance->id,
            'titulo' => $avance->modulo->titulo,
            'total_paginas' => $this->pdf->totalPaginas(),
            'paginas_vistas' => array_values(array_map('intval', (array) ($progreso['paginas_vistas'] ?? []))),
            'ultima_pagina' => isset($progreso['ultima_pagina']) ? (int) $progreso['ultima_pagina'] : null,
            'total_capitulos' => $totalCapitulos,
            'capitulos_vistos' => count((array) ($progreso['capitulos_vistos'] ?? [])),
            // Claves exactas (se puede saltar entre capítulos: el conteo solo
            // no dice cuáles faltan).
            'claves_vistas' => array_values(array_map('strval', (array) ($progreso['capitulos_vistos'] ?? []))),
            'porcentaje' => $completado ? 100 : (int) ($progreso['porcentaje'] ?? 0),
            'ultima_capitulo' => $progreso['ultima_capitulo'] ?? null,
            'completado' => $completado,
            // RH se la presentó en una sesión: fecha ISO, o null.
            'presentada_por_rh_en' => $presentada !== null ? (string) ($presentada['presentada_en'] ?? '') : null,
        ];
    }

    /**
     * Capítulos del módulo de un avance — valida que el avance pertenezca al
     * colaborador autenticado antes de devolver el contenido.
     *
     * @return list<array<mixed, mixed>>
     */
    public function capitulos(OnboardingAvance $avance, User $usuario): array
    {
        $avance->loadMissing('proceso', 'modulo');

        if ($usuario->colaborador_id === null || $usuario->colaborador_id !== $avance->proceso->colaborador_id) {
            throw new AuthorizationException('Solo el colaborador titular consulta su propio contenido de inducción.');
        }

        // La inducción la presenta RH: solo se ve durante su sesión.
        $this->acceso->exigirContenidoVisible($usuario);

        return $avance->modulo->contenido_estructurado;
    }

    /**
     * Capítulos de la inducción institucional para PRESENTARLA (RH en una
     * sesión por Meet) o verla (el colaborador, solo DURANTE la sesión de RH
     * que lo incluye): mismo contenido real del módulo con evaluación
     * formal, sin ligarlo a un avance — no registra progreso ni toca la
     * evaluación. Contenido institucional, sin datos personales ni clave de
     * respuestas.
     *
     * @return array{titulo: string, capitulos: list<array<mixed, mixed>>}|null
     *
     * @throws AuthorizationException
     */
    public function presentacion(User $usuario): ?array
    {
        $this->acceso->exigirPdfVisible($usuario);

        $modulo = OnboardingModulo::query()
            ->whereNotNull('evaluacion_formal_clave')
            ->where('activo', true)
            ->orderBy('orden')
            ->first();

        if ($modulo === null || $modulo->contenido_estructurado === []) {
            return null;
        }

        return ['titulo' => $modulo->titulo, 'capitulos' => $modulo->contenido_estructurado];
    }

    /**
     * Preguntas/afirmaciones SIN la respuesta correcta y SIN la rúbrica de
     * los casos prácticos — lo único que debe llegar al colaborador.
     *
     * @return array<string, mixed>
     */
    public function contenidoPublico(): array
    {
        return [
            'version' => config('induccion_evaluacion.version'),
            'instrucciones' => [
                'calificacion_aprobatoria' => config('induccion_evaluacion.calificacion_aprobatoria'),
                'total_puntos' => config('induccion_evaluacion.total_puntos'),
            ],
            'seccion_a' => collect((array) config('induccion_evaluacion.seccion_a', []))
                ->map(fn (array $p, int $i) => ['indice' => $i, 'pregunta' => $p['pregunta'], 'opciones' => $p['opciones']])
                ->values()->all(),
            'seccion_b' => collect((array) config('induccion_evaluacion.seccion_b', []))
                ->map(fn (array $p, int $i) => ['indice' => $i, 'afirmacion' => $p['afirmacion']])
                ->values()->all(),
            'seccion_c' => [
                'conceptos' => collect((array) config('induccion_evaluacion.seccion_c.conceptos', []))
                    ->map(fn (array $c, int $i) => ['indice' => $i, 'concepto' => $c['concepto']])
                    ->values()->all(),
                'descripciones' => config('induccion_evaluacion.seccion_c.descripciones', []),
            ],
            'seccion_d' => collect((array) config('induccion_evaluacion.seccion_d', []))
                ->map(fn (array $c) => ['clave' => $c['clave'], 'puntos_max' => $c['puntos_max'], 'enunciado' => $c['enunciado']])
                ->values()->all(),
            'seccion_e' => config('induccion_evaluacion.seccion_e'),
        ];
    }

    /**
     * Califica A/B/C en el servidor y deja D pendiente de RH.
     *
     * @param  array{a?: array<int, int>, b?: array<int, bool>, c?: array<int, string>, d?: array<string, string>}  $respuestas
     * @param  array{claridad?: int, conocimiento_puesto?: int, bienvenida?: int, tema_reforzar?: string|null}|null  $opinion
     */
    public function presentar(EvaluacionInduccion $evaluacion, User $colaborador, array $respuestas, ?array $opinion): EvaluacionInduccionIntento
    {
        $intento = DB::transaction(function () use ($evaluacion, $colaborador, $respuestas, $opinion): EvaluacionInduccionIntento {
            $evaluacion = EvaluacionInduccion::query()->lockForUpdate()->findOrFail($evaluacion->id);

            if ($colaborador->colaborador_id === null || $colaborador->colaborador_id !== $evaluacion->colaborador_id) {
                throw new AuthorizationException('Solo el colaborador titular presenta su evaluación de inducción.');
            }

            if (! $evaluacion->estado->permitePresentar()) {
                throw ValidationException::withMessages(['evaluacion' => "Esta evaluación está «{$evaluacion->estado->etiqueta()}»."]);
            }

            $calificacionA = $this->calificarSeccionA((array) ($respuestas['a'] ?? []));
            $calificacionB = $this->calificarSeccionB((array) ($respuestas['b'] ?? []));
            $calificacionC = $this->calificarSeccionC((array) ($respuestas['c'] ?? []));
            $this->validarRespuestasD((array) ($respuestas['d'] ?? []));

            $numero = $evaluacion->intentos_count + 1;

            $intento = EvaluacionInduccionIntento::query()->create([
                'evaluacion_induccion_id' => $evaluacion->id,
                'numero' => $numero,
                'respuestas' => [
                    'a' => (array) ($respuestas['a'] ?? []),
                    'b' => (array) ($respuestas['b'] ?? []),
                    'c' => (array) ($respuestas['c'] ?? []),
                    'd' => (array) ($respuestas['d'] ?? []),
                ],
                'opinion' => $opinion !== null ? [
                    'claridad' => isset($opinion['claridad']) ? (int) $opinion['claridad'] : null,
                    'conocimiento_puesto' => isset($opinion['conocimiento_puesto']) ? (int) $opinion['conocimiento_puesto'] : null,
                    'bienvenida' => isset($opinion['bienvenida']) ? (int) $opinion['bienvenida'] : null,
                    'tema_reforzar' => isset($opinion['tema_reforzar']) ? trim((string) $opinion['tema_reforzar']) : null,
                ] : null,
                'calificacion_a' => $calificacionA,
                'calificacion_b' => $calificacionB,
                'calificacion_c' => $calificacionC,
                'retroalimentacion_previa' => $evaluacion->retroalimentacion,
                'presentado_por' => $colaborador->id,
                'presentado_en' => now('America/Mexico_City'),
            ]);

            $evaluacion->update(['intentos_count' => $numero, 'estado' => EstadoEvaluacionInduccion::PendienteCalificacionRh]);

            return $intento;
        });

        $evaluacion->refresh()->loadMissing('colaborador');
        $this->tareas->resolver(TipoTarea::InduccionEvaluacionPendiente, $evaluacion, $colaborador);
        $this->tareas->abrir(TipoTarea::InduccionCasosPorCalificar, $intento, [
            'titulo' => "Calificar casos prácticos de inducción: {$evaluacion->colaborador->nombreCompleto()}",
            'prioridad' => PrioridadTarea::Alta,
            'colaborador' => $evaluacion->colaborador,
            'permiso' => OnboardingService::PERMISO_GESTIONAR,
            'accion' => 'calificar_induccion',
        ]);
        $this->notificador->notificarEvento(
            'induccion_evaluacion_presentada',
            $evaluacion->colaborador,
            ['creador' => $colaborador, 'excluir' => [$colaborador->id]],
            'Evaluación de inducción por calificar',
            "{$evaluacion->colaborador->nombreCompleto()} presentó su evaluación de inducción: faltan los casos prácticos.",
            $intento,
            'calificar_induccion',
            'alta',
        );
        $this->auditoria->registrar('induccion_evaluacion_presentada', $intento, $colaborador, [
            'colaborador_id' => $evaluacion->colaborador_id,
            'intento' => $intento->numero,
            'calificacion_abc' => (float) $intento->calificacion_a + (float) $intento->calificacion_b + (float) $intento->calificacion_c,
        ]);

        return $intento->refresh();
    }

    /**
     * RH captura los puntos de la rúbrica oficial de los casos prácticos
     * (sección D) y cierra el intento: calcula el total, el nivel y, si
     * aprueba, avanza el onboarding.
     *
     * @param  array<string, array<string, bool>>  $puntosPorCriterio  {caso_26: {identifica_malversacion: true, ...}, caso_27: {...}}
     */
    public function calificarCasosPracticos(EvaluacionInduccionIntento $intento, User $actor, array $puntosPorCriterio, ?string $comentario = null): EvaluacionInduccionIntento
    {
        $intento->loadMissing('evaluacion.colaborador');
        $this->exigirPermiso($actor, $intento->evaluacion->colaborador);

        [$intento, $evaluacion] = DB::transaction(function () use ($intento, $actor, $puntosPorCriterio, $comentario): array {
            $intento = EvaluacionInduccionIntento::query()->lockForUpdate()->findOrFail($intento->id);

            if (! $intento->pendienteCalificacionRh()) {
                throw ValidationException::withMessages(['evaluacion' => 'Este intento ya fue calificado.']);
            }

            $evaluacion = EvaluacionInduccion::query()->lockForUpdate()->findOrFail($intento->evaluacion_induccion_id);

            [$calificacionD, $desglose] = $this->calificarSeccionD($puntosPorCriterio);
            $total = round((float) $intento->calificacion_a + (float) $intento->calificacion_b + (float) $intento->calificacion_c + $calificacionD, 2);
            $nivel = NivelEvaluacionInduccion::paraCalificacion($total);

            $intento->update([
                'calificacion_d' => $calificacionD,
                'desglose_d' => $desglose,
                'comentario_rh' => $comentario !== null && trim($comentario) !== '' ? trim($comentario) : null,
                'calificacion_total' => $total,
                'nivel' => $nivel,
                'aprobado' => $nivel->aprobado(),
                'calificado_por' => $actor->id,
                'calificado_en' => now('America/Mexico_City'),
            ]);

            $evaluacion->update([
                'ultima_calificacion' => $total,
                'mejor_calificacion' => max((float) ($evaluacion->mejor_calificacion ?? 0), $total),
            ]);

            if ($nivel->aprobado()) {
                $evaluacion->update(['estado' => EstadoEvaluacionInduccion::Aprobada, 'aprobada_en' => now('America/Mexico_City'), 'fecha_limite_reevaluacion' => null]);

                if ($proceso = OnboardingProceso::query()->find($evaluacion->onboarding_proceso_id)) {
                    if ($proceso->estado !== EstadoOnboarding::Completado && $proceso->estado !== EstadoOnboarding::Cancelado) {
                        $this->onboarding->aprobarAvancesFormal($proceso, $evaluacion->clave);
                    }
                }
            } else {
                $evaluacion->update([
                    'estado' => EstadoEvaluacionInduccion::RequiereReforzamiento,
                    'fecha_limite_reevaluacion' => $this->calendario->sumarDiasHabiles(now('America/Mexico_City'), (int) config('induccion_evaluacion.dias_habiles_reevaluacion', 5)),
                ]);
            }

            return [$intento, $evaluacion->refresh()];
        });

        $this->tareas->resolver(TipoTarea::InduccionCasosPorCalificar, $intento, $actor);
        $evaluacion->loadMissing('colaborador.user', 'colaborador.jefe.user');
        $colaboradorUsuario = $evaluacion->colaborador->user;

        if ($evaluacion->estado === EstadoEvaluacionInduccion::Aprobada) {
            $proceso = OnboardingProceso::query()->find($evaluacion->onboarding_proceso_id);

            if ($proceso !== null) {
                $this->onboarding->sincronizarBandeja($proceso);
                // Aprobada la inducción: si ya no queda nada pendiente del
                // onboarding (módulos de puesto, activos), se cierra y el
                // colaborador queda activo — empieza a laborar.
                $this->onboarding->cerrarSiListo($proceso, $actor);
            }
        }

        if ($evaluacion->estado === EstadoEvaluacionInduccion::Aprobada) {
            if ($colaboradorUsuario !== null) {
                $this->notificador->notificar([$colaboradorUsuario], 'induccion_evaluacion_aprobada', '¡Aprobaste tu evaluación de inducción!', sprintf('Obtuviste %s/30 (%s).', number_format((float) $intento->calificacion_total, 1), $intento->nivel?->etiqueta()), $intento, 'ver_evaluacion_induccion', 'alta');
            }
            $this->notificador->notificarEvento('induccion_evaluacion_aprobada', $evaluacion->colaborador, ['creador' => $actor, 'excluir' => [$actor->id]], 'Inducción completada', "{$evaluacion->colaborador->nombreCompleto()} aprobó su evaluación de inducción.", $intento, 'ver_onboarding_colaborador');
            // PDF del formato al expediente (try/catch + Log::warning adentro: nunca deshace la calificación).
            app(EvaluacionInduccionPdfService::class)->adjuntarSiAprobada($intento->refresh(), $actor);
        } else {
            $this->tareas->abrir(TipoTarea::InduccionReforzamiento, $evaluacion, [
                'titulo' => "Reforzamiento de inducción: {$evaluacion->colaborador->nombreCompleto()}",
                'descripcion' => sprintf('Obtuvo %s/30 (mínimo 24). Reaplicar antes del %s.', number_format((float) $intento->calificacion_total, 1), $evaluacion->fecha_limite_reevaluacion?->toDateString()),
                'prioridad' => PrioridadTarea::Urgente,
                'colaborador' => $evaluacion->colaborador,
                'permiso' => OnboardingService::PERMISO_GESTIONAR,
                'accion' => 'retroalimentar_induccion',
            ]);
            $this->notificador->notificarEvento('induccion_requiere_reforzamiento', $evaluacion->colaborador, ['creador' => $actor, 'excluir' => [$actor->id]], 'Reforzamiento de inducción requerido', sprintf('%s obtuvo %s/30. Da retroalimentación y habilita la reevaluación (máximo 5 días hábiles).', $evaluacion->colaborador->nombreCompleto(), number_format((float) $intento->calificacion_total, 1)), $intento, 'retroalimentar_induccion', 'urgente');

            if ($colaboradorUsuario !== null) {
                $this->notificador->notificar([$colaboradorUsuario], 'induccion_requiere_reforzamiento', 'Sigamos reforzando tu inducción', 'Recursos Humanos te contactará para reforzar algunos temas antes de repetir la evaluación.', $intento, 'ver_evaluacion_induccion');
            }
        }

        $this->auditoria->registrar('induccion_casos_calificados', $intento, $actor, [
            'colaborador_id' => $evaluacion->colaborador_id,
            'calificacion_total' => (float) $intento->calificacion_total,
            'nivel' => $intento->nivel?->value,
        ]);

        return $intento->refresh();
    }

    /**
     * RH retroalimenta un reforzamiento y habilita la reevaluación.
     */
    public function retroalimentar(EvaluacionInduccion $evaluacion, User $actor, string $retroalimentacion): EvaluacionInduccion
    {
        $evaluacion->loadMissing('colaborador');
        $this->exigirPermiso($actor, $evaluacion->colaborador);

        if (trim($retroalimentacion) === '') {
            throw ValidationException::withMessages(['retroalimentacion' => 'Escribe la retroalimentación para el colaborador.']);
        }

        $evaluacion = DB::transaction(function () use ($evaluacion, $actor, $retroalimentacion): EvaluacionInduccion {
            $evaluacion = EvaluacionInduccion::query()->lockForUpdate()->findOrFail($evaluacion->id);

            if ($evaluacion->estado !== EstadoEvaluacionInduccion::RequiereReforzamiento) {
                throw ValidationException::withMessages(['evaluacion' => 'Esta evaluación no está esperando retroalimentación.']);
            }

            $evaluacion->update([
                'estado' => EstadoEvaluacionInduccion::ReevaluacionHabilitada,
                'retroalimentacion' => $retroalimentacion,
                'retroalimentado_por' => $actor->id,
                'retroalimentado_en' => now('America/Mexico_City'),
            ]);

            return $evaluacion;
        });

        $evaluacion->loadMissing('colaborador.user');
        $this->tareas->resolver(TipoTarea::InduccionReforzamiento, $evaluacion, $actor);
        $this->auditoria->registrar('induccion_retroalimentacion', $evaluacion, $actor, ['colaborador_id' => $evaluacion->colaborador_id]);

        $usuario = $evaluacion->colaborador->user;

        if ($usuario !== null) {
            $this->tareas->abrir(TipoTarea::InduccionEvaluacionPendiente, $evaluacion, [
                'titulo' => 'Vuelve a presentar tu evaluación de inducción',
                'colaborador' => $evaluacion->colaborador,
                'usuario' => $usuario,
                'accion' => 'presentar_evaluacion_induccion',
            ]);
            $this->notificador->notificar([$usuario], 'induccion_reevaluacion_habilitada', 'Ya puedes volver a presentar tu evaluación', 'Recursos Humanos dejó retroalimentación. Revísala antes de responder de nuevo.', $evaluacion, 'presentar_evaluacion_induccion', 'alta');
        }

        return $evaluacion;
    }

    /**
     * DTO estable para web/app. $incluirCasosPracticos solo para RH (nunca
     * para el colaborador): trae enunciados + respuestas + rúbrica.
     *
     * @return array<string, mixed>
     */
    public function aArray(EvaluacionInduccion $evaluacion, ?User $viewer = null, bool $incluirCasosPracticos = false): array
    {
        $evaluacion->loadMissing(['colaborador.puesto', 'colaborador.sucursalPrincipal', 'colaborador.jefe', 'intentos']);
        $colaborador = $evaluacion->colaborador;
        $ultimoIntento = $evaluacion->intentos->last();

        return [
            'id' => $evaluacion->id,
            'colaborador' => [
                'id' => $colaborador->id,
                'nombre' => $colaborador->nombreCompleto(),
                'numero_empleado' => $colaborador->numero_empleado,
                'puesto' => $colaborador->puesto?->nombre,
                'sucursal' => $colaborador->sucursalPrincipal?->nombre,
                'fecha_ingreso' => $colaborador->fecha_ingreso?->toDateString(),
                'jefe_inmediato' => $colaborador->jefe?->nombreCompleto(),
            ],
            'estado' => $evaluacion->estado->value,
            'estado_etiqueta' => $evaluacion->estado->etiqueta(),
            'version' => $evaluacion->version,
            'intentos_count' => $evaluacion->intentos_count,
            'mejor_calificacion' => $evaluacion->mejor_calificacion !== null ? (float) $evaluacion->mejor_calificacion : null,
            'fecha_limite_reevaluacion' => $evaluacion->fecha_limite_reevaluacion?->toDateString(),
            'retroalimentacion' => $evaluacion->retroalimentacion,
            'puede_presentar' => $viewer !== null && $viewer->colaborador_id === $colaborador->id && $evaluacion->estado->permitePresentar(),
            'puede_calificar' => $viewer !== null && $viewer->can(OnboardingService::PERMISO_GESTIONAR) && $this->alcance->alcanzaColaborador($viewer, $colaborador),
            'intentos' => $evaluacion->intentos->map(fn (EvaluacionInduccionIntento $i) => [
                'id' => $i->id,
                'numero' => $i->numero,
                'presentado_en' => $i->presentado_en->toIso8601String(),
                'pendiente_calificacion_rh' => $i->pendienteCalificacionRh(),
                'calificacion_total' => $i->calificacion_total !== null ? (float) $i->calificacion_total : null,
                'nivel' => $i->nivel?->value,
                'nivel_etiqueta' => $i->nivel?->etiqueta(),
                'aprobado' => $i->aprobado,
                'calificado_en' => $i->calificado_en?->toIso8601String(),
            ])->values()->all(),
            'ultimo_intento_id' => $ultimoIntento?->id,
            'casos_practicos' => $incluirCasosPracticos && $ultimoIntento !== null ? $this->detalleCasosPracticos($ultimoIntento) : null,
        ];
    }

    /**
     * Detalle de los casos prácticos de un intento + rúbrica oficial — SOLO
     * para la pantalla de calificación de RH, nunca para el colaborador.
     *
     * @return array<int, array<string, mixed>>
     */
    public function detalleCasosPracticos(EvaluacionInduccionIntento $intento): array
    {
        $respuestas = (array) ($intento->respuestas['d'] ?? []);

        return collect((array) config('induccion_evaluacion.seccion_d', []))->map(fn (array $caso) => [
            'clave' => $caso['clave'],
            'enunciado' => $caso['enunciado'],
            'puntos_max' => $caso['puntos_max'],
            'respuesta' => (string) ($respuestas[$caso['clave']] ?? ''),
            'rubrica' => $caso['rubrica'],
            'desglose_previo' => $intento->desglose_d,
        ])->values()->all();
    }

    /**
     * Revisión completa de un intento — SOLO para RH (pantalla de revisión
     * y PDF del formato): cada pregunta con lo que contestó el colaborador,
     * la respuesta correcta y si acertó (A/B/C ya calificadas en el
     * servidor), los casos prácticos con su rúbrica, la opinión y el total.
     * Nunca se manda al colaborador.
     *
     * @return array{seccion_a: list<array<string, mixed>>, seccion_b: list<array<string, mixed>>, seccion_c: array<string, mixed>, seccion_d: list<array<string, mixed>>, opinion: array<string, mixed>|null, comentario_rh: string|null, puntos: array<string, float|null>, nivel: string|null, nivel_etiqueta: string|null, aprobado: bool|null, calificado: bool}
     */
    public function detalleRevision(EvaluacionInduccionIntento $intento): array
    {
        $respuestas = (array) $intento->respuestas;
        $a = (array) ($respuestas['a'] ?? []);
        $b = (array) ($respuestas['b'] ?? []);
        $c = (array) ($respuestas['c'] ?? []);
        $desglose = collect($intento->desglose_d ?? [])->mapWithKeys(fn (array $fila) => [$fila['clave'] => $fila['puntos']]);

        $seccionA = collect((array) config('induccion_evaluacion.seccion_a', []))->map(function (array $p, int $i) use ($a): array {
            $elegida = $a[$i] ?? $a[(string) $i] ?? null;

            return [
                'numero' => $i + 1,
                'pregunta' => $p['pregunta'],
                'opciones' => $p['opciones'],
                'elegida' => $elegida !== null ? (int) $elegida : null,
                'correcta' => (int) $p['correcta'],
                'acierto' => $elegida !== null && (int) $elegida === (int) $p['correcta'],
            ];
        })->values()->all();

        $baseB = count($seccionA);
        $seccionB = collect((array) config('induccion_evaluacion.seccion_b', []))->map(function (array $p, int $i) use ($b, $baseB): array {
            $elegida = $b[$i] ?? $b[(string) $i] ?? null;
            $valor = $elegida !== null ? filter_var($elegida, FILTER_VALIDATE_BOOLEAN) : null;

            return [
                'numero' => $baseB + $i + 1,
                'afirmacion' => $p['afirmacion'],
                'elegida' => $valor,
                'correcto' => (bool) $p['correcto'],
                'acierto' => $valor !== null && $valor === (bool) $p['correcto'],
            ];
        })->values()->all();

        $baseC = $baseB + count($seccionB);
        $seccionC = [
            'descripciones' => (array) config('induccion_evaluacion.seccion_c.descripciones', []),
            'conceptos' => collect((array) config('induccion_evaluacion.seccion_c.conceptos', []))->map(function (array $p, int $i) use ($c, $baseC): array {
                $elegida = $c[$i] ?? $c[(string) $i] ?? null;
                $letra = $elegida !== null ? strtoupper((string) $elegida) : null;

                return [
                    'numero' => $baseC + $i + 1,
                    'concepto' => $p['concepto'],
                    'elegida' => $letra,
                    'correcta' => strtoupper((string) $p['letra_correcta']),
                    'acierto' => $letra !== null && $letra === strtoupper((string) $p['letra_correcta']),
                ];
            })->values()->all(),
        ];

        $baseD = $baseC + count($seccionC['conceptos']);
        $respuestasD = (array) ($respuestas['d'] ?? []);
        $seccionD = collect((array) config('induccion_evaluacion.seccion_d', []))->map(fn (array $caso, int $i): array => [
            'numero' => $baseD + $i + 1,
            'clave' => $caso['clave'],
            'enunciado' => $caso['enunciado'],
            'puntos_max' => $caso['puntos_max'],
            'respuesta' => (string) ($respuestasD[$caso['clave']] ?? ''),
            'rubrica' => collect((array) $caso['rubrica'])->map(fn (array $r): array => [
                'clave' => $r['clave'],
                'puntos' => $r['puntos'],
                'criterio' => $r['criterio'],
                'otorgado' => $intento->desglose_d !== null ? ($desglose->get("{$caso['clave']}.{$r['clave']}", 0) > 0) : null,
            ])->values()->all(),
            'puntos_obtenidos' => $intento->desglose_d !== null
                ? collect((array) $caso['rubrica'])->sum(fn (array $r): int => $desglose->get("{$caso['clave']}.{$r['clave']}", 0))
                : null,
        ])->values()->all();

        return [
            'seccion_a' => array_values($seccionA),
            'seccion_b' => array_values($seccionB),
            'seccion_c' => $seccionC,
            'seccion_d' => array_values($seccionD),
            'opinion' => $intento->opinion,
            'comentario_rh' => $intento->comentario_rh,
            'puntos' => [
                'a' => (float) $intento->calificacion_a,
                'b' => (float) $intento->calificacion_b,
                'c' => (float) $intento->calificacion_c,
                'd' => $intento->calificacion_d !== null ? (float) $intento->calificacion_d : null,
                'total' => $intento->calificacion_total !== null ? (float) $intento->calificacion_total : null,
            ],
            'nivel' => $intento->nivel?->value,
            'nivel_etiqueta' => $intento->nivel?->etiqueta(),
            'aprobado' => $intento->aprobado,
            'calificado' => ! $intento->pendienteCalificacionRh(),
        ];
    }

    /**
     * @param  array<int|string, mixed>  $respuestas
     */
    private function calificarSeccionA(array $respuestas): float
    {
        $puntos = 0;

        foreach ((array) config('induccion_evaluacion.seccion_a', []) as $indice => $pregunta) {
            $elegida = $respuestas[$indice] ?? $respuestas[(string) $indice] ?? null;

            if ($elegida !== null && (int) $elegida === (int) $pregunta['correcta']) {
                $puntos++;
            }
        }

        return (float) $puntos;
    }

    /**
     * @param  array<int|string, mixed>  $respuestas
     */
    private function calificarSeccionB(array $respuestas): float
    {
        $puntos = 0;

        foreach ((array) config('induccion_evaluacion.seccion_b', []) as $indice => $afirmacion) {
            $elegida = $respuestas[$indice] ?? $respuestas[(string) $indice] ?? null;

            if ($elegida !== null && filter_var($elegida, FILTER_VALIDATE_BOOLEAN) === (bool) $afirmacion['correcto']) {
                $puntos++;
            }
        }

        return (float) $puntos;
    }

    /**
     * @param  array<int|string, mixed>  $respuestas
     */
    private function calificarSeccionC(array $respuestas): float
    {
        $puntos = 0;

        foreach ((array) config('induccion_evaluacion.seccion_c.conceptos', []) as $indice => $concepto) {
            $elegida = $respuestas[$indice] ?? $respuestas[(string) $indice] ?? null;

            if ($elegida !== null && strtoupper((string) $elegida) === strtoupper((string) $concepto['letra_correcta'])) {
                $puntos++;
            }
        }

        return (float) $puntos;
    }

    /**
     * @param  array<string, mixed>  $respuestas
     */
    private function validarRespuestasD(array $respuestas): void
    {
        foreach ((array) config('induccion_evaluacion.seccion_d', []) as $caso) {
            if (trim((string) ($respuestas[$caso['clave']] ?? '')) === '') {
                throw ValidationException::withMessages(["respuestas.d.{$caso['clave']}" => 'Responde el caso práctico antes de enviar la evaluación.']);
            }
        }
    }

    /**
     * @param  array<string, array<string, bool>>  $puntosPorCriterio
     * @return array{0: float, 1: array<int, array{clave: string, puntos: int}>}
     */
    private function calificarSeccionD(array $puntosPorCriterio): array
    {
        $total = 0;
        $desglose = [];

        foreach ((array) config('induccion_evaluacion.seccion_d', []) as $caso) {
            foreach ((array) $caso['rubrica'] as $criterio) {
                $otorgado = filter_var($puntosPorCriterio[$caso['clave']][$criterio['clave']] ?? false, FILTER_VALIDATE_BOOLEAN);
                $puntos = $otorgado ? (int) $criterio['puntos'] : 0;
                $total += $puntos;
                $desglose[] = ['clave' => "{$caso['clave']}.{$criterio['clave']}", 'puntos' => $puntos];
            }
        }

        return [(float) $total, $desglose];
    }

    private function exigirPermiso(User $actor, Colaborador $colaborador): void
    {
        if (! $actor->can(OnboardingService::PERMISO_GESTIONAR)) {
            throw new AuthorizationException('No tienes permiso para calificar la evaluación de inducción.');
        }

        if (! $this->alcance->alcanzaColaborador($actor, $colaborador) || $actor->colaborador_id === $colaborador->id) {
            throw new AuthorizationException('Este colaborador está fuera de tu alcance.');
        }
    }
}
