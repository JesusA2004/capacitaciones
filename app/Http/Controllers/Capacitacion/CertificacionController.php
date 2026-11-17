<?php

namespace App\Http\Controllers\Capacitacion;

use App\Http\Controllers\Controller;
use App\Models\Curso;
use App\Services\Certificados\CertificacionMrLanaService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tablero de la «Certificación Mr. Lana» (modo operativo): niveles y
 * quién está en cada uno, acotado por alcance en el Service.
 */
class CertificacionController extends Controller
{
    public function __construct(private readonly CertificacionMrLanaService $certificacion) {}

    public function index(Request $request): Response
    {
        $this->authorize('certificacion', Curso::class);

        return Inertia::render('Capacitacion/Certificacion', [
            'niveles' => $this->certificacion->catalogo(),
            'tablero' => Inertia::defer(fn () => $this->certificacion->tablero($request->user())),
        ]);
    }
}
