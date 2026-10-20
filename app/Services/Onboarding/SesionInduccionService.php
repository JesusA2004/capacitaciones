<?php

namespace App\Services\Onboarding;

use App\Enums\EstadoAltaColaborador;
use App\Enums\EstadoAvanceOnboarding;
use App\Enums\EstadoEvaluacionInduccion;
use App\Enums\EstadoOnboarding;
use App\Enums\EstadoSesionInduccion;
use App\Enums\EtapaSeguimientoInduccion;
use App\Enums\ResultadoAsistenciaInduccion;
use App\Enums\TipoModuloOnboarding;
use App\Models\Colaborador;
use App\Models\EvaluacionInduccion;
use App\Models\EvaluacionInduccionIntento;
use App\Models\OnboardingAvance;
use App\Models\OnboardingProceso;
use App\Models\SesionInduccion;
use App\Models\SesionInduccionColaborador;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Auditoria\AuditoriaService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Sesiones de presentación de la Inducción Mr. Lana (camino principal de la
 * primera evaluación de ingreso):
 *
 *   1. ANTES de presentar, RH elige del buscador (dentro de su alcance del
 *      módulo «onboarding», nunca texto libre) a quién se la presenta — uno
 *      o varios, p. ej. un grupo por Meet → sesión «En curso».
 *   2. Presenta el PDF oficial («Presentar inducción»). Solo mientras la
 *      sesión está «En curso» cada asistente puede verlo en su «Mi
 *      espacio» (AccesoInduccionColaboradorService); antes y después, no.
 *   3. Al terminar, RH presiona «Enviar evaluación» (nada sale antes): por
 *      cada asistente, el contenido institucional
 *      queda visto por presentación de RH (auditable, idempotente), su
 *      evaluación oficial FO-RH-EI se HABILITA y se le avisa (notificación +
 *      push) que la conteste en la app.
 *   4. Contesta → RH califica D → ≥ 24/30 aprueba y, sin otros pendientes de
 *      onboarding, queda activo (Laborando); < 24 → reforzamiento
 *      (EvaluacionInduccionService).
 *
 * Misma regla para la web (Rh\SesionInduccionController) y la API
 * (Api\V1\Rh\SesionInduccionController).
 */
class SesionInduccionService
{
    public const PERMISO = OnboardingService::PERMISO_GESTIONAR;

    /** Módulo de alcance (config/permisos_modulos.php). */
    public const MODULO = 'onboarding';

    public function __construct(
        private readonly EvaluacionInduccionService $evaluaciones,
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly AuditoriaService $auditoria,
        private readonly AcuseDescriptivoPuestoService $acusesDescriptivo,
    ) {}

    public function puedeGestionar(User $usuario): bool
    {
        return $usuario->can(self::PERMISO);
    }

    /**
     * Buscador de asistentes: colaboradores no dados de baja dentro del
     * alcance de quien presenta; primero quienes están en su proceso de
     * ingreso. Sin mínimo de letras (lista completa al enfocar).
     *
     * @return list<array{id: int, nombre: string, numero_empleado: string|null, puesto: string|null, sucursal: string|null, en_ingreso: bool, etapa: string}>
     */
    public function buscarColaboradores(User $actor, string $termino): array
    {
        $this->exigirPermiso($actor);

        $palabras = array_slice(preg_split('/\s+/u', Str::lower(Str::ascii(trim($termino))), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 4);
        $consulta = $this->consultaElegibles($actor)->select(['id', 'name', 'apellidos', 'numero_empleado', 'estado_alta']);

        $ids = $consulta->orderBy('name')->orderBy('apellidos')->limit(3000)->get()
            ->filter(function (Colaborador $c) use ($palabras): bool {
                $texto = Str::lower(Str::ascii(sprintf('%s %s %s', $c->name, $c->apellidos, $c->numero_empleado)));

                return collect($palabras)->every(fn (string $p): bool => str_contains($texto, $p));
            })
            ->sortBy(fn (Colaborador $c): int => $c->estado_alta === EstadoAltaColaborador::Activo ? 1 : 0)
            ->take(30)
            ->pluck('id')
            ->all();

        $abiertos = OnboardingProceso::query()
            ->whereIn('colaborador_id', $ids)
            ->whereNotIn('estado', [EstadoOnboarding::Completado->value, EstadoOnboarding::Cancelado->value])
            ->pluck('colaborador_id')
            ->all();

        return array_values(Colaborador::query()
            ->with(['puesto:id,nombre', 'sucursalPrincipal:id,nombre'])
            ->whereIn('id', $ids)
            ->get()
            ->sortBy(fn (Colaborador $c): string => sprintf('%d %s', in_array($c->id, $abiertos, true) ? 0 : 1, Str::lower(Str::ascii($c->nombreCompleto()))))
            ->map(fn (Colaborador $c): array => [
                'id' => $c->id,
                'nombre' => $c->nombreCompleto(),
                'numero_empleado' => $c->numero_empleado,
                'puesto' => $c->puesto?->nombre,
                'sucursal' => $c->sucursalPrincipal?->nombre,
                'en_ingreso' => in_array($c->id, $abiertos, true),
                'etapa' => in_array($c->id, $abiertos, true) ? 'En proceso de ingreso' : ($c->estado_alta?->etiqueta() ?? 'Activo'),
            ])
            ->values()
            ->all());
    }

    /**
     * Registra la sesión ANTES de presentar: quién, cuándo y a quién.
     *
     * @param  array<int, int|string>  $colaboradorIds
     *
     * @throws AuthorizationException sin permiso
     * @throws ValidationException lista vacía, persona inexistente, dada de baja o fuera de alcance
     */
    public function iniciar(User $actor, array $colaboradorIds, ?string $notas = null): SesionInduccion
    {
        $this->exigirPermiso($actor);

        $ids = collect($colaboradorIds)->map(fn ($id): int => (int) $id)->filter(fn (int $id): bool => $id > 0)->unique()->values();

        if ($ids->isEmpty()) {
            throw ValidationException::withMessages(['colaborador_ids' => 'Elige al menos a un colaborador al que le presentarás la inducción.']);
        }

        if ($ids->count() > 60) {
            throw ValidationException::withMessages(['colaborador_ids' => 'Una sesión admite máximo 60 colaboradores.']);
        }

        $elegibles = $this->consultaElegibles($actor)->whereIn('id', $ids->all())->pluck('id')->all();
        $rechazados = $ids->reject(fn (int $id): bool => in_array($id, $elegibles, true));

        if ($rechazados->isNotEmpty()) {
            $nombres = Colaborador::query()->withTrashed()->whereIn('id', $rechazados->all())->get()
                ->map(fn (Colaborador $c): string => $c->nombreCompleto())->implode(', ');

            throw ValidationException::withMessages([
                'colaborador_ids' => sprintf('No puedes presentarle la inducción a: %s (fuera de tu alcance, dado de baja o inexistente).', $nombres !== '' ? $nombres : 'colaborador no encontrado'),
            ]);
        }

        $sesion = DB::transaction(function () use ($actor, $ids, $notas): SesionInduccion {
            $sesion = SesionInduccion::query()->create([
                'presentada_por' => $actor->id,
                'estado' => EstadoSesionInduccion::EnCurso,
                'notas' => $notas !== null && trim($notas) !== '' ? trim($notas) : null,
                'iniciada_en' => now('America/Mexico_City'),
            ]);

            foreach ($ids as $id) {
                SesionInduccionColaborador::query()->create([
                    'sesion_induccion_id' => $sesion->id,
                    'colaborador_id' => $id,
                ]);
            }

            return $sesion;
        });

        $this->auditoria->registrar('induccion_sesion_iniciada', $sesion, $actor, ['colaboradores' => $ids->all()]);

        return $sesion->refresh();
    }

    /**
     * «Enviar evaluación» (al terminar de presentar): nada se habilita ni
     * se avisa antes de este paso. Idempotente: si ya se envió no
     * vuelve a marcar ni a notificar a nadie.
     *
     * @throws AuthorizationException
     * @throws ValidationException sesión cancelada
     */
    public function enviarEvaluacion(SesionInduccion $sesion, User $actor): SesionInduccion
    {
        $this->exigirSobreSesion($sesion, $actor);

        /** @var array{0: SesionInduccion, 1: bool} $resultado */
        $resultado = DB::transaction(function () use ($sesion): array {
            $sesion = SesionInduccion::query()->lockForUpdate()->findOrFail($sesion->id);

            if ($sesion->estado === EstadoSesionInduccion::Presentada) {
                return [$sesion, false];
            }

            if ($sesion->estado === EstadoSesionInduccion::Cancelada) {
                throw ValidationException::withMessages(['sesion' => 'Esta sesión fue cancelada; inicia una nueva para presentar la inducción.']);
            }

            $sesion->update(['estado' => EstadoSesionInduccion::Presentada, 'presentada_en' => now('America/Mexico_City')]);

            foreach ($sesion->asistentes()->get() as $asistente) {
                $proceso = $this->procesoAbierto($asistente->colaborador_id);

                if ($proceso === null) {
                    $asistente->update(['resultado' => ResultadoAsistenciaInduccion::SinProcesoIngreso, 'procesado_en' => now()]);

                    continue;
                }

                // Contenido institucional visto por presentación de RH (no
                // aprueba nada: eso lo decide la evaluación).
                $this->evaluaciones->registrarPresentacionRh($proceso, $sesion);
            }

            return [$sesion, true];
        });

        [$sesion, $recienPresentada] = $resultado;

        if (! $recienPresentada) {
            return $sesion->load('asistentes');
        }

        // Ya con commit: habilitar + avisar (un push nunca sale para un
        // estado que todavía podría revertirse).
        foreach ($sesion->asistentes()->with('colaborador.user')->get() as $asistente) {
            if ($asistente->resultado === ResultadoAsistenciaInduccion::SinProcesoIngreso) {
                continue;
            }

            $proceso = $this->procesoAbierto($asistente->colaborador_id);

            if ($proceso === null) {
                $asistente->update(['resultado' => ResultadoAsistenciaInduccion::SinProcesoIngreso, 'procesado_en' => now()]);

                continue;
            }

            $previa = EvaluacionInduccion::query()->where('onboarding_proceso_id', $proceso->id)->first();
            $evaluacion = $this->evaluaciones->habilitarPara($proceso);

            $resultadoAsistente = match (true) {
                $evaluacion === null => ResultadoAsistenciaInduccion::SinProcesoIngreso,
                $previa === null => ResultadoAsistenciaInduccion::EvaluacionHabilitada,
                $evaluacion->estado === EstadoEvaluacionInduccion::Aprobada => ResultadoAsistenciaInduccion::YaAprobada,
                default => ResultadoAsistenciaInduccion::EvaluacionEnCurso,
            };

            // Ya la tenía abierta y puede contestarla: se le recuerda.
            $usuario = $asistente->colaborador->user;

            if ($previa !== null && $evaluacion !== null && $evaluacion->estado->permitePresentar() && $usuario !== null) {
                $this->evaluaciones->notificarDisponible($usuario, $evaluacion);
            }

            $asistente->update([
                'resultado' => $resultadoAsistente,
                'evaluacion_induccion_id' => $evaluacion?->id,
                'procesado_en' => now(),
            ]);

            $this->auditoria->registrar('induccion_presentada_por_rh', $asistente->colaborador, $actor, [
                'colaborador_id' => $asistente->colaborador_id,
                'sesion_induccion_id' => $sesion->id,
                'resultado' => $resultadoAsistente->value,
                'evaluacion_induccion_id' => $evaluacion?->id,
            ]);
        }

        $this->auditoria->registrar('induccion_sesion_presentada', $sesion, $actor, ['asistentes' => $sesion->asistentes()->count()]);

        return $sesion->refresh()->load('asistentes');
    }

    /**
     * Mensaje para RH tras «Enviar evaluación»: a cuántos se les habilitó y
     * quiénes no tienen proceso de ingreso abierto (no se les envió nada).
     * Nunca dice «enviada» a quien no la recibió. Es el toast del web.
     *
     * @return array{type: string, message: string}
     */
    public function mensajeEnvio(SesionInduccion $sesion): array
    {
        $asistentes = $sesion->asistentes()->with('colaborador')->get();
        $sinProceso = $asistentes->filter(fn (SesionInduccionColaborador $a): bool => $a->resultado === ResultadoAsistenciaInduccion::SinProcesoIngreso);
        $enviadas = $asistentes->count() - $sinProceso->count();

        $mensaje = $enviadas > 0
            ? sprintf('Evaluación enviada a %d colaborador(es): les llegó el aviso en la app para contestarla.', $enviadas)
            : 'No se envió ninguna evaluación.';

        if ($sinProceso->isNotEmpty()) {
            $mensaje .= sprintf(
                ' Sin proceso de ingreso abierto (no se les envió): %s. Su evaluación se habilitará cuando inicie su onboarding.',
                $sinProceso->map(fn (SesionInduccionColaborador $a): string => $a->colaborador->nombreCompleto())->implode(', '),
            );
        }

        return ['type' => $sinProceso->isEmpty() ? 'success' : 'warning', 'message' => $mensaje];
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function cancelar(SesionInduccion $sesion, User $actor): SesionInduccion
    {
        $this->exigirSobreSesion($sesion, $actor);

        $sesion = DB::transaction(function () use ($sesion): SesionInduccion {
            $sesion = SesionInduccion::query()->lockForUpdate()->findOrFail($sesion->id);

            if ($sesion->estado !== EstadoSesionInduccion::EnCurso) {
                throw ValidationException::withMessages(['sesion' => "La sesión ya está «{$sesion->estado->etiqueta()}»."]);
            }

            $sesion->update(['estado' => EstadoSesionInduccion::Cancelada, 'cancelada_en' => now('America/Mexico_City')]);

            return $sesion;
        });

        $this->auditoria->registrar('induccion_sesion_cancelada', $sesion, $actor);

        return $sesion;
    }

    /**
     * La sesión que este usuario dejó en curso (para retomarla al recargar).
     */
    public function enCursoDe(User $actor): ?SesionInduccion
    {
        return SesionInduccion::query()
            ->where('presentada_por', $actor->id)
            ->where('estado', EstadoSesionInduccion::EnCurso->value)
            ->latest('id')
            ->first();
    }

    /**
     * Todo lo que necesita la pantalla «Presentar inducción» del lado de RH
     * — null para quien no gestiona onboarding (la pantalla además lo rechaza:
     * el colaborador nunca presenta, ver AccesoInduccionColaboradorService).
     *
     * @return array{sesion_en_curso: array<string, mixed>|null, recientes: list<array<string, mixed>>, seguimiento: list<array<string, mixed>>}|null
     */
    public function datosPantalla(User $actor): ?array
    {
        if (! $this->puedeGestionar($actor)) {
            return null;
        }

        $enCurso = $this->enCursoDe($actor);

        return [
            'sesion_en_curso' => $enCurso !== null ? $this->aArray($enCurso) : null,
            'recientes' => array_values(SesionInduccion::query()
                ->where('presentada_por', $actor->id)
                ->where('estado', EstadoSesionInduccion::Presentada->value)
                ->latest('id')
                ->limit(5)
                ->get()
                ->map(fn (SesionInduccion $s): array => $this->aArray($s))
                ->values()
                ->all()),
            'seguimiento' => $this->seguimiento($actor),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function aArray(SesionInduccion $sesion): array
    {
        $sesion->loadMissing(['presentador:id,name,apellidos', 'asistentes.colaborador.puesto:id,nombre', 'asistentes.colaborador.sucursalPrincipal:id,nombre']);

        return [
            'id' => $sesion->id,
            'estado' => $sesion->estado->value,
            'estado_etiqueta' => $sesion->estado->etiqueta(),
            'presentada_por' => $sesion->presentador->nombreCompleto(),
            'notas' => $sesion->notas,
            'iniciada_en' => $sesion->iniciada_en->toIso8601String(),
            'presentada_en' => $sesion->presentada_en?->toIso8601String(),
            'asistentes' => $sesion->asistentes->map(fn (SesionInduccionColaborador $a): array => [
                'id' => $a->colaborador_id,
                'nombre' => $a->colaborador->nombreCompleto(),
                'numero_empleado' => $a->colaborador->numero_empleado,
                'puesto' => $a->colaborador->puesto?->nombre,
                'sucursal' => $a->colaborador->sucursalPrincipal?->nombre,
                'resultado' => $a->resultado?->value,
                'resultado_etiqueta' => $a->resultado?->etiqueta(),
                'evaluacion_induccion_id' => $a->evaluacion_induccion_id,
            ])->values()->all(),
        ];
    }

    /**
     * Seguimiento para RH: cada persona con inducción institucional en su
     * onboarding (abierto, o cerrado en los últimos 30 días) dentro del
     * alcance — pendiente de presentar → presentada, por contestar → por
     * calificar (D) → aprobada / reforzamiento, y si ya labora.
     *
     * Con $historial incluye también los onboardings ya completados (sin el
     * corte de 30 días) — lo usa «Calificaciones y refuerzo».
     *
     * @return list<array<string, mixed>>
     */
    public function seguimiento(User $actor, bool $historial = false): array
    {
        $procesos = OnboardingProceso::query()
            ->where('estado', '!=', EstadoOnboarding::Cancelado->value)
            ->when(! $historial, fn (Builder $consulta) => $consulta->where(fn (Builder $q) => $q
                ->where('estado', '!=', EstadoOnboarding::Completado->value)
                ->orWhere('completado_en', '>=', now()->subDays(30))))
            ->whereHas('avances.modulo', fn (Builder $q) => $q
                ->where('tipo', TipoModuloOnboarding::Institucional->value)
                ->whereNotNull('evaluacion_formal_clave'))
            ->whereHas('colaborador', fn (Builder $q) => $this->alcance->limitarPorModulo($q, $actor, self::MODULO, 'id'))
            ->with(['colaborador.puesto:id,nombre', 'colaborador.sucursalPrincipal:id,nombre', 'avances.modulo'])
            ->latest('id')
            ->limit(300)
            ->get()
            // Una fila por persona (su proceso más reciente).
            ->unique('colaborador_id');

        $evaluaciones = EvaluacionInduccion::query()
            ->whereIn('onboarding_proceso_id', $procesos->pluck('id')->all())
            ->with('intentos')
            ->get()
            ->keyBy('onboarding_proceso_id');

        $orden = [
            EtapaSeguimientoInduccion::PorCalificar->value => 0,
            EtapaSeguimientoInduccion::PendientePresentar->value => 1,
            EtapaSeguimientoInduccion::Reforzamiento->value => 2,
            EtapaSeguimientoInduccion::PorContestar->value => 3,
            EtapaSeguimientoInduccion::ReevaluacionPorContestar->value => 4,
            EtapaSeguimientoInduccion::Aprobada->value => 5,
        ];

        // Inducción aprobada → «Enviar descriptivo de puesto» y su acuse.
        $descriptivos = $this->acusesDescriptivo->resumenesRh(
            $procesos
                ->filter(fn (OnboardingProceso $p): bool => $evaluaciones->get($p->id)?->estado === EstadoEvaluacionInduccion::Aprobada)
                ->map(fn (OnboardingProceso $p): Colaborador => $p->colaborador)
                ->values(),
            $actor,
        );

        return array_values($procesos->map(function (OnboardingProceso $proceso) use ($evaluaciones, $descriptivos): array {
            $avance = $proceso->avances->first(fn (OnboardingAvance $a): bool => $a->modulo->evaluacion_formal_clave !== null && $a->modulo->tipo === TipoModuloOnboarding::Institucional);
            $evaluacion = $evaluaciones->get($proceso->id);
            $etapa = EtapaSeguimientoInduccion::desde($evaluacion?->estado, $avance?->contenido_visto_en !== null);
            $presentada = (array) (((array) ($avance->progreso_contenido ?? []))['presentada_por_rh'] ?? []);
            $porCalificar = $evaluacion?->intentos->first(fn (EvaluacionInduccionIntento $i): bool => $i->pendienteCalificacionRh());
            $ultimo = $evaluacion?->intentos->last();
            $colaborador = $proceso->colaborador;

            return [
                'colaborador' => [
                    'id' => $colaborador->id,
                    'nombre' => $colaborador->nombreCompleto(),
                    'numero_empleado' => $colaborador->numero_empleado,
                    'puesto' => $colaborador->puesto?->nombre,
                    'sucursal' => $colaborador->sucursalPrincipal?->nombre,
                ],
                'etapa' => $etapa->value,
                'etapa_etiqueta' => $etapa->etiqueta(),
                'presentada_por_rh_en' => isset($presentada['presentada_en']) ? (string) $presentada['presentada_en'] : null,
                'evaluacion_id' => $evaluacion?->id,
                'intento_por_calificar_id' => $porCalificar?->id,
                'ultimo_intento_id' => $ultimo?->id,
                'calificacion' => $evaluacion?->ultima_calificacion !== null ? (float) $evaluacion->ultima_calificacion : null,
                'fecha_limite_reevaluacion' => $evaluacion?->fecha_limite_reevaluacion?->toDateString(),
                'mejor_calificacion' => $evaluacion?->mejor_calificacion !== null ? (float) $evaluacion->mejor_calificacion : null,
                'intentos_count' => $evaluacion->intentos_count ?? 0,
                'retroalimentacion' => $evaluacion?->retroalimentacion,
                'retroalimentado_en' => $evaluacion?->retroalimentado_en?->toIso8601String(),
                // Lecciones de bienvenida (módulos con quiz, no la FO-RH-EI).
                'modulos' => $proceso->avances
                    ->filter(fn (OnboardingAvance $a): bool => $a->modulo->evaluacion_formal_clave === null)
                    ->sortBy('orden')
                    ->map(fn (OnboardingAvance $a): array => [
                        'avance_id' => $a->id,
                        'titulo' => $a->modulo->titulo,
                        'estado' => $a->estado->value,
                        'estado_etiqueta' => $a->estado->etiqueta(),
                        'calificacion' => $a->ultima_calificacion !== null ? (float) $a->ultima_calificacion : null,
                        'calificacion_minima' => (float) $a->modulo->calificacion_minima,
                        'intentos_count' => $a->intentos_count,
                        'retroalimentacion' => $a->retroalimentacion,
                        'requiere_refuerzo' => $a->estado === EstadoAvanceOnboarding::RequiereRefuerzo,
                    ])
                    ->values()
                    ->all(),
                'onboarding_estado' => $proceso->estado->value,
                'laborando' => $colaborador->activado_en !== null,
                'descriptivo_puesto' => $descriptivos[$colaborador->id] ?? null,
            ];
        })
            ->sortBy(fn (array $fila): string => sprintf('%d %s', $orden[$fila['etapa']], Str::lower(Str::ascii($fila['colaborador']['nombre']))))
            ->values()
            ->all());
    }

    /**
     * «Calificaciones y refuerzo» (grupo 3 · Onboarding): el resultado de
     * cada persona en la evaluación de inducción FO-RH-EI y en sus lecciones,
     * con retroalimentación y reevaluación — incluido el historial. Mismo
     * alcance que el seguimiento (limitarPorModulo).
     *
     * @return list<array<string, mixed>>
     *
     * @throws AuthorizationException sin onboarding.gestionar
     */
    public function calificacionesRh(User $actor): array
    {
        $this->exigirPermiso($actor);

        return $this->seguimiento($actor, true);
    }

    /**
     * Colaboradores a los que este usuario puede presentar la inducción:
     * no dados de baja, dentro de su alcance del módulo y nunca él mismo.
     *
     * @return Builder<Colaborador>
     */
    private function consultaElegibles(User $actor): Builder
    {
        $consulta = Colaborador::query()
            ->where(fn (Builder $q) => $q->whereNull('estado_alta')->orWhere('estado_alta', '!=', EstadoAltaColaborador::Baja->value))
            ->when($actor->colaborador_id !== null, fn (Builder $q) => $q->where('id', '!=', $actor->colaborador_id));

        return $this->alcance->limitarPorModulo($consulta, $actor, self::MODULO, 'id');
    }

    private function procesoAbierto(int $colaboradorId): ?OnboardingProceso
    {
        return OnboardingProceso::query()
            ->where('colaborador_id', $colaboradorId)
            ->whereNotIn('estado', [EstadoOnboarding::Completado->value, EstadoOnboarding::Cancelado->value])
            ->latest('id')
            ->first();
    }

    private function exigirPermiso(User $actor): void
    {
        if (! $this->puedeGestionar($actor)) {
            throw new AuthorizationException('No tienes permiso para presentar la inducción.');
        }
    }

    /**
     * Quien la presentó, o alguien de RH que alcance a TODOS sus asistentes.
     */
    private function exigirSobreSesion(SesionInduccion $sesion, User $actor): void
    {
        $this->exigirPermiso($actor);

        if ($sesion->presentada_por === $actor->id) {
            return;
        }

        $sesion->loadMissing('asistentes.colaborador');

        foreach ($sesion->asistentes as $asistente) {
            if (! $this->alcance->alcanzaColaboradorEnModulo($actor, $asistente->colaborador, self::MODULO)) {
                throw new AuthorizationException('Esta sesión incluye colaboradores fuera de tu alcance.');
            }
        }
    }
}
