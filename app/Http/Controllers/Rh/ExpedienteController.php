<?php

namespace App\Http\Controllers\Rh;

use App\Enums\EstadoUsuario;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rh\ActualizarDatosPersonalesRequest;
use App\Models\Departamento;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\Empresa;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Expedientes\ExpedienteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class ExpedienteController extends Controller
{
    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly ExpedienteService $expediente,
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

        $colaboradores = User::query()
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
                'foto_path' => $colaborador->foto_path,
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
            'filtros' => $request->only('busqueda', 'empresa_id', 'sucursal_id', 'departamento_id', 'puesto_id', 'estatus'),
            'empresasDisponibles' => Empresa::query()->orderBy('nombre')->get(['id', 'nombre']),
            'sucursalesDisponibles' => Sucursal::query()->orderBy('nombre')->get(['id', 'nombre', 'empresa_id']),
            'departamentosDisponibles' => Departamento::query()->orderBy('nombre')->get(['id', 'nombre']),
            'puestosDisponibles' => Puesto::query()->orderBy('nombre')->get(['id', 'nombre']),
            'estados' => array_map(fn (EstadoUsuario $estado) => ['value' => $estado->value, 'etiqueta' => $estado->etiqueta()], EstadoUsuario::cases()),
        ]);
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
                'foto_path' => $colaborador->foto_path,
                'estatus' => $colaborador->estatus->value,
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
        ]);
    }

    public function actualizarDatosPersonales(ActualizarDatosPersonalesRequest $request, User $colaborador): RedirectResponse
    {
        $colaborador->update($request->validated());

        return back()->with('toast', ['type' => 'success', 'message' => 'Datos personales actualizados correctamente.']);
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
