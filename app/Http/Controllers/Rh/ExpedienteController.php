<?php

namespace App\Http\Controllers\Rh;

use App\Enums\EstadoUsuario;
use App\Enums\TipoSolicitudInterna;
use App\Exports\ReporteRhExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Colaboradores\SubirFotoRequest;
use App\Http\Requests\Rh\ActualizarDatosLaboralesRequest;
use App\Http\Requests\Rh\ActualizarDatosPersonalesRequest;
use App\Http\Requests\Rh\GenerarReciboNominaRequest;
use App\Http\Requests\Rh\RegistrarAvisosRequest;
use App\Models\AltaDigital;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\Empresa;
use App\Models\MovimientoLaboral;
use App\Models\Prestamo;
use App\Models\PrestamoMovimiento;
use App\Models\Puesto;
use App\Models\ReciboNomina;
use App\Models\SolicitudInterna;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Colaboradores\FotoColaboradorService;
use App\Services\Expedientes\AvisoPrivacidadService;
use App\Services\Expedientes\DocumentoStorageService;
use App\Services\Expedientes\ExpedienteService;
use App\Services\MovimientosLaborales\MovimientoLaboralService;
use App\Services\Nomina\PrestamoService;
use App\Services\Nomina\ReciboNominaService;
use App\Services\Onboarding\OnboardingService;
use App\Services\Solicitudes\BajaColaboradorService;
use App\Services\Vacaciones\VacacionesService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Role;
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
        private readonly AvisoPrivacidadService $avisoPrivacidad,
        private readonly BajaColaboradorService $baja,
        private readonly MovimientoLaboralService $movimientos,
        private readonly ReciboNominaService $reciboNomina,
        private readonly PrestamoService $prestamoService,
        private readonly FotoColaboradorService $fotos,
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

        $colaboradores->getCollection()->transform(function (Colaborador $colaborador) {
            $resumen = $this->expediente->resumenCompletitud($colaborador);

            return [
                'id' => $colaborador->id,
                'name' => $colaborador->name,
                'apellidos' => $colaborador->apellidos,
                'numero_empleado' => $colaborador->numero_empleado,
                'foto_url' => $this->fotoUrl($colaborador),
                'estatus' => $colaborador->estatus->value,
                'deleted_at' => $colaborador->deleted_at?->toISOString(),
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

        $filas = $colaboradores->map(function (Colaborador $colaborador) {
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
     * @return Builder<Colaborador>
     */
    private function queryFiltrada(Request $request): Builder
    {
        $usuario = $request->user();

        return Colaborador::withTrashed()
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

    public function show(Request $request, Colaborador $colaborador): Response
    {
        return $this->renderExpediente($request, $colaborador, esPropio: false);
    }

    public function miExpediente(Request $request): Response
    {
        $colaborador = $request->user()->colaborador;

        abort_if($colaborador === null, 403, 'Tu cuenta no tiene un colaborador enlazado.');

        return $this->renderExpediente($request, $colaborador, esPropio: true);
    }

    private function renderExpediente(Request $request, Colaborador $colaborador, bool $esPropio): Response
    {
        $usuario = $request->user();

        abort_unless($this->alcance->puedeVerExpediente($usuario, $colaborador), 403);

        $esCuentaPropia = $usuario->colaborador_id === $colaborador->id;
        $cuenta = $colaborador->user;
        $idUsuarioColaborador = $cuenta?->id;

        $colaborador->loadMissing([
            'sucursalPrincipal:id,nombre,empresa_id',
            'sucursalPrincipal.empresa:id,nombre',
            'departamento:id,nombre',
            'puesto:id,nombre',
            'jefe:id,name,apellidos',
            'avisosRegistradoPor:id,name,apellidos',
        ]);

        $resumen = $this->expediente->resumenCompletitud($colaborador);
        $documentos = $this->expediente->documentosVigentes($colaborador);
        $alta = AltaDigital::query()->where('colaborador_id', $colaborador->id)->first();

        return Inertia::render($esPropio ? 'Rh/Expedientes/MiExpediente' : 'Rh/Expedientes/Show', [
            'esPropio' => $esPropio,
            'puedeEditar' => $usuario->can('expedientes.editar') || $esCuentaPropia,
            'puedeReactivar' => $usuario->can('usuarios.reactivar'),
            'puedeRevisarDocumentos' => $usuario->can('documentos.revisar') && ! $esCuentaPropia,
            'puedeVerExtraccion' => $usuario->can('rh.documentos.extraccion.ver') && ! $esCuentaPropia,
            'puedeAplicarExtraccion' => $usuario->can('rh.documentos.extraccion.aplicar') && ! $esCuentaPropia,
            'puedeReprocesarExtraccion' => $usuario->can('rh.documentos.extraccion.reprocesar') && ! $esCuentaPropia,
            'puedeIgnorarExtraccion' => $usuario->can('rh.documentos.extraccion.ignorar') && ! $esCuentaPropia,
            'puedeGestionarAcceso' => $usuario->can('usuarios.desactivar') && ! $esCuentaPropia && $cuenta !== null,
            'puedeGestionarPassword' => $usuario->can('usuarios.editar') && ! $esCuentaPropia && $cuenta !== null,
            'puedeEditarCuenta' => $usuario->can('usuarios.editar') && ! $esCuentaPropia && $cuenta !== null,
            // Cambiar empresa/sucursal/departamento/puesto/jefe/sueldo es una
            // decisión organizacional: siempre RH, nunca autoservicio (a
            // diferencia de datos personales, donde el propio colaborador
            // puede editar los suyos).
            'puedeEditarLaborales' => $usuario->can('expedientes.editar') && ! $esCuentaPropia,
            'empresasDisponibles' => Empresa::query()->orderBy('nombre')->get(['id', 'nombre']),
            'sucursalesDisponibles' => Sucursal::query()->orderBy('nombre')->get(['id', 'nombre', 'empresa_id']),
            'departamentosDisponibles' => Departamento::query()->orderBy('nombre')->get(['id', 'nombre']),
            'puestosDisponibles' => Puesto::query()->orderBy('nombre')->get(['id', 'nombre']),
            'jefesDisponibles' => Colaborador::query()
                ->where('id', '!=', $colaborador->id)
                ->where('estatus', EstadoUsuario::Activo)
                ->orderBy('name')
                ->get(['id', 'name', 'apellidos', 'numero_empleado']),
            // La pestaña "Cuenta" del expediente absorbió lo que antes vivía
            // en Administración → Usuarios (ese listado se retiró, ver
            // docs/ROLES_Y_NAVEGACION.md): un colaborador sin cuenta todavía
            // puede recibir una aquí mismo, sin salir del expediente.
            'puedeCrearCuenta' => $usuario->can('usuarios.crear') && ! $esCuentaPropia && $cuenta === null,
            'rolesDisponibles' => Role::query()->orderBy('name')->pluck('name'),
            // Distingue "no tienes permiso" de "es tu propia cuenta" en el
            // mensaje del frontend — ambos casos esconden el mismo botón
            // pero la razón (y la acción sugerida) es distinta.
            'esCuentaPropia' => $esCuentaPropia,
            'colaborador' => [
                'id' => $colaborador->id,
                'name' => $colaborador->name,
                'apellidos' => $colaborador->apellidos,
                'numero_empleado' => $colaborador->numero_empleado,
                'email' => $cuenta?->email,
                'telefono' => $colaborador->telefono,
                'telefono_corporativo' => $colaborador->telefono_corporativo,
                'sueldo_mensual' => $colaborador->sueldo_mensual,
                'tiene_cuenta' => $cuenta !== null,
                'usuario_id' => $idUsuarioColaborador,
                'roles' => $cuenta?->getRoleNames() ?? collect(),
                'foto_url' => $this->fotoUrl($colaborador),
                'estatus' => $colaborador->estatus->value,
                'deleted_at' => $colaborador->deleted_at?->toISOString(),
                'acceso_bloqueado_en' => $cuenta?->acceso_bloqueado_en?->toISOString(),
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
            // colaborador_id es la fuente real de identidad de una
            // solicitud/vacaciones (ver SolicitudInterna::personaSolicitante()):
            // un colaborador SIN cuenta de acceso puede — y debe — tener
            // saldo de vacaciones, solicitudes, finiquito, préstamo y
            // recibos. user_id se conserva solo como fallback de lectura
            // para filas legacy creadas antes de que colaborador_id
            // empezara a llenarse.
            'saldoVacaciones' => $this->vacaciones->saldoColaborador($colaborador),
            // Fuente única de verdad (docs/SOLICITUDES_UNIFICADAS.md): las
            // vacaciones nuevas se crean en solicitudes_internas (tipo
            // vacaciones), no en la tabla legacy solicitudes_vacaciones —
            // leer de ahí dejaría el expediente mostrando historial viejo
            // congelado mientras RH aprueba/rechaza desde el Kanban actual.
            'solicitudesVacaciones' => SolicitudInterna::query()
                ->where(fn ($q) => $q->where('colaborador_id', $colaborador->id)->when(
                    $idUsuarioColaborador !== null,
                    fn ($sub) => $sub->orWhere('user_id', $idUsuarioColaborador),
                ))
                ->where('tipo', TipoSolicitudInterna::Vacaciones)
                ->orderByDesc('created_at')
                ->limit(10)
                ->get(['id', 'user_id', 'fecha_inicio', 'fecha_fin', 'dias_solicitados', 'motivo', 'estado', 'motivo_rechazo', 'created_at'])
                ->map(fn (SolicitudInterna $solicitud) => [
                    'id' => $solicitud->id,
                    'user_id' => $solicitud->user_id,
                    'fecha_inicio' => $solicitud->fecha_inicio?->toDateString(),
                    'fecha_fin' => $solicitud->fecha_fin?->toDateString(),
                    'dias_solicitados' => $solicitud->dias_solicitados,
                    'comentario' => $solicitud->motivo,
                    'estado' => $solicitud->estado->value,
                    'motivo_rechazo' => $solicitud->motivo_rechazo,
                    'created_at' => $solicitud->created_at?->toISOString(),
                ]),
            // Tab "Solicitudes" del expediente (sección 40 del encargo): todo
            // tipo de solicitud interna de este colaborador, no solo
            // vacaciones — mismo modelo unificado de arriba.
            'solicitudes' => SolicitudInterna::query()
                ->where(fn ($q) => $q->where('colaborador_id', $colaborador->id)->when(
                    $idUsuarioColaborador !== null,
                    fn ($sub) => $sub->orWhere('user_id', $idUsuarioColaborador),
                ))
                ->orderByDesc('created_at')
                ->limit(20)
                ->get(['id', 'folio', 'tipo', 'estado', 'motivo', 'created_at'])
                ->map(fn (SolicitudInterna $solicitud) => [
                    'id' => $solicitud->id,
                    'folio' => $solicitud->folio,
                    'tipo' => $solicitud->tipo->value,
                    'tipo_etiqueta' => $solicitud->tipo->etiqueta(),
                    'estado' => $solicitud->estado->value,
                    'motivo' => $solicitud->motivo,
                    'created_at' => $solicitud->created_at?->toISOString(),
                ]),
            // Tab "Recibos de nómina": historial de recibos informativos ya
            // generados (ver App\Services\Nomina\ReciboNominaService) — el
            // PDF se descarga aparte (recibos-nomina.descargar), aquí solo
            // se manda si el archivo quedó guardado o no.
            'recibosNomina' => $colaborador->recibosNomina()
                ->limit(20)
                ->get()
                ->map(fn (ReciboNomina $recibo) => [
                    'id' => $recibo->id,
                    'periodo_inicio' => $recibo->periodo_inicio->toDateString(),
                    'periodo_fin' => $recibo->periodo_fin->toDateString(),
                    'fecha_pago' => $recibo->fecha_pago->toDateString(),
                    'sueldo_base' => (float) $recibo->sueldo_base,
                    'percepciones' => $recibo->percepciones,
                    'deducciones' => $recibo->deducciones,
                    'total_percepciones' => (float) $recibo->total_percepciones,
                    'total_deducciones' => (float) $recibo->total_deducciones,
                    'neto' => (float) $recibo->neto,
                    'tiene_pdf' => $recibo->pdf_path !== null,
                    'created_at' => $recibo->created_at?->toISOString(),
                ]),
            // Tab "Préstamos": préstamos reales del colaborador (ver
            // App\Services\Nomina\PrestamoService), cada uno con su
            // historial de movimientos (ledger append-only).
            'prestamos' => collect(array_map(
                $this->prestamoResumen(...),
                $colaborador->prestamos()
                    ->with('movimientos.registradoPor:id,name,apellidos')
                    ->limit(10)
                    ->get()
                    ->all(),
            )),
            'movimientosLaborales' => MovimientoLaboral::query()
                ->where('colaborador_id', $colaborador->id)
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
            // Solo tiene sentido cuando NO hay alta digital: si ya existe una
            // (bloque de arriba), esa es la fuente de verdad y esta pestaña
            // no ofrece el registro manual (ver AvisoPrivacidadService).
            'avisosManual' => $alta ? null : [
                'aviso_privacidad_aceptado' => $colaborador->aviso_privacidad_aceptado,
                'aviso_privacidad_aceptado_en' => $colaborador->aviso_privacidad_aceptado_en?->toDateTimeString(),
                'consentimiento_datos_aceptado' => $colaborador->consentimiento_datos_aceptado,
                'consentimiento_datos_aceptado_en' => $colaborador->consentimiento_datos_aceptado_en?->toDateTimeString(),
                'registrado_por' => $colaborador->avisosRegistradoPor
                    ? trim("{$colaborador->avisosRegistradoPor->name} {$colaborador->avisosRegistradoPor->apellidos}")
                    : null,
            ],
            'puedeGestionarAvisos' => $usuario->can('expedientes.editar') || $esCuentaPropia,
            'avisoPrivacidadTexto' => config('legal.aviso_privacidad'),
            'consentimientoDatosTexto' => config('legal.consentimiento_datos'),
        ]);
    }

    /**
     * Baja administrativa inmediata sin pasar por el flujo de aprobación de
     * solicitudes (ver App\Services\Solicitudes\BajaColaboradorService, la
     * misma fuente que usa la baja aprobada por solicitud). Funciona con o
     * sin cuenta de acceso.
     */
    public function darDeBaja(Request $request, Colaborador $colaborador): RedirectResponse
    {
        $this->abortSiNoPuedeGestionarAcceso($request, $colaborador);

        $datos = $request->validate(['motivo' => ['nullable', 'string', 'max:500']]);

        $this->baja->ejecutar($colaborador, $request->user(), $datos['motivo'] ?? null);

        return back()->with('toast', ['type' => 'success', 'message' => 'El colaborador se dio de baja correctamente.']);
    }

    /**
     * Reactiva la relación laboral (deshace la baja). Nunca reactiva el
     * acceso al sistema por su cuenta — ver BajaColaboradorService::reactivar().
     */
    public function reactivar(Request $request, Colaborador $colaborador): RedirectResponse
    {
        abort_unless($request->user()->can('usuarios.reactivar'), 403);

        $this->baja->reactivar($colaborador);

        return back()->with('toast', ['type' => 'success', 'message' => 'El colaborador se reactivó correctamente.']);
    }

    private function abortSiNoPuedeGestionarAcceso(Request $request, Colaborador $colaborador): void
    {
        $usuario = $request->user();

        abort_unless(
            $usuario->can('usuarios.desactivar') && $usuario->colaborador_id !== $colaborador->id,
            403,
        );
    }

    public function actualizarDatosPersonales(ActualizarDatosPersonalesRequest $request, Colaborador $colaborador): RedirectResponse
    {
        $colaborador->update($request->validated());

        return back()->with('toast', ['type' => 'success', 'message' => 'Datos personales actualizados correctamente.']);
    }

    /**
     * Cambia empresa (vía sucursal)/sucursal/departamento/puesto/jefe/sueldo
     * directamente, sin pasar por una vacante — mismo patrón
     * snapshot → update → registrarCambioPuesto() que ya usa
     * VacanteController::cubrir(), aquí con $vacanteId = null.
     */
    public function actualizarDatosLaborales(ActualizarDatosLaboralesRequest $request, Colaborador $colaborador): RedirectResponse
    {
        $antes = $this->movimientos->snapshot($colaborador);

        $colaborador->update($request->safe()->only([
            'sucursal_principal_id', 'departamento_id', 'puesto_id', 'jefe_id', 'sueldo_mensual',
        ]));

        $this->movimientos->registrarCambioPuesto(
            $colaborador->fresh(),
            $antes,
            $request->user(),
            $request->safe()->string('motivo')->toString() ?: null,
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'Datos laborales actualizados correctamente.']);
    }

    /**
     * Genera y persiste un recibo de nómina informativo (ver
     * App\Services\Nomina\ReciboNominaService::generar()) a partir del
     * periodo/percepciones/deducciones capturados en el formulario. Mismo
     * patrón que FiniquitoController::generarPdf(): esta acción solo
     * persiste (redirige con toast para que Inertia recargue el historial),
     * la descarga del PDF va por separado en descargarReciboNomina().
     */
    public function generarReciboNomina(GenerarReciboNominaRequest $request, Colaborador $colaborador): RedirectResponse
    {
        abort_if($colaborador->sueldo_mensual === null, 422, 'Este colaborador todavía no tiene un sueldo mensual capturado en Datos laborales.');

        $recibo = $this->reciboNomina->generar($colaborador, [
            'periodo_inicio' => (string) $request->validated('periodo_inicio'),
            'periodo_fin' => (string) $request->validated('periodo_fin'),
            'fecha_pago' => (string) $request->validated('fecha_pago'),
            'sueldo_base' => (float) $request->validated('sueldo_base'),
            'percepciones' => $request->validated('percepciones') ?? [],
            'deducciones' => $request->validated('deducciones') ?? [],
        ], $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => $recibo->pdf_path !== null
                ? 'Recibo de nómina generado correctamente.'
                : 'El recibo se generó y guardó, pero el PDF no pudo escribirse en el almacenamiento; intenta descargarlo de nuevo más tarde.',
        ]);
    }

    /**
     * Descarga el PDF ya guardado de un recibo de nómina existente. Si el
     * PDF no se pudo guardar en su momento (ver ReciboNominaService), no hay
     * nada que descargar — se avisa explícitamente en vez de fallar en
     * silencio o regenerar montos distintos a los que ya se persistieron.
     */
    public function descargarReciboNomina(Request $request, ReciboNomina $recibo): StreamedResponse
    {
        $recibo->loadMissing('colaborador');

        abort_unless($this->alcance->puedeVerExpediente($request->user(), $recibo->colaborador), 403);
        abort_if($recibo->pdf_path === null || $recibo->pdf_disk === null, 404, 'El PDF de este recibo no está disponible; genera un recibo nuevo.');

        $nombre = 'recibo-nomina-'.($recibo->colaborador->numero_empleado ?? $recibo->colaborador_id).'-'.$recibo->periodo_inicio->format('Y-m').'.pdf';

        return Storage::disk($recibo->pdf_disk)->download($recibo->pdf_path, $nombre);
    }

    /**
     * Reintenta generar/guardar el PDF de un recibo ya persistido cuyo
     * primer intento falló (ver ReciboNominaService::regenerarPdf()): nunca
     * recalcula montos, solo usa el snapshot ya guardado.
     */
    public function regenerarPdfReciboNomina(Request $request, ReciboNomina $recibo): RedirectResponse
    {
        $recibo->loadMissing('colaborador');

        abort_unless($this->alcance->puedeVerExpediente($request->user(), $recibo->colaborador), 403);
        abort_unless($request->user()->can('expedientes.editar'), 403);

        $recibo = $this->reciboNomina->regenerarPdf($recibo);

        return back()->with('toast', $recibo->pdf_path !== null
            ? ['type' => 'success', 'message' => 'PDF regenerado correctamente.']
            : ['type' => 'warning', 'message' => 'El PDF sigue sin poder guardarse; intenta de nuevo más tarde.']);
    }

    /**
     * Registro manual de un abono/ajuste al saldo de un préstamo (fuera del
     * recibo de nómina automático) — siempre RH, nunca autoservicio, ver
     * App\Services\Nomina\PrestamoService::registrarMovimiento().
     */
    public function registrarPagoPrestamo(Request $request, Prestamo $prestamo): RedirectResponse
    {
        abort_unless($request->user()->can('expedientes.editar'), 403);

        $datos = $request->validate([
            'monto' => ['required', 'numeric', 'min:0.01', 'max:9999999.99'],
            'tipo' => ['required', 'string', 'in:manual,ajuste'],
        ]);

        $this->prestamoService->registrarMovimiento($prestamo, (float) $datos['monto'], $datos['tipo'], $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Movimiento del préstamo registrado correctamente.']);
    }

    /**
     * RH confirma que el dinero del préstamo ya se entregó al colaborador
     * (ver App\Services\Nomina\PrestamoService::activar()): solo entonces
     * el préstamo pasa de 'pendiente_entrega' a 'activo' y cuenta como
     * deuda vigente / se sugiere en el recibo de nómina.
     */
    public function activarPrestamo(Request $request, Prestamo $prestamo): RedirectResponse
    {
        abort_unless($request->user()->can('expedientes.editar'), 403);

        $datos = $request->validate([
            'fecha_otorgamiento' => ['required', 'date'],
            'fecha_primer_descuento' => ['required', 'date', 'after_or_equal:fecha_otorgamiento'],
            'periodicidad' => ['required', 'string', 'in:semanal,quincenal,mensual'],
            'pago_programado' => ['required', 'numeric', 'min:0.01', 'max:9999999.99'],
        ]);

        $this->prestamoService->activar($prestamo, $datos);

        return back()->with('toast', ['type' => 'success', 'message' => 'Entrega del préstamo confirmada: ya está activo.']);
    }

    /**
     * Registro manual del aviso de privacidad / consentimiento de datos para
     * colaboradores sin Alta digital (ver AvisoPrivacidadService). Nunca se
     * usa si ya existe un alta digital real para este colaborador.
     */
    public function registrarAvisos(RegistrarAvisosRequest $request, Colaborador $colaborador): RedirectResponse
    {
        $this->avisoPrivacidad->registrar(
            $colaborador,
            $request->boolean('aviso_privacidad_aceptado'),
            $request->boolean('consentimiento_datos_aceptado'),
            $request->user(),
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'Avisos y consentimientos actualizados.']);
    }

    /**
     * Sirve la foto de perfil del colaborador de forma protegida: nunca se
     * expone la ruta física del disco NAS al frontend (ver
     * DocumentoStorageService), solo esta URL con la misma autorización que
     * el resto del expediente.
     */
    public function descargarFoto(Request $request, Colaborador $colaborador): StreamedResponse
    {
        abort_unless($this->alcance->puedeVerExpediente($request->user(), $colaborador), 403);
        abort_unless($colaborador->foto_path !== null, 404);

        return $this->documentoStorage->respuesta($colaborador->foto_path, [
            'Content-Disposition' => 'inline; filename="foto.jpg"',
            // La URL lleva `?v=` que cambia con cada foto nueva (ver
            // FotoColaboradorService::url), así que puede cachearse: las
            // miniaturas de listas y tableros no se vuelven a descargar.
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    /**
     * RH (o el propio colaborador desde su expediente) sube o cambia la
     * foto de perfil — normalizada a miniatura cuadrada por el servicio.
     */
    public function subirFoto(SubirFotoRequest $request, Colaborador $colaborador): RedirectResponse
    {
        $usuario = $request->user();

        abort_unless($this->alcance->puedeVerExpediente($usuario, $colaborador), 403);
        abort_unless($usuario->can('expedientes.editar') || $usuario->colaborador_id === $colaborador->id, 403);

        $this->fotos->actualizar($colaborador, $request->file('foto'), $usuario);

        return back()->with('toast', ['type' => 'success', 'message' => 'Foto de perfil actualizada.']);
    }

    /**
     * El colaborador sube su propia foto al registrar sus documentos.
     */
    public function subirFotoPropia(SubirFotoRequest $request): RedirectResponse
    {
        $usuario = $request->user();
        $colaborador = $usuario->colaborador;

        abort_if($colaborador === null, 403, 'Tu cuenta no tiene un colaborador enlazado.');

        $this->fotos->actualizar($colaborador, $request->file('foto'), $usuario);

        return back()->with('toast', ['type' => 'success', 'message' => 'Tu foto de perfil quedó guardada.']);
    }

    /**
     * URL protegida de la foto de perfil, o null si el colaborador no tiene
     * una. Nunca se expone `foto_path` (ruta física en el disco NAS) al
     * frontend — ver docs/SEGURIDAD.md.
     */
    private function fotoUrl(Colaborador $colaborador): ?string
    {
        return $this->fotos->url($colaborador);
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
                        'mime' => $documento->mime,
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

    /**
     * "Nombre apellidos" de un actor opcional — tipo de retorno nativo
     * `?string` (no inferido de un ternario inline) para que PHPStan no
     * derive `string` en vez de `string|null` en los `map()` que lo usan
     * (Collection<TValue> no es covariante, ver VacanteController).
     */
    private function nombreActor(?User $actor): ?string
    {
        return $actor ? trim("{$actor->name} {$actor->apellidos}") : null;
    }

    /**
     * @return array{id: int, monto_original: float, saldo: float, plazo: int, periodicidad: string, pago_programado: float, porcentaje_pagado: int, fecha_otorgamiento: string|null, fecha_primer_descuento: string|null, estado: string, movimientos: Collection<int, array<string, mixed>>}
     */
    private function prestamoResumen(Prestamo $prestamo): array
    {
        return [
            'id' => $prestamo->id,
            'monto_original' => (float) $prestamo->monto_original,
            'saldo' => (float) $prestamo->saldo,
            'plazo' => $prestamo->plazo,
            'periodicidad' => $prestamo->periodicidad,
            'pago_programado' => (float) $prestamo->pago_programado,
            'porcentaje_pagado' => (float) $prestamo->monto_original > 0
                ? (int) round((1 - ((float) $prestamo->saldo / (float) $prestamo->monto_original)) * 100)
                : 0,
            'fecha_otorgamiento' => $prestamo->fecha_otorgamiento?->toDateString(),
            'fecha_primer_descuento' => $prestamo->fecha_primer_descuento?->toDateString(),
            'estado' => $prestamo->estado,
            'movimientos' => collect(array_map(
                fn (PrestamoMovimiento $m): array => $this->item($this->movimientoResumen($m)),
                $prestamo->movimientos->all(),
            )),
        ];
    }

    /**
     * @return array{id: int, fecha: string, monto: float, tipo: string, saldo_anterior: float, saldo_nuevo: float, registrado_por: string|null}
     */
    private function movimientoResumen(PrestamoMovimiento $movimiento): array
    {
        return [
            'id' => $movimiento->id,
            'fecha' => $movimiento->fecha->toDateString(),
            'monto' => (float) $movimiento->monto,
            'tipo' => $movimiento->tipo,
            'saldo_anterior' => (float) $movimiento->saldo_anterior,
            'saldo_nuevo' => (float) $movimiento->saldo_nuevo,
            'registrado_por' => $this->nombreActor($movimiento->registradoPor),
        ];
    }

    /**
     * Illuminate\Support\Collection no es covariante (ver
     * https://phpstan.org/blog/whats-up-with-template-covariant): un shape
     * de array literal preciso no se acepta donde se declaro
     * `Collection<int, array<string, mixed>>` aunque sea estructuralmente
     * compatible. Ensanchar aqui en la frontera de la funcion si es una
     * operacion valida para PHPStan (mismo patron que
     * RhPendientesService::item()).
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function item(array $item): array
    {
        return $item;
    }
}
