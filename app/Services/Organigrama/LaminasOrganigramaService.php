<?php

namespace App\Services\Organigrama;

use App\Models\Colaborador;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Organigrama en LÁMINAS, como el PDF oficial de Inducción (cap. 4, hojas
 * 17 · Corporativo, 18 · Dirección Comercial y 19 · Sucursal): la estructura
 * y los textos vienen de config('organigrama.laminas'); las personas son los
 * colaboradores ACTIVOS de cada puesto. Un puesto sin nadie se muestra como
 * vacante, nunca con un nombre inventado.
 */
class LaminasOrganigramaService
{
    /** @var Collection<int, Colaborador>|null */
    private ?Collection $activos = null;

    public function __construct(private readonly AlcanceOrganizacionalService $alcance) {}

    /**
     * @return array{corporativo: array<string, mixed>, comercial: array<string, mixed>, sucursal: array<string, mixed>}
     */
    public function laminas(User $usuario): array
    {
        return [
            'corporativo' => $this->corporativo(),
            'comercial' => $this->comercial(),
            'sucursal' => $this->sucursal($usuario),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function corporativo(): array
    {
        $config = (array) config('organigrama.laminas.corporativo');
        $dg = (array) ($config['direccion_general'] ?? []);
        $titulares = $this->personas((array) ($dg['puestos'] ?? []));

        if ($titulares === [] && filled($dg['titular_documento'] ?? null)) {
            $titulares = [['id' => null, 'nombre' => (string) $dg['titular_documento'], 'sucursal' => null]];
        }

        return [
            'direccion_general' => ['titulo' => 'Dirección General', 'personas' => $titulares],
            'areas' => array_map(fn (array $area): array => [
                'sigla' => $area['sigla'],
                'titulo' => $area['titulo'],
                'icono' => $area['icono'],
                'personas' => $this->personas((array) $area['puestos']) ?: $this->responsablesDe((array) ($area['departamentos'] ?? [])),
            ], (array) ($config['areas'] ?? [])),
            'nota' => $config['nota'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function comercial(): array
    {
        $config = (array) config('organigrama.laminas.comercial');
        $caja = fn (array $c): array => [
            'titulo' => $c['titulo'],
            'icono' => $c['icono'] ?? null,
            'destacado' => (bool) ($c['destacado'] ?? false),
            'personas' => $this->personas((array) $c['puestos']),
        ];

        return [
            'director' => $caja((array) $config['director']),
            'apoyo' => array_map($caja, (array) ($config['apoyo'] ?? [])),
            'columnas' => array_map(fn (array $col): array => [
                ...$caja($col),
                'cadena' => array_map($caja, (array) ($col['cadena'] ?? [])),
                'hijos' => array_map($caja, (array) ($col['hijos'] ?? [])),
            ], (array) ($config['columnas'] ?? [])),
            'leyenda' => $config['leyenda'] ?? [],
        ];
    }

    /**
     * Una lámina por sucursal (las que la persona puede ver), con su
     * Gerente, Subgerente, Coordinación y Gestores de Crédito.
     *
     * @return array<string, mixed>
     */
    private function sucursal(User $usuario): array
    {
        $config = (array) config('organigrama.laminas.sucursal');
        $visibles = $this->alcance->sucursalesVisiblesIds($usuario);
        $sucursales = Sucursal::query()
            ->where('activo', true)
            ->where('es_corporativo', false)
            ->whereIn('id', $visibles)
            ->orderBy('nombre')
            ->get(['id', 'nombre']);

        $bloques = ['gerente', 'subgerente', 'coordinacion', 'gestores'];

        return [
            'puestos' => collect($bloques)->mapWithKeys(fn (string $b): array => [$b => [
                'titulo' => $config[$b]['titulo'] ?? $b,
                'funciones' => (array) ($config[$b]['funciones'] ?? []),
            ]])->all(),
            'sucursales' => $sucursales->map(fn (Sucursal $s): array => [
                'id' => $s->id,
                'nombre' => $s->nombre,
                ...collect($bloques)->mapWithKeys(fn (string $b): array => [
                    $b => $this->personas((array) ($config[$b]['puestos'] ?? []), $s->id),
                ])->all(),
            ])->values()->all(),
            'leyenda' => $config['leyenda'] ?? [],
        ];
    }

    /**
     * Colaboradores activos cuyo puesto coincide (nombre exacto o patrón con
     * %), opcionalmente de una sucursal.
     *
     * @param  array<mixed>  $patrones  Nombres de puesto o patrones con %.
     * @return list<array{id: int|null, nombre: string, sucursal: string|null}>
     */
    private function personas(array $patrones, ?int $sucursalId = null): array
    {
        // Sin distinguir acentos ni mayúsculas: «Contraloria» = «Contraloría».
        $regex = array_map(fn (string $p): string => '/^'.str_replace('%', '.*', preg_quote(Str::ascii(trim($p)), '/')).'$/i', array_filter($patrones, 'is_string'));

        return array_values($this->activos()
            ->filter(fn (Colaborador $c): bool => ($sucursalId === null || $c->sucursal_principal_id === $sucursalId)
                && $c->puesto !== null
                && collect($regex)->contains(fn (string $r): bool => preg_match($r, Str::ascii(trim($c->puesto->nombre))) === 1))
            ->map(fn (Colaborador $c): array => ['id' => $c->id, 'nombre' => $c->nombreCompleto(), 'sucursal' => $c->sucursalPrincipal?->nombre])
            ->sortBy('nombre')
            ->all());
    }

    /**
     * Respaldo cuando el puesto del área se llama distinto en cada empresa
     * (p. ej. «Encargado de Sistemas»): el o los colaboradores de MAYOR
     * jerarquía de ese departamento.
     *
     * @param  array<mixed>  $departamentos
     * @return list<array{id: int|null, nombre: string, sucursal: string|null}>
     */
    private function responsablesDe(array $departamentos): array
    {
        $buscados = array_map(fn (string $d): string => Str::lower(Str::ascii($d)), array_filter($departamentos, 'is_string'));

        if ($buscados === []) {
            return [];
        }

        $delArea = $this->activos()->filter(function (Colaborador $c) use ($buscados): bool {
            $departamento = $c->puesto->departamento->nombre ?? $c->departamento->nombre ?? null;

            return $departamento !== null && in_array(Str::lower(Str::ascii($departamento)), $buscados, true);
        });

        if ($delArea->isEmpty()) {
            return [];
        }

        $nivel = $delArea->min(fn (Colaborador $c): int => $c->puesto->nivel_jerarquico ?? 99);

        return array_values($delArea
            ->filter(fn (Colaborador $c): bool => ($c->puesto->nivel_jerarquico ?? 99) === $nivel)
            ->map(fn (Colaborador $c): array => ['id' => $c->id, 'nombre' => $c->nombreCompleto(), 'sucursal' => $c->sucursalPrincipal?->nombre])
            ->sortBy('nombre')
            ->all());
    }

    /**
     * @return Collection<int, Colaborador>
     */
    private function activos(): Collection
    {
        return $this->activos ??= Colaborador::query()
            ->where('estatus', 'activo')
            ->whereNotNull('puesto_id')
            ->with(['puesto:id,nombre,nivel_jerarquico,departamento_id', 'puesto.departamento:id,nombre', 'departamento:id,nombre', 'sucursalPrincipal:id,nombre'])
            ->get(['id', 'name', 'apellidos', 'puesto_id', 'sucursal_principal_id', 'departamento_id'])
            ->filter(fn (Colaborador $c): bool => $c->puesto !== null);
    }
}
