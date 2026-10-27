<?php

namespace App\Http\Controllers\Rh;

use App\Enums\EstadoCivil;
use App\Enums\EstadoDocumento;
use App\Enums\EstadoUsuario;
use App\Enums\Genero;
use App\Enums\TipoSolicitudInterna;
use App\Exports\ReporteRhExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Colaboradores\SubirFotoRequest;
use App\Http\Requests\Rh\ActualizarDatosLaboralesRequest;
use App\Http\Requests\Rh\ActualizarDatosPersonalesRequest;
use App\Http\Requests\Rh\GenerarReciboNominaRequest;
use App\Http\Requests\Rh\RegistrarAvisosRequest;
use App\Http\Requests\Rh\VistaPreviaReciboNominaRequest;
use App\Models\AltaDigital;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\Empresa;
use App\Models\MovimientoLaboral;
use App\Models\OfficialFormatGeneration;
use App\Models\Prestamo;
use App\Models\PrestamoMovimiento;
use App\Models\Puesto;
use App\Models\ReciboNomina;
use App\Models\SolicitudInterna;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Autenticacion\CredencialesService;
use App\Services\Avisos\AvisoService;
use App\Services\Colaboradores\AltaImssService;
use App\Services\Colaboradores\DomicilioService;
use App\Services\Colaboradores\FotoColaboradorService;
use App\Services\DocumentosMaestros\DocumentoProcesoService;
use App\Services\Expedientes\AvisoPrivacidadService;
use App\Services\Expedientes\CartaCompromisoService;
use App\Services\Expedientes\ContratosPendientesExpedienteService;
use App\Services\Expedientes\CorreccionesService;
use App\Services\Expedientes\DatosExpedienteService;
use App\Services\Expedientes\DatosFaltantesService;
use App\Services\Expedientes\DocumentoParService;
use App\Services\Expedientes\DocumentoStorageService;
use App\Services\Expedientes\ExpedienteCentroService;
use App\Services\Expedientes\ExpedienteService;
use App\Services\Expedientes\ExpedienteZipService;
use App\Services\Expedientes\HistorialCambiosDatosService;
use App\Services\Expedientes\MigracionInicial\ExpedientesInitialMigrationService;
use App\Services\Expedientes\VinculacionSynologyService;
use App\Services\Nomina\PrestamoService;
use App\Services\Nomina\ReciboNominaService;
use App\Services\Onboarding\ChecklistAdministrativoService;
use App\Services\Solicitudes\BajaColaboradorService;
use App\Services\Vacaciones\VacacionesService;
use App\Support\Paginacion;
use BackedEnum;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExpedienteController extends Controller
{
    private const FILTROS = ['busqueda', 'empresa_id', 'sucursal_id', 'departamento_id', 'puesto_id', 'estatus', 'fecha_inicio', 'fecha_fin', 'datos'];

    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly ExpedienteService $expediente,
        private readonly ChecklistAdministrativoService $onboarding,
        private readonly VacacionesService $vacaciones,
        private readonly DocumentoStorageService $documentoStorage,
        private readonly AvisoPrivacidadService $avisoPrivacidad,
        private readonly BajaColaboradorService $baja,
        private readonly ReciboNominaService $reciboNomina,
        private readonly PrestamoService $prestamoService,
        private readonly FotoColaboradorService $fotos,
        private readonly DocumentoProcesoService $documentoProceso,
        private readonly AltaImssService $altaImss,
        private readonly DatosExpedienteService $datosExpediente,
        private readonly DomicilioService $domicilios,
        private readonly DocumentoParService $pares,
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

        abort_unless($this->alcance->puedeExplorarExpedientes($usuario), 403);

        $colaboradores = $this->queryFiltrada($request)
            ->orderBy('name')
            ->paginate(Paginacion::porPagina(24))
            ->withQueryString();

        $resumenes = $this->expediente->resumenesCompletitud($colaboradores->getCollection()->pluck('id'));
        // Mismas columnas que exige el motor documental real (ver
        // completitudAlta): se revisan en memoria sobre las filas ya
        // cargadas, sin ninguna consulta extra por colaborador.
        $columnasRequeridas = array_keys($this->documentoProceso->columnasColaboradorRequeridasGlobal());

        $contratosPendientes = app(ContratosPendientesExpedienteService::class);

        $colaboradores->getCollection()->transform(function (Colaborador $colaborador) use ($resumenes, $columnasRequeridas, $contratosPendientes) {
            $resumen = $resumenes[$colaborador->id];
            $documentosPendientes = $resumen['pendientes'] + $resumen['rechazados'];
            // Columnas con cast a enum (estado civil, género) no se pueden
            // convertir a texto: un enum presente nunca está vacío.
            $datosFaltantes = collect($columnasRequeridas)->contains(function (string $columna) use ($colaborador): bool {
                $valor = $colaborador->{$columna};

                return ! $valor instanceof BackedEnum && trim((string) ($valor ?? '')) === '';
            });

            return [
                'id' => $colaborador->id,
                'name' => $colaborador->name,
                'apellidos' => $colaborador->apellidos,
                'nombre_completo' => $colaborador->nombreCompleto(),
                'numero_empleado' => $colaborador->numero_empleado,
                'foto_url' => $this->fotoUrl($colaborador),
                'estatus' => $colaborador->estatus->value,
                'deleted_at' => $colaborador->deleted_at?->toISOString(),
                'empresa' => $colaborador->sucursalPrincipal?->empresa,
                'sucursal' => $colaborador->sucursalPrincipal,
                'departamento' => $colaborador->departamento,
                'puesto' => $colaborador->puesto,
                'expediente_porcentaje' => $resumen['porcentaje'],
                'documentos_pendientes' => $documentosPendientes,
                // Su parte ya está completa: lo que falta son los contratos
                // (trabajo de RH), no algo que deba subir la persona.
                'contratos_pendientes' => $documentosPendientes === 0 ? $contratosPendientes->resumen($colaborador) : null,
                'datos_incompletos' => $datosFaltantes,
                // Acuse de alta IMSS: verde (registrado) / naranja (falta).
                'imss_alta' => $this->altaImss->indicador($colaborador),
                'actualizado_en' => $colaborador->updated_at?->toDateString(),
            ];
        });

        return Inertia::render('Rh/Expedientes/Index', [
            'colaboradores' => $colaboradores,
            'filtros' => [...$request->only(self::FILTROS), 'estatus' => $this->estatusFiltro($request)],
            'empresasDisponibles' => Empresa::query()->orderBy('nombre')->get(['id', 'nombre']),
            'sucursalesDisponibles' => Sucursal::query()->orderBy('nombre')->get(['id', 'nombre', 'empresa_id']),
            'departamentosDisponibles' => Departamento::query()->orderBy('nombre')->get(['id', 'nombre']),
            'puestosDisponibles' => Puesto::query()->orderBy('nombre')->get(['id', 'nombre']),
            'estados' => array_map(fn (EstadoUsuario $estado) => ['value' => $estado->value, 'etiqueta' => $estado->etiqueta()], EstadoUsuario::cases()),
            // Conteo real (no de esta página): cuántos colaboradores dentro
            // de tu alcance tienen algún dato base vacío que un documento
            // maestro activo requiere.
            'datosIncompletosTotal' => $this->documentoProceso->contarDatosFaltantesRh($this->queryFiltrada($request)),
        ]);
    }

    public function exportarExcel(Request $request): HttpResponse
    {
        $usuario = $request->user();
        abort_unless($this->alcance->puedeExplorarExpedientes($usuario), 403);

        [$columnas, $filas] = $this->tabla($request);

        return Excel::download(
            new ReporteRhExport('Expedientes', $columnas, $filas),
            'expedientes-'.now()->format('Y-m-d').'.xlsx',
        );
    }

    public function exportarPdf(Request $request): HttpResponse
    {
        $usuario = $request->user();
        abort_unless($this->alcance->puedeExplorarExpedientes($usuario), 403);

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

        $resumenes = $this->expediente->resumenesCompletitud($colaboradores->pluck('id'));

        $filas = $colaboradores->map(function (Colaborador $colaborador) use ($resumenes) {
            $resumen = $resumenes[$colaborador->id];

            return [
                $colaborador->nombreCompleto(),
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
     * Estado del listado: por defecto solo activos; "todos" sin filtro.
     */
    private function estatusFiltro(Request $request): string
    {
        $estatus = $request->string('estatus')->toString();

        return $estatus === 'todos' || EstadoUsuario::tryFrom($estatus) !== null ? $estatus : EstadoUsuario::Activo->value;
    }

    /**
     * @return Builder<Colaborador>
     */
    private function queryFiltrada(Request $request): Builder
    {
        $usuario = $request->user();

        return Colaborador::withTrashed()
            ->tap(fn ($query) => $this->alcance->limitarExpedientesPorAlcance($query, $usuario))
            // «Datos incompletos» (atajo de Mis pendientes).
            ->when($request->string('datos')->toString() === 'incompletos', fn ($query) => app(DatosFaltantesService::class)->filtrarIncompletos($query))
            ->with([
                'sucursalPrincipal:id,nombre,empresa_id',
                'sucursalPrincipal.empresa:id,nombre',
                'departamento:id,nombre',
                'puesto:id,nombre',
            ])
            // Buscador único: cada palabra en nombre, apellidos, número,
            // puesto, sucursal o departamento («demo cuernavaca»).
            ->when($request->string('busqueda')->toString(), fn ($query, string $busqueda) => $query->buscar($busqueda))
            ->when($request->integer('empresa_id'), fn ($query, int $id) => $query->whereHas('sucursalPrincipal', fn ($sub) => $sub->where('empresa_id', $id)))
            ->when($request->integer('sucursal_id'), fn ($query, int $id) => $query->where('sucursal_principal_id', $id))
            ->when($request->integer('departamento_id'), fn ($query, int $id) => $query->where('departamento_id', $id))
            ->when($request->integer('puesto_id'), fn ($query, int $id) => $query->where('puesto_id', $id))
            // Por defecto solo ACTIVOS; "todos" quita el filtro. Las bajas
            // nunca se borran: se ven eligiendo "Inactivo".
            ->when($this->estatusFiltro($request) !== 'todos', fn ($query) => $query->where('estatus', $this->estatusFiltro($request)))
            ->when($request->string('fecha_inicio')->toString(), fn ($query, string $valor) => $query->whereDate('fecha_ingreso', '>=', $valor))
            ->when($request->string('fecha_fin')->toString(), fn ($query, string $valor) => $query->whereDate('fecha_ingreso', '<=', $valor));
    }

    public function show(Request $request, Colaborador $colaborador): Response
    {
        return $this->renderExpediente($request, $colaborador, esPropio: false);
    }

    /**
     * «Avisar al colaborador»: notificación + push con los datos personales
     * que faltan (a lo más uno cada 24 h). Snapshot en avisos_datos_faltantes.
     */
    public function avisarDatosFaltantes(Request $request, Colaborador $colaborador, DatosFaltantesService $datos): RedirectResponse
    {
        abort_unless($request->user()->can('expedientes.revisar') && $this->alcance->puedeVerExpediente($request->user(), $colaborador), 403);

        $aviso = $datos->avisar($colaborador, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => sprintf('Se avisó a %s: faltan %d dato(s).', $colaborador->name, count($aviso->campos))]);
    }

    public function miExpediente(Request $request): Response
    {
        $colaborador = $request->user()->colaborador;

        abort_if($colaborador === null, 403, 'Tu cuenta no tiene un colaborador enlazado.');

        // Mis correcciones (datos, documentos, foto) con su estado y contador.
        return $this->renderExpediente($request, $colaborador, esPropio: true)
            ->with('misCorrecciones', app(CorreccionesService::class)->deColaborador($request->user()));
    }

    private function renderExpediente(Request $request, Colaborador $colaborador, bool $esPropio): Response
    {
        $usuario = $request->user();

        abort_unless($this->alcance->puedeVerExpediente($usuario, $colaborador), 403);

        $esCuentaPropia = $usuario->colaborador_id === $colaborador->id;
        $cuenta = $colaborador->user;
        $idUsuarioColaborador = $cuenta?->id;
        // «Generar credenciales» / «Restablecer contraseña» van a
        // CredencialesController: misma regla que su endpoint.
        $puedeGenerarCredenciales = ! $esPropio && app(CredencialesService::class)->puedeGenerar($usuario, $colaborador);

        $colaborador->loadMissing([
            'sucursalPrincipal:id,nombre,empresa_id',
            'sucursalPrincipal.empresa:id,nombre',
            'departamento:id,nombre',
            'puesto:id,nombre',
            'jefe:id,name,apellidos',
            'avisosRegistradoPor:id,name,apellidos',
        ]);

        $resumen = $this->expediente->resumenCompletitud($colaborador);
        $completitudDatos = $this->documentoProceso->completitudAlta($colaborador);
        $documentos = $this->expediente->documentosVigentes($colaborador);
        $alta = AltaDigital::query()->where('colaborador_id', $colaborador->id)->first();

        return Inertia::render($esPropio ? 'Rh/Expedientes/MiExpediente' : 'Rh/Expedientes/Show', [
            'esPropio' => $esPropio,
            // Carta compromiso de documentos pendientes (la más reciente), con su PDF.
            'cartaCompromiso' => app(CartaCompromisoService::class)->paraExpediente($colaborador),
            // ⚠ Faltan datos contractuales (+ «Avisar al colaborador» para RH).
            'datosFaltantes' => app(DatosFaltantesService::class)->resumen($colaborador),
            // «Avisos / Comunicaciones»: generales + personales, con lectura.
            // (El aviso de privacidad/consentimiento sigue en la base, ya no se muestra aquí.)
            'avisosComunicaciones' => collect(app(AvisoService::class)->paraColaborador($colaborador, 30)->items())
                ->map(fn (array $aviso) => [...$aviso, 'archivo_url' => $aviso['archivo'] !== null ? route($esPropio ? 'avisos.archivo' : 'rh.avisos.archivo', $aviso['id']) : null, 'imagen_path' => null])
                ->values()
                ->all(),
            'puedeAvisarDatosFaltantes' => ! $esPropio && $usuario->can('expedientes.revisar'),
            // Nadie edita su propio expediente (ni RH ni administración): en
            // "Mi expediente" se ofrece "Solicitar corrección".
            'puedeEditar' => $usuario->can('expedientes.editar') && ! $esCuentaPropia,
            // Recibos de nómina: solo RH (nomina.recibos.crear), nunca el propio.
            'puedeGenerarRecibos' => $usuario->can('nomina.recibos.crear') && ! $esCuentaPropia,
            'puedeReactivar' => $usuario->can('usuarios.reactivar'),
            // Acceso al Ciclo laboral (contratos, onboarding, evaluación,
            // «Iniciar baja» con su finiquito): antes solo se llegaba desde
            // un pendiente o una notificación, nunca desde el expediente.
            'cicloUrl' => ! $esPropio && ($this->alcance->alcanzaColaborador($usuario, $colaborador) || $usuario->can('iniciarCierre', $colaborador))
                ? route('rh.colaboradores.ciclo', $colaborador->id)
                : null,
            // Jefe directo (cadena de mando) o RH: sin solicitud previa.
            'puedeIniciarBaja' => ! $esPropio && $usuario->can('iniciarCierre', $colaborador),
            'puedeRevisarDocumentos' => $usuario->can('documentos.revisar') && ! $esCuentaPropia,
            'puedeDescargarExpediente' => ! $esPropio && $usuario->can('descargarExpediente', [EmployeeDocument::class, $colaborador]),
            'puedeVerExtraccion' => $usuario->can('rh.documentos.extraccion.ver') && ! $esCuentaPropia,
            'puedeAplicarExtraccion' => $usuario->can('rh.documentos.extraccion.aplicar') && ! $esCuentaPropia,
            'puedeReprocesarExtraccion' => $usuario->can('rh.documentos.extraccion.reprocesar') && ! $esCuentaPropia,
            'puedeIgnorarExtraccion' => $usuario->can('rh.documentos.extraccion.ignorar') && ! $esCuentaPropia,
            'puedeGestionarAcceso' => $usuario->can('usuarios.desactivar') && ! $esCuentaPropia && $cuenta !== null,
            // Baja administrativa inmediata = decisión laboral, no de cuenta:
            // permiso de baja del cierre laboral (nunca usuarios.desactivar).
            // El alcance ya lo exige puedeVerExpediente() arriba.
            'puedeDarDeBaja' => ! $esPropio && $this->puedeDarDeBaja($usuario, $colaborador),
            'puedeGestionarPassword' => $cuenta !== null && $puedeGenerarCredenciales,
            // Mismo chequeo que UpdateUsuarioRequest (UserPolicy::update).
            'puedeEditarCuenta' => ! $esCuentaPropia && $cuenta !== null && $usuario->can('update', $cuenta),
            // Cambiar empresa/sucursal/departamento/puesto/jefe/sueldo es una
            // decisión organizacional: siempre RH, nunca autoservicio (a
            // diferencia de datos personales, donde el propio colaborador
            // puede editar los suyos).
            'puedeEditarLaborales' => $usuario->can('expedientes.editar') && ! $esCuentaPropia,
            'empresasDisponibles' => Empresa::query()->orderBy('nombre')->get(['id', 'nombre']),
            'sucursalesDisponibles' => Sucursal::query()->orderBy('nombre')->get(['id', 'nombre', 'empresa_id']),
            'departamentosDisponibles' => Departamento::query()->orderBy('nombre')->get(['id', 'nombre']),
            // Sin combo de "Jefe directo": el jefe sale del organigrama según
            // puesto y sucursal (JefeDirectoService).
            'puestosDisponibles' => Puesto::query()->orderBy('nombre')->get(['id', 'nombre', 'puesto_superior_id']),
            // La pestaña "Cuenta" del expediente absorbió lo que antes vivía
            // en Administración → Usuarios (ese listado se retiró, ver
            // docs/ROLES_Y_NAVEGACION.md): un colaborador sin cuenta todavía
            // puede recibir una aquí mismo, sin salir del expediente.
            'puedeCrearCuenta' => $cuenta === null && $puedeGenerarCredenciales,
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
                'correo_corporativo' => $colaborador->correo_corporativo,
                'sueldo_mensual' => $colaborador->sueldo_mensual,
                'tiene_cuenta' => $cuenta !== null,
                'usuario_id' => $idUsuarioColaborador,
                'roles' => $cuenta?->getRoleNames() ?? collect(),
                'foto_url' => $this->fotoUrl($colaborador),
                // Solo la propia persona ve si su cambio de foto está pendiente/aprobado/rechazado.
                'foto_estado' => $esPropio ? $this->fotos->estadoPara($colaborador, api: false) : null,
                'estatus' => $colaborador->estatus->value,
                'deleted_at' => $colaborador->deleted_at?->toISOString(),
                'acceso_bloqueado_en' => $cuenta?->acceso_bloqueado_en?->toISOString(),
                'estatus_imss' => $colaborador->estatus_imss->value,
                'fecha_alta_imss' => $colaborador->fecha_alta_imss?->toDateString(),
                'imss_alta' => $esPropio ? null : $this->altaImss->indicador($colaborador),
                'imss_alta_archivo' => ! $esPropio && $colaborador->alta_imss_path !== null && $request->user()->can('documentos.descargar') ? [
                    'nombre' => $colaborador->alta_imss_nombre ?? 'Acuse de alta IMSS',
                    'url' => route('rh.expedientes.alta-imss.show', $colaborador->id),
                    'subido_en' => $colaborador->alta_imss_subido_en?->toIso8601String(),
                ] : null,
                'puede_subir_imss' => ! $esPropio && $request->user()->can('expedientes.editar'),
                // La persona no ve los nombres ni fechas de sus etapas
                // internas (periodo de prueba, onboarding): solo RH.
                'periodo_prueba_inicio' => $esPropio ? null : $colaborador->periodo_prueba_inicio?->toDateString(),
                'periodo_prueba_fin' => $esPropio ? null : $colaborador->periodo_prueba_fin?->toDateString(),
                'en_periodo_prueba' => ! $esPropio && $colaborador->enPeriodoDePrueba(),
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
                // Domicilio estructurado (calle, número, colonia, C.P., municipio, estado).
                ...$this->domicilios->valores($colaborador),
                'correo_personal' => $colaborador->correo_personal,
                'contacto_emergencia_nombre' => $colaborador->contacto_emergencia_nombre,
                'contacto_emergencia_telefono' => $colaborador->contacto_emergencia_telefono,
                'contacto_emergencia_parentesco' => $colaborador->contacto_emergencia_parentesco,
                'contacto_emergencia_direccion' => $colaborador->contacto_emergencia_direccion,
                // Resto de datos personales (edición completa desde el expediente).
                'genero' => $colaborador->genero?->value,
                'genero_etiqueta' => $colaborador->genero?->etiqueta(),
                'nacionalidad' => $colaborador->nacionalidad,
                'estado_civil' => $colaborador->estado_civil?->value,
                'estado_civil_etiqueta' => $colaborador->estado_civil?->etiqueta($colaborador->genero),
                'lugar_nacimiento' => $colaborador->lugar_nacimiento,
                'clave_elector' => $colaborador->clave_elector,
                'profesion' => $colaborador->profesion,
                'beneficiario_nombre' => $colaborador->beneficiario_nombre,
                'beneficiario_parentesco' => $colaborador->beneficiario_parentesco,
            ],
            // Edición completa (super admin global, RH admin por alcance):
            // estos datos exigen motivo y todo queda en el historial.
            'camposCriticos' => DatosExpedienteService::CAMPOS_CRITICOS,
            'puedeEditarDatosMedicos' => $usuario->can('expedientes.editar') && $usuario->can('expedientes.datos_medicos.ver') && ! $esCuentaPropia,
            'opcionesDatos' => [
                'genero' => array_map(fn (Genero $g): array => ['value' => $g->value, 'label' => $g->etiqueta()], Genero::cases()),
                'estado_civil' => EstadoCivil::opciones(),
            ],
            // Historial de cambios administrativos de datos (antes/después,
            // quién, cuándo, módulo y motivo). Solo para RH, no en «Mi expediente».
            'historialCambios' => $esPropio ? null : app(HistorialCambiosDatosService::class)->paraColaborador($colaborador, $usuario),
            // Expediente como centro: tarjetas de organización, contratación
            // (vista previa permisiva / generación estricta), origen en
            // reclutamiento, onboarding, evaluaciones, actas, bajas, OCR…
            // Diferido: no frena la primera pintura del expediente.
            'centro' => $esPropio ? null : Inertia::defer(fn () => app(ExpedienteCentroService::class)->resumen($colaborador, $usuario)),
            'resumenExpediente' => $resumen,
            // Datos (no documentos) que faltan para el paquete de
            // contratación de ESTE puesto: distinto de resumenExpediente
            // (documentos cargados/aprobados). Sale del mapping real de
            // cada master, nunca de una lista de "obligatorios" inventada.
            'completitudDatos' => $completitudDatos,
            // Historial de solo lectura de lo emitido con el motor anterior de
            // formatos oficiales: ya no se genera nada ahí (todo sale de
            // Documentos maestros), pero el expediente conserva lo que tenía.
            'documentosOficiales' => ! $esPropio && $usuario->can('formatos_oficiales.ver') ? $this->documentosOficiales($colaborador) : null,
            'documentosRequeridos' => $this->documentosParaVista($documentos, $usuario),
            'onboarding' => $esPropio ? [] : $this->onboarding->checklist($colaborador),
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
            // PDF(s) históricos únicos de la migración inicial: sección
            // aparte, NO cuentan en el checklist de 14 documentos.
            'expedienteHistorico' => app(ExpedientesInitialMigrationService::class)->historicosDe($colaborador),
            // «Ligar expediente de Synology» (sin escanear el NAS aquí: la
            // lista se pide al abrir el diálogo). Misma regla que su endpoint.
            'puedeLigarSynology' => ! $esPropio && ! $esCuentaPropia && $usuario->can('expedientes.editar')
                && ! app(VinculacionSynologyService::class)->tieneExpedienteLigado($colaborador),
            // Nuevo ingreso que ya tiene su carpeta del sistema: su expediente
            // NUEVO ya está en formación, no le falta ligar uno histórico. La
            // tarjeta se muestra sin alarma (ligar un histórico es opcional).
            'expedienteEnFormacion' => app(VinculacionSynologyService::class)->tieneExpedienteNuevo($colaborador),
            // Datos médicos: sensibles, solo con permiso explícito.
            'datosMedicos' => $usuario->can('expedientes.datos_medicos.ver') ? [
                'condicion_medica' => $colaborador->datosMedicos?->condicion_medica,
                'alergias' => $colaborador->datosMedicos?->alergias,
            ] : null,
            'contactoEmergenciaExtra' => [
                'parentesco' => $colaborador->contacto_emergencia_parentesco,
                'direccion' => $colaborador->contacto_emergencia_direccion,
            ],
            // Tab "Recibos de nómina": historial de recibos informativos ya
            // generados (ver App\Services\Nomina\ReciboNominaService) — el
            // PDF se descarga aparte (recibos-nomina.descargar), aquí solo
            // se manda si el archivo quedó guardado o no.
            // El colaborador NUNCA ve sus propios recibos (se entregan
            // impresos; features.recibos_colaborador=false): null oculta la
            // pestaña. RH y quien tenga nómina dentro de su alcance sí.
            'recibosNomina' => ! $this->puedeVerRecibosEnExpediente($usuario, $colaborador, $esPropio) ? null : $colaborador->recibosNomina()
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
        abort_unless($this->puedeDarDeBaja($request->user(), $colaborador), 403, 'No tienes permiso para dar de baja a este colaborador.');

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

    /**
     * La baja administrativa inmediata termina la relación laboral: exige el
     * permiso de baja del cierre laboral (`cierres.ejecutar_baja`, el mismo
     * de CierreLaboralPolicy::ejecutarBaja) y alcance sobre el expediente.
     * `usuarios.desactivar` solo administra la cuenta de acceso. Nadie se da
     * de baja a sí mismo.
     */
    private function puedeDarDeBaja(User $usuario, Colaborador $colaborador): bool
    {
        return $usuario->can('cierres.ejecutar_baja')
            && $usuario->colaborador_id !== $colaborador->id
            && $this->alcance->puedeVerExpediente($usuario, $colaborador);
    }

    public function actualizarDatosPersonales(ActualizarDatosPersonalesRequest $request, Colaborador $colaborador): RedirectResponse
    {
        $cambios = $this->datosExpediente->actualizarPersonales($colaborador, $request->datos(), $request->user(), $request->motivo(), 'expediente');

        return back()->with('toast', $cambios === []
            ? ['type' => 'info', 'message' => 'No hubo cambios: los datos ya estaban así.']
            : ['type' => 'success', 'message' => sprintf('Datos actualizados (%d cambio%s). Quedó registrado en el historial del expediente.', count($cambios), count($cambios) === 1 ? '' : 's')]);
    }

    /**
     * Cambia empresa (vía sucursal)/sucursal/departamento/puesto/sueldo
     * directamente, sin pasar por una vacante. La regla completa (jefe
     * derivado del organigrama, movimiento laboral, sueldo canónico por
     * CompensacionService) vive en DatosExpedienteService — la misma que usa
     * la edición rápida del Directorio.
     */
    public function actualizarDatosLaborales(ActualizarDatosLaboralesRequest $request, Colaborador $colaborador): RedirectResponse
    {
        // Este formulario siempre incluye el sueldo (aunque venga vacío): así
        // se conserva la regla de «completar con el salario mínimo» si nunca
        // tuvo uno capturado.
        $resultado = $this->datosExpediente->actualizarLaborales(
            $colaborador,
            [...$request->validated(), 'sueldo_mensual' => $request->validated('sueldo_mensual')],
            $request->user(),
        );

        if ($resultado['sin_salario_minimo']) {
            return back()->with('toast', [
                'type' => 'warning',
                'message' => 'Datos laborales actualizados, pero no se asignó un sueldo por defecto: falta configurar el salario mínimo vigente en el servidor (SALARIO_MINIMO_DIARIO). Captura el sueldo manualmente o pide a un administrador que configure esa variable.',
            ]);
        }

        // Nunca «éxito» si no se guardó nada.
        if ($resultado['cambios'] === []) {
            return back()->with('toast', ['type' => 'info', 'message' => 'No hubo cambios: los datos laborales ya estaban así.']);
        }

        // El sueldo que quedó no es el capturado (no cuadra con diario × 30):
        // se dice el monto real que se guardó.
        $capturado = $resultado['sueldo_capturado'];
        $guardado = $resultado['sueldo_guardado'];

        if ($capturado !== null && $guardado !== null && abs($capturado - $guardado) > 0.004) {
            return back()->with('toast', [
                'type' => 'warning',
                'message' => sprintf('Datos laborales actualizados. El sueldo quedó en $%s (salario diario $%s × 30), no en los $%s capturados.', number_format($guardado, 2), number_format((float) $resultado['salario_diario'], 2), number_format($capturado, 2)),
            ]);
        }

        return back()->with('toast', ['type' => 'success', 'message' => sprintf('Datos laborales actualizados (%d cambio%s). Quedó registrado en el historial del expediente.', count($resultado['cambios']), count($resultado['cambios']) === 1 ? '' : 's')]);
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

        return back()->with('toast', ['type' => 'success', 'message' => sprintf('Recibo de nómina %s generado correctamente.', $recibo->folio)]);
    }

    /**
     * Vista previa del recibo con lo capturado en el formulario, ANTES de
     * «Generar»: mismo formato vigente, sin crear el recibo ni archivar nada
     * (ReciboNominaService::vistaPreviaPdf()).
     */
    public function vistaPreviaReciboNomina(VistaPreviaReciboNominaRequest $request, Colaborador $colaborador): HttpResponse
    {
        $pdf = $this->reciboNomina->vistaPreviaPdf($colaborador, [
            'periodo_inicio' => (string) $request->validated('periodo_inicio'),
            'periodo_fin' => (string) $request->validated('periodo_fin'),
            'fecha_pago' => (string) $request->validated('fecha_pago'),
            'sueldo_base' => (float) $request->validated('sueldo_base'),
            'percepciones' => $request->validated('percepciones') ?? [],
            'deducciones' => $request->validated('deducciones') ?? [],
        ]);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="recibo-nomina-vista-previa.pdf"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
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
        // ReciboNominaPolicy: RH con nómina dentro de su alcance; el dueño
        // solo si el autoservicio de recibos está encendido (no lo está).
        $this->authorize('descargar', $recibo);

        // Archivo faltante = pantalla de reparación (ArchivoGeneradoNoDisponibleException), no 404.
        return $this->reciboNomina->respuestaPdf($recibo, $request->user());
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
        // Solo RH genera recibos, nunca el suyo propio.
        abort_unless($request->user()->can('nomina.recibos.crear') && $request->user()->colaborador_id !== $recibo->colaborador_id, 403);

        try {
            $this->reciboNomina->regenerarPdf($recibo, $request->user());
        } catch (ValidationException $e) {
            return back()->with('toast', ['type' => 'error', 'message' => (string) collect($e->errors())->flatten()->first()]);
        }

        return back()->with('toast', ['type' => 'success', 'message' => 'PDF del recibo listo.']);
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
     * «Descargar expediente completo»: ZIP con documentos vigentes, historial
     * de versiones, documentos generados, foto e INDICE.csv (ver
     * ExpedienteZipService). Se borra del servidor al terminar de enviarse.
     */
    public function descargarZip(Request $request, Colaborador $colaborador, ExpedienteZipService $zip): BinaryFileResponse
    {
        $this->authorize('descargarExpediente', [EmployeeDocument::class, $colaborador]);

        return response()
            ->download($zip->generar($colaborador, $request->user()), $zip->nombreDescarga($colaborador), ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend();
    }

    /**
     * RH sube o cambia la foto de perfil de un colaborador — normalizada a
     * miniatura cuadrada por el servicio. El propio colaborador nunca usa
     * esta ruta: usa subirFotoPropia(), que pasa por la aprobación de RH.
     */
    public function subirFoto(SubirFotoRequest $request, Colaborador $colaborador): RedirectResponse
    {
        $usuario = $request->user();

        abort_unless($this->alcance->puedeVerExpediente($usuario, $colaborador), 403);
        abort_unless($usuario->can('expedientes.editar') && $usuario->colaborador_id !== $colaborador->id, 403);

        $this->fotos->actualizar($colaborador, $request->file('foto'), $usuario);

        return back()->with('toast', ['type' => 'success', 'message' => 'Foto de perfil actualizada.']);
    }

    /**
     * Acuse de alta IMSS: lo sube RH al concluir el alta (indicador verde;
     * mientras falte, naranja). No es un pendiente de la bandeja.
     */
    public function subirAltaImss(Request $request, Colaborador $colaborador): RedirectResponse
    {
        $usuario = $request->user();

        abort_unless($usuario->can('expedientes.editar') && $this->alcance->puedeVerExpediente($usuario, $colaborador), 403);
        $request->validate(['archivo' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240']], [], ['archivo' => 'acuse de alta IMSS']);

        $archivo = $request->file('archivo');
        abort_unless($archivo instanceof UploadedFile, 422);

        $this->altaImss->registrar($colaborador, $archivo, $usuario);

        return back()->with('toast', ['type' => 'success', 'message' => 'Acuse de alta IMSS registrado.']);
    }

    public function descargarAltaImss(Request $request, Colaborador $colaborador): StreamedResponse
    {
        abort_unless($this->alcance->puedeVerExpediente($request->user(), $colaborador), 403);
        // Es un documento del expediente: descargarlo exige el mismo permiso
        // que cualquier otro (el gerente ve el indicador, no el archivo).
        abort_unless($request->user()->can('documentos.descargar'), 403, 'No tienes permiso para descargar documentos del expediente.');

        return $this->altaImss->respuesta($colaborador);
    }

    /**
     * El colaborador sube su propia foto al registrar sus documentos.
     */
    public function subirFotoPropia(SubirFotoRequest $request): RedirectResponse
    {
        $usuario = $request->user();
        $colaborador = $usuario->colaborador;

        abort_if($colaborador === null, 403, 'Tu cuenta no tiene un colaborador enlazado.');

        $resultado = $this->fotos->subirPropia($colaborador, $request->file('foto'), $usuario);

        return back()->with('toast', ['type' => 'success', 'message' => $resultado['resultado'] === 'oficial'
            ? 'Tu foto de perfil quedó guardada.'
            : 'Enviamos tu nueva foto a RH. Tu foto actual se conserva hasta que la aprueben.']);
    }

    /**
     * URL protegida de la foto de perfil, o null si el colaborador no tiene
     * una. Nunca se expone `foto_path` (ruta física en el disco NAS) al
     * frontend — ver docs/SEGURIDAD.md.
     */
    /**
     * @return list<array<string, mixed>>
     */
    private function documentosOficiales(Colaborador $colaborador): array
    {
        return array_values(OfficialFormatGeneration::query()
            ->with(['formato:id,nombre,tipo', 'generadoPor:id,name', 'solicitud:id,folio'])
            ->where('colaborador_id', $colaborador->id)
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (OfficialFormatGeneration $g) => [
                'id' => $g->id,
                'formato' => $g->formato->nombre,
                'categoria' => $g->formato->tipo->etiqueta(),
                'version' => $g->version_numero,
                'solicitud_folio' => $g->solicitud?->folio,
                'generado_por' => $g->generadoPor?->name,
                'generado_en' => $g->created_at?->toIso8601String(),
                'estado' => $g->status->value,
                'ver_url' => route('rh.formatos-oficiales.previsualizar', $g->id),
                'descargar_url' => route('rh.formatos-oficiales.descargar', $g->id),
            ])
            ->all());
    }

    private function fotoUrl(Colaborador $colaborador): ?string
    {
        return $this->fotos->url($colaborador);
    }

    /**
     * @param  Collection<int, EmployeeDocument>  $vigentes
     * @return array<int, array<string, mixed>>
     */
    private function documentosParaVista(Collection $vigentes, User $usuario): array
    {
        // El expediente pide los documentos REQUERIDOS y muestra los
        // OPCIONALES del checklist (pagaré, antecedentes, licencia) con la
        // etiqueta «Opcional»: estos nunca cuentan para el avance ni como
        // faltantes. Lo demás (incapacidades, permisos, vacaciones...) entra
        // por Solicitudes; un tipo fuera del checklist solo aparece si ya
        // tiene un archivo, para no esconder historial.
        return DocumentType::query()
            ->where('activo', true)
            ->where(fn ($q) => $q->where('requerido', true)->orWhere('checklist_opcional', true)->orWhereIn('id', $vigentes->keys()))
            ->orderByDesc('requerido')
            ->orderBy('orden')
            ->orderBy('nombre')
            ->get()
            ->map(function (DocumentType $tipo) use ($vigentes, $usuario) {
                $documento = $vigentes->get($tipo->id);
                // Botones de revisión por documento: permiso (Policy) + estado
                // (EstadoDocumento::admiteDecisionDeRh, la misma regla que
                // valida IncorporacionService). Opcionales incluidos: se
                // aprueban igual, solo no cuentan para el avance.
                $decidible = $documento !== null && $documento->status->admiteDecisionDeRh();

                return [
                    'tipo' => ['id' => $tipo->id, 'nombre' => $tipo->nombre, 'clave' => $tipo->clave, 'requerido' => $tipo->requerido, 'opcional' => ! $tipo->requerido && $tipo->checklist_opcional, 'par' => $this->pares->resumenPar($tipo, $vigentes)],
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
                        'acciones' => [
                            'aprobar' => $decidible && $usuario->can('aprobar', $documento),
                            'rechazar' => $decidible && $usuario->can('rechazar', $documento),
                            'corregir' => $decidible && $usuario->can('pedirCorreccion', $documento),
                            'autorizar_cambio' => $documento->status === EstadoDocumento::CambioSolicitado && $usuario->can('autorizarCambio', $documento),
                            // Ver/descargar el archivo (documentos.descargar): sin
                            // él solo se ve el estado del documento.
                            'descargar' => $usuario->can('descargar', $documento),
                        ],
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

    /**
     * Pestaña «Recibos de nómina»: en su propio expediente solo con el
     * autoservicio encendido; en el de otra persona, quien tiene nómina
     * dentro de su alcance (misma regla que ReciboNominaPolicy::ver).
     */
    private function puedeVerRecibosEnExpediente(User $usuario, Colaborador $colaborador, bool $esPropio): bool
    {
        if ($esPropio || $usuario->colaborador_id === $colaborador->id) {
            return (bool) config('features.recibos_colaborador');
        }

        return $usuario->can('nomina.recibos.ver') && $this->alcance->alcanzaColaboradorEnModulo($usuario, $colaborador, 'recibos');
    }
}
