<?php

namespace App\Http\Controllers\Colaborador;

use App\Http\Controllers\Controller;
use App\Services\CicloLaboral\CicloLaboralService;
use App\Services\Colaboradores\ColaboradorPerfilService;
use App\Services\Notificaciones\NotificationFeedService;
use App\Services\Onboarding\ProgresoInduccionService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Portal limitado del colaborador (Fase 1, ver docs/PORTAL_COLABORADOR.md):
 * datos básicos, vacaciones, solicitudes recientes y notificaciones. Misma
 * lógica que la API móvil — reutiliza ColaboradorPerfilService, no calcula
 * nada por su cuenta (ver docs/ARQUITECTURA_SERVICES.md).
 */
class PortalController extends Controller
{
    public function __construct(
        private readonly ColaboradorPerfilService $perfil,
        private readonly NotificationFeedService $notificaciones,
        private readonly CicloLaboralService $ciclo,
        private readonly ProgresoInduccionService $induccion,
    ) {}

    public function index(Request $request): Response
    {
        $usuario = $request->user();

        return Inertia::render('Portal/Index', [
            ...$this->perfil->dashboard($usuario),
            // "Lo que necesitas hacer": pendientes en lenguaje llano, sin
            // nombres de etapas internas (CicloLaboralService::misPendientes).
            'pendientes' => $usuario->colaborador !== null ? $this->ciclo->misPendientes($usuario->colaborador, $usuario) : null,
            // «Mi inducción»: pasos y avance (ProgresoInduccionService, mismo DTO que la app).
            'miInduccion' => $usuario->colaborador !== null ? $this->induccion->resumen($usuario->colaborador) : null,
        ]);
    }

    public function perfil(Request $request): Response
    {
        return Inertia::render('Portal/Perfil', [
            'perfil' => $this->perfil->perfil($request->user()),
        ]);
    }

    /**
     * Página Inertia con el listado completo de notificaciones — distinta
     * de App\Http\Controllers\NotificacionController (endpoint JSON para la
     * campana del encabezado, nunca se navega ahí con un <Link>: causa
     * "All Inertia requests must receive a valid Inertia response").
     */
    public function notificaciones(Request $request): Response
    {
        return Inertia::render('Portal/Notificaciones', [
            'notificaciones' => $this->notificaciones->feed($request->user(), 50),
        ]);
    }
}
