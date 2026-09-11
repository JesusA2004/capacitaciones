<?php

namespace App\Http\Controllers\Colaborador;

use App\Http\Controllers\Controller;
use App\Services\Colaboradores\ColaboradorPerfilService;
use App\Services\Colaboradores\NotificacionesService;
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
        private readonly NotificacionesService $notificaciones,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Portal/Index', $this->perfil->dashboard($request->user()));
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
            'notificaciones' => $this->notificaciones->listar($request->user(), 50),
        ]);
    }
}
