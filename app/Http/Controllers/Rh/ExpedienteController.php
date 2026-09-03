<?php

namespace App\Http\Controllers\Rh;

use App\Enums\EstadoUsuario;
use App\Exports\ReporteRhExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rh\ActualizarDatosPersonalesRequest;
use App\Models\AltaDigital;
use App\Models\Departamento;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\Empresa;
use App\Models\MovimientoLaboral;
use App\Models\Puesto;
use App\Models\SolicitudVacaciones;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Expedientes\DocumentoStorageService;
use App\Services\Expedientes\ExpedienteService;
use App\Services\Onboarding\OnboardingService;
use App\Services\Vacaciones\VacacionesService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExpedienteController extends Controller
{
    private const FILTROS = ['busqueda', 'empresa_id', 'sucursal_id', 'departamento_id', 'puesto_id', 'estatus', 'fecha_inicio', 'fecha_fin'];

    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly ExpedienteService $expediente,
        private readonly OnboardingService $onboarding,
        private readonly VacacionesService $vacaciones,
        private readonly DocumentoStorageService $documentoStorage,
    ) {}

    /**
     * Explorador de expedientes: Empresas / Sucursales / Colaboradores.
     * No es un CRUD de "expedientes" (no existe esa tabla): lista
     * colaboradores dentro del alcance del usuario, con su avance de
     * expediente calculado.
     */
    public function index(Request $request): Response
    {
        $usuario = $request->user();

        abort_unless($usuario->can('expedientes.ver_todos') || $usuario->can('expedientes.ver_sucursal'), 403);

        $colaboradores = $this->queryFiltrada($request)
            ->orderBy('name')
            ->paginate(24)
            ->withQueryString();

        $colaboradores->getCollection()->transform(function (User $colaborador) {
            $resumen = $this->expediente->resumenCompletitud($colaborador);

            return [
                'id' => $colaborador->id,
                'name' => $colaborador->name,
                'apellidos' => $colaborador->apellidos,
                'numero_empleado' => $colaborador->numero_empleado,
                'foto_url' => $this->fotoUrl($colaborador),
                'estatus' => $colaborador->estatus->value,
                'empresa' => $colaborador->sucursalPrincipal?->empresa,
                'sucursal' => $colaborador->sucursalPrincipal,
                'departamento' => $colaborador->departamento,
                'puesto' => $colaborador->puesto,
                'expediente_porcentaje' => $resumen['porcentaje'],
                'documentos_pendientes' => $resumen['pendientes'] + $resumen['rechazados'],
                'actualizado_en' => $colaborador->updated_at?->toDateString(),
            ];
        });

        return Inertia::render('Rh/Expedientes/Index', [
            'colaboradores' => $colaboradores,
            'filtros' => $request->only(self::FILTROS),
            'empresasDisponibles' => Empresa::query()->orderBy('nombre')->get(['id', 'nombre']),
            'sucursalesDisponibles' => Sucursal::query()->orderBy('nombre')->get(['id', 'nombre', 'empresa_id']),
            'departamentosDisponibles' => Departamento::query()->orderBy('nombre')->get(['id', 'nombre']),
            'puestosDisponibles' => Puesto::query()->orderBy('nombre')->get(['id', 'nombre']),
            'estados' => array_map(fn (EstadoUsuario $estado) => ['value' => $estado->value, 'etiqueta' => $estado->etiqueta()], EstadoUsuario::cases()),
        ]);
    }

    public function exportarExcel(Request $request): HttpResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('expedientes.ver_todos') || $usuario->can('expedientes.ver_sucursal'), 403);

        [$columnas, $filas] = $this->tabla($request);

        return Excel::download(
            new ReporteRhExport('Expedientes', $columnas, $filas),
            'expedientes-'.now()->format('Y-m-d').'.xlsx',
        );
    }

    public function exportarPdf(Request $request): HttpResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('expedientes.ver_todos') || $usuario->can('expedientes.ver_sucursal'), 403);

        [$columnas, $filas] = $this->tabla($request);

        return Pdf::loadView('pdf.reporte-rh', ['titulo' => 'Expedientes', 'columnas' => $columnas, 'filas' => $filas])
            ->setPaper('letter', 'landscape')
            ->download('expedientes-'.now()->format('Y-m-d').'.pdf');
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, array<int, string|int|null>>}
     */
    private function tabla(Request $request): array
    {
        $colaboradores = $this->queryFiltrada($request)->orderBy('name')->get();

        $columnas = ['Nombre', 'Número de empleado', 'Empresa', 'Sucursal', 'Departamento', 'Puesto', 'Estado', 'Expediente completo', 'Documentos pendientes'];

        $filas = $colaboradores->map(function (User $colaborador) {
            $resumen = $this->expediente->resumenCompletitud($colaborador);

            return [
                trim("{$colaborador->name} {$colaborador->apellidos}"),
                $colaborador->numero_empleado,
                $colaborador->sucursalPrincipal?->empresa?->nombre,
                $colaborador->sucursalPrincipal?->nombre,
                $colaborador->departamento?->nombre,
                $colaborador->puesto?->nombre,
                $colaborador->estatus->etiqueta(),
                $resumen['porcentaje'].'%',
                $resumen['pendientes'] + $resumen['rechazados'],
            ];
        })->all();

        return [$columnas, $filas];
    }

    /**
     * @return Builder<User>
     */
    private function queryFiltrada(Request $request): Builder
    {
        $usuario = $request->user();

        return User::query()
            ->tap(fn ($query) => $this->alcance->limitarExpedientesPorAlcance($query, $usuario))
            ->with([
                'sucursalPrincipal:id,nombre,empresa_id',
                'sucursalPrincipal.empresa:id,nombre',
                'departamento:id,nombre',
                'puesto:id,nombre',
            ])
            ->when($request->string('busqueda')->toString(), function ($query, string $busqueda) {
                $query->where(function ($sub) use ($busqueda) {
                    $sub->where('name', 'like', "%{$busqueda}%")
                        ->orWhere('apellidos', 'like', "%{$busqueda}%")
                        ->orWhere('numero_empleado', 'like', "%{$busqueda}%");
                });
            })
            ->when($request->integer('empresa_id'), fn ($query, int $id) => $query->whereHas('sucursalPrincipal', fn ($sub) => $sub->where('empresa_id', $id)))
            ->when($request->integer('sucursal_id'), fn ($query, int $id) => $query->where('sucursal_principal_id', $id))
            ->when($request->integer('departamento_id'), fn ($query, int $id) => $query->where('departamento_id', $id))
            ->when($request->integer('puesto_id'), fn ($query, int $id) => $query->where('puesto_id', $id))
            ->when($request->string('estatus')->toString(), fn ($query, string $estatus) => $query->where('estatus', $estatus))
            ->when($request->string('fecha_inicio')->toString(), fn ($query, string $valor) => $query->whereDate('fecha_ingreso', '>=', $valor))
            ->when($request->string('fecha_fin')->toString(), fn ($query, string $valor) => $query->whereDate('fecha_ingreso', '<=', $valor));
    }

    public function show(Request $request, User $colaborador): Response
    {
        return $this->renderExpediente($request, $colaborador, esPropio: false);
    }

    public function miExpediente(Request $request): Response
    {
        return $this->renderExpediente($request, $request->user(), esPropio: true);
    }

    private function renderExpediente(Request $request, User $colaborador, bool $esPropio): Response
    {
        $usuario = $request->user();

        abort_unless($this->alcance->puedeVerExpediente($usuario, $colaborador), 403);

        $colaborador->loadMissing([
            'sucursalPrincipal:id,nombre,empresa_id',
            'sucursalPrincipal.empresa:id,nombre',
            'departamento:id,nombre',
            'puesto:id,nombre',
            'jefe:id,name,apellidos',
        ]);

        $resumen = $this->expediente->resumenCompletitud($colaborador);
        $documentos = $this->expediente->documentosVigentes($colaborador);
        $alta = AltaDigital::query()->where('user_id', $colaborador->id)->first();

        return Inertia::render($esPropio ? 'Rh/Expedientes/MiExpediente' : 'Rh/Expedientes/Show', [
            'esPropio' => $esPropio,
            'puedeEditar' => $usuario->can('expedientes.editar') || $usuario->is($colaborador),
            'puedeRevisarDocumentos' => $usuario->can('documentos.revisar') && ! $usuario->is($colaborador),
            'colaborador' => [
                'id' => $colaborador->id,
                'name' => $colaborador->name,
                'apellidos' => $colaborador->apellidos,
                'numero_empleado' => $colaborador->numero_empleado,
                'email' => $colaborador->email,
                'telefono' => $colaborador->telefono,
                'foto_url' => $this->fotoUrl($colaborador),
                'estatus' => $colaborador->estatus->value,
                'estatus_imss' => $colaborador->estatus_imss->value,
                'fecha_alta_imss' => $colaborador->fecha_alta_imss?->toDateString(),
                'periodo_prueba_inicio' => $colaborador->periodo_prueba_inicio?->toDateString(),
                'periodo_prueba_fin' => $colaborador->periodo_prueba_fin?->toDateString(),
                'en_periodo_prueba' => $colaborador->enPeriodoDePrueba(),
                'fecha_ingreso' => $colaborador->fecha_ingreso?->toDateString(),
                'empresa' => $colaborador->sucursalPrincipal?->empresa,
                'sucursal' => $colaborador->sucursalPrincipal,
                'departamento' => $colaborador->departamento,
                'puesto' => $colaborador->puesto,
                'jefe' => $colaborador->jefe,
                'fecha_nacimiento' => $colaborador->fecha_nacimiento?->toDateString(),
                'curp' => $colaborador->curp,
                'rfc' => $colaborador->rfc,
                'nss' => $colaborador->nss,
                'domicilio' => $colaborador->domicilio,
                'correo_personal' => $colaborador->correo_personal,
                'contacto_emergencia_nombre' => $colaborador->contacto_emergencia_nombre,
                'contacto_emergencia_telefono' => $colaborador->contacto_emergencia_telefono,
            ],
            'resumenExpediente' => $resumen,
            'documentosRequeridos' => $this->documentosParaVista($documentos),
            'onboarding' => $this->onboarding->checklist($colaborador),
            'saldoVacaciones' => $this->vacaciones->saldo($colaborador),
            'solicitudesVacaciones' => SolicitudVacaciones::query()
                ->where('user_id', $colaborador->id)
                ->orderByDesc('created_at')
                ->limit(10)
                ->get(),
            'movimientosLaborales' => MovimientoLaboral::query()
                ->where('user_id', $colaborador->id)
                ->with([
                    'colaborador:id,name,apellidos',
                    'puestoAnterior:id,nombre', 'puestoNuevo:id,nombre',
                    'sucursalAnterior:id,nombre', 'sucursalNueva:id,nombre',
                    'departamentoAnterior:id,nombre', 'departamentoNuevo:id,nombre',
                    'empresaAnterior:id,nombre', 'empresaNueva:id,nombre',
                    'jefeAnterior:id,name,apellidos', 'jefeNuevo:id,name,apellidos',
                    'vacante:id,puesto_id', 'vacante.puesto:id,nombre',
                    'documento:id,original_name',
                    'registradoPor:id,name,apellidos',
                ])
                ->orderByDesc('fecha_movimiento')
                ->orderByDesc('id')
                ->limit(30)
                ->get(),
            'altaDigital' => $alta ? [
                'id' => $alta->id,
                'estado' => $alta->estado->value,
                'aviso_privacidad_aceptado' => $alta->aviso_privacidad_aceptado,
                'aviso_privacidad_aceptado_en' => $alta->aviso_privacidad_aceptado_en?->toDateTimeString(),
                'consentimiento_datos_aceptado' => $alta->consentimiento_datos_aceptado,
                'consentimiento_datos_aceptado_en' => $alta->consentimiento_datos_aceptado_en?->toDateTimeString(),
            ] : null,
        ]);
    }

    public function actualizarDatosPersonales(ActualizarDatosPersonalesRequest $request, User $colaborador): RedirectResponse
    {
        $colaborador->update($request->validated());

        return back()->with('toast', ['type' => 'success', 'message' => 'Datos personales actualizados correctamente.']);
    }

    /**
     * Sirve la foto de perfil del colaborador de forma protegida: nunca se
     * expone la ruta física del disco NAS al frontend (ver
     * DocumentoStorageService), solo esta URL con la misma autorización que
     * el resto del expediente.
     */
    public function descargarFoto(Request $request, User $colaborador): StreamedResponse
    {
        abort_unless($this->alcance->puedeVerExpediente($request->user(), $colaborador), 403);
        abort_unless($colaborador->foto_path !== null, 404);

        return $this->documentoStorage->respuesta($colaborador->foto_path, [
            'Content-Disposition' => 'inline; filename="foto.jpg"',
        ]);
    }

    /**
     * URL protegida de la foto de perfil, o null si el colaborador no tiene
     * una. Nunca se expone `foto_path` (ruta física en el disco NAS) al
     * frontend — ver docs/SEGURIDAD.md.
     */
    private function fotoUrl(User $colaborador): ?string
    {
        if ($colaborador->foto_path === null) {
            return null;
        }

        return route('rh.expedientes.foto', $colaborador);
    }

    /**
     * @param  Collection<int, EmployeeDocument>  $vigentes
     * @return array<int, array<string, mixed>>
     */
    private function documentosParaVista(Collection $vigentes): array
    {
        return DocumentType::query()
            ->where('activo', true)
            ->orderBy('nombre')
            ->get()
            ->map(function (DocumentType $tipo) use ($vigentes) {
                $documento = $vigentes->get($tipo->id);

                return [
                    'tipo' => ['id' => $tipo->id, 'nombre' => $tipo->nombre, 'clave' => $tipo->clave, 'requerido' => $tipo->requerido],
                    'documento' => $documento ? [
                        'id' => $documento->id,
                        'status' => $documento->status->value,
                        'version' => $documento->version,
                        'original_name' => $documento->original_name,
                        'comments' => $documento->comments,
                        'rejection_reason' => $documento->rejection_reason,
                        'subido_por' => $documento->subidoPor ? trim("{$documento->subidoPor->name} {$documento->subidoPor->apellidos}") : null,
                        'revisado_por' => $documento->revisadoPor ? trim("{$documento->revisadoPor->name} {$documento->revisadoPor->apellidos}") : null,
                        'reviewed_at' => $documento->reviewed_at?->toDateString(),
                        'created_at' => $documento->created_at?->toDateString(),
                    ] : null,
                ];
            })
            ->all();
    }
}
