<?php

namespace App\Services\Flujos\Resolvers;

use App\Enums\EstadoAgendaEvento;
use App\Enums\EstadoCandidato;
use App\Enums\FlujoOperativo;
use App\Enums\ModoFlujo;
use App\Enums\TipoAgendaEvento;
use App\Enums\TipoSiguientePaso;
use App\Models\AgendaEvento;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\IncorporacionInvitacion;
use App\Models\User;
use App\Services\CicloLaboral\CicloLaboralService;
use App\Services\Flujos\ConstructorEnlaces;
use App\Services\Flujos\Dto\AccionFlujo;
use App\Services\Flujos\Dto\EnlaceFlujo;
use App\Services\Flujos\Dto\SiguientePaso;
use App\Services\Reclutamiento\CadenaContratacionService;
use App\Services\Reclutamiento\FaseContratadoService;

/**
 * Candidato → Agenda → Entrevista → Evaluaciones → Autorización → Contratación →
 * Expediente → Inducción. No decide transiciones: traduce lo que ya dicen
 * CicloLaboralService (acciones permitidas), CadenaContratacionService y
 * FaseContratadoService en una sola acción concreta con su destino.
 */
class FlujoContratacionResolver
{
    /** Palabras del botón. El mensaje conserva la tarea exacta de cada paso. */
    private const ETIQUETAS = [
        'registrar_entrevista' => 'Registrar resultado',
        'registrar_resultados_psicometricas' => 'Continuar evaluación',
        'revisar_psicometricas' => 'Continuar evaluación',
        'registrar_socioeconomico' => 'Continuar evaluación',
        'revisar_socioeconomico' => 'Continuar evaluación',
        'concluir_referencias' => 'Continuar evaluación',
        'iniciar_contratacion' => 'Iniciar contratación',
    ];

    public function __construct(
        private readonly CicloLaboralService $ciclo,
        private readonly CadenaContratacionService $cadena,
        private readonly FaseContratadoService $fases,
        private readonly ConstructorEnlaces $enlaces,
    ) {}

    /**
     * @param  array<string, mixed>|null  $estado  el de obtenerEstado(), si ya se calculó
     */
    public function paraCandidato(Candidato $candidato, User $viewer, ?array $estado = null): SiguientePaso
    {
        $estado ??= $this->ciclo->obtenerEstado($candidato, $viewer, false);
        $colaborador = $candidato->colaborador_id !== null ? Colaborador::withTrashed()->where('id', $candidato->colaborador_id)->first() : null;
        $relacionadas = [
            $colaborador !== null ? $this->enlaces->enlace('expediente', 'Abrir expediente', $this->enlaces->expediente($viewer, $colaborador)) : null,
            $colaborador !== null ? $this->enlaces->enlace('ciclo', 'Ver ciclo laboral', $this->enlaces->ciclo($viewer, $colaborador)) : null,
        ];

        if (in_array($candidato->estado, [EstadoCandidato::EnContratacion, EstadoCandidato::Contratado], true) && $colaborador !== null) {
            return $this->despuesDeSeleccion($candidato, $colaborador, $viewer, $estado, $relacionadas);
        }

        $accion = $candidato->estado === EstadoCandidato::EntrevistaPendiente
            ? $this->accionEntrevista($candidato, $viewer, $estado)
            : $this->primaria($estado);

        if ($candidato->estado === EstadoCandidato::EntrevistaPendiente) {
            $evento = $this->entrevistaProgramada($candidato);
            $relacionadas[] = $evento !== null && $accion?->clave !== 'registrar_resultado'
                ? $this->enlaces->enlace('ver_cita', 'Ver cita en agenda', $this->enlaces->evento($viewer, $evento))
                : null;
        }

        $sinSalida = $candidato->estado->esSalida();

        return $this->armar(
            $estado,
            $sinSalida && $accion === null ? TipoSiguientePaso::Terminado : ($accion !== null ? TipoSiguientePaso::Accion : TipoSiguientePaso::Espera),
            $accion,
            $this->mensaje($estado, $accion, $sinSalida ? 'El proceso de este candidato está cerrado.' : null),
            $relacionadas,
        );
    }

    /**
     * Pantalla del ciclo: la acción ya viene de CicloLaboralService; solo se le
     * pone destino. Sin acción, le toca a alguien más o no hay nada pendiente.
     *
     * @param  array<string, mixed>  $estado  el de obtenerEstado()
     */
    public function paraColaborador(Colaborador $colaborador, User $viewer, array $estado, FlujoOperativo $flujo = FlujoOperativo::Contratacion): SiguientePaso
    {
        $accion = $this->primaria($estado);
        $siguiente = $estado['siguiente_accion'];
        $etapa = $estado['etapa']['clave'];
        $relacionadas = [$this->enlaces->enlace('expediente', 'Abrir expediente', $this->enlaces->expediente($viewer, $colaborador))];

        // Iniciar una baja nunca es el botón principal de alguien que sigue
        // laborando sin pendientes: es un enlace relacionado.
        foreach ($estado['acciones_permitidas'] as $permitida) {
            if ($permitida['clave'] === 'solicitar_baja') {
                $relacionadas[] = $this->enlaces->enlace('solicitar_baja', $permitida['etiqueta'], $this->enlaces->ciclo($viewer, $colaborador, 'solicitar_baja'));
            }
        }

        if (in_array($etapa, ['onboarding', 'contratacion'], true) && ($accion === null || $accion->clave === 'completar_expediente')) {
            $relacionadas[] = $this->enlaces->enlace('generar_contratos', 'Ver contratos', $this->enlaces->generarContratos($viewer, $colaborador));
        }

        return $this->armar(
            $estado,
            $accion !== null ? TipoSiguientePaso::Accion : ($siguiente !== null ? TipoSiguientePaso::Espera : TipoSiguientePaso::Terminado),
            $accion,
            $this->mensaje($estado, $accion, $siguiente === null ? 'No hay nada pendiente en este proceso.' : null),
            $relacionadas,
            $flujo,
        );
    }

    /**
     * @param  array<string, mixed>  $estado
     * @param  list<EnlaceFlujo|null>  $relacionadas
     */
    private function despuesDeSeleccion(Candidato $candidato, Colaborador $colaborador, User $viewer, array $estado, array $relacionadas): SiguientePaso
    {
        $cadena = $this->cadena->paraCandidato($candidato, $viewer);

        if (($cadena['clave'] ?? null) === 'en_contratacion') {
            $invitacion = IncorporacionInvitacion::query()->where('candidato_id', $candidato->id)->latest('id')->first();
            $href = $invitacion !== null ? $this->enlaces->invitacion($viewer, $invitacion) : null;

            return $this->armar(
                $estado,
                $href !== null ? TipoSiguientePaso::Accion : TipoSiguientePaso::Espera,
                $href !== null ? AccionFlujo::navegar('ver_qr', 'Ver QR de registro', $href) : null,
                'La persona aún no completa su registro con el QR de contratación.',
                $relacionadas,
            );
        }

        $estadoColaborador = $this->ciclo->obtenerEstado($colaborador, $viewer, false);
        $etapa = $estadoColaborador['etapa']['clave'];
        $estadoFicha = [...$estado, 'bloqueos' => [...$estado['bloqueos'], ...$estadoColaborador['bloqueos']], 'responsable_actual' => $estadoColaborador['responsable_actual']];

        // Aún se contrata (documentos, contrato, firma): esas acciones ya traen destino.
        if ($etapa === 'contratacion') {
            return $this->desdeColaborador($estadoFicha, $estadoColaborador, $colaborador, $viewer, $relacionadas);
        }

        $fase = $this->fases->paraColaborador($colaborador->id);

        // Misma fase que el tablero y que la cadena: una sola verdad.
        [$accion, $mensaje] = match ($fase) {
            FaseContratadoService::INDUCCION => [
                ($h = $this->enlaces->presentarInduccion($viewer, $colaborador)) !== null ? AccionFlujo::navegar('iniciar_induccion', 'Iniciar inducción', $h) : null,
                'Falta presentarle la inducción.',
            ],
            FaseContratadoService::EVALUACION_INDUCCION => [
                ($h = $this->enlaces->calificarInduccion($viewer, $colaborador)) !== null ? AccionFlujo::navegar('calificar_induccion', 'Calificar inducción', $h) : null,
                'Inducción presentada: falta aprobar su evaluación.',
            ],
            FaseContratadoService::CONTRATO_INDETERMINADO => [null, 'Contrato indeterminado generado: el proceso de contratación concluyó.'],
            default => [null, null],
        };

        if ($fase === FaseContratadoService::CONTRATO_INDETERMINADO) {
            return $this->armar($estadoFicha, TipoSiguientePaso::Terminado, null, (string) $mensaje, $relacionadas);
        }

        if ($mensaje !== null) {
            return $this->armar($estadoFicha, $accion !== null ? TipoSiguientePaso::Accion : TipoSiguientePaso::Espera, $accion, $mensaje, $relacionadas);
        }

        return $this->desdeColaborador($estadoFicha, $estadoColaborador, $colaborador, $viewer, $relacionadas);
    }

    /**
     * Desde la ficha del candidato, las acciones que se atienden en el ciclo llevan a él.
     *
     * @param  array<string, mixed>  $estadoFicha
     * @param  array<string, mixed>  $estadoColaborador
     * @param  list<EnlaceFlujo|null>  $relacionadas
     */
    private function desdeColaborador(array $estadoFicha, array $estadoColaborador, Colaborador $colaborador, User $viewer, array $relacionadas): SiguientePaso
    {
        $accion = $this->primaria($estadoColaborador);

        if ($accion !== null && $accion->href === null) {
            $href = $this->enlaces->ciclo($viewer, $colaborador, $accion->clave);
            $accion = $href !== null ? AccionFlujo::navegar($accion->clave, $accion->etiqueta, $href) : null;
        }

        $siguiente = $estadoColaborador['siguiente_accion'];

        return $this->armar(
            $estadoFicha,
            $accion !== null ? TipoSiguientePaso::Accion : ($siguiente !== null ? TipoSiguientePaso::Espera : TipoSiguientePaso::Terminado),
            $accion,
            $this->mensaje($estadoColaborador, $accion, $siguiente === null ? 'No hay nada pendiente en este proceso.' : null),
            $relacionadas,
        );
    }

    /**
     * Con cita: registrar su resultado. Sin cita: agendarla. Quien aprueba sin
     * acceso a la agenda registra desde la ficha (acción ya permitida).
     *
     * @param  array<string, mixed>  $estado
     */
    private function accionEntrevista(Candidato $candidato, User $viewer, array $estado): ?AccionFlujo
    {
        $evento = $this->entrevistaProgramada($candidato);

        if ($evento !== null && $viewer->can('registrarResultado', $evento) && ($href = $this->enlaces->evento($viewer, $evento)) !== null) {
            return AccionFlujo::navegar('registrar_resultado', 'Registrar resultado', $href);
        }

        if ($evento === null && ($href = $this->enlaces->agendarCandidato($viewer, $candidato)) !== null) {
            return AccionFlujo::navegar('agendar_entrevista', 'Agendar entrevista', $href);
        }

        return $this->primaria($estado);
    }

    private function entrevistaProgramada(Candidato $candidato): ?AgendaEvento
    {
        return AgendaEvento::query()
            ->where('candidato_id', $candidato->id)
            ->where('tipo', TipoAgendaEvento::Entrevista->value)
            ->where('estado', EstadoAgendaEvento::Programado->value)
            ->latest('id')
            ->first();
    }

    /**
     * La primaria de acciones_permitidas (peligrosas y secundarias nunca lo son).
     *
     * @param  array<string, mixed>  $estado
     */
    private function primaria(array $estado): ?AccionFlujo
    {
        foreach ($estado['acciones_permitidas'] as $accion) {
            if ($accion['tipo'] === 'primaria') {
                $etiqueta = self::ETIQUETAS[$accion['clave']] ?? $accion['etiqueta'];

                return isset($accion['href'])
                    ? AccionFlujo::navegar($accion['clave'], $etiqueta, $accion['href'])
                    : AccionFlujo::dialogo($accion['clave'], $etiqueta);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $estado
     */
    private function mensaje(array $estado, ?AccionFlujo $accion, ?string $sinPendiente): string
    {
        if ($accion !== null) {
            foreach ($estado['acciones_permitidas'] as $permitida) {
                if ($permitida['clave'] === $accion->clave) {
                    return $permitida['etiqueta'];
                }
            }

            return match ($accion->clave) {
                'agendar_entrevista' => 'Aún no tiene entrevista agendada.',
                'registrar_resultado' => 'La entrevista está agendada: registra cómo resultó.',
                default => $accion->etiqueta,
            };
        }

        return $sinPendiente ?? ($estado['siguiente_accion']['etiqueta'] ?? 'No hay nada pendiente en este proceso.');
    }

    /**
     * @param  array<string, mixed>  $estado
     * @param  list<EnlaceFlujo|null>  $relacionadas
     */
    private function armar(array $estado, TipoSiguientePaso $tipo, ?AccionFlujo $accion, string $mensaje, array $relacionadas, FlujoOperativo $flujo = FlujoOperativo::Contratacion): SiguientePaso
    {
        return new SiguientePaso(
            flujo: $flujo,
            modo: ModoFlujo::Lineal,
            estado: ['clave' => $estado['estado']['clave'], 'etiqueta' => $estado['estado']['etiqueta']],
            tipo: $tipo,
            accion: $accion,
            mensaje: $mensaje,
            responsable: ['rol' => (string) ($estado['responsable_actual']['rol'] ?? '—'), 'nombre' => $estado['responsable_actual']['nombre'] ?? null],
            progreso: (int) $estado['progreso'],
            pasos: $estado['pasos'],
            bloqueos: $estado['bloqueos'],
            relacionadas: $relacionadas,
        );
    }
}
