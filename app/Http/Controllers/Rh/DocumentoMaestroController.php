<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Models\DocumentTemplate;
use App\Models\Puesto;
use App\Services\AlcanceOrganizacionalService;
use App\Services\DocumentosMaestros\AlmacenMaestrosService;
use App\Services\DocumentosMaestros\CoberturaDocumentalService;
use App\Services\DocumentosMaestros\DocumentoProcesoService;
use App\Services\DocumentosMaestros\DocumentosMaestrosAdminService;
use App\Services\DocumentosMaestros\EditorMaestroService;
use App\Services\DocumentosMaestros\ImportadorFormatosJuridicosService;
use App\Services\DocumentosMaestros\SaludDocumentalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Administración → Documentos maestros. Solo administración (cargar,
 * versionar, validar diseño, activar, probar, cobertura por puesto); RH no
 * opera aquí los documentos de cada persona — eso vive en cada proceso.
 * Toda regla vive en los Services; aquí solo se autoriza y se responde.
 */
class DocumentoMaestroController extends Controller
{
    public const PERMISO = 'plantillas_documentales.administrar';

    public function __construct(
        private readonly DocumentosMaestrosAdminService $admin,
        private readonly ImportadorFormatosJuridicosService $importador,
        private readonly AlmacenMaestrosService $almacen,
        private readonly CoberturaDocumentalService $cobertura,
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly EditorMaestroService $editor,
        private readonly SaludDocumentalService $salud,
        private readonly DocumentoProcesoService $procesos,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        $this->exigir($request);
        $masters = $this->admin->listar();

        return Inertia::render('Rh/DocumentosMaestros/Index', [
            'masters' => $masters,
            'kpis' => $this->admin->kpis($masters),
            'salud' => $this->salud->resumen($masters),
            'grupos' => config('documentos_maestros.grupos'),
            'procesos' => config('documentos_maestros.procesos'),
            'claves' => $this->admin->clavesDisponibles(),
        ]);
    }

    // ───────── Editor visual no-code ─────────

    public function editor(Request $request, DocumentTemplate $master): InertiaResponse
    {
        $this->exigir($request);
        abort_if($master->estado_master === null, 404);

        return Inertia::render('Rh/DocumentosMaestros/Editor', [
            'editor' => $this->editor->editor($master),
            'abrirPrueba' => $request->boolean('probar'),
        ]);
    }

    public function nuevo(Request $request): JsonResponse
    {
        $this->exigir($request);
        $procesos = [...array_keys((array) config('documentos_maestros.procesos', [])), 'expediente'];
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'min:3', 'max:150'],
            'clave' => ['nullable', 'string', 'max:60', 'regex:/^[a-z0-9_]*$/'],
            'grupo' => ['nullable', 'string', Rule::in(array_keys((array) config('documentos_maestros.grupos', [])))],
            'proceso' => ['required', 'string', Rule::in(array_diff($procesos, ['referencia']))],
            'requiere_impresion' => ['boolean'],
            'requiere_firma_fisica' => ['boolean'],
            'requiere_huella' => ['boolean'],
            'archivo' => ['required', 'file', 'max:20480', 'mimes:docx,pdf'],
        ], [
            'archivo.mimes' => 'El documento debe ser Word (.docx) o PDF.',
            'archivo.max' => 'El archivo pesa más de 20 MB.',
        ]);
        $archivo = $request->file('archivo');
        abort_unless($archivo instanceof UploadedFile, 422);

        $master = $this->editor->crearFormato($datos, $archivo, $request->user());
        $espacios = $master->campos()->count();

        return response()->json([
            'message' => $espacios > 0 ? sprintf('Se detectaron %d espacio(s). Confirma qué dato va en cada uno.', $espacios) : 'Formato cargado. Coloca los espacios para datos sobre el documento.',
            'data' => ['id' => $master->id],
        ], 201);
    }

    public function guardarCampos(Request $request, DocumentTemplate $master): JsonResponse
    {
        $this->exigir($request);
        abort_if($master->estado_master === null, 404);
        $datos = $request->validate(['campos' => ['present', 'array', 'max:200'], 'campos.*' => ['array']]);
        $master = $this->editor->guardarCampos($master, array_values($datos['campos']), $request->user());

        return response()->json(['message' => 'Cambios guardados.', 'data' => $this->editor->editor($master)]);
    }

    public function marcas(Request $request, DocumentTemplate $master): JsonResponse
    {
        $this->exigir($request);
        abort_if($master->estado_master === null, 404);
        $datos = $request->validate(['decision' => ['required', Rule::in(['limpiar', 'conservar'])]]);
        $master = $this->editor->decidirMarcas($master, $datos['decision'] === 'limpiar', $request->user());

        return response()->json([
            'message' => $datos['decision'] === 'limpiar' ? 'Listo: los resaltados ya no saldrán en el documento final.' : 'Se conservan los resaltados como parte del diseño.',
            'data' => $this->editor->editor($master),
        ]);
    }

    public function detectar(Request $request, DocumentTemplate $master): JsonResponse
    {
        $this->exigir($request);
        abort_if($master->estado_master === null, 404);
        $nuevos = $this->editor->detectarEspacios($master, $request->user());

        return response()->json(['message' => $nuevos > 0 ? sprintf('%d espacio(s) nuevo(s) detectado(s).', $nuevos) : 'No hay espacios nuevos por detectar.', 'data' => $this->editor->editor($master->refresh())]);
    }

    public function datosLargos(Request $request, DocumentTemplate $master): JsonResponse
    {
        $this->exigir($request);
        abort_if($master->estado_master === null, 404);

        return response()->json(['data' => $this->editor->datosLargos($master, $request->user())]);
    }

    public function copiarVersion(Request $request, DocumentTemplate $master): JsonResponse
    {
        $this->exigir($request);
        abort_if($master->estado_master === null, 404);
        $nueva = $this->editor->nuevaVersionDesde($master, $request->user());

        return response()->json(['message' => sprintf('Versión %d creada (inactiva) para editarla.', $nueva->version), 'data' => ['id' => $nueva->id]], 201);
    }

    public function revisarFuentes(Request $request): JsonResponse
    {
        $this->exigir($request);
        $resultado = $this->salud->revisarFuentes();
        $sustituidas = array_filter((array) $resultado['fuentes'], fn (mixed $f): bool => is_array($f) && ($f['sustituida'] ?? false));

        return response()->json([
            'message' => ! ($resultado['chrome']['disponible'] ?? false)
                ? 'No se pudo abrir el Chrome que imprime los documentos.'
                : ($sustituidas === [] ? 'Fuentes revisadas: Chrome usa todas las fuentes de los documentos.' : sprintf('%d fuente(s) se sustituyen en este servidor.', count($sustituidas))),
            'data' => $resultado,
        ]);
    }

    public function cobertura(Request $request): InertiaResponse
    {
        $this->exigirCobertura($request);

        return Inertia::render('Rh/DocumentosMaestros/Cobertura', [
            'cobertura' => $this->cobertura->reporte(),
            'gruposDocumentales' => array_map(fn (string $valor, string $etiqueta): array => ['value' => $valor, 'etiqueta' => $etiqueta], array_keys((array) config('documentos_maestros.grupos', [])), array_values((array) config('documentos_maestros.grupos', []))),
            'puedeEditar' => $request->user()?->can(self::PERMISO) || $request->user()?->can('configuracion.rh'),
        ]);
    }

    public function decidirPuesto(Request $request, Puesto $puesto): RedirectResponse
    {
        $this->exigirCobertura($request);
        $datos = $request->validate([
            'grupo_documental' => ['nullable', 'string', Rule::in(array_keys((array) config('documentos_maestros.grupos', [])))],
            'no_requiere_documentos_laborales' => ['required', 'boolean'],
            'motivo_sin_documentos' => ['nullable', 'string', 'max:500'],
        ]);

        $this->cobertura->decidir($puesto, $datos, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => sprintf('Cobertura documental de «%s» actualizada.', $puesto->nombre)]);
    }

    public function show(Request $request, DocumentTemplate $master): JsonResponse
    {
        $this->exigir($request);
        abort_if($master->estado_master === null, 404);

        return response()->json(['data' => $this->admin->detalle($master, $request->user())]);
    }

    public function cargarVersion(Request $request, string $familia): JsonResponse
    {
        $this->exigir($request);
        $request->validate(['archivo' => ['required', 'file', 'max:20480', 'mimes:docx,pdf']], [
            'archivo.mimes' => 'El documento oficial debe ser Word (.docx) o PDF.',
            'archivo.max' => 'El archivo pesa más de 20 MB.',
        ]);
        $archivo = $request->file('archivo');
        abort_unless($archivo instanceof UploadedFile, 422);

        $master = $this->importador->cargarNuevaVersion($familia, (string) file_get_contents($archivo->getRealPath()), $archivo->getClientOriginalName(), $request->user());

        return response()->json([
            'message' => match (true) {
                $master->estado_master !== 'listo' => sprintf('Versión %d cargada con pendientes: revisa el diagnóstico técnico antes de activarla.', $master->version),
                $master->disenoValidado() => sprintf('Versión %d cargada y con diseño validado. Pruébala con un colaborador y actívala.', $master->version),
                default => sprintf('Versión %d cargada. El diseño aún no está validado: revisa el resultado de la prueba.', $master->version),
            },
            'data' => $this->admin->detalle($master, $request->user()),
        ], 201);
    }

    public function validarDiseno(Request $request, DocumentTemplate $master): JsonResponse
    {
        $this->exigir($request);
        abort_if($master->estado_master === null, 404);
        $master = $this->admin->validarDiseno($master, $request->user());

        return response()->json([
            'message' => $master->disenoValidado() ? 'Diseño validado: el documento generado conserva el original.' : 'La prueba de diseño encontró diferencias: revisa el detalle.',
            'data' => $this->admin->detalle($master, $request->user()),
        ]);
    }

    /**
     * "Revalidar pendientes": vuelve a correr el QA visual de todas las
     * versiones operativas pendientes/fallidas con el estado ACTUAL del
     * servidor (p. ej. tras instalar una fuente). No reimporta, no crea
     * versiones ni activa nada.
     */
    public function revalidarPendientes(Request $request): JsonResponse
    {
        $this->exigir($request);
        $resultados = $this->admin->revalidarPendientes($request->user());
        $fallidas = count(array_filter($resultados, fn (array $r): bool => $r['estado'] === 'fallido'));

        return response()->json([
            'message' => $resultados === []
                ? 'No había versiones pendientes o fallidas por revalidar.'
                : sprintf('%d versión(es) revalidada(s), %d con fallas.', count($resultados), $fallidas),
            'data' => $resultados,
        ]);
    }

    public function activar(Request $request, DocumentTemplate $master): JsonResponse
    {
        $this->exigir($request);
        abort_if($master->estado_master === null, 404);
        $datos = $request->validate(['motivo_excepcional' => ['nullable', 'string', 'min:15', 'max:1000']]);
        $master = $this->importador->activar($master, $request->user(), isset($datos['motivo_excepcional']) ? (string) $datos['motivo_excepcional'] : null);

        return response()->json(['message' => $master->activacion_excepcional_motivo !== null ? 'Versión activada por excepción (auditada).' : 'Versión activada.', 'data' => $this->admin->detalle($master, $request->user())]);
    }

    public function desactivar(Request $request, DocumentTemplate $master): JsonResponse
    {
        $this->exigir($request);
        abort_if($master->estado_master === null, 404);

        return response()->json(['message' => 'Versión desactivada.', 'data' => $this->admin->detalle($this->admin->desactivar($master, $request->user()), $request->user())]);
    }

    /**
     * "Probar con colaborador": resumen comparativo ORIGINAL vs GENERADO y
     * URLs para ver ambos PDF. Nada se guarda en el expediente.
     */
    public function probar(Request $request, DocumentTemplate $master): JsonResponse
    {
        $this->exigir($request);
        abort_if($master->estado_master === null, 404);
        $datos = $request->validate(['colaborador_id' => ['required', 'integer'], 'completar' => ['sometimes', 'array', 'max:40']]);
        $colaborador = Colaborador::query()->where('id', (int) $datos['colaborador_id'])->firstOrFail();
        // Solo colaboradores dentro del alcance de quien prueba.
        abort_unless($this->alcance->alcanzaColaborador($request->user(), $colaborador), 403, 'Ese colaborador está fuera de tu alcance.');

        // «Completar datos del contrato» desde la misma vista previa: se
        // guardan en la ficha con las mismas reglas y permisos que al generar.
        if (($datos['completar'] ?? []) !== []) {
            $this->procesos->completarDatos($colaborador, $datos['completar'], $request->user());
            $colaborador->refresh();
        }

        return response()->json(['data' => $this->admin->probar($master, $colaborador, $request->user())]);
    }

    public function pdfPrueba(Request $request, DocumentTemplate $master, string $token): Response
    {
        $this->exigir($request);
        $pdf = $this->admin->pdfPrueba($master, $token);
        abort_if($pdf === null, 404, 'La vista previa expiró: vuelve a probar.');

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="vista-previa.pdf"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function limpioPdf(Request $request, DocumentTemplate $master): Response
    {
        $this->exigir($request);
        abort_if($master->estado_master === null, 404);
        $limpio = $this->admin->limpioPdf($master);
        abort_if($limpio === null, 422, 'No se pudo mostrar el documento limpio: el servidor no puede leer el original o convertirlo a PDF.');

        return response($limpio['pdf'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="limpio.pdf"',
            'Cache-Control' => 'no-store',
            'X-Fidelidad' => $limpio['fidelidad'],
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function originalPdf(Request $request, DocumentTemplate $master): Response
    {
        $this->exigir($request);
        abort_if($master->estado_master === null, 404);
        $original = $this->admin->originalPdf($master);
        abort_if($original === null, 422, 'No se pudo mostrar el original: el servidor no puede leerlo o convertirlo a PDF.');

        return response($original['pdf'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="original.pdf"',
            'Cache-Control' => 'no-store',
            'X-Fidelidad' => $original['fidelidad'],
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function buscarColaboradores(Request $request): JsonResponse
    {
        $this->exigir($request);
        $datos = $request->validate(['q' => ['nullable', 'string', 'max:80']]);

        return response()->json(['data' => $this->admin->buscarColaboradores($request->user(), (string) ($datos['q'] ?? ''))]);
    }

    /**
     * Original de Jurídico tal cual se entregó (auditoría).
     */
    public function original(Request $request, DocumentTemplate $master): Response
    {
        $this->exigir($request);
        abort_if($master->estado_master === null, 404);
        $contenido = $this->almacen->original($master);
        $nombre = str_replace(['"', '\\', '/'], '', (string) ($master->original_nombre ?? 'original'));

        return response($contenido, 200, [
            'Content-Type' => str_ends_with(strtolower($nombre), '.pdf') ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => 'attachment; filename="'.$nombre.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function exigir(Request $request): void
    {
        abort_unless($request->user()?->can(self::PERMISO), 403);
    }

    private function exigirCobertura(Request $request): void
    {
        abort_unless($request->user()?->can(self::PERMISO) || $request->user()?->can('configuracion.rh'), 403);
    }
}
