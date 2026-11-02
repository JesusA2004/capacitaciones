<?php

namespace App\Http\Controllers;

use App\Models\Colaborador;
use App\Services\AlcanceOrganizacionalService;
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
        $puedeConfigurarMaterial = $this->materiales->puedeConfigurar($usuario);

        return Inertia::render('Portal/Induccion', [
            'modoPresentacion' => true,
            // ?colaborador= desde «Iniciar inducción»: ya filtrado por nombre (solo su alcance).
            'colaboradorBuscado' => $this->nombreColaborador($request),
            'pdf' => $this->pdf->datosVisor(),
            'sesionRh' => $legacy ? $this->sesiones->datosPantalla($usuario) : null,
            // Estado real del procesamiento (y si la cola parece detenida);
            // la tarjeta lo vuelve a pedir sola mientras no esté listo.
            'materialRh' => $this->materiales->estadoParaRh($usuario),
            'puedeConfigurarMaterial' => $puedeConfigurarMaterial,
            'rutasMaterial' => $puedeConfigurarMaterial ? [
                'store' => route('rh.onboarding.induccion.material.store'),
                'destroy' => route('rh.onboarding.induccion.material.destroy'),
            ] : null,
            // Límites reales del backend para la zona de carga (el video va
            // por bloques; el audio en un solo request).
            'limitesMaterial' => [
                'video_max_mb' => (int) config('media.max_upload_mb'),
                'audio_max_mb' => (int) config('media.audio_max_mb'),
            ],
            // «Asignar inducción» + estado de cada persona (AsignacionInduccionService).
            'asignaciones' => $this->asignaciones->panorama($usuario),
        ]);
    }

    private function nombreColaborador(Request $request): ?string
    {
        $colaborador = Colaborador::query()->whereKey($request->integer('colaborador'))->first();

        return $colaborador !== null && app(AlcanceOrganizacionalService::class)->alcanzaColaborador($request->user(), $colaborador) ? $colaborador->nombreCompleto() : null;
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
