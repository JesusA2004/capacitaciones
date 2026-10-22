<?php

namespace App\Services\Onboarding;

use App\Enums\EstadoAsignacionInduccion;
use App\Enums\EstadoEvaluacionInduccion;
use App\Enums\EstadoOnboarding;
use App\Enums\ModoDestinatariosOnboarding;
use App\Models\Colaborador;
use App\Models\EvaluacionInduccion;
use App\Models\InduccionAsignacion;
use App\Models\OnboardingAvance;
use App\Models\OnboardingProceso;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Notificaciones\Aviso;
use App\Services\Notificaciones\NotificacionEventosService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * «Asignar inducción» (RH con onboarding.gestionar, Onboarding → Inducción
 * institucional y Calificaciones y refuerzo). Modelo mínimo y coherente con
 * el onboarding existente:
 *
 *  - La inducción vive SIEMPRE en el avance institucional con evaluación
 *    formal de un OnboardingProceso (ahí se lleva el progreso del PDF, del
 *    video y la evaluación FO-RH-EI). Esta clase no inventa otro lugar.
 *  - Persona con onboarding de INGRESO abierto: se (re)asigna sobre ese
 *    mismo proceso — solo cambia qué debe ver (presentación y/o material),
 *    la fecha límite y el mensaje. Nunca se crea un segundo proceso.
 *  - Persona SIN onboarding de ingreso abierto (personal activo, o cuyo
 *    onboarding ya cerró): se le abre una REINDUCCIÓN
 *    (OnboardingService::iniciarReinduccion()): proceso aparte, solo con la
 *    Inducción Mr. Lana; al aprobarla se cierra sin activar a nadie ni tocar
 *    su contratación o ciclo laboral.
 *  - Quien ya aprobó la inducción se omite (y se reporta) salvo que RH pida
 *    expresamente volver a asignársela (reinducción).
 *  - Retirar: en ingreso deja la inducción «Sin asignar» (sin borrar
 *    avance); en reinducción cancela el proceso. Solo mientras no haya
 *    contestado la evaluación.
 *
 * Cada persona se procesa en su propia transacción (crear proceso/avance +
 * asignación); los avisos salen DESPUÉS del commit por
 * NotificacionEventosService (web + app) y un fallo al avisar nunca deshace
 * la asignación.
 */
class AsignacionInduccionService
{
    public const PERMISO = OnboardingService::PERMISO_GESTIONAR;

    public const MENSAJE_MAX = 1000;

    public function __construct(
        private readonly DestinatariosOnboardingService $destinatarios,
        private readonly OnboardingService $onboarding,
        private readonly AccesoInduccionColaboradorService $acceso,
        private readonly MaterialInduccionService $materiales,
        private readonly ProgresoInduccionService $progreso,
        private readonly NotificacionEventosService $eventos,
        private readonly AuditoriaService $auditoria,
    ) {}

    /**
     * Resumen de confirmación ANTES de asignar: a cuántos se les asignará,
     * a quiénes se omite (ya la tienen igual, ya la aprobaron, ya la
     * contestaron) y por qué. No escribe nada.
     *
     * @param  array{modo: string, colaborador_ids?: array<int, int|string>, sucursal_ids?: array<int, int|string>, incluye_presentacion?: bool, incluye_material?: bool, reasignar_aprobadas?: bool}  $datos
     * @return array{descripcion: string, total: int, asignar: int, omitidos: list<array{id: int, nombre: string, motivo: string}>, muestra: list<string>}
     */
    public function previsualizar(User $actor, array $datos): array
    {
        $modo = $this->modo($datos);
        $personas = $this->destinatarios->resolver($actor, $modo, $datos['colaborador_ids'] ?? [], $datos['sucursal_ids'] ?? []);
        $contenido = $this->contenido($datos);
        $reasignar = (bool) ($datos['reasignar_aprobadas'] ?? false);

        $omitidos = [];
        $asignables = [];

        foreach ($personas as $colaborador) {
            $motivo = $this->motivoOmitir($colaborador, $contenido, $reasignar);

            if ($motivo !== null) {
                $omitidos[] = ['id' => $colaborador->id, 'nombre' => $colaborador->nombreCompleto(), 'motivo' => $motivo];
            } else {
                $asignables[] = $colaborador->nombreCompleto();
            }
        }

        return [
            'descripcion' => $this->destinatarios->descripcion($modo, $personas, $datos['sucursal_ids'] ?? []),
            'total' => $personas->count(),
            'asignar' => count($asignables),
            'omitidos' => $omitidos,
            'muestra' => array_slice($asignables, 0, 8),
        ];
    }

    /**
     * Asigna la inducción.
     *
     * @param  array{modo: string, colaborador_ids?: array<int, int|string>, sucursal_ids?: array<int, int|string>, incluye_presentacion?: bool, incluye_material?: bool, fecha_limite?: string|null, mensaje?: string|null, reasignar_aprobadas?: bool}  $datos
     * @return array{asignados: int, reinducciones: int, omitidos: list<array{id: int, nombre: string, motivo: string}>}
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function asignar(User $actor, array $datos): array
    {
        $modo = $this->modo($datos);
        $contenido = $this->contenido($datos);
        $fechaLimite = $this->fechaLimite($datos['fecha_limite'] ?? null);
        $mensaje = $this->mensaje($datos['mensaje'] ?? null);
        $reasignar = (bool) ($datos['reasignar_aprobadas'] ?? false);
        $personas = $this->destinatarios->resolver($actor, $modo, $datos['colaborador_ids'] ?? [], $datos['sucursal_ids'] ?? []);

        $asignados = 0;
        $reinducciones = 0;
        $omitidos = [];

        foreach ($personas as $colaborador) {
            $motivo = $this->motivoOmitir($colaborador, $contenido, $reasignar);

            if ($motivo !== null) {
                $omitidos[] = ['id' => $colaborador->id, 'nombre' => $colaborador->nombreCompleto(), 'motivo' => $motivo];

                continue;
            }

            try {
                [$asignacion, $esReinduccion] = $this->asignarA($colaborador, $actor, $contenido, $fechaLimite, $mensaje);
            } catch (ValidationException $e) {
                $omitidos[] = ['id' => $colaborador->id, 'nombre' => $colaborador->nombreCompleto(), 'motivo' => collect($e->errors())->flatten()->implode(' ')];

                continue;
            }

            $asignados++;
            $reinducciones += $esReinduccion ? 1 : 0;
            $this->despuesDeAsignar($asignacion, $colaborador, $actor);
        }

        $this->auditoria->registrar('induccion_asignada_lote', null, $actor, [
            'modo' => $modo->value,
            'asignados' => $asignados,
            'reinducciones' => $reinducciones,
            'omitidos' => count($omitidos),
            'incluye_presentacion' => $contenido['presentacion'],
            'incluye_material' => $contenido['material'],
        ]);

        return ['asignados' => $asignados, 'reinducciones' => $reinducciones, 'omitidos' => $omitidos];
    }

    /**
     * Retira la inducción asignada a una persona (mientras no haya
     * contestado su evaluación).
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function retirar(Colaborador $colaborador, User $actor, ?string $motivo = null): void
    {
        $this->exigirSobre($actor, $colaborador);

        $proceso = $this->acceso->procesoInduccion($colaborador);
        $avance = $proceso !== null ? $this->acceso->avanceDeProceso($proceso) : null;

        if ($proceso === null || $avance === null) {
            throw ValidationException::withMessages(['colaborador' => sprintf('%s no tiene la inducción asignada.', $colaborador->nombreCompleto())]);
        }

        $estado = EstadoAsignacionInduccion::from((string) $this->progreso->resumen($colaborador)['estado_rh']);

        if (! $estado->permiteRetirar()) {
            throw ValidationException::withMessages(['colaborador' => sprintf('No se puede retirar: la inducción de %s está «%s».', $colaborador->nombreCompleto(), $estado->etiqueta())]);
        }

        $motivo = $motivo !== null && trim($motivo) !== '' ? mb_substr(trim($motivo), 0, 500) : null;

        DB::transaction(function () use ($proceso, $avance, $colaborador, $actor, $motivo): void {
            $vigente = InduccionAsignacion::query()->where('onboarding_avance_id', $avance->id)->where('vigente', true)->lockForUpdate()->first();

            if ($vigente !== null) {
                $vigente->update(['vigente' => null, 'retirada_por' => $actor->id, 'retirada_en' => now(), 'motivo_retiro' => $motivo]);
            } else {
                // Asignación automática del ingreso: queda constancia del retiro.
                InduccionAsignacion::query()->create([
                    'colaborador_id' => $colaborador->id,
                    'onboarding_proceso_id' => $proceso->id,
                    'onboarding_avance_id' => $avance->id,
                    'vigente' => null,
                    'asignada_por' => null,
                    'asignada_en' => $avance->created_at ?? now(),
                    'retirada_por' => $actor->id,
                    'retirada_en' => now(),
                    'motivo_retiro' => $motivo,
                ]);
            }

            if ($proceso->esReinduccion()) {
                $proceso->update(['estado' => EstadoOnboarding::Cancelado, 'colaborador_abierto_id' => null]);
            }
        });

        $this->auditoria->registrar('induccion_asignacion_retirada', $colaborador, $actor, [
            'colaborador_id' => $colaborador->id,
            'onboarding_proceso_id' => $proceso->id,
            'reinduccion' => $proceso->esReinduccion(),
            'motivo' => $motivo,
        ]);

        $this->onboarding->sincronizarBandeja($proceso);
    }

    /**
     * Lista para RH: cada persona del alcance con inducción asignada (abierta
     * o cerrada en los últimos 60 días) y su estado.
     *
     * @return list<array<string, mixed>>
     */
    public function panorama(User $actor): array
    {
        $this->exigirPermiso($actor);

        $procesos = OnboardingProceso::query()
            ->where('estado', '!=', EstadoOnboarding::Cancelado->value)
            ->where(fn ($q) => $q->where('estado', '!=', EstadoOnboarding::Completado->value)->orWhere('completado_en', '>=', now()->subDays(60)))
            ->whereHas('avances.modulo', fn ($q) => $q->whereNotNull('evaluacion_formal_clave'))
            ->whereIn('colaborador_id', $this->destinatarios->consultaElegibles($actor)->select('id'))
            ->with(['colaborador.puesto:id,nombre', 'colaborador.sucursalPrincipal:id,nombre'])
            ->latest('id')
            ->limit(400)
            ->get()
            ->unique('colaborador_id');

        return array_values($procesos->map(function (OnboardingProceso $proceso): array {
            $colaborador = $proceso->colaborador;
            $resumen = $this->progreso->resumen($colaborador);

            return [
                'colaborador' => [
                    'id' => $colaborador->id,
                    'nombre' => $colaborador->nombreCompleto(),
                    'numero_empleado' => $colaborador->numero_empleado,
                    'puesto' => $colaborador->puesto?->nombre,
                    'sucursal' => $colaborador->sucursalPrincipal?->nombre,
                ],
                'induccion' => $resumen,
                'puede_retirar' => EstadoAsignacionInduccion::from((string) $resumen['estado_rh'])->permiteRetirar(),
            ];
        })->values()->all());
    }

    /**
     * @param  array{presentacion: bool, material: bool}  $contenido
     */
    private function motivoOmitir(Colaborador $colaborador, array $contenido, bool $reasignar): ?string
    {
        $proceso = $this->acceso->procesoInduccion($colaborador);
        $avance = $proceso !== null ? $this->acceso->avanceDeProceso($proceso) : null;
        $abierto = $proceso !== null && ! in_array($proceso->estado, [EstadoOnboarding::Completado, EstadoOnboarding::Cancelado], true);
        $evaluacion = $proceso !== null ? EvaluacionInduccion::query()->where('onboarding_proceso_id', $proceso->id)->latest('id')->first() : null;

        if ($colaborador->user === null) {
            return 'No tiene cuenta para verla en Mi espacio o la app.';
        }

        if ($abierto && $evaluacion !== null && ! $evaluacion->estado->permitePresentar()) {
            return $evaluacion->estado === EstadoEvaluacionInduccion::Aprobada
                ? 'Ya aprobó su inducción (su onboarding sigue abierto).'
                : sprintf('Ya contestó su evaluación (%s).', $evaluacion->estado->etiqueta());
        }

        if ($abierto && $avance !== null && ! $this->acceso->asignacionRetirada($avance)) {
            $actual = $this->acceso->contenidoAsignado($avance);

            if ($actual === $contenido && $this->acceso->asignacionVigente($avance) !== null) {
                return 'Ya la tiene asignada igual.';
            }

            return null;
        }

        if (! $abierto && ! $reasignar && $evaluacion?->estado === EstadoEvaluacionInduccion::Aprobada) {
            return 'Ya aprobó su inducción (marca «Reinducción» para volver a asignársela).';
        }

        return null;
    }

    /**
     * @param  array{presentacion: bool, material: bool}  $contenido
     * @return array{0: InduccionAsignacion, 1: bool}
     */
    private function asignarA(Colaborador $colaborador, User $actor, array $contenido, ?Carbon $fechaLimite, ?string $mensaje): array
    {
        return DB::transaction(function () use ($colaborador, $actor, $contenido, $fechaLimite, $mensaje): array {
            Colaborador::query()->whereKey($colaborador->id)->lockForUpdate()->first();

            $proceso = $this->acceso->procesoInduccion($colaborador);
            $abierto = $proceso !== null && ! in_array($proceso->estado, [EstadoOnboarding::Completado, EstadoOnboarding::Cancelado], true);

            if (! $abierto) {
                // Un ingreso abierto SIN el módulo formal (anterior a él):
                // se le agrega en vez de abrir otro proceso.
                $ingreso = $this->onboarding->procesoActual($colaborador);

                if ($ingreso !== null && ! in_array($ingreso->estado, [EstadoOnboarding::Completado, EstadoOnboarding::Cancelado], true)) {
                    $this->onboarding->asegurarModulosFormales($ingreso);
                    $proceso = $ingreso;
                    $abierto = true;
                }
            }

            $esReinduccion = ! $abierto;

            if ($esReinduccion) {
                $proceso = $this->onboarding->iniciarReinduccion($colaborador, $actor);
            }

            $avance = $this->acceso->avanceDeProceso($proceso);

            if (! $avance instanceof OnboardingAvance) {
                throw ValidationException::withMessages(['colaborador_ids' => 'No hay un módulo de Inducción Mr. Lana activo.']);
            }

            InduccionAsignacion::query()
                ->where('onboarding_avance_id', $avance->id)
                ->where('vigente', true)
                ->update(['vigente' => null, 'retirada_por' => $actor->id, 'retirada_en' => now(), 'motivo_retiro' => 'Reasignada']);

            $asignacion = InduccionAsignacion::query()->create([
                'colaborador_id' => $colaborador->id,
                'onboarding_proceso_id' => $proceso->id,
                'onboarding_avance_id' => $avance->id,
                'incluye_presentacion' => $contenido['presentacion'],
                'incluye_material' => $contenido['material'],
                'fecha_limite' => $fechaLimite?->toDateString(),
                'mensaje' => $mensaje,
                'vigente' => true,
                'asignada_por' => $actor->id,
                'asignada_en' => now(),
            ]);

            return [$asignacion, $esReinduccion];
        });
    }

    /**
     * Ya con commit: bandeja, evaluación (si lo asignado ya está visto) y
     * aviso por la capa única de notificaciones. Nunca lanza.
     */
    private function despuesDeAsignar(InduccionAsignacion $asignacion, Colaborador $colaborador, User $actor): void
    {
        $asignacion->loadMissing('proceso');
        $this->onboarding->sincronizarBandeja($asignacion->proceso);

        $this->auditoria->registrar('induccion_asignada', $colaborador, $actor, [
            'colaborador_id' => $colaborador->id,
            'induccion_asignacion_id' => $asignacion->id,
            'onboarding_proceso_id' => $asignacion->onboarding_proceso_id,
            'reinduccion' => $asignacion->proceso->esReinduccion(),
        ]);

        try {
            $partes = array_values(array_filter([
                $asignacion->incluye_presentacion ? 'la presentación' : null,
                $asignacion->incluye_material ? 'el video/audio de explicación' : null,
            ]));
            $texto = sprintf(
                'Recursos Humanos te asignó %s de la Inducción Mr. Lana. Entra a «Mi inducción» en Mi espacio o en la app y al terminar contesta tu evaluación.%s%s',
                implode(' y ', $partes),
                $asignacion->fecha_limite !== null ? sprintf(' Fecha límite: %s.', $asignacion->fecha_limite->format('d/m/Y')) : '',
                $asignacion->mensaje !== null ? sprintf(' «%s»', mb_strimwidth($asignacion->mensaje, 0, 200, '…')) : '',
            );

            $this->eventos->crearPendiente([$colaborador->user], new Aviso(
                evento: 'induccion_asignada',
                tipo: 'onboarding_habilitado',
                titulo: 'Tienes tu Inducción Mr. Lana asignada',
                mensaje: $texto,
                recurso: $asignacion,
                accion: 'ver_onboarding',
                prioridad: 'alta',
                datos: ['colaborador_id' => $colaborador->id],
                ocurrencia: (string) $asignacion->id,
            ));
        } catch (Throwable $e) {
            Log::warning('AsignacionInduccionService: no se pudo avisar la asignación.', ['asignacion_id' => $asignacion->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array{presentacion: bool, material: bool}
     */
    private function contenido(array $datos): array
    {
        $presentacion = (bool) ($datos['incluye_presentacion'] ?? true);
        $material = (bool) ($datos['incluye_material'] ?? true);
        $hayMaterial = ($modulo = $this->materiales->moduloInstitucional()) !== null && $this->materiales->materialActivo($modulo) !== null;

        if ($material && ! $hayMaterial) {
            // Sin material cargado solo puede asignarse la presentación.
            $material = false;

            if (! $presentacion) {
                throw ValidationException::withMessages(['incluye_material' => 'No hay video ni audio de explicación cargado: asigna la presentación o carga el material primero.']);
            }
        }

        if (! $presentacion && ! $material) {
            throw ValidationException::withMessages(['incluye_presentacion' => 'Elige qué asignar: la presentación, el video/audio o ambos.']);
        }

        return ['presentacion' => $presentacion, 'material' => $material];
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function modo(array $datos): ModoDestinatariosOnboarding
    {
        $modo = ModoDestinatariosOnboarding::tryFrom((string) ($datos['modo'] ?? ''));

        if ($modo === null) {
            throw ValidationException::withMessages(['modo' => 'Elige a quién asignar: todos, personas o sucursales.']);
        }

        return $modo;
    }

    private function fechaLimite(mixed $valor): ?Carbon
    {
        if (! is_string($valor) || trim($valor) === '') {
            return null;
        }

        try {
            $fecha = Carbon::parse($valor, 'America/Mexico_City')->startOfDay();
        } catch (Throwable) {
            throw ValidationException::withMessages(['fecha_limite' => 'La fecha límite no es válida.']);
        }

        if ($fecha->lt(now('America/Mexico_City')->startOfDay())) {
            throw ValidationException::withMessages(['fecha_limite' => 'La fecha límite no puede ser en el pasado.']);
        }

        return $fecha;
    }

    private function mensaje(mixed $valor): ?string
    {
        if (! is_string($valor) || trim($valor) === '') {
            return null;
        }

        if (mb_strlen(trim($valor)) > self::MENSAJE_MAX) {
            throw ValidationException::withMessages(['mensaje' => sprintf('El mensaje no puede pasar de %d caracteres.', self::MENSAJE_MAX)]);
        }

        return trim($valor);
    }

    /**
     * @throws AuthorizationException
     */
    private function exigirPermiso(User $actor): void
    {
        if (! $actor->can(self::PERMISO)) {
            throw new AuthorizationException('No tienes permiso para asignar la inducción.');
        }
    }

    /**
     * @throws AuthorizationException
     */
    private function exigirSobre(User $actor, Colaborador $colaborador): void
    {
        $this->exigirPermiso($actor);

        if (! $this->destinatarios->consultaElegibles($actor)->whereKey($colaborador->id)->exists()) {
            throw new AuthorizationException('Este colaborador está fuera de tu alcance.');
        }
    }

    /**
     * «Ver como colaborador»: autoriza a RH sobre esa persona.
     *
     * @throws AuthorizationException
     */
    public function autorizarVistaPrevia(User $actor, ?Colaborador $colaborador): void
    {
        if ($colaborador === null) {
            $this->exigirPermiso($actor);

            return;
        }

        $this->exigirSobre($actor, $colaborador);
    }
}
