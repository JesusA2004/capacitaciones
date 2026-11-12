<?php

namespace App\Http\Controllers\Registro;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\FinalizarRegistroRequest;
use App\Http\Requests\Api\V1\GuardarDatosRegistroRequest;
use App\Http\Requests\Api\V1\SubirDocumentoIncorporacionRequest;
use App\Http\Requests\Api\V1\VistaPreviaCartaCompromisoRequest;
use App\Models\Colaborador;
use App\Models\DocumentType;
use App\Models\User;
use App\Services\Expedientes\CartaCompromisoService;
use App\Services\Incorporacion\IncorporacionService;
use App\Services\Incorporacion\RegistroAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use RuntimeException;

/**
 * Asistente de registro en la WEB (resources/js/pages/Registro/Asistente.vue):
 * los mismos pasos y reglas que la app (src/app/registro.tsx), siempre sobre
 * la cuenta en sesión. Toda regla vive en RegistroAppService (la misma que
 * Api\V1\RegistroAppController); los documentos se suben con
 * IncorporacionService::subirDocumento (igual que
 * POST api/v1/colaborador/incorporacion/documentos/{tipo}/subir) y la vista
 * previa de la carta sale de CartaCompromisoService.
 */
class AsistenteRegistroController extends Controller
{
    public function __construct(private readonly RegistroAppService $registro) {}

    public function show(Request $request): InertiaResponse|RedirectResponse
    {
        $usuario = $this->usuario($request);

        if (! $this->registro->aplica($usuario)) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('Registro/Asistente', [
            'estado' => $this->registro->estadoWeb($usuario),
            'usuario' => [
                'username' => $usuario->username,
                'nombre' => trim(sprintf('%s %s', $usuario->name, (string) $usuario->apellidos)),
            ],
        ]);
    }

    public function datos(GuardarDatosRegistroRequest $request): JsonResponse
    {
        $usuario = $this->usuario($request);
        $datos = $request->validated('datos');
        $medicos = $request->validated('datos_medicos');

        $this->registro->guardarDatos(
            $usuario,
            is_array($datos) ? $datos : [],
            is_array($medicos) ? [
                'condicion_medica' => isset($medicos['condicion_medica']) ? (string) $medicos['condicion_medica'] : null,
                'alergias' => isset($medicos['alergias']) ? (string) $medicos['alergias'] : null,
            ] : null,
        );

        return response()->json(['data' => $this->registro->estadoWeb($usuario)]);
    }

    public function subirDocumento(SubirDocumentoIncorporacionRequest $request, DocumentType $documentoRequerido, IncorporacionService $incorporacion): JsonResponse
    {
        $usuario = $this->usuario($request);

        try {
            $incorporacion->subirDocumento($this->colaboradorDe($usuario), $documentoRequerido, $request->file('archivo'), $usuario->id, $request->boolean('incluye_par'), $request->file('archivo_reverso'));
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['archivo' => $e->getMessage()]);
        }

        return response()->json(['data' => $this->registro->estadoWeb($usuario->refresh())]);
    }

    public function vistaPreviaCarta(VistaPreviaCartaCompromisoRequest $request, CartaCompromisoService $cartas): Response
    {
        $pdf = $cartas->vistaPrevia($this->colaboradorDe($this->usuario($request)), $request->documentos());

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="Vista previa carta compromiso.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * Cierra el registro (RegistroAppService::finalizar). Con éxito la web
     * deja de regresar a la persona al asistente.
     */
    public function finalizar(FinalizarRegistroRequest $request): JsonResponse
    {
        $resultado = $this->registro->finalizar($this->usuario($request), $request->documentosSinTener());

        return response()->json([
            'data' => [
                'registro_completo' => $resultado['registro_completo'] ?? true,
                'carta_compromiso' => $resultado['carta_compromiso'] ?? null,
            ],
            'redirect' => route('dashboard'),
            'message' => '¡Listo! Terminaste tu registro.',
        ]);
    }

    private function usuario(Request $request): User
    {
        $usuario = $request->user();
        abort_if($usuario === null, 401);

        return $usuario;
    }

    private function colaboradorDe(User $usuario): Colaborador
    {
        $colaborador = $usuario->colaborador;
        abort_if($colaborador === null, 422, 'Tu cuenta no tiene un colaborador enlazado.');

        return $colaborador;
    }
}
