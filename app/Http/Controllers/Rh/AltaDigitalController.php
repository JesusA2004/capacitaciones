<?php

namespace App\Http\Controllers\Rh;

use App\Enums\EstadoAltaDigital;
use App\Exports\ReporteRhExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rh\RechazarAltaDigitalRequest;
use App\Http\Requests\Rh\RevisarAltaDigitalRequest;
use App\Http\Requests\Rh\StoreAltaDigitalRequest;
use App\Models\AltaDigital;
use App\Models\AltaDigitalDocumento;
use App\Models\Candidato;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Services\AlcanceOrganizacionalService;
use App\Services\AltaDigital\AltaDigitalStorageService;
use App\Services\AltaDigital\ConversionColaboradorService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AltaDigitalController extends Controller
{
    private const FILTROS = ['empresa_id', 'sucursal_id', 'departamento_id', 'puesto_id', 'estado', 'busqueda', 'fecha_inicio', 'fecha_fin'];

    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly ConversionColaboradorService $conversion,
        private readonly AltaDigitalStorageService $storage,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', AltaDigital::class);

        $altas = $this->queryFiltrada($request)->orderByDesc('created_at')->paginate(15)->withQueryString();

        return Inertia::render('Rh/Altas/Index', [
            'altas' => $altas,
            'filtros' => $request->only(self::FILTROS),
            'opciones' => [
                'empresas' => Empresa::query()->orderBy('nombre')->get(['id', 'nombre']),
                'sucursales' => Sucursal::query()->orderBy('nombre')->get(['id', 'nombre', 'empresa_id']),
                'departamentos' => Departamento::query()->orderBy('nombre')->get(['id', 'nombre']),
                'puestos' => Puesto::query()->orderBy('nombre')->get(['id', 'nombre', 'departamento_id']),
                'estados' => array_map(fn (EstadoAltaDigital $e) => ['value' => $e->value, 'etiqueta' => $e->etiqueta()], EstadoAltaDigital::cases()),
            ],
        ]);
    }

    public function exportarExcel(Request $request): HttpResponse
    {
        $this->authorize('viewAny', AltaDigital::class);

        [$columnas, $filas] = $this->tabla($request);

        return Excel::download(
            new ReporteRhExport('Altas digitales', $columnas, $filas),
            'altas-digitales-'.now()->format('Y-m-d').'.xlsx',
        );
    }

    public function exportarPdf(Request $request): HttpResponse
    {
        $this->authorize('viewAny', AltaDigital::class);

        [$columnas, $filas] = $this->tabla($request);

        return Pdf::loadView('pdf.reporte-rh', ['titulo' => 'Altas digitales', 'columnas' => $columnas, 'filas' => $filas])
            ->setPaper('letter', 'landscape')
            ->download('altas-digitales-'.now()->format('Y-m-d').'.pdf');
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, array<int, string|int|null>>}
     */
    private function tabla(Request $request): array
    {
        $altas = $this->queryFiltrada($request)->orderByDesc('created_at')->get();

        $columnas = ['Nombre', 'Correo', 'Puesto', 'Departamento', 'Empresa', 'Sucursal', 'Estado', 'Fecha de creación'];

        $filas = $altas->map(fn (AltaDigital $a) => [
            trim("{$a->nombre} {$a->apellidos}"),
            $a->correo,
            $a->puesto?->nombre,
            $a->departamento?->nombre,
            $a->empresa?->nombre,
            $a->sucursal?->nombre,
            $a->estado->etiqueta(),
            $a->created_at->toDateString(),
        ])->all();

        return [$columnas, $filas];
    }

    /**
     * @return Builder<AltaDigital>
     */
    private function queryFiltrada(Request $request): Builder
    {
        $usuario = $request->user();

        return $this->alcance
            ->limitarPorSucursal(
                AltaDigital::query()->with([
                    'candidato:id,nombre,apellidos',
                    'empresa:id,nombre',
                    'sucursal:id,nombre',
                    'departamento:id,nombre',
                    'puesto:id,nombre',
                ]),
                $usuario,
            )
            ->when($request->integer('empresa_id'), fn ($query, $valor) => $query->where('empresa_id', $valor))
            ->when($request->integer('sucursal_id'), fn ($query, $valor) => $query->where('sucursal_id', $valor))
            ->when($request->integer('departamento_id'), fn ($query, $valor) => $query->where('departamento_id', $valor))
            ->when($request->integer('puesto_id'), fn ($query, $valor) => $query->where('puesto_id', $valor))
            ->when($request->string('estado')->toString(), fn ($query, string $estado) => $query->where('estado', $estado))
            ->when($request->string('fecha_inicio')->toString(), fn ($query, string $valor) => $query->whereDate('created_at', '>=', $valor))
            ->when($request->string('fecha_fin')->toString(), fn ($query, string $valor) => $query->whereDate('created_at', '<=', $valor))
            ->when($request->string('busqueda')->toString(), function ($query, string $busqueda) {
                $query->where(function ($sub) use ($busqueda): void {
                    $sub->where('nombre', 'like', "%{$busqueda}%")
                        ->orWhere('apellidos', 'like', "%{$busqueda}%")
                        ->orWhere('correo', 'like', "%{$busqueda}%")
                        ->orWhere('curp', 'like', "%{$busqueda}%");
                });
            });
    }

    public function show(AltaDigital $alta): Response
    {
        $this->authorize('view', $alta);

        $alta->load([
            'candidato:id,nombre,apellidos,correo',
            'vacante:id,puesto_id',
            'empresa:id,nombre',
            'sucursal:id,nombre',
            'departamento:id,nombre',
            'puesto:id,nombre',
            'documentos.tipo',
            'revisadoPor:id,name,apellidos',
            'aprobadoPor:id,name,apellidos',
            'colaborador:id,name,apellidos',
        ]);

        return Inertia::render('Rh/Altas/Show', [
            'alta' => $alta,
        ]);
    }

    public function store(StoreAltaDigitalRequest $request): RedirectResponse
    {
        $datos = $request->validated();
        $candidato = $datos['candidato_id'] ?? null
            ? Candidato::query()->firstWhere('id', $datos['candidato_id'])
            : null;

        $alta = AltaDigital::create([
            ...$datos,
            'nombre' => $datos['nombre'] ?? $candidato?->nombre,
            'apellidos' => $datos['apellidos'] ?? $candidato?->apellidos,
            'correo' => $datos['correo'] ?? $candidato?->correo,
            'telefono' => $datos['telefono'] ?? $candidato?->telefono,
            'empresa_id' => $datos['empresa_id'] ?? $candidato?->empresa_id,
            'sucursal_id' => $datos['sucursal_id'] ?? $candidato?->sucursal_id,
            'departamento_id' => $datos['departamento_id'] ?? $candidato?->departamento_id,
            'puesto_id' => $datos['puesto_id'] ?? $candidato?->puesto_objetivo_id,
            'token' => Str::random(48),
            'token_expira_en' => now()->addDays((int) config('altas.token_dias_vigencia')),
            'estado' => EstadoAltaDigital::Creada,
            'creado_por' => $request->user()?->id,
        ]);

        return redirect()->route('rh.altas.show', $alta)
            ->with('toast', ['type' => 'success', 'message' => 'Alta digital creada. Copia la liga y envíala al candidato.']);
    }

    public function enviar(AltaDigital $alta): RedirectResponse
    {
        $this->authorize('enviar', $alta);

        $alta->update([
            'estado' => EstadoAltaDigital::Enviada,
            'token' => $alta->token ?: Str::random(48),
            'token_expira_en' => now()->addDays((int) config('altas.token_dias_vigencia')),
            'enviada_en' => now(),
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Liga (re)enviada. Vigente por '.config('altas.token_dias_vigencia').' días.']);
    }

    public function revisar(RevisarAltaDigitalRequest $request, AltaDigital $alta): RedirectResponse
    {
        $alta->update([
            'estado' => $request->validated('estado'),
            'comentarios' => $request->validated('comentarios'),
            'revisado_por' => $request->user()?->id,
            'revisado_en' => now(),
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Alta digital actualizada.']);
    }

    public function aprobar(Request $request, AltaDigital $alta): RedirectResponse
    {
        $this->authorize('aprobar', $alta);

        $alta->update([
            'estado' => EstadoAltaDigital::Aprobada,
            'aprobado_por' => $request->user()?->id,
            'aprobado_en' => now(),
        ]);

        try {
            $this->conversion->convertir($alta, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('toast', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        return back()->with('toast', ['type' => 'success', 'message' => 'Alta aprobada: se creó el colaborador y su expediente.']);
    }

    public function rechazar(RechazarAltaDigitalRequest $request, AltaDigital $alta): RedirectResponse
    {
        $alta->update([
            'estado' => EstadoAltaDigital::Rechazada,
            'motivo_rechazo' => $request->validated('motivo_rechazo'),
            'revisado_por' => $request->user()?->id,
            'revisado_en' => now(),
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Alta digital rechazada.']);
    }

    public function descargarDocumento(AltaDigital $alta, AltaDigitalDocumento $documento): StreamedResponse
    {
        $this->authorize('view', $alta);
        abort_unless($documento->alta_digital_id === $alta->id, 404);

        return $this->storage->respuesta($documento->path, [
            'Content-Disposition' => 'attachment; filename="'.$documento->original_name.'"',
        ]);
    }

    public function descargarFoto(AltaDigital $alta): StreamedResponse
    {
        $this->authorize('view', $alta);
        abort_unless($alta->foto_path !== null, 404);

        return $this->storage->respuesta($alta->foto_path, [
            'Content-Disposition' => 'inline; filename="'.$alta->foto_original_name.'"',
        ]);
    }

    public function descargarFirma(AltaDigital $alta): StreamedResponse
    {
        $this->authorize('view', $alta);
        abort_unless($alta->firma_path !== null, 404);

        return $this->storage->respuesta($alta->firma_path, [
            'Content-Disposition' => 'inline; filename="firma.png"',
        ]);
    }

    public function cancelar(AltaDigital $alta): RedirectResponse
    {
        $this->authorize('cancelar', $alta);

        $alta->update(['estado' => EstadoAltaDigital::Cancelada]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Alta digital cancelada.']);
    }
}
