<?php

namespace App\Http\Controllers\Capacitacion;

use App\Http\Controllers\Controller;
use App\Models\CertificacionMrLana;
use App\Models\Curso;
use App\Models\User;
use App\Services\Certificados\CertificacionFormatoService;
use App\Services\Certificados\CertificacionMrLanaService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tablero de la «Certificación Mr. Lana» (modo operativo): niveles y
 * quién está en cada uno, acotado por alcance en el Service. Con
 * `certificacion.gestionar`, además, la lista de personas del alcance con
 * su formato oficial RH-CC-R-001 (descargar, subir firmado, certificado).
 */
class CertificacionController extends Controller
{
    public function __construct(
        private readonly CertificacionMrLanaService $certificacion,
        private readonly CertificacionFormatoService $formato,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('certificacion', Curso::class);

        $usuario = $request->user();
        abort_unless($usuario instanceof User, 401);
        $gestiona = $usuario->can('viewAny', CertificacionMrLana::class);

        return Inertia::render('Capacitacion/Certificacion', [
            'niveles' => $this->certificacion->catalogo(),
            'tablero' => Inertia::defer(fn () => $this->certificacion->tablero($usuario)),
            'puede_gestionar' => $gestiona,
            'gestion' => $gestiona ? Inertia::defer(fn () => $this->formato->listado($usuario), 'gestion') : null,
        ]);
    }
}
