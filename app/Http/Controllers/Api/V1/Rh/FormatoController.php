<?php

namespace App\Http\Controllers\Api\V1\Rh;

use App\Enums\EstadoDocumentoGenerado;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Rh\PrepararGenerarFormatoRequest;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Formatos\FormatoCatalogoService;
use App\Services\Formatos\FormatoPreviewService;
use App\Services\Formatos\Motor\ConversorDocxPdf;
use App\Services\Plantillas\PlaceholderResolver;
use App\Services\Plantillas\PlantillaDocumentoService;
use App\Services\Plantillas\PlantillaStorageService;
use App\Services\Plantillas\VariableMappingService;
use Dompdf\Dompdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Catálogo, generación y descarga de formatos DOCX desde la app móvil de RH
 * — mismos servicios que el panel web (ver
 * App\Http\Controllers\Rh\FormatoController — no se duplica la lógica de
 * generación/mapeo de variables). Administrar plantillas (subir DOCX,
 * mapear variables manuales, versionar) se queda en Portal RH.
 */
class FormatoController extends Controller
{
    public function __construct(
        private readonly FormatoCatalogoService $catalogo,
        private readonly PlantillaStorageService $storage,
        private readonly FormatoPreviewService $previsualizador,
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly PlaceholderResolver $resolver,
        private readonly PlantillaDocumentoService $generador,
        private readonly VariableMappingService $mapeo,
        private readonly ConversorDocxPdf $conversor,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', DocumentTemplate::class);

        return response()->json(['data' => $this->catalogo->listar()]);
    }

    /**
     * Qué se puede resolver solo, qué falta y si ya se puede generar —
     * nunca genera ni persiste nada (mismo cálculo que
     * `Rh\FormatoController::preview()`/`FormatoPreviewService`, sin el
     * HTML que la app no necesita).
     */
    public function preparar(PrepararGenerarFormatoRequest $request, DocumentTemplate $plantilla): JsonResponse
    {
        $sujeto = $this->resolverSujetoAutorizado($request, $plantilla);

        return response()->json(['data' => $this->prepararRespuesta($plantilla, $sujeto, $request->validated('extra') ?? [])]);
    }

    /**
     * Genera el documento y lo archiva en el expediente del colaborador.
     * Igual que el panel web: una variable manual requerida sin valor
     * bloquea la generación (422) antes de tocar el almacenamiento.
     */
    public function generar(PrepararGenerarFormatoRequest $request, DocumentTemplate $plantilla): JsonResponse
    {
        $sujeto = $this->resolverSujetoAutorizado($request, $plantilla);
        $extra = $request->validated('extra') ?? [];

        $valoresResueltos = $this->resolver->resolver($sujeto, $extra);
        $faltantesRequeridos = array_values(array_filter(
            $this->mapeo->clavesRequeridas($plantilla),
            fn (string $clave) => trim((string) ($valoresResueltos[$clave] ?? '')) === '',
        ));

        if ($faltantesRequeridos !== []) {
            throw ValidationException::withMessages([
                'extra' => $this->mensajeFaltantes($faltantesRequeridos),
            ]);
        }

        $resultado = $this->generador->generar($plantilla, $sujeto, $extra);
        $ruta = $this->storage->rutaGenerado($resultado['nombre_interno']);
        $this->storage->guardarContenido($ruta, $resultado['contenido']);

        $nombreGenerado = $plantilla->tipo->etiqueta().' - '.now()->format('Y-m-d').'.docx';

        $documento = GeneratedDocument::create([
            'document_template_id' => $plantilla->id,
            'colaborador_id' => $sujeto instanceof Colaborador ? $sujeto->id : null,
            'candidato_id' => $sujeto instanceof Candidato ? $sujeto->id : null,
            'empresa_id' => $plantilla->empresa_id,
            'sucursal_id' => $plantilla->sucursal_id,
            'disk' => config('plantillas.disk'),
            'path' => $ruta,
            'original_name' => $plantilla->original_name,
            'generated_name' => $nombreGenerado,
            'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'size' => strlen($resultado['contenido']),
            'status' => EstadoDocumentoGenerado::Generado,
            'generated_by' => $request->user()?->id,
        ]);

        return response()->json(['data' => [
            'documento_generado_id' => $documento->id,
            'nombre' => $nombreGenerado,
            'filename' => $nombreGenerado,
            'mime_type' => $documento->mime,
            'created_at' => $documento->created_at?->toIso8601String(),
            'acciones_permitidas' => [
                'download',
                ...(class_exists(Dompdf::class) ? ['preview'] : []),
            ],
        ]]);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function prepararRespuesta(DocumentTemplate $plantilla, Colaborador|Candidato $sujeto, array $extra): array
    {
        $resultado = $this->previsualizador->previsualizar($plantilla, $sujeto, $extra);
        $nombreSujeto = $sujeto instanceof Colaborador
            ? trim("{$sujeto->name} {$sujeto->apellidos}")
            : trim("{$sujeto->nombre} {$sujeto->apellidos}");

        $datos = [];
        foreach ($resultado['variables'] as $clave => $valor) {
            if (trim((string) $valor) === '') {
                continue;
            }
            $datos[] = ['clave' => $clave, 'etiqueta' => str_replace('_', ' ', $clave), 'valor' => $valor];
        }

        $faltantesManuales = array_flip($this->mapeo->clavesManuales($plantilla));
        $requeridas = array_flip($resultado['faltantes_requeridos']);
        $faltantes = [];
        foreach ($resultado['faltantes'] as $clave) {
            if (isset($faltantesManuales[$clave])) {
                continue;
            }
            // requerido=true aquí SÍ bloquea generar (variable automática
            // que RH marcó obligatoria y sigue vacía), a diferencia del
            // resto de faltantes (solo aviso, ver docs/DOCX_TEMPLATES.md).
            $faltantes[] = ['variable' => $clave, 'etiqueta' => $this->mapeo->etiquetar($clave), 'requerido' => isset($requeridas[$clave])];
        }

        $manuales = $this->mapeo->manuales($plantilla)->map(fn (array $def) => [
            'clave' => $def['clave'],
            'etiqueta' => $def['etiqueta'],
            'descripcion' => $def['descripcion'] ?? null,
            'tipo' => $def['tipo'],
            'requerido' => (bool) $def['requerido'],
            'valor' => $extra[$def['clave']] ?? $def['valor_por_defecto'] ?? '',
            'opciones' => $def['opciones'] ?? null,
        ])->values()->all();

        return [
            'plantilla' => ['id' => $plantilla->id, 'nombre' => $plantilla->nombre],
            'sujeto' => ['id' => $sujeto->id, 'nombre' => $nombreSujeto],
            'datos' => $datos,
            'faltantes' => $faltantes,
            'manuales' => $manuales,
            'puede_generar' => $resultado['puede_generar'],
            'output_available' => ['docx' => true, 'pdf' => class_exists(Dompdf::class)],
        ];
    }

    private function resolverSujetoAutorizado(PrepararGenerarFormatoRequest $request, DocumentTemplate $plantilla): Colaborador|Candidato
    {
        $tipoSujeto = (string) $request->validated('tipo_sujeto');
        $sujetoId = (int) $request->validated('sujeto_id');

        if ($tipoSujeto === 'candidato') {
            $candidato = Candidato::query()->where('id', $sujetoId)->first();
            abort_unless($candidato !== null, 404, 'No se encontró el candidato indicado.');

            return $candidato;
        }

        $colaborador = Colaborador::query()->where('id', $sujetoId)->first();
        abort_unless($colaborador !== null, 404, 'No se encontró el colaborador indicado.');

        // Nunca se genera un documento para un colaborador fuera del
        // alcance del usuario (mismo criterio que el resto de la API RH
        // móvil) — el panel web no tiene esta restricción porque RH ahí ya
        // filtra la lista de colaboradores por alcance antes de llegar aquí;
        // en móvil el sujeto_id llega directo del cliente.
        abort_unless($this->alcance->alcanzaColaborador($request->user(), $colaborador), 404);

        return $colaborador;
    }

    public function descargar(Request $request, GeneratedDocument $documento): StreamedResponse
    {
        $this->autorizarDocumento($request, $documento);

        return $this->storage->respuesta($documento->path, [
            'Content-Disposition' => 'attachment; filename="'.$documento->generated_name.'"',
        ]);
    }

    public function descargarPdf(Request $request, GeneratedDocument $documento): HttpResponse|RedirectResponse
    {
        $this->autorizarDocumento($request, $documento);

        // Mismo conversor desacoplado que ya usa el módulo de formatos
        // oficiales (App\Services\Formatos\Motor\ConversorDocxPdf): prefiere
        // LibreOffice headless si está configurado (fidelidad exacta),
        // nunca rompe la descarga si no lo está (cae a PhpWord/DomPDF).
        $resultado = $this->conversor->convertir($this->storage->disco()->get($documento->path));
        abort_if($resultado === null, 422, 'No se pudo generar el PDF de este documento. Descarga el Word.');

        $nombre = pathinfo($documento->generated_name, PATHINFO_FILENAME).'.pdf';

        return response($resultado['pdf'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$nombre.'"',
        ]);
    }

    /**
     * @param  list<string>  $claves
     */
    private function mensajeFaltantes(array $claves): string
    {
        $etiquetas = array_map(fn (string $clave) => $this->mapeo->etiquetar($clave), $claves);

        return count($etiquetas) === 1
            ? "Falta {$etiquetas[0]}."
            : 'Faltan datos obligatorios de la plantilla: '.implode(', ', $etiquetas).'.';
    }

    private function autorizarDocumento(Request $request, GeneratedDocument $documento): void
    {
        $usuario = $request->user();
        abort_unless($usuario->can('formatos.descargar_docx') || $usuario->can('formatos.descargar_pdf'), 403);

        // Un documento generado para un colaborador nunca se sirve a quien
        // no puede ver a ese colaborador (mismo criterio de alcance que el
        // resto de la API RH movil). Los documentos de candidatos no tienen
        // alcance por sucursal que validar aqui (ya lo cubre el permiso
        // formatos.descargar_* en si).
        if ($documento->usuario !== null) {
            abort_unless($this->alcance->puedeVerUsuario($usuario, $documento->usuario), 404);
        }
    }
}
