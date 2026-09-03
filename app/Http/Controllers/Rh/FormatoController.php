<?php

namespace App\Http\Controllers\Rh;

use App\Enums\EstadoDocumentoGenerado;
use App\Enums\TipoPlantillaDocumento;
use App\Exports\ReporteRhExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rh\StoreGeneratedDocumentRequest;
use App\Http\Requests\Rh\SubirFormatoFirmadoRequest;
use App\Models\Candidato;
use App\Models\DocumentTemplate;
use App\Models\DocumentType;
use App\Models\GeneratedDocument;
use App\Models\SolicitudInterna;
use App\Models\SolicitudVacaciones;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Expedientes\DocumentoStorageService;
use App\Services\Plantillas\PlantillaDocumentoService;
use App\Services\Plantillas\PlantillaStorageService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FormatoController extends Controller
{
    private const FILTROS = ['tipo', 'status', 'generated_by', 'busqueda', 'fecha_inicio', 'fecha_fin'];

    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly PlantillaDocumentoService $generador,
        private readonly PlantillaStorageService $storage,
        private readonly DocumentoStorageService $documentoStorage,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', DocumentTemplate::class);

        $documentos = $this->queryFiltrada($request)->orderByDesc('created_at')->paginate(15)->withQueryString();

        return Inertia::render('Rh/Formatos/Index', [
            'documentos' => $documentos,
            'filtros' => $request->only(self::FILTROS),
            'plantillasDisponibles' => DocumentTemplate::query()->where('activo', true)->orderBy('nombre')->get(['id', 'nombre', 'tipo']),
            'colaboradoresDisponibles' => User::query()->orderBy('name')->limit(200)->get(['id', 'name', 'apellidos']),
            'candidatosDisponibles' => Candidato::query()->orderBy('nombre')->limit(200)->get(['id', 'nombre', 'apellidos']),
            'responsablesDisponibles' => User::query()->role(['rh_admin', 'rh_auxiliar'])->orderBy('name')->get(['id', 'name', 'apellidos']),
            'tipos' => array_map(fn (TipoPlantillaDocumento $t) => ['value' => $t->value, 'etiqueta' => $t->etiqueta()], TipoPlantillaDocumento::cases()),
            'estados' => array_map(fn (EstadoDocumentoGenerado $e) => ['value' => $e->value, 'etiqueta' => $e->etiqueta()], EstadoDocumentoGenerado::cases()),
        ]);
    }

    public function exportarExcel(Request $request): HttpResponse
    {
        $this->authorize('viewAny', DocumentTemplate::class);

        [$columnas, $filas] = $this->tabla($request);

        return Excel::download(
            new ReporteRhExport('Formatos generados', $columnas, $filas),
            'formatos-'.now()->format('Y-m-d').'.xlsx',
        );
    }

    public function exportarPdf(Request $request): HttpResponse
    {
        $this->authorize('viewAny', DocumentTemplate::class);

        [$columnas, $filas] = $this->tabla($request);

        return Pdf::loadView('pdf.reporte-rh', ['titulo' => 'Formatos generados', 'columnas' => $columnas, 'filas' => $filas])
            ->setPaper('letter', 'landscape')
            ->download('formatos-'.now()->format('Y-m-d').'.pdf');
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, array<int, string|int|null>>}
     */
    private function tabla(Request $request): array
    {
        $documentos = $this->queryFiltrada($request)->orderByDesc('created_at')->get();

        $columnas = ['Documento', 'Plantilla', 'Para', 'Estado', 'Generado por', 'Fecha'];

        $filas = $documentos->map(fn (GeneratedDocument $d) => [
            $d->generated_name,
            $d->plantilla?->nombre,
            $d->usuario ? trim("{$d->usuario->name} {$d->usuario->apellidos}") : ($d->candidato ? trim("{$d->candidato->nombre} {$d->candidato->apellidos}") : null),
            $d->status->etiqueta(),
            $d->generadoPor ? trim("{$d->generadoPor->name} {$d->generadoPor->apellidos}") : null,
            $d->created_at->toDateString(),
        ])->all();

        return [$columnas, $filas];
    }

    /**
     * @return Builder<GeneratedDocument>
     */
    private function queryFiltrada(Request $request): Builder
    {
        $usuario = $request->user();

        return $this->alcance
            ->limitarPorSucursal(
                GeneratedDocument::query()->with(['plantilla:id,nombre,tipo', 'usuario:id,name,apellidos', 'candidato:id,nombre,apellidos', 'generadoPor:id,name,apellidos']),
                $usuario,
            )
            ->when($request->string('tipo')->toString(), fn ($query, string $tipo) => $query->whereHas('plantilla', fn ($q) => $q->where('tipo', $tipo)))
            ->when($request->string('status')->toString(), fn ($query, string $status) => $query->where('status', $status))
            ->when($request->integer('generated_by'), fn ($query, $valor) => $query->where('generated_by', $valor))
            ->when($request->string('fecha_inicio')->toString(), fn ($query, string $valor) => $query->whereDate('created_at', '>=', $valor))
            ->when($request->string('fecha_fin')->toString(), fn ($query, string $valor) => $query->whereDate('created_at', '<=', $valor))
            ->when($request->string('busqueda')->toString(), fn ($query, string $busqueda) => $query->where('generated_name', 'like', "%{$busqueda}%"));
    }

    public function store(StoreGeneratedDocumentRequest $request): RedirectResponse
    {
        $plantilla = DocumentTemplate::query()->where('id', $request->validated('document_template_id'))->firstOrFail();
        $this->authorize('generar', $plantilla);

        $solicitud = $request->validated('solicitud_id')
            ? SolicitudInterna::query()->findOrFail((int) $request->validated('solicitud_id'))
            : null;
        $solicitudVacaciones = $request->validated('solicitud_vacaciones_id')
            ? SolicitudVacaciones::query()->findOrFail((int) $request->validated('solicitud_vacaciones_id'))
            : null;

        if ($solicitud !== null) {
            $sujeto = $solicitud->usuario;
            $extra = $this->extraDesdeSolicitud($solicitud);
        } elseif ($solicitudVacaciones !== null) {
            $sujeto = $solicitudVacaciones->usuario;
            $extra = $this->extraDesdeSolicitudVacaciones($solicitudVacaciones);
        } else {
            $sujeto = $request->validated('tipo_sujeto') === 'colaborador'
                ? User::query()->firstWhere('id', $request->validated('sujeto_id'))
                : Candidato::query()->firstWhere('id', $request->validated('sujeto_id'));
            $extra = $request->validated('extra') ?? [];
        }

        abort_unless($sujeto !== null, 404, 'No se encontró el colaborador o candidato indicado.');

        $resultado = $this->generador->generar($plantilla, $sujeto, $extra);
        $ruta = $this->storage->rutaGenerado($resultado['nombre_interno']);
        $this->storage->guardarContenido($ruta, $resultado['contenido']);

        $nombreGenerado = $plantilla->tipo->etiqueta().' - '.now()->format('Y-m-d').'.docx';

        GeneratedDocument::create([
            'document_template_id' => $plantilla->id,
            'user_id' => $sujeto instanceof User ? $sujeto->id : null,
            'candidato_id' => $sujeto instanceof Candidato ? $sujeto->id : null,
            'solicitud_id' => $solicitud?->id,
            'solicitud_vacaciones_id' => $solicitudVacaciones?->id,
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

        return back()->with('toast', ['type' => 'success', 'message' => 'Documento generado correctamente.']);
    }

    /**
     * Sube el documento firmado en físico (escaneado) para un formato ya
     * generado: lo guarda en el expediente del colaborador como un
     * EmployeeDocument normal (mismo flujo que subir cualquier documento de
     * expediente) y enlaza ambos registros vía signed_document_id.
     */
    public function subirFirmado(SubirFormatoFirmadoRequest $request, GeneratedDocument $documento): RedirectResponse
    {
        $this->authorize('viewAny', DocumentTemplate::class);

        $colaborador = $documento->usuario;

        if ($colaborador === null && $documento->solicitud !== null) {
            $colaborador = $documento->solicitud->usuario;
        }

        if ($colaborador === null && $documento->solicitudVacaciones !== null) {
            $colaborador = $documento->solicitudVacaciones->usuario;
        }

        abort_unless($colaborador !== null, 422, 'Este documento no está asociado a un colaborador; no se puede archivar en un expediente.');

        $tipo = DocumentType::query()->findOrFail((int) $request->validated('document_type_id'));
        $firmado = $this->documentoStorage->subirVersion($colaborador, $tipo, $request->file('archivo'), $request->user()->id);

        $documento->update([
            'signed_document_id' => $firmado->id,
            'status' => EstadoDocumentoGenerado::Firmado,
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Documento firmado subido y asociado al expediente del colaborador.']);
    }

    /**
     * @return array<string, string>
     */
    private function extraDesdeSolicitud(SolicitudInterna $solicitud): array
    {
        return [
            'folio_solicitud' => $solicitud->folio,
            'tipo_solicitud' => $solicitud->tipo->etiqueta(),
            'motivo_solicitud' => $solicitud->motivo,
            'motivo_permiso' => $solicitud->motivo,
            'observaciones' => (string) $solicitud->observaciones,
            'fecha_inicio_permiso' => $solicitud->fecha_inicio?->format('d/m/Y') ?? '',
            'fecha_fin_permiso' => $solicitud->fecha_fin?->format('d/m/Y') ?? '',
            'fecha_inicio_incapacidad' => $solicitud->fecha_inicio?->format('d/m/Y') ?? '',
            'fecha_fin_incapacidad' => $solicitud->fecha_fin?->format('d/m/Y') ?? '',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function extraDesdeSolicitudVacaciones(SolicitudVacaciones $solicitud): array
    {
        return [
            'fecha_inicio_permiso' => $solicitud->fecha_inicio->format('d/m/Y'),
            'fecha_fin_permiso' => $solicitud->fecha_fin->format('d/m/Y'),
            'dias_vacaciones' => (string) $solicitud->dias_solicitados,
            'motivo_solicitud' => (string) $solicitud->comentario,
            'observaciones' => (string) $solicitud->comentario,
        ];
    }

    public function descargar(GeneratedDocument $documento): StreamedResponse
    {
        $this->authorize('viewAny', DocumentTemplate::class);

        if ($documento->status === EstadoDocumentoGenerado::Generado) {
            $documento->update(['status' => EstadoDocumentoGenerado::Entregado]);
        }

        return $this->storage->respuesta($documento->path, [
            'Content-Disposition' => 'attachment; filename="'.$documento->generated_name.'"',
        ]);
    }

    public function destroy(GeneratedDocument $documento): RedirectResponse
    {
        $this->authorize('viewAny', DocumentTemplate::class);

        $this->storage->eliminar($documento->path);
        $documento->delete();

        return back()->with('toast', ['type' => 'success', 'message' => 'Documento generado eliminado.']);
    }
}
