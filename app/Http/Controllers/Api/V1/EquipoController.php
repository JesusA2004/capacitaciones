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
    ) {}

    public function index(Request $request): JsonResponse
    {
        $yo = $this->colaborador($request);

        return response()->json(['data' => $this->jerarquia->subordinadosDirectos($yo)->map(fn (Colaborador $c) => $this->jerarquia->resumen($c))->values()]);
    }

    public function pendientes(Request $request): JsonResponse
    {
        $yo = $this->colaborador($request);

        $solicitudes = SolicitudInterna::query()
            ->whereIn('estado', [EstadoSolicitudInterna::Enviada->value, EstadoSolicitudInterna::EnRevision->value])
            ->where(fn (Builder $q) => $q
                ->whereHas('colaborador', fn (Builder $c) => $c->where('jefe_id', $yo->id)->orWhere('gerente_id', $yo->id))
                ->orWhereHas('usuario.colaborador', fn (Builder $c) => $c->where('jefe_id', $yo->id)->orWhere('gerente_id', $yo->id)))
            ->with(['colaborador:id,name,apellidos,numero_empleado,jefe_id,gerente_id', 'usuario.colaborador:id,name,apellidos,numero_empleado,jefe_id,gerente_id', 'aprobaciones'])
            ->orderBy('created_at')
            ->limit(200)
            ->get();

        $evaluaciones = EvaluacionPeriodoPrueba::query()
            ->whereIn('estado', [EstadoEvaluacionPrueba::Pendiente->value, EstadoEvaluacionPrueba::Devuelta->value])
            ->where(fn (Builder $q) => $q->where('evaluador_colaborador_id', $yo->id)->orWhereHas('colaborador', fn (Builder $c) => $c->where('jefe_id', $yo->id)))
            ->with(['colaborador:id,name,apellidos,numero_empleado', 'contrato:id,fecha_fin'])
            ->orderBy('fecha_limite')
            ->get();

        return response()->json(['data' => [
            'solicitudes' => $solicitudes->map(fn (SolicitudInterna $s) => [
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
                'creada_en' => $s->created_at?->toIso8601String(),
            ])->values(),
            'evaluaciones' => $evaluaciones->map(fn (EvaluacionPeriodoPrueba $e) => [
                'id' => $e->id,
                'colaborador' => $e->colaborador->nombreCompleto(),
                'estado' => $e->estado->value,
                'fecha_limite' => $e->fecha_limite?->toDateString(),
            ])->values(),
        ]]);
    }

    public function vistoBueno(DecisionRequest $request, SolicitudInterna $solicitud): JsonResponse
    {
        abort_unless($this->aprobaciones->puedeDarVistoBueno($request->user(), $solicitud), 403, 'Solo el jefe inmediato o gerente del colaborador puede dar el visto bueno.');
        $request->validate(['aprobado' => ['required', 'boolean']]);

        $decision = $this->vistoBueno->registrar($solicitud, $request->user(), $request->boolean('aprobado'), $request->validated('comentario'));

        return response()->json(['data' => [
            'solicitud_id' => $solicitud->id,
            'decision' => $decision->decision,
            'estado_solicitud' => $solicitud->refresh()->estado->value,
        ]]);
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
