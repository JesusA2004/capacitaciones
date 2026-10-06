<?php

namespace App\Http\Controllers\Rh;

use App\Enums\FamiliaAdministrativa;
use App\Enums\MotorPdf;
use App\Enums\TipoDocumentAsset;
use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Models\DocumentAsset;
use App\Models\PlantillaAdministrativa;
use App\Services\DocumentosAdministrativos\DisenoAdministrativoService;
use App\Services\DocumentosAdministrativos\DocumentoAdministrativoService;
use App\Services\DocumentosAdministrativos\PlantillasAdministrativasService;
use App\Services\Pdf\PdfRendererException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Documentos maestros → Documentos administrativos: diseño versionado de
 * recibo de nómina, finiquito, comprobante de solicitud y constancia. Solo
 * presentación (los datos los calcula cada servicio de negocio). Reglas en
 * PlantillasAdministrativasService / DocumentoAdministrativoService.
 */
class DocumentoAdministrativoController extends Controller
{
    private const PERMISO = 'plantillas_documentales.administrar';

    public function __construct(
        private readonly PlantillasAdministrativasService $plantillas,
        private readonly DocumentoAdministrativoService $documentos,
        private readonly DisenoAdministrativoService $disenos,
    ) {}

    public function index(Request $request): Response
    {
        $this->exigir($request);

        return Inertia::render('Rh/DocumentosMaestros/Administrativos', [
            'familias' => $this->plantillas->resumen(),
        ]);
    }

    public function editar(Request $request, FamiliaAdministrativa $familia): Response
    {
        $this->exigir($request);
        $vigente = $this->plantillas->vigente($familia);
        $borrador = PlantillaAdministrativa::query()->where('familia', $familia->value)->where('estado', PlantillaAdministrativa::BORRADOR)->latest('version')->first();

        return Inertia::render('Rh/DocumentosMaestros/AdministrativoEditor', [
            'familia' => [
                'clave' => $familia->value,
                'nombre' => $familia->etiqueta(),
                'descripcion' => $familia->descripcion(),
                'secciones' => $familia->secciones(),
                'campos' => $familia->campos(),
            ],
            'vigente' => ['version' => $vigente['version'], 'motor' => $vigente['motor']->value, 'diseno' => $vigente['diseno']],
            'borrador' => $borrador !== null ? [
                'id' => $borrador->id,
                'version' => $borrador->version,
                'motor' => $borrador->motor?->value,
                'notas' => $borrador->notas,
                'diseno' => $this->disenos->normalizar($familia, $borrador->diseno),
            ] : null,
            'historial' => $this->plantillas->historial($familia),
            'motores' => array_map(fn (MotorPdf $m) => ['valor' => $m->value, 'etiqueta' => $m->etiqueta()], MotorPdf::cases()),
            'motorPorDefecto' => MotorPdf::porDefecto()->value,
            'fuentes' => array_keys(DisenoAdministrativoService::FUENTES),
            'recursos' => DocumentAsset::query()->where('activo', true)->orderBy('nombre')->get()
                ->map(fn (DocumentAsset $a) => [
                    'id' => $a->id,
                    'nombre' => sprintf('%s (v%d)', $a->nombre, $a->version),
                    'tipo' => $a->tipo->value,
                    'es_fondo' => in_array($a->tipo, [TipoDocumentAsset::Fondo, TipoDocumentAsset::MarcaAgua, TipoDocumentAsset::Imagen], true),
                    'es_logo' => in_array($a->tipo, [TipoDocumentAsset::Logo, TipoDocumentAsset::Imagen, TipoDocumentAsset::Sello], true),
                    'url' => route('rh.documentos-maestros.fondos.imagen', $a),
                ])->values(),
        ]);
    }

    /** Crea (o recupera) el borrador editable. */
    public function borrador(Request $request, FamiliaAdministrativa $familia): RedirectResponse
    {
        $this->exigir($request);
        $this->plantillas->borrador($familia, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Borrador listo para editar. Los documentos se siguen generando con la versión activa hasta que lo actives.']);
    }

    public function guardar(Request $request, PlantillaAdministrativa $plantilla): RedirectResponse
    {
        $this->exigir($request);
        $datos = $request->validate([
            'diseno' => ['required', 'array'],
            'motor' => ['nullable', 'string'],
            'notas' => ['nullable', 'string', 'max:500'],
        ]);

        $this->plantillas->guardar($plantilla, (array) $datos['diseno'], $request->string('motor')->toString() ?: null, $request->filled('notas') ? $request->string('notas')->toString() : null, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Diseño guardado en el borrador.']);
    }

    public function activar(Request $request, PlantillaAdministrativa $plantilla): RedirectResponse
    {
        $this->exigir($request);
        $this->plantillas->activar($plantilla, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => sprintf('Versión %d activa. Los documentos ya generados no cambian.', $plantilla->version)]);
    }

    public function descartar(Request $request, PlantillaAdministrativa $plantilla): RedirectResponse
    {
        $this->exigir($request);
        $this->plantillas->descartar($plantilla, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Borrador descartado.']);
    }

    /**
     * Vista previa REAL en PDF (mismo HTML y motor que la generación).
     * ?plantilla={id} para una versión; sin él, la vigente. ?colaborador={id}
     * para "Probar con colaborador" (datos reales si existen; si no, el
     * motivo en texto plano, nunca una excepción ni un PDF roto).
     */
    public function vistaPrevia(Request $request, FamiliaAdministrativa $familia): HttpResponse
    {
        $this->exigir($request);
        $plantilla = $request->filled('plantilla')
            ? PlantillaAdministrativa::query()->where('familia', $familia->value)->whereKey($request->integer('plantilla'))->firstOrFail()
            : $this->plantillas->activa($familia);

        try {
            if ($request->filled('colaborador')) {
                $colaborador = Colaborador::query()->whereKey($request->integer('colaborador'))->firstOrFail();
                $resultado = $this->documentos->vistaPreviaConColaborador($familia, $plantilla, $colaborador);

                if (isset($resultado['faltante'])) {
                    return response($resultado['faltante'], 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
                }

                $pdf = $resultado['pdf'];
            } else {
                $pdf = $this->documentos->vistaPrevia($familia, $plantilla);
            }
        } catch (PdfRendererException $e) {
            // El detalle técnico (comando de node, rutas, stack) va al log;
            // RH solo ve un mensaje corto y una acción concreta.
            Log::warning('Vista previa de documento administrativo: el motor de impresión falló.', [
                'familia' => $familia->value,
                'error' => $e->getPrevious()?->getMessage() ?? $e->getMessage(),
            ]);

            return response(
                $e->getMessage().' Sistemas puede revisar el diagnóstico del servidor (php artisan people:diagnostico-pdf) o cambiar el motor de esta versión a DomPDF.',
                503,
                ['Content-Type' => 'text/plain; charset=UTF-8'],
            );
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="vista-previa-%s.pdf"', $familia->value),
            'Cache-Control' => 'no-store',
        ]);
    }

    private function exigir(Request $request): void
    {
        abort_unless($request->user()?->can(self::PERMISO), 403);
    }
}
