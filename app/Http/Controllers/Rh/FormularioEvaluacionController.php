<?php

namespace App\Http\Controllers\Rh;

use App\Enums\ClaveFormularioEvaluacion;
use App\Http\Controllers\Controller;
use App\Http\Requests\FormulariosEvaluacion\GuardarFormularioEvaluacionRequest;
use App\Models\FormularioEvaluacion;
use App\Services\Evaluaciones\FormularioEvaluacionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Módulo «Formularios de evaluación» (RH): configurar como en Google Forms
 * la evaluación de inducción y la de capacitación inicial (periodo de
 * prueba), con diseño, vista previa y versiones. Delgado: todo vive en
 * FormularioEvaluacionService; la autorización en FormularioEvaluacionPolicy.
 */
class FormularioEvaluacionController extends Controller
{
    public function __construct(private readonly FormularioEvaluacionService $formularios) {}

    public function index(): Response
    {
        $this->authorize('viewAny', FormularioEvaluacion::class);

        return Inertia::render('Rh/FormulariosEvaluacion/Index', [
            'formularios' => $this->formularios->resumen(),
        ]);
    }

    public function editar(ClaveFormularioEvaluacion $clave): Response
    {
        $this->authorize('viewAny', FormularioEvaluacion::class);

        $publicado = $this->formularios->publicado($clave) ?? $this->formularios->asegurarVersionInicial($clave);
        $borrador = $this->formularios->borrador($clave);

        return $this->pantalla($clave, $borrador ?? $publicado, $publicado);
    }

    /** Una versión anterior (o la vigente), solo lectura. */
    public function version(FormularioEvaluacion $formulario): Response
    {
        $this->authorize('view', $formulario);

        if ($formulario->esBorrador()) {
            return $this->editar($formulario->clave);
        }

        $publicado = $this->formularios->publicado($formulario->clave) ?? $formulario;

        return $this->pantalla($formulario->clave, $formulario, $publicado, true);
    }

    /** «Editar»: crea (o retoma) el borrador; con `desde`, restaura esa versión como borrador. */
    public function crearBorrador(Request $request, ClaveFormularioEvaluacion $clave): RedirectResponse
    {
        $this->authorize('create', FormularioEvaluacion::class);
        $datos = $request->validate(['desde' => ['nullable', 'integer']]);
        $base = isset($datos['desde']) ? FormularioEvaluacion::query()->where('id', (int) $datos['desde'])->first() : null;

        $this->formularios->crearBorrador($clave, $request->user(), $base);

        return to_route('rh.formularios-evaluacion.editar', $clave->value);
    }

    public function guardar(GuardarFormularioEvaluacionRequest $request, FormularioEvaluacion $formulario): RedirectResponse
    {
        $this->authorize('update', $formulario);
        $this->formularios->guardarBorrador($formulario, $request->user(), $request->validated());

        return back();
    }

    public function publicar(Request $request, FormularioEvaluacion $formulario): RedirectResponse
    {
        $this->authorize('publicar', $formulario);
        $publicado = $this->formularios->publicar($formulario, $request->user());

        return to_route('rh.formularios-evaluacion.editar', $publicado->clave->value)->with('toast', [
            'type' => 'success',
            'message' => sprintf('Publicaste la versión %d. Las evaluaciones nuevas ya la usan; las que ya existen conservan su versión.', $publicado->version),
        ]);
    }

    public function descartar(Request $request, FormularioEvaluacion $formulario): RedirectResponse
    {
        $this->authorize('update', $formulario);
        $clave = $formulario->clave;
        $this->formularios->descartarBorrador($formulario, $request->user());

        return to_route('rh.formularios-evaluacion.editar', $clave->value)->with('toast', ['type' => 'success', 'message' => 'Descartaste el borrador. El formulario vigente no cambió.']);
    }

    public function subirBanner(Request $request, FormularioEvaluacion $formulario): RedirectResponse
    {
        $this->authorize('update', $formulario);
        $request->validate(
            ['banner' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096']],
            [
                'banner.required' => 'Elige una imagen.',
                'banner.image' => 'Sube una imagen JPG, PNG o WEBP.',
                'banner.mimes' => 'Sube una imagen JPG, PNG o WEBP.',
                'banner.max' => 'La imagen puede pesar hasta 4 MB.',
            ],
        );

        $archivo = $request->file('banner');
        abort_unless($archivo instanceof UploadedFile, 422);
        $this->formularios->subirBanner($formulario, $request->user(), $archivo);

        return back();
    }

    public function quitarBanner(Request $request, FormularioEvaluacion $formulario): RedirectResponse
    {
        $this->authorize('update', $formulario);
        $this->formularios->quitarBanner($formulario, $request->user());

        return back();
    }

    private function pantalla(ClaveFormularioEvaluacion $clave, FormularioEvaluacion $formulario, FormularioEvaluacion $publicado, bool $soloLectura = false): Response
    {
        return Inertia::render('Rh/FormulariosEvaluacion/Editor', [
            'clave' => $clave->value,
            'etiqueta' => $clave->etiqueta(),
            'descripcionModulo' => $clave->descripcion(),
            'formulario' => $this->formularios->aArray($formulario, true),
            'publicado' => $this->formularios->aArray($publicado, false),
            'editable' => ! $soloLectura && $formulario->esBorrador(),
            'soloLectura' => $soloLectura,
            'historial' => $this->formularios->historial($clave),
            'opciones' => $this->formularios->opcionesDiseno(),
        ]);
    }
}
