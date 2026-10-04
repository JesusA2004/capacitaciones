<?php

namespace App\Http\Controllers\Administracion;

use App\Enums\GrupoPuestoIndicador;
use App\Enums\TipoDestinatarioNotificacion;
use App\Http\Controllers\Controller;
use App\Models\DocumentType;
use App\Models\Puesto;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Configuracion\ConfiguracionSistemaService;
use App\Services\Configuracion\WorkflowRoutingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;

/**
 * Administración → Configuración: apariencia, ruteo de notificaciones y
 * parámetros de RH. Autoriza por permiso
 * (configuracion.*) y delega todo a ConfiguracionSistemaService y
 * WorkflowRoutingService. El jefe directo no se configura aquí: sale del
 * organigrama (JefeDirectoService).
 */
class ConfiguracionController extends Controller
{
    public function __construct(
        private readonly ConfiguracionSistemaService $configuracion,
        private readonly WorkflowRoutingService $routing,
        private readonly AuditoriaService $auditoria,
    ) {}

    public function index(Request $request): RedirectResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->can('configuracion.ver'), 403);

        foreach (['configuracion.notificaciones' => 'notificaciones', 'configuracion.rh' => 'parametros-rh', 'configuracion.apariencia' => 'apariencia'] as $permiso => $seccion) {
            if ($usuario->can($permiso)) {
                return to_route("administracion.configuracion.{$seccion}");
            }
        }

        abort(403);
    }

    public function apariencia(Request $request): Response
    {
        $this->exigir($request, 'configuracion.apariencia');

        return Inertia::render('Administracion/Configuracion/Apariencia', [
            'colores' => $this->configuracion->grupo('apariencia'),
            'secciones' => $this->secciones($request->user()),
        ]);
    }

    public function guardarApariencia(Request $request): RedirectResponse
    {
        $this->exigir($request, 'configuracion.apariencia');
        $datos = $request->validate(['valores' => ['required', 'array']]);
        $this->configuracion->guardar('apariencia', $datos['valores'], $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Colores institucionales guardados.']);
    }

    public function restaurar(Request $request): RedirectResponse
    {
        $datos = $request->validate(['clave' => ['required', 'string']]);
        $grupo = (string) ($this->configuracion->catalogo()[$datos['clave']]['grupo'] ?? '');
        $this->exigir($request, $grupo === 'apariencia' ? 'configuracion.apariencia' : 'configuracion.rh');
        $this->configuracion->restaurar($datos['clave'], $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Valor de fábrica restaurado.']);
    }

    public function notificaciones(Request $request): Response
    {
        $this->exigir($request, 'configuracion.notificaciones');

        return Inertia::render('Administracion/Configuracion/Notificaciones', [
            'reglas' => $this->routing->reglas(),
            'tipos' => array_map(fn (TipoDestinatarioNotificacion $t) => ['value' => $t->value, 'etiqueta' => $t->etiqueta(), 'ayuda' => $t->ayuda()], TipoDestinatarioNotificacion::cases()),
            'permisos' => Permission::query()->orderBy('name')->pluck('name'),
            'usuarios' => User::query()->whereNull('acceso_bloqueado_en')->orderBy('name')->get(['id', 'name', 'email'])->map(fn (User $u) => ['id' => $u->id, 'nombre' => $u->name, 'email' => $u->email]),
            'secciones' => $this->secciones($request->user()),
        ]);
    }

    public function guardarNotificacion(Request $request, string $evento): RedirectResponse
    {
        $this->exigir($request, 'configuracion.notificaciones');
        $datos = $request->validate([
            'destinatarios' => ['present', 'array'],
            'destinatarios.*' => ['string'],
            'fallback' => ['nullable', 'array'],
            'fallback.*' => ['string'],
            'permiso' => ['nullable', 'string'],
            'usuario_ids' => ['nullable', 'array'],
            'usuario_ids.*' => ['integer'],
            'activa' => ['sometimes', 'boolean'],
        ]);

        /** @var list<string> $destinatarios */
        $destinatarios = array_values(array_map('strval', $datos['destinatarios']));

        $this->routing->guardarRegla($evento, [
            'destinatarios' => $destinatarios,
            'fallback' => array_values(array_map('strval', $datos['fallback'] ?? [])),
            'permiso' => $datos['permiso'] ?? null,
            'usuario_ids' => array_values(array_map('intval', $datos['usuario_ids'] ?? [])),
            'activa' => (bool) ($datos['activa'] ?? true),
        ], $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Regla de notificación guardada.']);
    }

    public function restaurarNotificacion(Request $request, string $evento): RedirectResponse
    {
        $this->exigir($request, 'configuracion.notificaciones');
        $this->routing->restaurarRegla($evento, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Regla de notificación restaurada a la de fábrica.']);
    }

    public function parametrosRh(Request $request): Response
    {
        $this->exigir($request, 'configuracion.rh');

        return Inertia::render('Administracion/Configuracion/ParametrosRh', [
            'parametros' => $this->configuracion->grupo('rh'),
            'puestos' => Puesto::query()->where('activo', true)->orderBy('nivel_jerarquico')->orderBy('nombre')->get(['id', 'nombre', 'meses_periodo_prueba', 'grupo_indicador', 'grupo_documental'])
                ->map(fn (Puesto $p) => ['id' => $p->id, 'nombre' => $p->nombre, 'meses_periodo_prueba' => $p->meses_periodo_prueba, 'grupo_indicador' => $p->grupo_indicador?->value, 'grupo_documental' => $p->grupo_documental,
                    // Quién cambió la configuración del puesto (meses, grupo documental) y cuándo.
                    'historial' => $this->auditoria->historial($p, ['configuracion_puesto_actualizada'], 5)]),
            'tiposDocumento' => DocumentType::query()->where('activo', true)->orderBy('nombre')->get(['id', 'nombre', 'vigencia_meses']),
            'grupos' => array_map(fn (GrupoPuestoIndicador $g) => ['value' => $g->value, 'etiqueta' => $g->etiqueta()], GrupoPuestoIndicador::cases()),
            // Variante de documentos jurídicos (contratos) que le toca al puesto.
            'gruposDocumentales' => array_map(fn (string $valor, string $etiqueta): array => ['value' => $valor, 'etiqueta' => $etiqueta], array_keys((array) config('documentos_maestros.grupos', [])), array_values((array) config('documentos_maestros.grupos', []))),
            'secciones' => $this->secciones($request->user()),
        ]);
    }

    public function guardarParametrosRh(Request $request): RedirectResponse
    {
        $this->exigir($request, 'configuracion.rh');
        $datos = $request->validate(['valores' => ['required', 'array']]);
        $this->configuracion->guardar('rh', $datos['valores'], $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Parámetros de RH guardados.']);
    }

    public function guardarPuesto(Request $request, Puesto $puesto): RedirectResponse
    {
        $this->exigir($request, 'configuracion.rh');
        $datos = $request->validate([
            'meses_periodo_prueba' => ['nullable', 'integer', 'min:1', 'max:12'],
            'grupo_indicador' => ['nullable', 'string', Rule::enum(GrupoPuestoIndicador::class)],
            'grupo_documental' => ['nullable', 'string', Rule::in(array_keys((array) config('documentos_maestros.grupos', [])))],
        ]);

        $this->configuracion->actualizarPuesto($puesto, [
            'meses_periodo_prueba' => isset($datos['meses_periodo_prueba']) ? (int) $datos['meses_periodo_prueba'] : null,
            'grupo_indicador' => isset($datos['grupo_indicador']) ? (string) $datos['grupo_indicador'] : null,
            'grupo_documental' => isset($datos['grupo_documental']) ? (string) $datos['grupo_documental'] : null,
        ], $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => sprintf('Puesto «%s» actualizado.', $puesto->nombre)]);
    }

    public function guardarTipoDocumento(Request $request, DocumentType $tipoDocumento): RedirectResponse
    {
        $this->exigir($request, 'configuracion.rh');
        $datos = $request->validate(['vigencia_meses' => ['nullable', 'integer', 'min:1', 'max:120']]);

        $this->configuracion->actualizarVigenciaDocumento($tipoDocumento, isset($datos['vigencia_meses']) ? (int) $datos['vigencia_meses'] : null, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => sprintf('Vigencia de «%s» actualizada.', $tipoDocumento->nombre)]);
    }

    private function exigir(Request $request, string $permiso): void
    {
        abort_unless($request->user()->can('configuracion.ver') && $request->user()->can($permiso), 403);
    }

    /**
     * Pestañas visibles según los permisos del usuario.
     *
     * @return list<array{clave: string, titulo: string}>
     */
    private function secciones(User $usuario): array
    {
        $todas = [
            'notificaciones' => ['configuracion.notificaciones', 'Notificaciones'],
            'parametros-rh' => ['configuracion.rh', 'Parámetros de RH'],
            'apariencia' => ['configuracion.apariencia', 'Apariencia'],
        ];

        $visibles = [];

        foreach ($todas as $clave => [$permiso, $titulo]) {
            if ($usuario->can($permiso)) {
                $visibles[] = ['clave' => $clave, 'titulo' => $titulo];
            }
        }

        return $visibles;
    }
}
