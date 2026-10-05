<?php

namespace App\Http\Controllers\Rh;

use App\Enums\AjusteFondo;
use App\Enums\TipoDocumentAsset;
use App\Http\Controllers\Controller;
use App\Models\DocumentAsset;
use App\Models\DocumentFamilyLayout;
use App\Models\DocumentLayoutPreset;
use App\Services\DocumentosMaestros\LayoutDocumentoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Documentos maestros → Fondos (biblioteca) y «Diseño de página» por
 * familia. La lógica vive en LayoutDocumentoService.
 */
class DisenoDocumentoController extends Controller
{
    private const PERMISO = 'plantillas_documentales.administrar';

    public function __construct(private readonly LayoutDocumentoService $diseno) {}

    public function fondos(Request $request): Response
    {
        $this->exigir($request);

        return Inertia::render('Rh/DocumentosMaestros/Fondos', [
            'fondos' => DocumentAsset::query()->with('creadoPor:id,name,apellidos')->where('tipo', TipoDocumentAsset::Fondo->value)
                ->orderBy('nombre')->orderByDesc('version')->get()
                ->map(fn (DocumentAsset $a) => $this->diseno->assetArray($a))->values(),
            'presets' => DocumentLayoutPreset::query()->orderBy('nombre')->get()->map(fn (DocumentLayoutPreset $p) => [
                'id' => $p->id,
                'slug' => $p->slug,
                'nombre' => $p->nombre,
                'config' => $p->config,
                'familias' => DocumentFamilyLayout::query()->where('preset_id', $p->id)->orderBy('familia')->pluck('familia')->all(),
            ])->values(),
            'puedeEditar' => true,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->exigir($request);
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:150'],
            'archivo' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:20480'],
            ...$this->reglasConfiguracion(),
        ]);
        $archivo = $request->file('archivo');
        abort_unless($archivo instanceof UploadedFile, 422);

        $this->diseno->subirFondo($archivo, $datos, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Fondo agregado a la biblioteca.']);
    }

    public function update(Request $request, DocumentAsset $fondo): RedirectResponse
    {
        $this->exigir($request);
        $datos = $request->validate([
            'nombre' => ['sometimes', 'string', 'max:150'],
            'activo' => ['sometimes', 'boolean'],
            ...$this->reglasConfiguracion(),
        ]);

        $this->diseno->actualizarFondo($fondo, $datos, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Fondo actualizado.']);
    }

    public function reemplazar(Request $request, DocumentAsset $fondo): RedirectResponse
    {
        $this->exigir($request);
        $request->validate(['archivo' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:20480']]);
        $archivo = $request->file('archivo');
        abort_unless($archivo instanceof UploadedFile, 422);

        $nuevo = $this->diseno->reemplazar($fondo, $archivo, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => sprintf('Se creó la versión %d. Los documentos ya generados conservan la anterior.', $nuevo->version)]);
    }

    public function destroy(Request $request, DocumentAsset $fondo): RedirectResponse
    {
        $this->exigir($request);
        $this->diseno->eliminarFondo($fondo, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Fondo eliminado.']);
    }

    public function imagen(Request $request, DocumentAsset $fondo): StreamedResponse
    {
        $this->exigir($request);

        return $this->diseno->imagen($fondo);
    }

    public function show(Request $request, string $familia): JsonResponse
    {
        $this->exigir($request);

        return response()->json(['data' => $this->diseno->disenoFamilia($familia)]);
    }

    public function updateFamilia(Request $request, string $familia): JsonResponse
    {
        $this->exigir($request);
        $datos = $request->validate([
            'preset_id' => ['nullable', 'integer'],
            'overrides' => ['nullable', 'array'],
            'overrides.background' => ['nullable', 'array'],
            'overrides.background.asset_id' => ['nullable', 'integer'],
            'overrides.background.apply_to' => ['nullable', Rule::in(['all_pages', 'first_page'])],
            'overrides.background.fit' => ['nullable', Rule::enum(AjusteFondo::class)],
            'overrides.background.opacity' => ['nullable', 'integer', 'min:0', 'max:100'],
            'overrides.header_logo_enabled' => ['nullable', 'boolean'],
            'overrides.paragraph' => ['nullable', 'array'],
            'overrides.paragraph.left_indent_cm' => ['nullable', 'numeric', 'min:0', 'max:5'],
            'overrides.paragraph.right_indent_cm' => ['nullable', 'numeric', 'min:0', 'max:5'],
            'overrides.paragraph.space_before_pt' => ['nullable', 'numeric', 'min:0', 'max:72'],
            'overrides.paragraph.space_after_pt' => ['nullable', 'numeric', 'min:0', 'max:72'],
            'overrides.paragraph.justify' => ['nullable', 'boolean'],
            'overrides.paragraph.keep_lines' => ['nullable', 'boolean'],
            'overrides.page' => ['nullable', 'array'],
            'overrides.page.margins_cm' => ['nullable', 'array'],
            'overrides.page.margins_cm.*' => ['numeric', 'min:0.5', 'max:8'],
        ]);

        $this->diseno->guardarDiseno($familia, isset($datos['preset_id']) ? (int) $datos['preset_id'] : null, $datos['overrides'] ?? null, $request->user());

        return response()->json(['data' => $this->diseno->disenoFamilia($familia)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function reglasConfiguracion(): array
    {
        return [
            'fit_mode' => ['sometimes', Rule::enum(AjusteFondo::class)],
            'default_opacity' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'safe_area_top_mm' => ['nullable', 'numeric', 'min:0', 'max:150'],
            'safe_area_right_mm' => ['nullable', 'numeric', 'min:0', 'max:150'],
            'safe_area_bottom_mm' => ['nullable', 'numeric', 'min:0', 'max:150'],
            'safe_area_left_mm' => ['nullable', 'numeric', 'min:0', 'max:150'],
        ];
    }

    private function exigir(Request $request): void
    {
        abort_unless($request->user()?->can(self::PERMISO), 403);
    }
}
