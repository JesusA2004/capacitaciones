<?php

namespace App\Http\Controllers\Colaborador;

use App\Http\Controllers\Controller;
use App\Services\Colaboradores\ColaboradorPerfilService;
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
    public function __construct(private readonly ColaboradorPerfilService $perfil) {}

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
}
