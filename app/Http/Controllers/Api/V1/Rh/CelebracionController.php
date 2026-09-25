<?php

namespace App\Http\Controllers\Api\V1\Rh;

use App\Enums\TipoCelebracion;
use App\Http\Controllers\Controller;
use App\Models\BirthdayGreeting;
use App\Models\Colaborador;
use App\Services\Celebraciones\CelebracionService;
use App\Services\Celebraciones\FechasCelebracion;
use App\Services\Cumpleanos\MuroCumpleanosService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Acciones de RH sobre celebraciones desde la app (docs/API_MOVIL.md →
 * "Celebraciones (RH)"). Mismo CelebracionService que el panel web.
 */
class CelebracionController extends Controller
{
    public function __construct(
        private readonly CelebracionService $celebraciones,
        private readonly MuroCumpleanosService $muro,
    ) {}

    public function aniversarios(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('celebraciones.ver'), 403);

        $hoy = FechasCelebracion::hoy();
        $desde = $request->date('desde') ?? $hoy;
        $hasta = $request->date('hasta') ?? $hoy->copy()->addDays(max(0, min(366, $request->integer('dias', 30))));
        $filtros = [
            'empresa_id' => $request->integer('empresa_id') ?: null,
            'sucursal_id' => $request->integer('sucursal_id') ?: null,
            'departamento_id' => $request->integer('departamento_id') ?: null,
            'busqueda' => $request->string('q')->toString() ?: null,
        ];

        return response()->json([
            'data' => $this->celebraciones->filasAniversarios($request->user(), Carbon::parse($desde->toDateString()), Carbon::parse($hasta->toDateString()), $filtros),
            'meta' => ['desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString()],
        ]);
    }

    /**
     * Evento del día de un colaborador (lo crea si le toca hoy).
     */
    public function evento(Request $request, Colaborador $colaborador, string $tipo): JsonResponse
    {
        $celebracion = $this->eventoDeHoy($colaborador, $tipo);
        $this->authorize('gestionar', $celebracion);

        return response()->json(['data' => $this->celebraciones->aArray($celebracion, $request->user())]);
    }

    public function enviar(Request $request, Colaborador $colaborador, string $tipo): JsonResponse
    {
        $celebracion = $this->eventoDeHoy($colaborador, $tipo);
        $this->authorize('enviar', $celebracion);
        $celebracion = $this->celebraciones->enviarAlColaborador($celebracion, $request->user());

        return response()->json(['data' => $this->celebraciones->aArray($celebracion, $request->user()), 'message' => 'Felicitación enviada.']);
    }

    public function avisarATodos(Request $request, Colaborador $colaborador, string $tipo): JsonResponse
    {
        $celebracion = $this->eventoDeHoy($colaborador, $tipo);
        $this->authorize('enviar', $celebracion);
        $resultado = $this->celebraciones->avisarATodos($celebracion, $request->user());

        return response()->json([
            'data' => $this->celebraciones->aArray($resultado['celebracion'], $request->user()),
            'avisado' => $resultado['avisado'],
            'destinatarios' => $resultado['destinatarios'],
            'message' => $resultado['avisado']
                ? sprintf('Se avisó a %d colaborador(es).', $resultado['destinatarios'])
                : sprintf('Aviso general enviado el %s.', $resultado['celebracion']->avisada_todos_at?->timezone(FechasCelebracion::ZONA)->format('d/m/Y H:i')),
        ]);
    }

    public function regenerarTarjeta(Request $request, Colaborador $colaborador, string $tipo): JsonResponse
    {
        $celebracion = $this->eventoDeHoy($colaborador, $tipo);
        $this->authorize('gestionar', $celebracion);
        $celebracion = $this->celebraciones->tarjeta($celebracion, regenerar: true);

        return response()->json(['data' => $this->celebraciones->aArray($celebracion, $request->user())]);
    }

    public function recepcion(Request $request, BirthdayGreeting $celebracion): JsonResponse
    {
        $this->authorize('enviar', $celebracion);
        $datos = $request->validate(['abierta' => ['required', 'boolean']]);

        (bool) $datos['abierta']
            ? $this->muro->abrir($celebracion, $request->user(), avisar: false)
            : $this->muro->cerrar($celebracion);

        return response()->json(['data' => $this->celebraciones->aArray($celebracion->refresh(), $request->user())]);
    }

    private function eventoDeHoy(Colaborador $colaborador, string $tipo): BirthdayGreeting
    {
        $tipoCelebracion = TipoCelebracion::tryFrom($tipo);
        abort_if($tipoCelebracion === null, 404);

        return $this->celebraciones->delDia($colaborador, $tipoCelebracion)
            ?? throw ValidationException::withMessages(['celebracion' => 'Hoy no hay una celebración de este tipo para esta persona.']);
    }
}
