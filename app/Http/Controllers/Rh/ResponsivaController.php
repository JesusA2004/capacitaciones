<?php

namespace App\Http\Controllers\Rh;

use App\Enums\CondicionActivoResponsiva;
use App\Enums\TipoResponsiva;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rh\GuardarResponsivaRequest;
use App\Models\Colaborador;
use App\Models\Responsiva;
use App\Services\Responsivas\ResponsivaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Responsivas de equipo desde la ficha del colaborador (expediente). Rutas
 * JSON que consume el componente ResponsivasColaborador.vue; toda la regla
 * de negocio vive en ResponsivaService y la autorización en
 * ResponsivaPolicy (gerente con alcance y RH).
 */
class ResponsivaController extends Controller
{
    public function __construct(private readonly ResponsivaService $responsivas) {}

    public function index(Request $request, Colaborador $colaborador): JsonResponse
    {
        $this->authorize('verDe', [Responsiva::class, $colaborador]);
        $usuario = $request->user();
        $puedeGenerar = $usuario->can('generar', [Responsiva::class, $colaborador]);

        return response()->json([
            'responsivas' => $this->responsivas->delColaborador($colaborador)
                ->map(fn (Responsiva $r): array => $this->responsivas->presentar($r, $usuario))
                ->values(),
            'puede_generar' => $puedeGenerar,
            'valores_iniciales' => $puedeGenerar ? $this->responsivas->valoresIniciales($colaborador, $usuario) : null,
            'tipos' => array_map(fn (TipoResponsiva $t): array => ['value' => $t->value, 'label' => $t->etiqueta()], TipoResponsiva::cases()),
            'condiciones' => array_map(fn (CondicionActivoResponsiva $c): array => ['value' => $c->value, 'label' => $c->etiqueta()], CondicionActivoResponsiva::cases()),
        ]);
    }

    public function vistaPrevia(GuardarResponsivaRequest $request, Colaborador $colaborador): Response
    {
        $this->authorize('generar', [Responsiva::class, $colaborador]);

        return response($this->responsivas->vistaPrevia($colaborador, $request->validated(), $request->user()), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="Vista previa responsiva.pdf"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function store(GuardarResponsivaRequest $request, Colaborador $colaborador): JsonResponse
    {
        $this->authorize('generar', [Responsiva::class, $colaborador]);
        $responsiva = $this->responsivas->crear($colaborador, $request->validated(), $request->user());

        return response()->json([
            'message' => sprintf('Responsiva %s generada. Imprímela y recaba las firmas.', $responsiva->folio),
            'responsiva' => $this->responsivas->presentar($responsiva, $request->user()),
        ], 201);
    }

    public function pdf(Responsiva $responsiva): StreamedResponse
    {
        $this->authorize('ver', $responsiva);

        return $this->responsivas->respuestaPdf($responsiva);
    }

    public function firmada(Responsiva $responsiva): StreamedResponse
    {
        $this->authorize('ver', $responsiva);

        return $this->responsivas->respuestaPdf($responsiva, firmada: true);
    }

    /**
     * Flujo vigente: la responsiva se imprime y se firma en papel; se marca
     * «firmada físicamente» sin subir ningún archivo (solo queda constancia
     * de quién y cuándo). El escaneo firmado ya no se sube; firmada() sigue
     * sirviendo el archivo de los registros antiguos (legacy, solo lectura).
     */
    public function marcarFirmadaFisicamente(Request $request, Responsiva $responsiva): JsonResponse
    {
        $this->authorize('operar', $responsiva);

        $responsiva = $this->responsivas->marcarFirmadaFisicamente($responsiva, $request->user());

        return response()->json([
            'message' => sprintf('Responsiva %s marcada como firmada físicamente.', $responsiva->folio),
            'responsiva' => $this->responsivas->presentar($responsiva->load(['activos', 'entregadoPor:id,name,apellidos,colaborador_id', 'entregadoPor.colaborador', 'registradoPor:id,name,apellidos,colaborador_id', 'registradoPor.colaborador']), $request->user()),
        ]);
    }

    public function devolucion(Request $request, Responsiva $responsiva): JsonResponse
    {
        $this->authorize('operar', $responsiva);
        $datos = $request->validate([
            'devuelta_en' => ['required', 'date', 'before_or_equal:today'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ], ['devuelta_en.before_or_equal' => 'La fecha de devolución no puede ser futura.'], ['devuelta_en' => 'fecha de devolución']);

        $responsiva = $this->responsivas->registrarDevolucion($responsiva, $datos, $request->user());

        return response()->json([
            'message' => sprintf('Se registró la devolución del equipo de la responsiva %s.', $responsiva->folio),
            'responsiva' => $this->responsivas->presentar($responsiva->load(['activos', 'entregadoPor:id,name,apellidos,colaborador_id', 'entregadoPor.colaborador', 'registradoPor:id,name,apellidos,colaborador_id', 'registradoPor.colaborador']), $request->user()),
        ]);
    }

    public function cancelar(Request $request, Responsiva $responsiva): JsonResponse
    {
        $this->authorize('cancelar', $responsiva);
        $datos = $request->validate([
            'motivo' => ['required', 'string', 'min:5', 'max:500'],
        ], [], ['motivo' => 'motivo de cancelación']);

        $responsiva = $this->responsivas->cancelar($responsiva, (string) $datos['motivo'], $request->user());

        return response()->json([
            'message' => sprintf('Responsiva %s cancelada.', $responsiva->folio),
            'responsiva' => $this->responsivas->presentar($responsiva->load(['activos', 'entregadoPor:id,name,apellidos,colaborador_id', 'entregadoPor.colaborador', 'registradoPor:id,name,apellidos,colaborador_id', 'registradoPor.colaborador']), $request->user()),
        ]);
    }
}
