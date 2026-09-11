<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Services\Cumpleanos\CumpleanosStorageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Configuración del fondo reutilizable de la tarjeta de cumpleaños (docs/CUMPLEANOS.md):
 * un único archivo, subido por RH, que BirthdayCardService usa como capa
 * base en vez del fondo dibujado con GD (color plano + globos). Vive
 * aparte de CumpleanosController porque es una pantalla de administración,
 * no del flujo día a día del calendario.
 */
class CumpleanosConfiguracionController extends Controller
{
    public function __construct(private readonly CumpleanosStorageService $storage) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('rh.cumpleanos.configurar'), 403);

        $ruta = $this->storage->rutaFondo();

        return Inertia::render('Rh/Cumpleanos/Configuracion', [
            'tieneFondo' => $this->storage->existe($ruta),
            'fondoUrl' => $this->storage->existe($ruta) ? route('rh.cumpleanos.configuracion.fondo.ver') : null,
        ]);
    }

    public function actualizarFondo(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('rh.cumpleanos.configurar'), 403);

        $datos = $request->validate([
            'fondo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:8192'],
        ]);

        $contenido = file_get_contents($datos['fondo']->getRealPath());
        abort_if($contenido === false, 500, 'No se pudo leer el archivo de fondo.');

        $this->storage->guardar($this->storage->rutaFondo(), $contenido);

        return back()->with('toast', ['type' => 'success', 'message' => 'Fondo actualizado. Las próximas tarjetas lo usarán.']);
    }

    public function eliminarFondo(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('rh.cumpleanos.configurar'), 403);

        $this->storage->eliminar($this->storage->rutaFondo());

        return back()->with('toast', ['type' => 'success', 'message' => 'Fondo personalizado eliminado. Se vuelve al diseño por defecto.']);
    }

    public function fondo(Request $request): HttpResponse
    {
        abort_unless($request->user()->can('rh.cumpleanos.configurar'), 403);

        $ruta = $this->storage->rutaFondo();

        abort_unless($this->storage->existe($ruta), 404);

        return $this->storage->respuesta($ruta, [
            'Content-Type' => $this->storage->disco()->mimeType($ruta) ?: 'image/png',
        ]);
    }
}
