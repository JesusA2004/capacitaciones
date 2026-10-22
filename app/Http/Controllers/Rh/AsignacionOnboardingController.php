<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Services\Onboarding\AcuseDescriptivoPuestoService;
use App\Services\Onboarding\AsignacionInduccionService;
use App\Services\Onboarding\DestinatariosOnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * «Asignar a…» de Onboarding (RH, onboarding.gestionar): la presentación y
 * el material de la Inducción Mr. Lana y el descriptivo de puesto, a todos,
 * a una o varias personas o por sucursal — siempre dentro del alcance del
 * módulo. Delgado: la regla vive en AsignacionInduccionService,
 * AcuseDescriptivoPuestoService y DestinatariosOnboardingService.
 */
class AsignacionOnboardingController extends Controller
{
    public function __construct(
        private readonly DestinatariosOnboardingService $destinatarios,
        private readonly AsignacionInduccionService $induccion,
        private readonly AcuseDescriptivoPuestoService $descriptivos,
    ) {}

    /** Buscador del diálogo (lista completa al enfocar, filtra al escribir). */
    public function colaboradores(Request $request): JsonResponse
    {
        $termino = $request->query('q');

        return response()->json(['data' => $this->destinatarios->buscar($request->user(), is_string($termino) ? $termino : '')]);
    }

    public function sucursales(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->destinatarios->sucursales($request->user())]);
    }

    public function previsualizarInduccion(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->induccion->previsualizar($request->user(), $this->datosInduccion($request))]);
    }

    public function asignarInduccion(Request $request): RedirectResponse
    {
        $resultado = $this->induccion->asignar($request->user(), $this->datosInduccion($request));

        $mensaje = $resultado['asignados'] > 0
            ? sprintf('Inducción asignada a %d persona(s)%s: ya la ven en Mi espacio y les llegó el aviso.', $resultado['asignados'], $resultado['reinducciones'] > 0 ? sprintf(' (%d como reinducción)', $resultado['reinducciones']) : '')
            : 'No se asignó a nadie.';

        return back()->with('toast', [
            'type' => $resultado['omitidos'] === [] ? 'success' : 'warning',
            'message' => $mensaje.$this->textoOmitidos($resultado['omitidos']),
        ]);
    }

    public function retirarInduccion(Request $request, Colaborador $colaborador): RedirectResponse
    {
        $datos = $request->validate(['motivo' => ['nullable', 'string', 'max:500']]);
        $this->induccion->retirar($colaborador, $request->user(), $datos['motivo'] ?? null);

        return back()->with('toast', ['type' => 'success', 'message' => sprintf('Se retiró la inducción asignada a %s.', $colaborador->nombreCompleto())]);
    }

    public function previsualizarDescriptivo(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->descriptivos->previsualizarAsignacion($request->user(), $this->datosDestinatarios($request))]);
    }

    public function asignarDescriptivo(Request $request): RedirectResponse
    {
        $resultado = $this->descriptivos->asignar($request->user(), $this->datosDestinatarios($request));

        return back()->with('toast', [
            'type' => $resultado['omitidos'] === [] ? 'success' : 'warning',
            'message' => ($resultado['asignados'] > 0
                ? sprintf('Descriptivo de puesto enviado a %d persona(s): les llegó el aviso.', $resultado['asignados'])
                : 'No se envió a nadie.').$this->textoOmitidos($resultado['omitidos']),
        ]);
    }

    /**
     * @return array{modo: string, colaborador_ids: array<int, int|string>, sucursal_ids: array<int, int|string>}
     */
    private function datosDestinatarios(Request $request): array
    {
        $datos = $request->validate([
            'modo' => ['required', 'string', 'in:todos,personas,sucursales'],
            'colaborador_ids' => ['nullable', 'array', 'max:'.DestinatariosOnboardingService::MAXIMO],
            'colaborador_ids.*' => ['integer'],
            'sucursal_ids' => ['nullable', 'array', 'max:200'],
            'sucursal_ids.*' => ['integer'],
        ]);

        return [
            'modo' => (string) $datos['modo'],
            'colaborador_ids' => (array) ($datos['colaborador_ids'] ?? []),
            'sucursal_ids' => (array) ($datos['sucursal_ids'] ?? []),
        ];
    }

    /**
     * @return array{modo: string, colaborador_ids: array<int, int|string>, sucursal_ids: array<int, int|string>, incluye_presentacion: bool, incluye_material: bool, fecha_limite: string|null, mensaje: string|null, reasignar_aprobadas: bool}
     */
    private function datosInduccion(Request $request): array
    {
        $extra = $request->validate([
            'incluye_presentacion' => ['required', 'boolean'],
            'incluye_material' => ['required', 'boolean'],
            'fecha_limite' => ['nullable', 'date'],
            'mensaje' => ['nullable', 'string', 'max:'.AsignacionInduccionService::MENSAJE_MAX],
            'reasignar_aprobadas' => ['nullable', 'boolean'],
        ]);

        return [
            ...$this->datosDestinatarios($request),
            'incluye_presentacion' => (bool) $extra['incluye_presentacion'],
            'incluye_material' => (bool) $extra['incluye_material'],
            'fecha_limite' => isset($extra['fecha_limite']) ? (string) $extra['fecha_limite'] : null,
            'mensaje' => isset($extra['mensaje']) ? (string) $extra['mensaje'] : null,
            'reasignar_aprobadas' => (bool) ($extra['reasignar_aprobadas'] ?? false),
        ];
    }

    /**
     * @param  list<array{id: int, nombre: string, motivo: string}>  $omitidos
     */
    private function textoOmitidos(array $omitidos): string
    {
        if ($omitidos === []) {
            return '';
        }

        $lista = collect($omitidos)->take(5)->map(fn (array $o): string => sprintf('%s (%s)', $o['nombre'], rtrim($o['motivo'], '.')))->implode('; ');

        return sprintf(' Se omitió a %d: %s%s.', count($omitidos), $lista, count($omitidos) > 5 ? '…' : '');
    }
}
