<?php

namespace App\Http\Controllers;

use App\Services\Onboarding\InduccionPdfService;
use App\Services\Onboarding\MaterialInduccionService;
use App\Services\Onboarding\SesionInduccionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * «Presentar inducción» (modo operativo, solo RH con onboarding.gestionar):
 * el PDF OFICIAL de la inducción en el visor, p. ej. por Meet, más la sesión
 * de presentación — a quién se la presenta, «Enviar evaluación» y el
 * seguimiento — vía SesionInduccionService. El colaborador nunca presenta:
 * la ve en «Mi espacio» solo durante la sesión que lo incluye
 * (Portal\MiProcesoController::verInduccion).
 */
class InduccionPresentacionController extends Controller
{
    public function __construct(
        private readonly InduccionPdfService $pdf,
        private readonly SesionInduccionService $sesiones,
        private readonly MaterialInduccionService $materiales,
    ) {}

    /**
     * @throws AuthorizationException sin onboarding.gestionar
     */
    public function show(Request $request): Response
    {
        $usuario = $request->user();

        if (! $this->sesiones->puedeGestionar($usuario)) {
            throw new AuthorizationException('Solo Recursos Humanos presenta la inducción.');
        }

        // El flujo de «sesión en vivo» quedó tras el flag legacy; por defecto RH
        // ya no presenta en vivo, solo repasa el PDF y gestiona el material que
        // el colaborador verá solo (audio/video).
        $legacy = (bool) config('features.induccion_sesiones_legacy');

        return Inertia::render('Portal/Induccion', [
            'modoPresentacion' => true,
            'pdf' => $this->pdf->datosVisor(),
            'sesionRh' => $legacy ? $this->sesiones->datosPantalla($usuario) : null,
            'materialRh' => $this->materialRh(),
            'rutasMaterial' => [
                'store' => route('rh.onboarding.induccion.material.store'),
                'destroy' => route('rh.onboarding.induccion.material.destroy'),
            ],
        ]);
    }

    /**
     * Material de explicación vigente de la inducción para la pantalla de RH
     * (tipo + estado del recurso). null si no hay módulo o material.
     *
     * @return array{tipo: string, tipo_etiqueta: string, recurso_nombre: string|null, recurso_estado: string, duracion_segundos: int|null}|null
     */
    private function materialRh(): ?array
    {
        $modulo = $this->materiales->moduloInstitucional();

        if ($modulo === null) {
            return null;
        }

        $material = $this->materiales->materialActivo($modulo);

        if ($material === null) {
            return null;
        }

        return [
            'tipo' => $material->tipo->value,
            'tipo_etiqueta' => $material->tipo->etiqueta(),
            'recurso_nombre' => $material->recurso?->nombre_original,
            'recurso_estado' => $material->recurso?->estado->value ?? 'desconocido',
            'duracion_segundos' => $material->recurso?->duracion_segundos,
        ];
    }

    /**
     * El PDF oficial, solo con sesión (nunca una URL pública): RH, o el
     * colaborador mientras está en la sesión de RH que lo incluye.
     */
    public function pdf(Request $request): BinaryFileResponse
    {
        return $this->pdf->respuesta($request->user());
    }
}
