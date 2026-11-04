<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Incorporacion\GuardarSeccionSolicitudEmpleoRequest;
use App\Models\Colaborador;
use App\Models\User;
use App\Services\Incorporacion\RegistroAppService;
use App\Services\Incorporacion\SolicitudEmpleo\PerfilIngresoService;
use App\Services\Incorporacion\SolicitudEmpleo\SolicitudEmpleoRegistroService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Solicitud de empleo + Estudio socioeconómico dentro del registro en la
 * app (siempre de la cuenta en sesión, nunca por id). Contrato JSON en
 * docs/SOLICITUD_EMPLEO_Y_SOCIOECONOMICO.md; reglas en
 * App\Services\Incorporacion\SolicitudEmpleo\PerfilIngresoService.
 */
class SolicitudEmpleoRegistroController extends Controller
{
    public function __construct(
        private readonly PerfilIngresoService $perfil,
        private readonly RegistroAppService $registro,
        private readonly SolicitudEmpleoRegistroService $solicitud,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->estado($request->user(), $this->colaboradorDe($request))]);
    }

    public function guardar(GuardarSeccionSolicitudEmpleoRequest $request, string $seccion): JsonResponse
    {
        $usuario = $request->user();
        $colaborador = $this->colaboradorDe($request);

        // Captura inicial: solo mientras el registro está abierto; después,
        // cualquier cambio es por solicitud de corrección.
        if (! $this->registro->aplica($usuario)) {
            throw ValidationException::withMessages([
                'registro' => 'Tu registro ya está completo. Si necesitas cambiar un dato, solicita una corrección desde la app.',
            ]);
        }

        $this->perfil->guardarSeccion($colaborador, $seccion, $request->datos(), $request->noAplica(), $usuario, PerfilIngresoService::ORIGEN_APP);

        return response()->json(['data' => $this->estado($usuario, $colaborador->refresh()), 'message' => 'Sección guardada.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function estado(User $usuario, Colaborador $colaborador): array
    {
        $abierto = $this->registro->aplica($usuario);

        return [
            'registro_abierto' => $abierto,
            'exigida' => $this->solicitud->exigidaEnRegistro(),
            ...$this->perfil->estado($colaborador, PerfilIngresoService::ORIGEN_APP, $abierto),
        ];
    }

    private function colaboradorDe(Request $request): Colaborador
    {
        $colaborador = $request->user()?->colaborador;
        abort_if($colaborador === null, 422, 'Tu cuenta no tiene un colaborador enlazado.');

        return $colaborador;
    }
}
