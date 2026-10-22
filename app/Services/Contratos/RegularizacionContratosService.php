<?php

namespace App\Services\Contratos;

use App\Enums\EstadoContratoCorrespondiente as Estado;
use App\Enums\EstadoContratoLaboral;
use App\Enums\EstadoDocumento;
use App\Enums\EstadoEvaluacionPrueba;
use App\Enums\EstadoFlujoDocumento;
use App\Enums\EstadoUsuario;
use App\Enums\ResultadoGeneracionContratos as Resultado;
use App\Enums\TipoContratacion;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\DocumentTemplate;
use App\Models\EmployeeDocument;
use App\Models\EvaluacionPeriodoPrueba;
use App\Models\GeneratedDocument;
use App\Models\User;
use App\Policies\GeneratedDocumentPolicy;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Auditoria\AuditoriaService;
use App\Services\DocumentosLaborales\MotorDocumentalService;
use App\Services\DocumentosMaestros\CatalogoMaestrosService;
use App\Services\DocumentosMaestros\DatosDocumentoService;
use App\Services\DocumentosMaestros\DocumentoProcesoService;
use App\Services\DocumentosMaestros\ResolvedorMaestroService;
use App\Support\FechaMexico;
use App\Support\Paginacion;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Módulo «Contratos» (Etapa 2 · Contratación y expediente): qué contratos le
 * corresponden a cada colaborador ACTIVO según su fecha de ingreso y su
 * puesto, cuáles ya están en su expediente, cuáles faltan por generar y qué
 * datos impiden generarlos — y la regularización idempotente (individual o
 * en lote, ver LoteContratosService).
 *
 * No inventa reglas ni duplica el motor:
 *  - duración del periodo de prueba: puestos.meses_periodo_prueba vía
 *    ContratoLaboralService::fechaFinPeriodoPrueba() (sin respaldo global);
 *  - paquete de ingreso / renovación: config documentos_maestros.paquetes
 *    vía DocumentoProcesoService::clavesPaquete();
 *  - datos faltantes: el mapping real del master (MotorDocumentalService::
 *    faltantesDe + DatosDocumentoService::resolver), igual que la ficha;
 *  - registro y documentos: ContratoLaboralService (crearContrato, renovar,
 *    generarDocumento) — mismo camino que el alta.
 *
 * @phpstan-type Documento array{clave: string, nombre: string, estado: string, estado_etiqueta: string, documento_id: int|null, descargar_url: string|null, faltantes: list<array<string, mixed>>, motivo: string|null, firmado_cargado: array{etiqueta: string, url: string, nombre: string, mime: string|null}|null, tipo_firmado: string|null}
 * @phpstan-type Etapa array{etapa: string, etiqueta: string, tipo: string, tipo_etiqueta: string, contrato_id: int|null, estado_contrato: string|null, fecha_inicio: string, fecha_fin: string|null, documentos: list<Documento>, pendiente_evaluacion: bool}
 * @phpstan-type Plan array{id: int, nombre: string, numero_empleado: string|null, puesto: string|null, sucursal: string|null, fecha_ingreso: string|null, meses_periodo_prueba: int|null, fin_periodo_prueba: string|null, periodo_vencido: bool, expediente_url: string, ciclo_url: string, estado: string, estado_etiqueta: string, motivo: string|null, contratos: list<Etapa>, faltantes: list<array<string, mixed>>, bloqueos: list<array{etiqueta: string, detalle: string, configurar_url: string|null}>, generable: bool}
 */
class RegularizacionContratosService
{
    /** @var array<string, DocumentTemplate|string> Master (o motivo de por qué no hay) por clave+puesto+empresa. */
    private array $masters = [];

    public function __construct(
        private readonly ContratoLaboralService $contratos,
        private readonly DocumentoProcesoService $documentos,
        private readonly MotorDocumentalService $motor,
        private readonly DatosDocumentoService $datos,
        private readonly ResolvedorMaestroService $resolvedor,
        private readonly CatalogoMaestrosService $catalogo,
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly AuditoriaService $auditoria,
        private readonly ContratoFirmadoService $firmados,
    ) {}

    // ───────────────────────── Permisos ─────────────────────────

    public function puedeVer(User $usuario): bool
    {
        return $usuario->can('contratos.ver');
    }

    public function puedeGenerar(User $usuario): bool
    {
        // General de RH (todos los documentos laborales) o la acción
        // específica del módulo «Contratos».
        return $usuario->can(ContratoLaboralService::PERMISO_GENERAR) || $usuario->can('contratos.generar');
    }

    /**
     * Ajusta un plan a lo que quien mira puede hacer: sin contratos.
     * ver_documento no recibe la URL del contrato generado; sin
     * documentos.descargar, la del firmado cargado (solo ve que existe); y
     * marca si puede subir el firmado (ContratoFirmadoService). El backend
     * vuelve a validar cada acción.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    public function paraVisor(array $plan, User $viewer, Colaborador $colaborador): array
    {
        $verContrato = GeneratedDocumentPolicy::puedeVerContratos($viewer);
        $verFirmado = $viewer->can('documentos.descargar');
        $subir = $this->firmados->puedeSubir($viewer, $colaborador);

        foreach ((array) ($plan['contratos'] ?? []) as $i => $etapa) {
            foreach ((array) ($etapa['documentos'] ?? []) as $j => $documento) {
                if (! $verContrato) {
                    $plan['contratos'][$i]['documentos'][$j]['descargar_url'] = null;
                }

                if (! $verFirmado && is_array($documento['firmado_cargado'] ?? null)) {
                    $plan['contratos'][$i]['documentos'][$j]['firmado_cargado']['url'] = null;
                }

                $plan['contratos'][$i]['documentos'][$j]['puede_subir_firmado'] = $subir && ($documento['tipo_firmado'] ?? null) !== null;
            }
        }

        $plan['puede_subir_firmado'] = $subir;

        return $plan;
    }

    public function exigirAlcance(User $usuario, Colaborador $colaborador): void
    {
        if (! $this->alcance->alcanzaColaboradorEnModulo($usuario, $colaborador, 'contratos')) {
            throw new AuthorizationException('Este colaborador está fuera de tu alcance.');
        }
    }

    // ───────────────────────── Consulta ─────────────────────────

    /**
     * Colaboradores activos dentro del alcance del módulo «contratos».
     *
     * @param  array<string, mixed>  $filtros  busqueda, sucursal_id, puesto_id
     * @return Builder<Colaborador>
     */
    public function queryActivos(User $usuario, array $filtros = []): Builder
    {
        $busqueda = isset($filtros['busqueda']) ? trim((string) $filtros['busqueda']) : '';
        $sucursalId = isset($filtros['sucursal_id']) ? (int) $filtros['sucursal_id'] : 0;
        $puestoId = isset($filtros['puesto_id']) ? (int) $filtros['puesto_id'] : 0;

        $query = Colaborador::query()
            ->where('estatus', EstadoUsuario::Activo->value)
            ->with(['puesto', 'sucursalPrincipal']);

        $this->alcance->limitarPorModulo($query, $usuario, 'contratos', 'id');

        return $query
            ->when($busqueda !== '', fn (Builder $q) => $q->buscar($busqueda))
            ->when($sucursalId > 0, fn (Builder $q) => $q->where('sucursal_principal_id', $sucursalId))
            ->when($puestoId > 0, fn (Builder $q) => $q->where('puesto_id', $puestoId))
            ->orderBy('name')
            ->orderBy('apellidos');
    }

    /**
     * Pantalla «Contratos»: una fila por colaborador activo con sus
     * contratos correspondientes, más el resumen por estado y los datos
     * faltantes más comunes (sobre todo lo filtrado, sin el filtro de estado).
     *
     * @param  array<string, mixed>  $filtros  busqueda, sucursal_id, puesto_id, estado
     * @return array{filas: array<string, mixed>, resumen: array<string, int>, faltantes_comunes: list<array{etiqueta: string, total: int}>}
     */
    public function listar(User $usuario, array $filtros = [], ?CarbonInterface $hoy = null): array
    {
        $planes = [];
        $colaboradores = [];

        foreach ($this->queryActivos($usuario, $filtros)->get() as $colaborador) {
            $planes[] = $this->plan($colaborador, $hoy);
            $colaboradores[$colaborador->id] = $colaborador;
        }

        $resumen = ['total' => count($planes)];

        foreach (Estado::deFila() as $estado) {
            $resumen[$estado->value] = count(array_filter($planes, fn (array $p): bool => $p['estado'] === $estado->value));
        }

        $estado = Estado::tryFrom((string) ($filtros['estado'] ?? ''));
        $filtradas = $estado !== null ? array_values(array_filter($planes, fn (array $p): bool => $p['estado'] === $estado->value)) : $planes;

        // Solo la página visible se ajusta a lo que quien mira puede hacer
        // (URLs del contrato, «Subir firmado»).
        $filas = $this->paginar($filtradas)->toArray();
        $filas['data'] = array_map(fn (array $plan): array => $this->paraVisor($plan, $usuario, $colaboradores[$plan['id']]), $filas['data']);

        return [
            'filas' => $filas,
            'resumen' => $resumen,
            'faltantes_comunes' => $this->faltantesComunes($planes),
        ];
    }

    /**
     * Paginación en memoria (el estado de cada fila se calcula, no es columna).
     *
     * @template TFila
     *
     * @param  list<TFila>  $planes
     * @return LengthAwarePaginator<int, TFila>
     */
    private function paginar(array $planes): LengthAwarePaginator
    {
        $porPagina = Paginacion::porPagina();
        $pagina = max(1, LengthAwarePaginator::resolveCurrentPage());

        $paginador = new LengthAwarePaginator(
            array_slice($planes, ($pagina - 1) * $porPagina, $porPagina),
            count($planes),
            $porPagina,
            $pagina,
            ['path' => LengthAwarePaginator::resolveCurrentPath()],
        );

        return $paginador->withQueryString();
    }

    /**
     * Sucursales y puestos de los colaboradores activos del alcance (para
     * los filtros: nunca se ofrece una opción fuera del alcance).
     *
     * @return array{sucursales: list<array{id: int, nombre: string}>, puestos: list<array{id: int, nombre: string}>}
     */
    public function opcionesFiltro(User $usuario): array
    {
        $sucursales = [];
        $puestos = [];

        foreach ($this->queryActivos($usuario)->get(['id', 'puesto_id', 'sucursal_principal_id']) as $colaborador) {
            if ($colaborador->sucursalPrincipal !== null) {
                $sucursales[$colaborador->sucursalPrincipal->id] = ['id' => $colaborador->sucursalPrincipal->id, 'nombre' => sprintf('%s', $colaborador->sucursalPrincipal->nombre)];
            }

            if ($colaborador->puesto !== null) {
                $puestos[$colaborador->puesto->id] = ['id' => $colaborador->puesto->id, 'nombre' => sprintf('%s', $colaborador->puesto->nombre)];
            }
        }

        $ordenar = function (array $lista): array {
            usort($lista, fn (array $a, array $b): int => strcmp($a['nombre'], $b['nombre']));

            return $lista;
        };

        return ['sucursales' => $ordenar(array_values($sucursales)), 'puestos' => $ordenar(array_values($puestos))];
    }

    /**
     * @param  list<Plan>  $planes
     * @return list<array{etiqueta: string, total: int}>
     */
    private function faltantesComunes(array $planes): array
    {
        $conteo = [];

        foreach ($planes as $plan) {
            $etiquetas = [];

            foreach ((array) $plan['faltantes'] as $faltante) {
                $etiquetas[] = (string) $faltante['etiqueta'];
            }

            foreach ((array) $plan['bloqueos'] as $bloqueo) {
                $etiquetas[] = (string) $bloqueo['etiqueta'];
            }

            foreach (array_unique($etiquetas) as $etiqueta) {
                $conteo[$etiqueta] = ($conteo[$etiqueta] ?? 0) + 1;
            }
        }

        arsort($conteo);
        $resultado = [];

        foreach (array_slice($conteo, 0, 10, true) as $etiqueta => $total) {
            $resultado[] = ['etiqueta' => sprintf('%s', $etiqueta), 'total' => $total];
        }

        return $resultado;
    }

    /**
     * Qué contratos le corresponden al colaborador y en qué estado está cada
     * uno. Regla (fuente en código):
     *  - Contrato inicial: capacitación inicial (= periodo de prueba,
     *    TipoContratacion::normalizada) con inicio = fecha de ingreso y fin =
     *    ingreso + puestos.meses_periodo_prueba − 1 día
     *    (ContratoLaboralService::fechaFinPeriodoPrueba). Paquete:
     *    documentos_maestros.paquetes.alta.capacitacion_inicial.<grupo>.
     *  - Si hoy ya pasó ese fin: además el INDETERMINADO, con inicio = fin + 1
     *    (mismo cálculo que ContratoLaboralService::renovar) y paquete
     *    documentos_maestros.paquetes.renovacion.
     *  - Si ya existe un ContratoLaboral, se respetan sus fechas y su tipo.
     *
     * @return Plan
     */
    public function plan(Colaborador $colaborador, ?CarbonInterface $hoy = null): array
    {
        $hoy = CarbonImmutable::parse(($hoy ?? FechaMexico::inicioDeHoy())->toDateString());
        $colaborador->loadMissing(['puesto', 'sucursalPrincipal']);
        $puesto = $colaborador->puesto;
        $bloqueos = [];

        $base = [
            'id' => $colaborador->id,
            'nombre' => $colaborador->nombreCompleto(),
            'numero_empleado' => $colaborador->numero_empleado,
            'puesto' => $puesto?->nombre,
            'sucursal' => $colaborador->sucursalPrincipal?->nombre,
            'fecha_ingreso' => $colaborador->fecha_ingreso?->toDateString(),
            'meses_periodo_prueba' => $puesto?->meses_periodo_prueba,
            'fin_periodo_prueba' => null,
            'periodo_vencido' => false,
            'expediente_url' => sprintf('/rh/expedientes/%d', $colaborador->id),
            'ciclo_url' => sprintf('/rh/colaboradores/%d/ciclo', $colaborador->id),
        ];

        if ($puesto?->no_requiere_documentos_laborales) {
            return [...$base, 'estado' => Estado::NoAplica->value, 'estado_etiqueta' => Estado::NoAplica->etiqueta(),
                'motivo' => sprintf('El puesto %s no requiere documentos laborales: %s', $puesto->nombre, (string) $puesto->motivo_sin_documentos),
                'contratos' => [], 'faltantes' => [], 'bloqueos' => [], 'generable' => false];
        }

        $existentes = ContratoLaboral::query()->where('colaborador_id', $colaborador->id)->orderByDesc('fecha_inicio')->orderByDesc('id')->get();
        $inicial = $existentes->first(fn (ContratoLaboral $c): bool => $c->contrato_anterior_id === null && $c->estado === EstadoContratoLaboral::Vigente)
            ?? $existentes->first(fn (ContratoLaboral $c): bool => $c->contrato_anterior_id === null);
        $indeterminado = $existentes->first(fn (ContratoLaboral $c): bool => $c->contrato_anterior_id !== null && $c->tipo === TipoContratacion::Indeterminado);

        if ($inicial === null && $colaborador->fecha_ingreso === null) {
            $bloqueos[] = ['etiqueta' => 'Fecha de ingreso', 'detalle' => 'Captura la fecha de ingreso en el expediente del colaborador.', 'configurar_url' => null];
        }

        if ($inicial === null && (int) ($puesto->meses_periodo_prueba ?? 0) < 1) {
            $bloqueos[] = [
                'etiqueta' => 'Duración del periodo de prueba del puesto',
                'detalle' => sprintf('El puesto «%s» no tiene duración de periodo de prueba: captúrala en Organización → Puestos y tabulador.', $puesto->nombre ?? 'sin puesto'),
                'configurar_url' => $puesto !== null ? route('administracion.puestos.index', ['busqueda' => $puesto->nombre], false) : null,
            ];
        }

        if ($bloqueos !== []) {
            return [...$base, 'estado' => Estado::FaltanDatos->value, 'estado_etiqueta' => Estado::FaltanDatos->etiqueta(),
                'motivo' => implode(' ', array_column($bloqueos, 'detalle')),
                'contratos' => [], 'faltantes' => [], 'bloqueos' => $bloqueos, 'generable' => false];
        }

        if ($inicial === null) {
            $ingreso = CarbonImmutable::parse((string) $colaborador->fecha_ingreso?->toDateString());
            $inicial = $this->enMemoria($colaborador, TipoContratacion::CapacitacionInicial, $ingreso, $this->contratos->fechaFinPeriodoPrueba($colaborador->puesto_id, $ingreso), null);
        }

        $fin = $inicial->fecha_fin !== null ? CarbonImmutable::parse($inicial->fecha_fin->toDateString()) : null;
        $vencido = $inicial->tipo->tieneVencimiento() && $fin !== null && $hoy->gt($fin);
        $inicioIndeterminado = $fin !== null ? $fin->addDay() : $hoy;
        $base['fin_periodo_prueba'] = $fin?->toDateString();
        $base['periodo_vencido'] = $vencido;

        $generados = $this->generadosDe($colaborador);
        $cargados = $this->cargadosDe($colaborador);
        $etapas = [$this->etapa('inicial', $inicial, $colaborador, $generados, $cargados)];

        if ($inicial->tipo->tieneVencimiento() && ($vencido || $indeterminado !== null)) {
            // Criterio (decisión del negocio, 2026-10-20): si al regularizar el
            // periodo de prueba YA venció por fecha, el indeterminado se
            // registra directamente (renovar) aunque el contrato inicial ya
            // exista: es personal con antigüedad (plantilla migrada) y no se le
            // exige evaluación. La ÚNICA excepción es una evaluación de periodo
            // de prueba EN CURSO (pendiente / capturada / devuelta) de ese
            // contrato: entonces el flujo normal de un ingreso nuevo decide
            // (EvaluacionPeriodoPruebaService → renovar) y aquí no se brinca.
            $porEvaluacion = $indeterminado === null && $this->evaluacionEnCurso($inicial);
            $indeterminado ??= $this->enMemoria($colaborador, TipoContratacion::Indeterminado, $inicioIndeterminado, null, $inicial->exists ? $inicial : null);
            $etapa = $this->etapa('indeterminado', $indeterminado, $colaborador, $generados, $cargados);

            if ($porEvaluacion) {
                $motivo = sprintf('Su evaluación de periodo de prueba está en curso (vence %s): el indeterminado se genera al aprobarla en «Cierre del periodo de prueba».', $fin?->format('d/m/Y') ?? '—');
                $etapa['documentos'] = array_map(fn (array $d): array => $d['estado'] === Estado::Generado->value ? $d : [
                    ...$d, 'estado' => Estado::PendienteEvaluacion->value, 'estado_etiqueta' => Estado::PendienteEvaluacion->etiqueta(), 'faltantes' => [], 'motivo' => $motivo,
                ], $etapa['documentos']);
                $etapa['pendiente_evaluacion'] = true;
            }

            $etapas[] = $etapa;
        }

        $estados = [];
        $faltantes = [];

        foreach ($etapas as $etapa) {
            foreach ($etapa['documentos'] as $documento) {
                $estados[] = $documento['estado'];

                foreach ($documento['faltantes'] as $faltante) {
                    $faltantes[$faltante['base']] ??= [...$faltante, 'documentos' => []];
                    $faltantes[$faltante['base']]['documentos'][] = $documento['nombre'];
                }
            }
        }

        $sinRegistro = collect($etapas)->contains(fn (array $e): bool => $e['contrato_id'] === null && ! $e['pendiente_evaluacion']);
        $estado = match (true) {
            in_array(Estado::FaltanDatos->value, $estados, true) => Estado::FaltanDatos,
            in_array(Estado::PorGenerar->value, $estados, true) || $sinRegistro => Estado::PorGenerar,
            in_array(Estado::FormatoNoListo->value, $estados, true) => Estado::FormatoNoListo,
            in_array(Estado::PendienteEvaluacion->value, $estados, true) => Estado::PendienteEvaluacion,
            default => Estado::Completo,
        };

        return [
            ...$base,
            'estado' => $estado->value,
            'estado_etiqueta' => $estado->etiqueta(),
            'motivo' => match ($estado) {
                Estado::FaltanDatos => sprintf('Faltan datos: %s.', implode(', ', array_column($faltantes, 'etiqueta'))),
                Estado::FormatoNoListo => 'Algún formato oficial no está listo (ver cada documento).',
                Estado::PendienteEvaluacion => 'El contrato indeterminado se genera al aprobar su evaluación de periodo de prueba.',
                Estado::PorGenerar => $sinRegistro && ! in_array(Estado::PorGenerar->value, $estados, true) ? 'Falta registrar el contrato en el ciclo laboral.' : null,
                default => null,
            },
            'contratos' => $etapas,
            'faltantes' => array_values($faltantes),
            'bloqueos' => [],
            'generable' => $estado === Estado::PorGenerar,
        ];
    }

    /**
     * true si el contrato inicial (registrado) tiene una evaluación de
     * periodo de prueba abierta: pendiente, capturada o devuelta.
     */
    private function evaluacionEnCurso(ContratoLaboral $inicial): bool
    {
        return $inicial->exists && EvaluacionPeriodoPrueba::query()
            ->where('contrato_laboral_id', $inicial->id)
            ->whereIn('estado', [EstadoEvaluacionPrueba::Pendiente->value, EstadoEvaluacionPrueba::Capturada->value, EstadoEvaluacionPrueba::Devuelta->value])
            ->exists();
    }

    /**
     * Contrato EN MEMORIA (no se guarda) con los mismos datos que
     * ContratoLaboralService::crearContrato() — para calcular faltantes y la
     * vista previa antes de registrar nada.
     */
    private function enMemoria(Colaborador $colaborador, TipoContratacion $tipo, CarbonInterface $inicio, ?CarbonInterface $fin, ?ContratoLaboral $anterior): ContratoLaboral
    {
        $contrato = ContratoLaboral::query()->make([
            'colaborador_id' => $colaborador->id,
            'tipo' => $tipo->normalizada(),
            'fecha_inicio' => $inicio->toDateString(),
            'fecha_fin' => $tipo->tieneVencimiento() ? $fin?->toDateString() : null,
            'estado' => EstadoContratoLaboral::Vigente,
            'sueldo_mensual' => $colaborador->sueldo_mensual,
            'puesto_id' => $colaborador->puesto_id,
            'sucursal_id' => $colaborador->sucursal_principal_id,
            'contrato_anterior_id' => $anterior?->id,
        ]);
        $contrato->setRelation('colaborador', $colaborador);

        return $contrato;
    }

    /**
     * @param  Collection<string, GeneratedDocument>  $generados
     * @param  Collection<string, EmployeeDocument>  $cargados
     * @return Etapa
     */
    private function etapa(string $etapa, ContratoLaboral $contrato, Colaborador $colaborador, Collection $generados, Collection $cargados): array
    {
        $contrato->setRelation('colaborador', $colaborador);
        $claves = $etapa === 'inicial'
            ? $this->documentos->clavesPaquete('alta', $contrato->tipo->value, $colaborador)
            : $this->documentos->clavesPaquete('renovacion', null, $colaborador);
        $valores = null;
        $documentos = [];

        foreach ($claves as $clave) {
            $documentos[] = $this->documento($clave, $contrato, $colaborador, $generados, $cargados, $valores);
        }

        return [
            'etapa' => $etapa,
            'etiqueta' => $etapa === 'inicial' ? 'Contrato inicial' : 'Contrato indeterminado',
            'tipo' => $contrato->tipo->value,
            'tipo_etiqueta' => $contrato->tipo->etiqueta(),
            'contrato_id' => $contrato->exists ? $contrato->id : null,
            'estado_contrato' => $contrato->exists ? $contrato->estado->etiqueta() : null,
            'fecha_inicio' => $contrato->fecha_inicio->toDateString(),
            'fecha_fin' => $contrato->fecha_fin?->toDateString(),
            'documentos' => $documentos,
            'pendiente_evaluacion' => false,
        ];
    }

    /**
     * @param  Collection<string, GeneratedDocument>  $generados
     * @param  Collection<string, EmployeeDocument>  $cargados
     * @param  array<string, string>|null  $valores  Datos resueltos de la etapa (se calculan una sola vez).
     * @return Documento
     */
    private function documento(string $clave, ContratoLaboral $contrato, Colaborador $colaborador, Collection $generados, Collection $cargados, ?array &$valores): array
    {
        $nombre = $this->catalogo->nombreClave($clave);
        // El escaneo cargado en el expediente es el original FIRMADO
        // físicamente: se muestra como información, pero no sustituye al
        // documento del sistema (sí puede generarse). La idempotencia es
        // solo contra documentos GENERADOS.
        $firmado = null;

        foreach ($this->tiposExpediente($clave) as $tipo) {
            $cargado = $cargados->get($tipo);

            if ($cargado !== null) {
                $firmado = [
                    'etiqueta' => sprintf('Firmado cargado: %s · v%d · %s', $cargado->tipo->nombre, $cargado->version, $cargado->status->etiqueta()),
                    'url' => route('rh.documentos.descargar', $cargado->id, false),
                    // Para abrirlo en el visor embebido (PDF o imagen) sin
                    // que el navegador tenga que adivinar el tipo.
                    'nombre' => $cargado->original_name,
                    'mime' => $cargado->mime,
                ];

                break;
            }
        }

        $fila = ['clave' => $clave, 'nombre' => $nombre, 'documento_id' => null, 'descargar_url' => null, 'faltantes' => [], 'motivo' => null, 'firmado_cargado' => $firmado,
            // Tipo del expediente donde se sube su firmado (null: no admite carga).
            'tipo_firmado' => $this->firmados->tipoParaClave($clave)];

        foreach ([$clave, ...$this->equivalentesGenerados($clave)] as $posible) {
            $generado = $generados->get($posible);

            if ($generado !== null) {
                return [...$fila, 'estado' => Estado::Generado->value, 'estado_etiqueta' => Estado::Generado->etiqueta(),
                    'documento_id' => $generado->id,
                    'descargar_url' => route('rh.documentos-laborales.descargar', $generado->id, false),
                    'motivo' => $generado->estado_flujo?->etiquetaCorta((bool) $generado->requiere_firma_fisica)];
            }
        }

        $master = $this->master($clave, $colaborador);

        if (is_string($master)) {
            return [...$fila, 'estado' => Estado::FormatoNoListo->value, 'estado_etiqueta' => Estado::FormatoNoListo->etiqueta(), 'motivo' => $master];
        }

        if ($master->estado_master !== null) {
            if ($valores === null) {
                $valores = $this->datos->resolver($this->motor->contextoDesde($colaborador, $contrato, null));
            }

            $faltantes = $this->motor->faltantesDe($master, $valores);

            if ($faltantes !== []) {
                return [...$fila, 'estado' => Estado::FaltanDatos->value, 'estado_etiqueta' => Estado::FaltanDatos->etiqueta(),
                    'faltantes' => $faltantes,
                    'motivo' => sprintf('Faltan: %s.', implode(', ', array_column($faltantes, 'etiqueta')))];
            }
        }

        return [...$fila, 'estado' => Estado::PorGenerar->value, 'estado_etiqueta' => Estado::PorGenerar->etiqueta()];
    }

    /**
     * Master con el que se generaría la clave para esta persona (misma
     * resolución que MotorDocumentalService::generar), o el motivo legible de
     * por qué no se puede: formato no cargado o diseño sin validar.
     */
    private function master(string $clave, Colaborador $colaborador): DocumentTemplate|string
    {
        $llave = sprintf('%s|%s|%s', $clave, (string) $colaborador->puesto_id, (string) $colaborador->sucursalPrincipal?->empresa_id);

        if (isset($this->masters[$llave])) {
            return $this->masters[$llave];
        }

        try {
            $master = $this->resolvedor->esClaveMaestra($clave)
                ? $this->resolvedor->resolver($clave, $colaborador, 'alta')
                : $this->motor->plantillaActiva($clave);
        } catch (ValidationException) {
            $master = null;
        }

        $resultado = match (true) {
            $master === null => sprintf('No hay formato cargado de «%s» para el puesto %s (Documentos maestros).', $this->catalogo->nombreClave($clave), $colaborador->puesto->nombre ?? 'sin puesto'),
            $master->estado_master !== null && ! $master->disenoValidado() => sprintf('El diseño de «%s» aún no está validado (Documentos maestros → prueba de diseño).', $master->nombre),
            default => $master,
        };

        return $this->masters[$llave] = $resultado;
    }

    /**
     * @return list<string>
     */
    private function equivalentesGenerados(string $clave): array
    {
        return array_values(array_filter((array) config("contratos.regularizacion.equivalentes_generados.{$clave}", []), 'is_string'));
    }

    /**
     * @return list<string>
     */
    private function tiposExpediente(string $clave): array
    {
        return array_values(array_filter((array) config("contratos.regularizacion.tipos_expediente.{$clave}", []), 'is_string'));
    }

    /**
     * Documentos contractuales ya generados (no cancelados) del colaborador,
     * por clave (el más reciente).
     *
     * @return Collection<string, GeneratedDocument>
     */
    private function generadosDe(Colaborador $colaborador): Collection
    {
        $resultado = new Collection;

        GeneratedDocument::query()
            ->where('colaborador_id', $colaborador->id)
            ->whereNotNull('clave_plantilla')
            ->where(fn (Builder $q) => $q->whereNull('estado_flujo')->orWhere('estado_flujo', '!=', EstadoFlujoDocumento::Cancelado->value))
            ->orderBy('id')
            ->get()
            ->each(function (GeneratedDocument $documento) use ($resultado): void {
                $resultado->put(sprintf('%s', $documento->clave_plantilla), $documento);
            });

        return $resultado;
    }

    /**
     * Documentos del expediente (escaneos cargados) que cubren un contrato,
     * por clave de tipo; se descartan los pendientes, rechazados y por corregir.
     *
     * @return Collection<string, EmployeeDocument>
     */
    private function cargadosDe(Colaborador $colaborador): Collection
    {
        $tipos = [];

        foreach ((array) config('contratos.regularizacion.tipos_expediente', []) as $lista) {
            $tipos = [...$tipos, ...array_filter((array) $lista, 'is_string')];
        }

        $resultado = new Collection;

        EmployeeDocument::query()
            ->with('tipo')
            ->where('colaborador_id', $colaborador->id)
            ->whereNotIn('status', [EstadoDocumento::Pendiente->value, EstadoDocumento::Rechazado->value, EstadoDocumento::RequiereCorreccion->value])
            ->whereHas('tipo', fn (Builder $q) => $q->whereIn('clave', array_values(array_unique($tipos))))
            ->orderBy('id')
            ->get()
            ->each(function (EmployeeDocument $documento) use ($resultado): void {
                $resultado->put(sprintf('%s', $documento->tipo->clave), $documento);
            });

        return $resultado;
    }

    // ───────────────────────── Acciones ─────────────────────────

    /**
     * Regulariza los contratos de UN colaborador: registra el ContratoLaboral
     * que falte (inicial y, si ya venció el periodo, la renovación a
     * indeterminado) y genera solo los documentos que aún no están en su
     * expediente. Idempotente: una segunda corrida no duplica nada. Si falta
     * algún dato, no genera nada y devuelve el motivo.
     *
     * @return array{colaborador_id: int, nombre: string, resultado: string, resultado_etiqueta: string, motivo: string|null, documentos: list<string>}
     */
    public function generarColaborador(Colaborador $colaborador, User $actor, ?CarbonInterface $hoy = null): array
    {
        if (! $this->puedeGenerar($actor)) {
            throw new AuthorizationException('No tienes permiso para generar contratos.');
        }

        $this->exigirAlcance($actor, $colaborador);

        $candado = Cache::lock(sprintf('contratos:regularizacion:%d', $colaborador->id), 300);

        if (! $candado->get()) {
            return $this->resultado($colaborador, Resultado::Omitido, 'Ya se están generando sus contratos en otro proceso.');
        }

        try {
            return $this->generarBloqueado($colaborador, $actor, $hoy);
        } finally {
            $candado->release();
        }
    }

    /**
     * @return array{colaborador_id: int, nombre: string, resultado: string, resultado_etiqueta: string, motivo: string|null, documentos: list<string>}
     */
    private function generarBloqueado(Colaborador $colaborador, User $actor, ?CarbonInterface $hoy): array
    {
        if ($colaborador->estatus !== EstadoUsuario::Activo) {
            return $this->resultado($colaborador, Resultado::Omitido, 'El colaborador no está activo.');
        }

        $plan = $this->plan($colaborador, $hoy);

        if ($plan['estado'] !== Estado::PorGenerar->value) {
            return match ($plan['estado']) {
                Estado::Completo->value => $this->resultado($colaborador, Resultado::SinCambios, null),
                default => $this->resultado($colaborador, Resultado::Omitido, (string) $plan['motivo']),
            };
        }

        $etapas = $plan['contratos'];
        $generados = [];
        $errores = [];

        try {
            $inicial = $this->registrarInicial($colaborador, $etapas[0], $actor);
            $generados = [...$generados, ...$this->generarPendientes($inicial, $etapas[0], $actor, $errores)];

            if (isset($etapas[1]) && ! $etapas[1]['pendiente_evaluacion']) {
                if ($etapas[1]['contrato_id'] !== null) {
                    $indeterminado = ContratoLaboral::query()->where('id', $etapas[1]['contrato_id'])->firstOrFail();
                    $generados = [...$generados, ...$this->generarPendientes($indeterminado, $etapas[1], $actor, $errores)];
                } else {
                    // Misma renovación que la evaluación aprobada: el inicial
                    // queda «renovado», nace el indeterminado (inicio = fin + 1)
                    // y su paquete se prepara (una plantilla faltante queda como
                    // pendiente explícito, nunca como error silencioso).
                    $indeterminado = $this->contratos->renovar($inicial->refresh(), $actor);
                    $generados = [...$generados, ...$this->contratos->documentosDelContrato($indeterminado)
                        ->map(fn (GeneratedDocument $d): string => sprintf('%s', $d->titulo ?? $d->clave_plantilla))->values()->all()];
                    $faltaPrincipal = $this->documentos->documentoVigente($indeterminado, 'contrato_indeterminado') === null;

                    if ($faltaPrincipal) {
                        $errores[] = 'Contrato indeterminado registrado; su documento quedó pendiente (ver Pendientes de RH).';
                    }
                }
            }
        } catch (Throwable $e) {
            Log::warning('RegularizacionContratosService: no se pudieron regularizar los contratos.', ['colaborador_id' => $colaborador->id, 'error' => $e->getMessage()]);

            return $this->resultado($colaborador, Resultado::Error, $e instanceof ValidationException ? collect($e->errors())->flatten()->implode(' ') : $e->getMessage(), $generados);
        }

        $this->auditoria->registrar('contratos_regularizados', $colaborador, $actor, [
            'colaborador_id' => $colaborador->id,
            'documentos' => $generados,
            'pendientes' => $errores,
        ]);

        if ($generados === [] && $errores !== []) {
            return $this->resultado($colaborador, Resultado::Error, implode(' ', $errores));
        }

        return $this->resultado($colaborador, Resultado::Generado, $errores !== [] ? implode(' ', $errores) : null, $generados);
    }

    /**
     * @param  Etapa  $etapa
     */
    private function registrarInicial(Colaborador $colaborador, array $etapa, User $actor): ContratoLaboral
    {
        if ($etapa['contrato_id'] !== null) {
            return ContratoLaboral::query()->where('id', $etapa['contrato_id'])->firstOrFail();
        }

        return DB::transaction(fn (): ContratoLaboral => $this->contratos->crearContrato(
            $colaborador,
            TipoContratacion::from($etapa['tipo']),
            CarbonImmutable::parse($etapa['fecha_inicio']),
            $etapa['fecha_fin'] !== null ? CarbonImmutable::parse($etapa['fecha_fin']) : null,
            $actor,
        ));
    }

    /**
     * Genera los documentos «por generar» de la etapa (nunca los que ya
     * están generados o cargados en el expediente).
     *
     * @param  Etapa  $etapa
     * @param  list<string>  $errores
     * @return list<string>
     */
    private function generarPendientes(ContratoLaboral $contrato, array $etapa, User $actor, array &$errores): array
    {
        $generados = [];

        foreach ($etapa['documentos'] as $documento) {
            $clave = (string) $documento['clave'];

            if ($documento['estado'] === Estado::FormatoNoListo->value) {
                $errores[] = sprintf('%s: %s', $documento['nombre'], $documento['motivo']);

                continue;
            }

            if ($documento['estado'] !== Estado::PorGenerar->value || $this->documentos->documentoVigente($contrato, $clave) !== null) {
                continue;
            }

            if (! $this->documentos->puedeGenerar($actor, $clave, $contrato)) {
                $errores[] = sprintf('%s: sin permiso para generarlo.', $documento['nombre']);

                continue;
            }

            try {
                $generado = $this->contratos->generarDocumento($contrato, $clave, $actor);
                $generados[] = sprintf('%s', $generado->titulo ?? $documento['nombre']);
            } catch (ValidationException $e) {
                $errores[] = sprintf('%s: %s', $documento['nombre'], collect($e->errors())->flatten()->implode(' '));
            }
        }

        return $generados;
    }

    /**
     * @param  list<string>  $documentos
     * @return array{colaborador_id: int, nombre: string, resultado: string, resultado_etiqueta: string, motivo: string|null, documentos: list<string>}
     */
    private function resultado(Colaborador $colaborador, Resultado $resultado, ?string $motivo, array $documentos = []): array
    {
        return [
            'colaborador_id' => $colaborador->id,
            'nombre' => $colaborador->nombreCompleto(),
            'resultado' => $resultado->value,
            'resultado_etiqueta' => $resultado->etiqueta(),
            'motivo' => $motivo,
            'documentos' => $documentos,
        ];
    }

    /**
     * Vista previa REAL de un contrato que le corresponde (mismo formato y
     * datos que al generarlo, nada se guarda): reutiliza
     * DocumentoProcesoService::vistaPrevia con el contrato registrado o, si
     * aún no existe, con el contrato en memoria del plan. Con
     * $marcarPendientes (expediente) los faltantes salen como «PENDIENTE: …».
     *
     * @return array{pdf: string, nombre: string, faltantes: int}
     */
    public function vistaPrevia(Colaborador $colaborador, string $clave, User $actor, ?CarbonInterface $hoy = null, bool $marcarPendientes = false): array
    {
        if (! $this->puedeVer($actor)) {
            throw new AuthorizationException('No tienes permiso para ver contratos.');
        }

        $this->exigirAlcance($actor, $colaborador);
        $plan = $this->plan($colaborador, $hoy);

        foreach ((array) $plan['contratos'] as $etapa) {
            if (! in_array($clave, array_column($etapa['documentos'], 'clave'), true)) {
                continue;
            }

            $contrato = $etapa['contrato_id'] !== null
                ? ContratoLaboral::query()->where('id', $etapa['contrato_id'])->firstOrFail()
                : $this->enMemoria(
                    $colaborador,
                    TipoContratacion::from($etapa['tipo']),
                    CarbonImmutable::parse($etapa['fecha_inicio']),
                    $etapa['fecha_fin'] !== null ? CarbonImmutable::parse($etapa['fecha_fin']) : null,
                    null,
                );

            return $this->documentos->vistaPrevia($etapa['etapa'] === 'inicial' ? 'alta' : 'renovacion', $contrato, $clave, $actor, marcarPendientes: $marcarPendientes);
        }

        throw ValidationException::withMessages(['clave' => 'Ese documento no le corresponde a este colaborador.']);
    }

    /**
     * Guarda los datos que faltaban (misma lista blanca y permisos que el
     * modal de Documentos del proceso).
     *
     * @param  array<string, mixed>  $datos
     */
    public function completarDatos(Colaborador $colaborador, array $datos, User $actor): void
    {
        $this->exigirAlcance($actor, $colaborador);
        $this->documentos->completarDatos($colaborador, $datos, $actor);
    }
}
