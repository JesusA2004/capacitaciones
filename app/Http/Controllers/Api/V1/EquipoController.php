<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EstadoEvaluacionPrueba;
use App\Enums\EstadoSolicitudInterna;
use App\Http\Controllers\Controller;
use App\Http\Requests\CicloLaboral\DecisionRequest;
use App\Models\Colaborador;
use App\Models\EvaluacionPeriodoPrueba;
use App\Models\SolicitudInterna;
use App\Services\Colaboradores\JerarquiaColaboradorService;
use App\Services\Solicitudes\AprobacionJerarquicaService;
use App\Services\Solicitudes\AprobacionOrganizacionalService;
use App\Services\Solicitudes\VistoBuenoService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Vista de jefe: su equipo directo, solicitudes del equipo que esperan su
 * visto bueno, evaluaciones de periodo de prueba que le corresponden y el
 * visto bueno en sí. Todo acotado por la estructura jerárquica real
 * (jefe_id / gerente_id), no solo por rol.
 */
class EquipoController extends Controller
{
    public function __construct(
        private readonly JerarquiaColaboradorService $jerarquia,
        private readonly AprobacionJerarquicaService $aprobaciones,
        private readonly VistoBuenoService $vistoBueno,
        private readonly AprobacionOrganizacionalService $motor,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $yo = $this->colaborador($request);

        return response()->json(['data' => $this->jerarquia->subordinadosDirectos($yo)->map(fn (Colaborador $c) => $this->jerarquia->resumen($c))->values()]);
    }

    public function pendientes(Request $request): JsonResponse
    {
        $yo = $this->colaborador($request);

        // Solicitudes "Recibidas" donde a MÍ me toca el siguiente visto bueno
        // (gerente de su sucursal o regional de su región).
        $abiertas = SolicitudInterna::query()
            ->where('estado', EstadoSolicitudInterna::Enviada->value)
            ->whereIn('tipo', (array) config('solicitudes.visto_bueno_jefe', []))
            ->with(['colaborador:id,name,apellidos,numero_empleado,jefe_id,gerente_id,sucursal_principal_id', 'usuario.colaborador:id,name,apellidos,numero_empleado,jefe_id,gerente_id,sucursal_principal_id', 'aprobaciones'])
            ->orderBy('created_at')
            ->limit(500)
            ->get();

        $solicitudes = $abiertas
            ->filter(fn (SolicitudInterna $s) => $this->aprobaciones->puedeDarVistoBueno($request->user(), $s))
            ->take(200)
            ->values();

        // Donde me toca un paso POSTERIOR: se ven con su flujo, sin botón
        // activo («Esperando jefe directo»), hasta que llegue mi turno.
        $enEspera = $abiertas
            ->reject(fn (SolicitudInterna $s) => $solicitudes->contains('id', $s->id))
            ->filter(fn (SolicitudInterna $s) => $this->aprobaciones->esAprobadorDe($request->user(), $s))
            ->take(200)
            ->values();

        $item = fn (SolicitudInterna $s) => [
            'id' => $s->id,
            'folio' => $s->folio,
            'tipo' => $s->tipo->value,
            'tipo_etiqueta' => $s->tipo->etiqueta(),
            'estado' => $s->estado->value,
            'colaborador' => $s->personaSolicitante()?->nombreCompleto(),
            'fecha_inicio' => $s->fecha_inicio?->toDateString(),
            'fecha_fin' => $s->fecha_fin?->toDateString(),
            'monto_solicitado' => $s->monto_solicitado,
            'requiere_visto_bueno' => $this->aprobaciones->requiereVistoBuenoJefe($s),
            'visto_bueno' => $this->aprobaciones->decisionJefe($s)?->decision,
            'vistos_buenos' => $this->aprobaciones->resumen($s),
            'creada_en' => $s->created_at?->toIso8601String(),
            ...$this->flujoApi($request, $s),
        ];

        $evaluaciones = EvaluacionPeriodoPrueba::query()
            ->whereIn('estado', [EstadoEvaluacionPrueba::Pendiente->value, EstadoEvaluacionPrueba::Devuelta->value])
            ->where(fn (Builder $q) => $q->where('evaluador_colaborador_id', $yo->id)->orWhereHas('colaborador', fn (Builder $c) => $c->where('jefe_id', $yo->id)))
            ->with(['colaborador:id,name,apellidos,numero_empleado', 'contrato:id,fecha_fin'])
            ->orderBy('fecha_limite')
            ->get();

        return response()->json(['data' => [
            'solicitudes' => $solicitudes->map($item)->values(),
            'en_espera' => $enEspera->map($item)->values(),
            'evaluaciones' => $evaluaciones->map(fn (EvaluacionPeriodoPrueba $e) => [
                'id' => $e->id,
                'colaborador' => $e->colaborador->nombreCompleto(),
                'estado' => $e->estado->value,
                'fecha_limite' => $e->fecha_limite?->toDateString(),
            ])->values(),
        ], 'meta' => ['aprobaciones' => $this->motor->conteoPendientes($request->user())]]);
    }

    /**
     * Badge «Mis aprobaciones pendientes»: el MISMO conteo que la web
     * (AprobacionOrganizacionalService::conteoPendientes): solo lo que la
     * cuenta puede aprobar ahora.
     */
    public function conteo(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->motor->conteoPendientes($request->user())]);
    }

    public function vistoBueno(DecisionRequest $request, SolicitudInterna $solicitud): JsonResponse
    {
        abort_unless($this->aprobaciones->esAprobadorDe($request->user(), $solicitud), 403, 'Esta solicitud no la apruebas tú: la revisan sus responsables de aprobación.');
        $request->validate(['aprobado' => ['required', 'boolean']]);

        $decision = $this->vistoBueno->registrar($solicitud, $request->user(), $request->boolean('aprobado'), $request->validated('comentario'));

        return response()->json(['data' => [
            'solicitud_id' => $solicitud->id,
            'decision' => $decision->decision,
            'estado_solicitud' => $solicitud->refresh()->estado->value,
        ]]);
    }

    /**
     * @return array{flujo: list<array<string, mixed>>, puede_actuar_usuario_actual: bool, accion_usuario_actual: string|null, motivo_no_puede: string|null, pendiente_de: array<string, mixed>|null, estado_flujo_texto: string}
     */
    private function flujoApi(Request $request, SolicitudInterna $solicitud): array
    {
        $flujo = $this->motor->flujo($solicitud, $request->user());

        return [
            'flujo' => $flujo['pasos'],
            'puede_actuar_usuario_actual' => $flujo['puede_actuar_usuario_actual'],
            'accion_usuario_actual' => $flujo['accion_usuario_actual'],
            'motivo_no_puede' => $flujo['motivo_no_puede'],
            'pendiente_de' => $flujo['pendiente_de'],
            'estado_flujo_texto' => $flujo['estado_texto'],
        ];
    }

    private function colaborador(Request $request): Colaborador
    {
        $colaborador = $request->user()->colaborador;

        if ($colaborador === null) {
            abort(404, 'Tu cuenta no está vinculada a un colaborador.');
        }

        return $colaborador;
    }
}
