<?php

namespace App\Services\Organigrama;

use App\Enums\EstadoUsuario;
use App\Models\Colaborador;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Colaboradores\FotoColaboradorService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Organigrama en LÁMINAS, como el PDF oficial de Inducción (cap. 4, hojas
 * 17 · Corporativo, 18 · Dirección Comercial y 19 · Sucursal). La vista
 * GENERAL del frontend es la composición de estas tres (una sola verdad).
 *
 * - Estructura y textos: config('organigrama.laminas').
 * - Personas: colaboradores VIGENTES (activos + en incorporación, como el
 *   headcount; los en incorporación con su chip de fase) cuyo puesto coincide con el nombre
 *   exacto o un alias EXPLÍCITO del config (nunca comodines que atrapen
 *   subordinados, p. ej. «Asistente de Dirección Comercial» no es titular
 *   de Dirección Comercial).
 * - Una persona aparece en un solo lugar: el respaldo por departamento (solo
 *   si el puesto no aparece) nunca toma a quien ya tiene su lugar ni a quien
 *   tiene un puesto de otra caja.
 * - Puesto sin nadie: vacante (o «Por ingresar: <candidato>» si RH ya lo
 *   autorizó); caja cuyo puesto no existe en el catálogo: «Puesto no
 *   registrado» (`registrado` = false), nunca un nombre inventado.
 * - Foto: la URL segura de siempre (FotoColaboradorService), solo si quien
 *   mira puede ver ese expediente; si no, iniciales.
 */
class LaminasOrganigramaService
{
    /** @var Collection<int, Colaborador>|null */
    private ?Collection $activos = null;

    private ?User $usuario = null;

    /** @var array<int, bool> */
    private array $fotoPermitida = [];

    /** @var array<int, string>|null fase del chip por colaborador en incorporación */
    private ?array $fases = null;

    /** @var list<string>|null nombres de los puestos activos del catálogo */
    private ?array $catalogo = null;

    /** @var list<array{candidato_id: int, nombre: string, puesto_id: int, sucursal_id: int|null}>|null */
    private ?array $porIngresar = null;

    /** @var array<int, string>|null nombre de puesto por id (para «Por ingresar») */
    private ?array $nombresPuesto = null;

    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly FotoColaboradorService $fotos,
        private readonly IncorporacionOrganigramaService $incorporacion,
    ) {}

    /**
     * @return array{corporativo: array<string, mixed>, comercial: array<string, mixed>, sucursal: array<string, mixed>}
     */
    public function laminas(User $usuario): array
    {
        $this->usuario = $usuario;

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
            $titulares = [['id' => null, 'nombre' => (string) $dg['titular_documento'], 'sucursal' => null, 'foto_url' => null]];
        }

        $areas = (array) ($config['areas'] ?? []);
        $porArea = [];
        $usados = array_values(array_filter(array_column($titulares, 'id')));

        // 1) Por puesto exacto/alias.
        foreach ($areas as $i => $area) {
            $porArea[$i] = $this->personas((array) $area['puestos']);
            $usados = [...$usados, ...array_column($porArea[$i], 'id')];
        }

        // 2) Respaldo por departamento, sin repetir personas.
        foreach ($areas as $i => $area) {
            if ($porArea[$i] === []) {
                $porArea[$i] = $this->responsablesDe((array) ($area['departamentos'] ?? []), $usados);
                $usados = [...$usados, ...array_column($porArea[$i], 'id')];
            }
        }

        return [
            'direccion_general' => ['titulo' => 'Dirección General', 'personas' => $titulares, ...$this->estadoCaja((array) ($dg['puestos'] ?? []))],
            'areas' => array_map(fn (array $area, int $i): array => [
                'sigla' => $area['sigla'],
                'titulo' => $area['titulo'],
                'icono' => $area['icono'],
                'personas' => $porArea[$i],
                ...$this->estadoCaja((array) $area['puestos']),
            ], $areas, array_keys($areas)),
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
            ...$this->estadoCaja((array) $c['puestos']),
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
                'registrado' => $this->registrado((array) ($config[$b]['puestos'] ?? [])),
            ]])->all(),
            'sucursales' => $sucursales->map(fn (Sucursal $s): array => [
                'id' => $s->id,
                'nombre' => $s->nombre,
                ...collect($bloques)->mapWithKeys(fn (string $b): array => [
                    $b => $this->personas((array) ($config[$b]['puestos'] ?? []), $s->id),
                ])->all(),
                'por_ingresar' => collect($bloques)->mapWithKeys(fn (string $b): array => [
                    $b => $this->porIngresarEn((array) ($config[$b]['puestos'] ?? []), $s->id),
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
     * @return list<array{id: int|null, nombre: string, sucursal: string|null, foto_url: string|null, incorporacion?: string|null}>
     */
    private function personas(array $patrones, ?int $sucursalId = null): array
    {
        $regex = $this->regex($patrones);

        return array_values($this->activos()
            ->filter(fn (Colaborador $c): bool => ($sucursalId === null || $c->sucursal_principal_id === $sucursalId)
                && $c->puesto !== null
                && $this->coincide($regex, $c->puesto->nombre))
            ->map(fn (Colaborador $c): array => $this->persona($c))
            ->sortBy('nombre')
            ->all());
    }

    /**
     * Respaldo cuando el puesto del área no está en el catálogo: la persona
     * de MAYOR jerarquía del departamento, que no esté ya en otra caja ni
     * tenga un puesto que pertenezca a otra caja de las láminas.
     *
     * @param  array<mixed>  $departamentos
     * @param  list<int|null>  $usados
     * @return list<array{id: int|null, nombre: string, sucursal: string|null, foto_url: string|null, incorporacion?: string|null}>
     */
    private function responsablesDe(array $departamentos, array $usados): array
    {
        $buscados = array_map(fn (string $d): string => Str::lower(Str::ascii($d)), array_filter($departamentos, 'is_string'));

        if ($buscados === []) {
            return [];
        }

        $deOtraCaja = $this->regex($this->patronesDeLaminas());
        $delArea = $this->activos()->filter(function (Colaborador $c) use ($buscados, $usados, $deOtraCaja): bool {
            $departamento = $c->puesto->departamento->nombre ?? $c->departamento->nombre ?? null;

            return $departamento !== null
                && in_array(Str::lower(Str::ascii($departamento)), $buscados, true)
                && ! in_array($c->id, $usados, true)
                && ! $this->coincide($deOtraCaja, (string) $c->puesto?->nombre);
        });

        if ($delArea->isEmpty()) {
            return [];
        }

        $nivel = $delArea->min(fn (Colaborador $c): int => $c->puesto->nivel_jerarquico ?? 99);

        return array_values($delArea
            ->filter(fn (Colaborador $c): bool => ($c->puesto->nivel_jerarquico ?? 99) === $nivel)
            ->map(fn (Colaborador $c): array => $this->persona($c))
            ->sortBy('nombre')
            ->all());
    }

    /**
     * @return array{id: int, nombre: string, sucursal: string|null, foto_url: string|null, incorporacion: string|null}
     */
    private function persona(Colaborador $c): array
    {
        return [
            'id' => $c->id,
            'nombre' => $c->nombreCompleto(),
            'sucursal' => $c->sucursalPrincipal?->nombre,
            'foto_url' => $c->foto_path !== null && $this->puedeVerFoto($c) ? $this->fotos->url($c) : null,
            'incorporacion' => $this->fasesIncorporacion()[$c->id] ?? null,
        ];
    }

    private function puedeVerFoto(Colaborador $c): bool
    {
        if ($this->usuario === null) {
            return false;
        }

        return $this->fotoPermitida[$c->id] ??= $this->alcance->puedeVerExpediente($this->usuario, $c);
    }

    /**
     * Todos los puestos que las láminas ya ubican en alguna caja.
     *
     * @return list<string>
     */
    private function patronesDeLaminas(): array
    {
        $patrones = [];
        $recorrer = function (mixed $nodo) use (&$recorrer, &$patrones): void {
            if (! is_array($nodo)) {
                return;
            }
            if (isset($nodo['puestos']) && is_array($nodo['puestos'])) {
                $patrones = [...$patrones, ...array_filter($nodo['puestos'], 'is_string')];
            }
            foreach ($nodo as $hijo) {
                $recorrer($hijo);
            }
        };
        $recorrer((array) config('organigrama.laminas.comercial'));
        $recorrer((array) config('organigrama.laminas.sucursal'));

        return array_values($patrones);
    }

    /**
     * @param  array<mixed>  $patrones
     * @return list<string>
     */
    private function regex(array $patrones): array
    {
        // Sin distinguir acentos ni mayúsculas: «Contraloria» = «Contraloría».
        return array_values(array_map(fn (string $p): string => '/^'.str_replace('%', '.*', preg_quote(Str::ascii(trim($p)), '/')).'$/i', array_filter($patrones, 'is_string')));
    }

    /**
     * @param  list<string>  $regex
     */
    private function coincide(array $regex, string $puesto): bool
    {
        $nombre = Str::ascii(trim($puesto));

        foreach ($regex as $r) {
            if (preg_match($r, $nombre) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Estado de una caja: ¿su puesto existe en el catálogo? y ¿hay
     * candidatos autorizados por RH que ya vienen en camino?
     *
     * @param  array<mixed>  $patrones
     * @return array{registrado: bool, por_ingresar: list<string>}
     */
    private function estadoCaja(array $patrones): array
    {
        return [
            'registrado' => $this->registrado($patrones),
            'por_ingresar' => $this->porIngresarEn($patrones, null),
        ];
    }

    /**
     * @param  array<mixed>  $patrones
     */
    private function registrado(array $patrones): bool
    {
        $regex = $this->regex($patrones);
        $this->catalogo ??= array_values(Puesto::query()->where('activo', true)->pluck('nombre')->map(fn ($n): string => (string) $n)->all());

        foreach ($this->catalogo as $nombre) {
            if ($this->coincide($regex, $nombre)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Candidatos autorizados por RH, sin colaborador todavía, cuya vacante es
     * de un puesto de esta caja (y de esta sucursal, si se indica).
     *
     * @param  array<mixed>  $patrones
     * @return list<string>
     */
    private function porIngresarEn(array $patrones, ?int $sucursalId): array
    {
        $this->porIngresar ??= $this->incorporacion->porIngresar();

        if ($this->porIngresar === []) {
            return [];
        }

        $this->nombresPuesto ??= Puesto::query()->pluck('nombre', 'id')->map(fn ($n): string => (string) $n)->all();
        $regex = $this->regex($patrones);
        $nombres = [];

        foreach ($this->porIngresar as $candidato) {
            $puesto = $this->nombresPuesto[$candidato['puesto_id']] ?? null;

            if ($puesto !== null && ($sucursalId === null || $candidato['sucursal_id'] === $sucursalId) && $this->coincide($regex, $puesto)) {
                $nombres[] = $candidato['nombre'];
            }
        }

        return $nombres;
    }

    /**
     * @return array<int, string>
     */
    private function fasesIncorporacion(): array
    {
        return $this->fases ??= $this->incorporacion->fasesDe($this->activos());
    }

    /**
     * @return Collection<int, Colaborador>
     */
    private function activos(): Collection
    {
        return $this->activos ??= Colaborador::query()
            ->whereIn('estatus', EstadoUsuario::valoresVigentes())
            ->whereNotNull('puesto_id')
            ->with(['puesto:id,nombre,nivel_jerarquico,departamento_id', 'puesto.departamento:id,nombre', 'departamento:id,nombre', 'sucursalPrincipal:id,nombre'])
            ->get(['id', 'name', 'apellidos', 'puesto_id', 'sucursal_principal_id', 'departamento_id', 'foto_path', 'jefe_id', 'gerente_id', 'updated_at', 'estatus'])
            ->filter(fn (Colaborador $c): bool => $c->puesto !== null);
    }
}
