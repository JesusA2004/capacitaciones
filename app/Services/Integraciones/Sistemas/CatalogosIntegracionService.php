<?php

namespace App\Services\Integraciones\Sistemas;

use App\Models\ClienteIntegracion;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\Puesto;
use App\Models\Sucursal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Catálogos de la API multi-sistema (/api/integracion/v1): empresas,
 * sucursales, puestos y departamentos, y el alcance de sucursales de un
 * cliente de integración.
 *
 * Alcance: un cliente lee sólo las empresas que se le permitieron al emitir
 * su token (ClienteIntegracion::empresasPermitidas); una sucursal de otra
 * empresa «no existe» para él. Puestos y departamentos son globales en People
 * (sin empresa). Se entregan también los inactivos o con borrado lógico
 * (`activo=false`, `eliminado=true`) para que el otro sistema refleje bajas
 * de catálogo; nada se borra físicamente.
 */
class CatalogosIntegracionService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function empresas(ClienteIntegracion $cliente): array
    {
        $permitidas = $cliente->empresasPermitidas();

        return array_values(Empresa::query()
            ->when($permitidas !== null, fn (Builder $q) => $q->whereIn('id', $permitidas ?? []))
            ->orderBy('id')
            ->get(['id', 'nombre', 'razon_social', 'activo', 'updated_at'])
            ->map(fn (Empresa $e): array => [
                'id' => $e->id,
                'nombre' => $e->nombre,
                'razon_social' => $e->razon_social,
                'activo' => $e->activo,
                'actualizado_en' => $e->updated_at?->toIso8601String(),
            ])
            ->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function sucursales(ClienteIntegracion $cliente, ?int $empresaId, ?CarbonImmutable $actualizadoDesde): array
    {
        $empresas = Empresa::query()->pluck('nombre', 'id');

        return array_values($this->consultaSucursales($cliente, $empresaId)
            ->when($actualizadoDesde !== null, fn (Builder $q) => $q->where('updated_at', '>=', $actualizadoDesde?->utc()->format('Y-m-d H:i:s')))
            ->orderBy('id')
            ->get(['id', 'empresa_id', 'clave', 'nombre', 'ciudad', 'estado', 'municipio', 'es_corporativo', 'activo', 'updated_at', 'deleted_at'])
            ->map(fn (Sucursal $s): array => [
                'id' => $s->id,
                'clave' => $this->texto($s->clave),
                'nombre' => $s->nombre,
                // Para emparejar con el catálogo de otro sistema (p. ej.
                // branches.normalized_name de Reportes) sin adivinar.
                'nombre_normalizado' => self::normalizar($s->nombre),
                'empresa' => $s->empresa_id !== null ? ['id' => $s->empresa_id, 'nombre' => (string) ($empresas[$s->empresa_id] ?? '')] : null,
                'ciudad' => $this->texto($s->ciudad),
                'municipio' => $this->texto($s->municipio),
                'estado' => $this->texto($s->estado),
                'es_corporativo' => (bool) $s->es_corporativo,
                'activo' => $s->activo && ! $s->trashed(),
                'eliminado' => $s->trashed(),
                'actualizado_en' => $s->updated_at?->toIso8601String(),
            ])
            ->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function puestos(?CarbonImmutable $actualizadoDesde): array
    {
        $departamentos = Departamento::withTrashed()->pluck('nombre', 'id');

        return array_values(Puesto::withTrashed()
            ->when($actualizadoDesde !== null, fn (Builder $q) => $q->where('updated_at', '>=', $actualizadoDesde?->utc()->format('Y-m-d H:i:s')))
            ->orderBy('id')
            ->get(['id', 'nombre', 'departamento_id', 'nivel_jerarquico', 'tipo_puesto', 'activo', 'updated_at', 'deleted_at'])
            ->map(fn (Puesto $p): array => [
                'id' => $p->id,
                'nombre' => $p->nombre,
                'departamento' => $p->departamento_id !== null && $departamentos->has($p->departamento_id)
                    ? ['id' => $p->departamento_id, 'nombre' => (string) $departamentos->get($p->departamento_id)]
                    : null,
                'nivel_jerarquico' => $p->nivel_jerarquico,
                'tipo' => $p->tipo_puesto !== null ? ['valor' => $p->tipo_puesto->value, 'etiqueta' => $p->tipo_puesto->etiqueta()] : null,
                'activo' => $p->activo && ! $p->trashed(),
                'eliminado' => $p->trashed(),
                'actualizado_en' => $p->updated_at?->toIso8601String(),
            ])
            ->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function departamentos(?CarbonImmutable $actualizadoDesde): array
    {
        return array_values(Departamento::withTrashed()
            ->when($actualizadoDesde !== null, fn (Builder $q) => $q->where('updated_at', '>=', $actualizadoDesde?->utc()->format('Y-m-d H:i:s')))
            ->orderBy('id')
            ->get(['id', 'nombre', 'activo', 'updated_at', 'deleted_at'])
            ->map(fn (Departamento $d): array => [
                'id' => $d->id,
                'nombre' => $d->nombre,
                'activo' => $d->activo && ! $d->trashed(),
                'eliminado' => $d->trashed(),
                'actualizado_en' => $d->updated_at?->toIso8601String(),
            ])
            ->all());
    }

    public function empresaVisible(ClienteIntegracion $cliente, int $empresaId): bool
    {
        return $cliente->puedeVerEmpresa($empresaId) && Empresa::query()->whereKey($empresaId)->exists();
    }

    public function sucursalVisible(ClienteIntegracion $cliente, int $sucursalId): bool
    {
        return $this->consultaSucursales($cliente, null)->whereKey($sucursalId)->exists();
    }

    /**
     * Ids de sucursal (también las de borrado lógico, por su historia) que el
     * cliente puede consultar, acotados a una empresa o a una sucursal.
     *
     * @return Collection<int, int>
     */
    public function idsSucursales(ClienteIntegracion $cliente, ?int $empresaId = null, ?int $sucursalId = null): Collection
    {
        return $this->consultaSucursales($cliente, $empresaId)
            ->when($sucursalId !== null, fn (Builder $q) => $q->whereKey($sucursalId))
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values();
    }

    /**
     * Id => {id, clave, nombre} de las sucursales indicadas (para las filas).
     *
     * @param  Collection<int, int>  $ids
     * @return array<int, array{id: int, clave: string|null, nombre: string}>
     */
    public function referenciasSucursales(Collection $ids): array
    {
        $salida = [];

        foreach (Sucursal::withTrashed()->whereIn('id', $ids->all())->get(['id', 'clave', 'nombre']) as $s) {
            $salida[$s->id] = ['id' => $s->id, 'clave' => $this->texto($s->clave), 'nombre' => $s->nombre];
        }

        return $salida;
    }

    /**
     * Mayúsculas sin acentos ni signos, espacios simples: la misma idea que el
     * nombre normalizado de los catálogos de Reportes.
     */
    public static function normalizar(string $texto): string
    {
        $sinAcentos = strtr(mb_strtoupper(trim($texto)), ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);

        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^A-Z0-9 ]/', ' ', $sinAcentos)));
    }

    /**
     * @return Builder<Sucursal>
     */
    private function consultaSucursales(ClienteIntegracion $cliente, ?int $empresaId): Builder
    {
        $permitidas = $cliente->empresasPermitidas();

        return Sucursal::withTrashed()
            ->when($permitidas !== null, fn (Builder $q) => $q->whereIn('empresa_id', $permitidas ?? []))
            ->when($empresaId !== null, fn (Builder $q) => $q->where('empresa_id', $empresaId));
    }

    private function texto(mixed $valor): ?string
    {
        if (! is_scalar($valor)) {
            return null;
        }

        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }
}
