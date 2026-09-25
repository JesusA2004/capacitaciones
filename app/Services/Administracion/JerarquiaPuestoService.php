<?php

namespace App\Services\Administracion;

use App\Models\Colaborador;
use App\Models\Puesto;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Colaboradores\FotoColaboradorService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

/**
 * Arbol de jerarquia de puestos (organigrama): usado por el panel web
 * (Administracion\JerarquiaPuestoController, vista con zoom/expand-collapse)
 * y por la API movil (Api\V1\Administracion\JerarquiaPuestoController), para
 * no duplicar la consulta ni el criterio de filtros entre ambos.
 */
class JerarquiaPuestoService
{
    private const FILTROS = ['empresa_id', 'sucursal_id', 'departamento_id', 'tipo_puesto'];

    /** Caras que se muestran por tarjeta del organigrama. */
    private const OCUPANTES_POR_PUESTO = 8;

    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly FotoColaboradorService $fotos,
    ) {}

    /**
     * Agrega a cada puesto sus primeros ocupantes activos (nombre + URL de
     * la miniatura) para mostrar las caras en el organigrama. Una sola
     * consulta para todo el árbol, acotada al alcance de quien mira y a la
     * empresa/sucursal filtrada. Nunca expone `foto_path`.
     *
     * @param  Collection<int, Puesto>  $puestos
     */
    public function agregarOcupantes(Collection $puestos, User $usuario, Request $request): void
    {
        $consulta = Colaborador::query()
            ->whereIn('puesto_id', $puestos->pluck('id'))
            ->where('estatus', 'activo')
            ->when($request->integer('sucursal_id'), fn ($query, int $id) => $query->where('sucursal_principal_id', $id))
            ->when($request->integer('empresa_id'), fn ($query, int $id) => $query->whereHas('sucursalPrincipal', fn ($s) => $s->where('empresa_id', $id)))
            ->orderByRaw('foto_path is null')
            ->orderBy('name');

        $porPuesto = $this->alcance->limitarColaboradoresPorAlcance($consulta, $usuario)
            ->with('sucursalPrincipal:id,nombre')
            ->get(['id', 'name', 'apellidos', 'puesto_id', 'foto_path', 'sucursal_principal_id', 'numero_empleado'])
            ->groupBy('puesto_id');

        foreach ($puestos as $puesto) {
            $puesto->setAttribute('ocupantes', ($porPuesto->get($puesto->id) ?? collect())
                ->take(self::OCUPANTES_POR_PUESTO)
                ->map(fn (Colaborador $colaborador) => [
                    'id' => $colaborador->id,
                    'nombre' => trim(sprintf('%s %s', $colaborador->name, $colaborador->apellidos ?? '')),
                    'foto_url' => $this->fotos->url($colaborador),
                    'sucursal' => $colaborador->sucursalPrincipal?->nombre,
                    'numero_empleado' => $colaborador->numero_empleado,
                ])
                ->values()
                ->all());
        }
    }

    /**
     * @return Collection<int, Puesto>
     */
    public function arbol(Request $request): Collection
    {
        return Puesto::query()
            ->with([
                'departamento:id,nombre',
                'puestoSuperior:id,nombre',
                'puestoCrecimiento:id,nombre',
                'respaldos:id,nombre',
                'puestosQuePuedeCubrir:id,nombre',
                'candidatos:id,puesto_objetivo_id,nombre,apellidos,estado',
            ])
            ->withCount([
                'colaboradores as colaboradores_count' => fn ($query) => $query->where('estatus', 'activo'),
                'candidatos',
                'vacantes as vacantes_abiertas_count' => fn ($query) => $query->whereNotIn('estado', ['cubierta', 'cancelada']),
            ])
            // Un puesto no tiene empresa/sucursal propia: se filtra por
            // "tiene al menos un colaborador activo en esa empresa/sucursal"
            // (ver docs/JERARQUIA_PUESTOS.md).
            ->when($request->integer('empresa_id'), fn ($query, int $id) => $query->whereHas(
                'colaboradores',
                fn ($sub) => $sub->whereHas('sucursalPrincipal', fn ($s) => $s->where('empresa_id', $id)),
            ))
            ->when($request->integer('sucursal_id'), fn ($query, int $id) => $query->whereHas(
                'colaboradores',
                fn ($sub) => $sub->where('sucursal_principal_id', $id),
            ))
            // Puestos retirados (inactivos, ver PuestoJerarquiaSeeder::retirar)
            // no ensucian el árbol — salvo que alguien activo siga en uno, para
            // que nadie desaparezca del organigrama sin haberse reasignado.
            ->where(fn ($query) => $query
                ->where('activo', true)
                ->orWhereHas('colaboradores', fn ($sub) => $sub->where('estatus', 'activo')))
            ->when($request->integer('departamento_id'), fn ($query, int $id) => $query->where('departamento_id', $id))
            ->when($request->string('tipo_puesto')->toString(), fn ($query, string $tipo) => $query->where('tipo_puesto', $tipo))
            ->orderBy('nivel_jerarquico')
            ->orderBy('nombre')
            ->get();
    }

    /**
     * @return array<int, string>
     */
    public function filtrosAceptados(): array
    {
        return self::FILTROS;
    }
}
