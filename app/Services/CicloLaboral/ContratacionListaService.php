<?php

namespace App\Services\CicloLaboral;

use App\Enums\EstadoAltaColaborador;
use App\Enums\EstadoAltaDigital;
use App\Enums\EstadoDocumento;
use App\Models\AltaDigital;
use App\Models\Colaborador;
use App\Models\EmployeeDocument;
use App\Models\GeneratedDocument;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Colaboradores\BuscadorColaboradoresService;
use App\Services\Contratos\ContratoLaboralService;
use App\Services\Expedientes\ExpedienteService;
use App\Support\Paginacion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Bandejas-lista de la Etapa 2 (contratación y expediente): quiénes están en
 * contratación, qué documentos de expediente esperan revisión de RH y qué
 * contratos de ingreso se han generado. NO inventa estado: cada fila deriva
 * su avance de los servicios ya existentes (CicloLaboralService para la
 * etapa/estado de la persona, ExpedienteService para el expediente,
 * ContratoLaboralService para los documentos laborales). La misma fuente que
 * la ficha por-persona y la API móvil — aquí solo se agrega en listas.
 */
class ContratacionListaService
{
    public function __construct(
        private readonly CicloLaboralService $ciclo,
        private readonly ExpedienteService $expediente,
        private readonly ContratoLaboralService $contratos,
        private readonly AlcanceOrganizacionalService $alcance,
    ) {}

    /**
     * Pantalla «Personas en contratación»: colaboradores cuya etapa del ciclo
     * es Contratación (Etapa 2), acotados al alcance del usuario.
     *
     * @param  array<string, mixed>  $filtros
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function personasEnContratacion(User $usuario, array $filtros = []): LengthAwarePaginator
    {
        $query = Colaborador::query()
            ->whereIn('estado_alta', self::estadosEnContratacion())
            ->with(['puesto:id,nombre', 'sucursalPrincipal:id,nombre']);

        $this->alcance->limitarColaboradoresPorAlcance($query, $usuario);
        $this->aplicarBusqueda($query, $filtros);

        $pagina = $query->orderBy('name')->paginate(Paginacion::porPagina());

        return $this->reemplazarItems($pagina, fn (Colaborador $colaborador) => $this->filaPersona($colaborador));
    }

    /**
     * Altas digitales todavía abiertas (el candidato aún no se convierte en
     * colaborador). Se muestran DENTRO de «Personas en contratación» para no
     * tener dos entradas de menú: son el paso previo del mismo embudo. Solo
     * para quien tiene el permiso de altas; su alcance es por sucursal.
     *
     * @return array<int, array<string, mixed>>
     */
    public function altasDigitalesPendientes(User $usuario): array
    {
        if (! $usuario->can('altas.ver')) {
            return [];
        }

        $query = AltaDigital::query()
            ->whereIn('estado', self::estadosAltaPendientes())
            ->with(['puesto:id,nombre', 'sucursal:id,nombre']);

        $this->alcance->limitarPorSucursal($query, $usuario, 'sucursal_id');

        return $query->orderByDesc('created_at')
            ->limit(Paginacion::TODOS)
            ->get()
            ->map(fn (AltaDigital $alta) => [
                'id' => $alta->id,
                'nombre' => trim(sprintf('%s %s', $alta->nombre, $alta->apellidos)),
                'puesto' => $alta->puesto?->nombre,
                'sucursal' => $alta->sucursal?->nombre,
                'estado' => $alta->estado->value,
                'estado_etiqueta' => $alta->estado->etiqueta(),
                'creado_en' => $alta->created_at?->toIso8601String(),
                'ver_url' => sprintf('/rh/altas/%d', $alta->id),
            ])
            ->values()
            ->all();
    }

    /**
     * Pantalla «Validación de documentos»: bandeja de documentos de expediente
     * con archivo cargado esperando decisión de RH (aprobar / rechazar /
     * solicitar nueva versión). Misma consulta que la bandeja móvil
     * (RhPendientesService) y mismo conjunto de estados
     * (EstadoDocumento::porRevisarValues) — aquí solo se expone en una tabla
     * web; las acciones reutilizan los endpoints rh.documentos.* existentes.
     *
     * @param  array<string, mixed>  $filtros
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function documentosPorRevisar(User $usuario, array $filtros = []): LengthAwarePaginator
    {
        $query = EmployeeDocument::query()
            ->whereIn('status', EstadoDocumento::porRevisarValues())
            ->with([
                'colaborador:id,name,apellidos,numero_empleado,sucursal_principal_id,puesto_id',
                'colaborador.sucursalPrincipal:id,nombre',
                'colaborador.puesto:id,nombre',
                'tipo:id,nombre',
                'subidoPor:id,name,apellidos',
            ]);

        $this->limitarPorColaborador($query, $usuario, 'colaborador_id');
        $this->aplicarBusquedaDocumentos($query, $filtros);

        $pagina = $query->orderByDesc('created_at')->paginate(Paginacion::porPagina());

        return $this->reemplazarItems($pagina, fn (EmployeeDocument $documento) => $this->filaDocumento($documento));
    }

    /**
     * Pantalla «Contratos de ingreso»: por cada persona en contratación, el
     * contrato vigente y sus documentos laborales iniciales (contrato del
     * puesto, confidencialidad, no competencia…) con su estado de flujo
     * (generado → impreso → firmado físicamente). Solo lectura: la generación
     * y la gestión física siguen en la pantalla por-persona (Documentos del
     * proceso), a la que se enlaza.
     *
     * @param  array<string, mixed>  $filtros
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function contratosDeIngreso(User $usuario, array $filtros = []): LengthAwarePaginator
    {
        $query = Colaborador::query()
            ->whereIn('estado_alta', self::estadosEnContratacion())
            ->with(['puesto:id,nombre', 'sucursalPrincipal:id,nombre']);

        $this->alcance->limitarColaboradoresPorAlcance($query, $usuario);
        $this->aplicarBusqueda($query, $filtros);

        $pagina = $query->orderBy('name')->paginate(Paginacion::porPagina());

        return $this->reemplazarItems($pagina, fn (Colaborador $colaborador) => $this->filaContrato($colaborador));
    }

    /**
     * @return array<string, mixed>
     */
    private function filaPersona(Colaborador $colaborador): array
    {
        // La etapa/estado/progreso/siguiente acción los deriva el servicio
        // único del ciclo (sin timeline ni acciones del viewer: es una lista).
        $ciclo = $this->ciclo->obtenerEstado($colaborador, null, false);
        $documental = $this->expediente->estadoDocumental($colaborador);
        $contrato = $this->contratos->vigente($colaborador);

        return [
            'id' => $colaborador->id,
            'nombre' => $colaborador->nombreCompleto(),
            'numero_empleado' => $colaborador->numero_empleado,
            'puesto' => $colaborador->puesto?->nombre,
            'sucursal' => $colaborador->sucursalPrincipal?->nombre,
            'etapa' => $ciclo['estado']['etiqueta'],
            'estado_clave' => $ciclo['estado']['clave'],
            'progreso' => $ciclo['progreso'],
            'documentos_pendientes' => max($documental['requeridos'] - $documental['aprobados'], 0),
            'documentos_rechazados' => $documental['rechazados'],
            'estado_contrato' => $contrato === null
                ? 'Sin contrato generado'
                : sprintf('%s · %s', $contrato->tipo->etiqueta(), $contrato->estado->etiqueta()),
            'estado_imss' => $colaborador->estatus_imss->etiqueta(),
            'siguiente_accion' => is_array($ciclo['siguiente_accion']) ? $ciclo['siguiente_accion']['etiqueta'] : null,
            'ciclo_url' => sprintf('/rh/colaboradores/%d/ciclo', $colaborador->id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function filaDocumento(EmployeeDocument $documento): array
    {
        // colaborador_id es un FK real del documento (con withTrashed): el
        // colaborador siempre existe.
        $colaborador = $documento->colaborador;

        return [
            'id' => $documento->id,
            'colaborador' => [
                'id' => $colaborador->id,
                'nombre' => $colaborador->nombreCompleto(),
                'numero_empleado' => $colaborador->numero_empleado,
                'sucursal' => $colaborador->sucursalPrincipal?->nombre,
                'puesto' => $colaborador->puesto?->nombre,
            ],
            'tipo' => $documento->tipo->nombre,
            // Nombre y mime del archivo: el visor centrado de la bandeja
            // decide con ellos si lo dibuja como PDF o como imagen.
            'archivo' => $documento->original_name,
            'mime' => $documento->mime,
            'version' => $documento->version,
            'estado' => $documento->status->value,
            'estado_etiqueta' => $documento->status->etiqueta(),
            'subido_por' => $documento->subidoPor !== null
                ? trim(sprintf('%s %s', $documento->subidoPor->name, $documento->subidoPor->apellidos))
                : null,
            'cargado_en' => $documento->created_at?->toIso8601String(),
            // La extracción y la vista completa del expediente viven en el
            // expediente; aquí se enlaza, no se duplica. El documento en sí
            // se revisa en el visor centrado con descargar_url (inline).
            'expediente_url' => sprintf('/rh/expedientes/%d', $colaborador->id),
            'descargar_url' => sprintf('/rh/documentos/%d/descargar', $documento->id),
            'aprobar_url' => sprintf('/rh/documentos/%d/aprobar', $documento->id),
            'rechazar_url' => sprintf('/rh/documentos/%d/rechazar', $documento->id),
            'correccion_url' => sprintf('/rh/documentos/%d/solicitar-correccion', $documento->id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function filaContrato(Colaborador $colaborador): array
    {
        $contrato = $this->contratos->vigente($colaborador);
        $documentos = [];

        if ($contrato !== null) {
            $documentos = $this->contratos->documentosDelContrato($contrato)
                ->map(fn (GeneratedDocument $documento) => [
                    'id' => $documento->id,
                    'titulo' => $documento->titulo ?? $documento->clave_plantilla ?? 'Documento',
                    'clave' => $documento->clave_plantilla,
                    'estado' => $documento->estado_flujo?->value,
                    'estado_etiqueta' => $documento->estado_flujo?->etiquetaCorta((bool) $documento->requiere_firma_fisica) ?? 'Pendiente',
                ])
                ->values()
                ->all();
        }

        return [
            'id' => $colaborador->id,
            'nombre' => $colaborador->nombreCompleto(),
            'numero_empleado' => $colaborador->numero_empleado,
            'puesto' => $colaborador->puesto?->nombre,
            'sucursal' => $colaborador->sucursalPrincipal?->nombre,
            'tipo_contrato' => $contrato?->tipo->etiqueta(),
            'estado_contrato' => $contrato === null ? 'Sin contrato generado' : $contrato->estado->etiqueta(),
            'paquete_generado' => $contrato !== null && $this->contratos->paqueteGenerado($contrato),
            'paquete_firmado' => $contrato !== null && $this->contratos->paqueteFirmado($contrato),
            'documentos' => $documentos,
            // La generación/firma del paquete vive en el Ciclo laboral de la
            // persona (sección «Documentos»); /rh/documentos-proceso/... es el
            // endpoint JSON que esa sección consume, no una página.
            'gestion_url' => route('rh.colaboradores.ciclo', $colaborador, false),
        ];
    }

    /**
     * Mismo paginador, con cada item ya convertido a array explícito (nunca el
     * modelo crudo). Se construye uno NUEVO porque setCollection() conserva el
     * tipo genérico original; withQueryString() preserva los filtros en los
     * enlaces de paginación. Mismo patrón que AvisoService::reemplazarItems().
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  LengthAwarePaginator<int, TModel>  $paginador
     * @param  callable(TModel): array<string, mixed>  $mapa
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function reemplazarItems(LengthAwarePaginator $paginador, callable $mapa): LengthAwarePaginator
    {
        $nuevo = new LengthAwarePaginator(
            $paginador->getCollection()->map($mapa)->values(),
            $paginador->total(),
            $paginador->perPage(),
            $paginador->currentPage(),
            ['path' => $paginador->path(), 'pageName' => $paginador->getPageName()],
        );

        return $nuevo->withQueryString();
    }

    /**
     * Acota una consulta de employee_documents al alcance del usuario a través
     * de su colaborador — mismo criterio que la bandeja móvil
     * (RhPendientesService::limitarPorAlcanceViaColaborador).
     *
     * @param  Builder<EmployeeDocument>  $query
     */
    private function limitarPorColaborador(Builder $query, User $usuario, string $columna): void
    {
        if ($this->alcance->tieneAlcanceGlobal($usuario)) {
            return;
        }

        $idsVisibles = $this->alcance
            ->limitarColaboradoresPorAlcance(Colaborador::query(), $usuario)
            ->pluck('id');

        $query->whereIn($columna, $idsVisibles);
    }

    /**
     * @param  Builder<Colaborador>  $query
     * @param  array<string, mixed>  $filtros
     */
    private function aplicarBusqueda(Builder $query, array $filtros): void
    {
        $busqueda = isset($filtros['busqueda']) ? trim((string) $filtros['busqueda']) : '';
        $sucursalId = isset($filtros['sucursal_id']) ? (int) $filtros['sucursal_id'] : 0;

        $query
            ->when($busqueda !== '', fn (Builder $sub) => $sub->buscar($busqueda))
            ->when($sucursalId > 0, fn (Builder $sub) => $sub->where('sucursal_principal_id', $sucursalId));
    }

    /**
     * @param  Builder<EmployeeDocument>  $query
     * @param  array<string, mixed>  $filtros
     */
    private function aplicarBusquedaDocumentos(Builder $query, array $filtros): void
    {
        $busqueda = isset($filtros['busqueda']) ? trim((string) $filtros['busqueda']) : '';
        $sucursalId = isset($filtros['sucursal_id']) ? (int) $filtros['sucursal_id'] : 0;

        $query
            ->when($busqueda !== '', fn (Builder $sub) => app(BuscadorColaboradoresService::class)->aplicarPorColumna($sub, $busqueda))
            ->when($sucursalId > 0, fn (Builder $sub) => $sub->whereHas('colaborador', fn (Builder $q) => $q->where('sucursal_principal_id', $sucursalId)));
    }

    /**
     * Valores de estado_alta que corresponden a la Etapa 2 (contratación).
     * Fuente única: EstadoAltaColaborador::enContratacion().
     *
     * @return array<int, string>
     */
    private static function estadosEnContratacion(): array
    {
        $valores = [];

        foreach (EstadoAltaColaborador::cases() as $estado) {
            if ($estado->enContratacion()) {
                $valores[] = $estado->value;
            }
        }

        return $valores;
    }

    /**
     * Altas digitales todavía abiertas: cualquier estado que no sea terminal
     * (aprobada / rechazada / convertida / cancelada).
     *
     * @return array<int, string>
     */
    private static function estadosAltaPendientes(): array
    {
        $terminales = [
            EstadoAltaDigital::Aprobada,
            EstadoAltaDigital::Rechazada,
            EstadoAltaDigital::ConvertidaAColaborador,
            EstadoAltaDigital::Cancelada,
        ];

        $valores = [];

        foreach (EstadoAltaDigital::cases() as $estado) {
            if (! in_array($estado, $terminales, true)) {
                $valores[] = $estado->value;
            }
        }

        return $valores;
    }
}
