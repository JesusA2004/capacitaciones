<?php

namespace App\Http\Controllers;

use App\Services\Onboarding\AsignacionInduccionService;
use App\Services\Onboarding\InduccionPdfService;
use App\Services\Onboarding\MaterialInduccionService;
use App\Services\Onboarding\SesionInduccionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * «Inducción institucional» (modo operativo, solo RH con onboarding.gestionar):
 * el PDF OFICIAL de la inducción en el visor (p. ej. para presentarla por
 * Meet), el material de explicación (audio/video), «Asignar inducción» a
 * todos / personas / sucursales con el estado de cada persona
 * (AsignacionInduccionService) y «Ver como colaborador». El colaborador la ve
 * por su cuenta en «Mi espacio» mientras la tiene asignada
 * (Portal\MiProcesoController::verInduccion). La sesión en vivo quedó tras el
 * flag legacy (SesionInduccionService).
 */
class InduccionPresentacionController extends Controller
{
    public function __construct(
        private readonly InduccionPdfService $pdf,
        private readonly SesionInduccionService $sesiones,
        private readonly MaterialInduccionService $materiales,
        private readonly AsignacionInduccionService $asignaciones,
    ) {}

    /**
     * @throws AuthorizationException sin onboarding.gestionar
     */
    public function show(Request $request): Response
    {
        $usuario = $request->user();

        // onboarding.gestionar + la acción «Presentar inducción institucional»
        // (Roles y permisos).
        if (! $this->sesiones->puedeGestionar($usuario) || ! $usuario->can('onboarding.presentar_induccion')) {
            throw new AuthorizationException('Solo Recursos Humanos presenta la inducción.');
        }

        // El flujo de «sesión en vivo» quedó tras el flag legacy; por defecto RH
        // ya no presenta en vivo, solo repasa el PDF y gestiona el material que
        // el colaborador verá solo (audio/video).
        $legacy = (bool) config('features.induccion_sesiones_legacy');

        // Subir / reemplazar / retirar el material exige además
        // onboarding.configurar (mismo chequeo que MaterialInduccionController).
        $puedeConfigurarMaterial = $usuario->can('onboarding.configurar');

        return Inertia::render('Portal/Induccion', [
            'modoPresentacion' => true,
            'pdf' => $this->pdf->datosVisor(),
            'sesionRh' => $legacy ? $this->sesiones->datosPantalla($usuario) : null,
            'materialRh' => $this->materialRh(),
            'puedeConfigurarMaterial' => $puedeConfigurarMaterial,
            'rutasMaterial' => $puedeConfigurarMaterial ? [
                'store' => route('rh.onboarding.induccion.material.store'),
                'destroy' => route('rh.onboarding.induccion.material.destroy'),
            ] : null,
            // «Asignar inducción» + estado de cada persona (AsignacionInduccionService).
            'asignaciones' => $this->asignaciones->panorama($usuario),
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
     * colaborador mientras tiene su inducción asignada y por ver.
     */
    public function pdf(Request $request): BinaryFileResponse
    {
        return $this->pdf->respuesta($request->user());
    }
}
