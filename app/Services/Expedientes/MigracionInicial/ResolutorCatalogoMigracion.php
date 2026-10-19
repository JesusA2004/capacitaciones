<?php

namespace App\Services\Expedientes\MigracionInicial;

use App\Enums\TipoNodoComercial;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\NodoComercial;
use App\Models\Puesto;
use App\Models\Sucursal;
use Illuminate\Support\Collection;

/**
 * Traduce los nombres LEGACY de BASE_GENERAL (Empresa, Departamento,
 * Puesto) a los catálogos CANÓNICOS del sistema, sin fuzzy matching:
 *
 *   1. reglas de contexto (solo 3 nombres que por sí solos son ambiguos);
 *   2. alias explícitos de config/expedientes.php (migracion_inicial.alias_*);
 *   3. nombre igual normalizado (sin acentos/mayúsculas/signos).
 *
 * El organigrama confirmado (App\Services\Organigrama\SincronizadorOrganigramaService)
 * manda: el departamento del colaborador es el de su puesto canónico; el
 * departamento del Excel solo se valida (debe ser reconocible) y se audita.
 * Nunca crea catálogos. Lo que no se puede decidir se devuelve con motivo.
 */
class ResolutorCatalogoMigracion
{
    /** Ambiguos por sí solos: se resuelven con sucursal/región/departamento. */
    public const REGIONAL_OPERACIONES = 'REGIONAL DE OPERACIONES';

    public const ASISTENTE_DIRECCION = 'ASISTENTE DE DIRECCION';

    public const COORDINADORA_ADMINISTRATIVA = 'COORDINADORA ADMINISTRATIVA';

    /** Nombres cortos del directorio de gerencias (DIRECTORIO_GERENCIAS_MRLANA). */
    public const GERENTE_REGIONAL = 'GERENTE REGIONAL';

    public const GERENTE = 'GERENTE';

    public const COORDINADORA = 'COORDINADORA';

    private const CLAVE_CORPORATIVO = 'CORP01';

    /** @var Collection<int, Departamento>|null */
    private ?Collection $departamentos = null;

    /** @var Collection<int, Puesto>|null */
    private ?Collection $puestos = null;

    /** @var Collection<int, Empresa>|null */
    private ?Collection $empresas = null;

    /** @var array<int, string>|null sucursal_id => Q1|Q3 (matriz comercial) */
    private ?array $regionPorSucursal = null;

    /**
     * Catálogos que la carga inicial maestra CREARÁ desde su hoja
     * CATALOGO_PUESTOS (modelos sin guardar, id null). Solo existen durante
     * el análisis para que el preview no los reporte como conflicto; al
     * aplicar se crean de verdad y el plan se vuelve a armar con sus ids.
     *
     * @var list<Departamento>
     */
    private array $departamentosProvisionales = [];

    /** @var list<Puesto> */
    private array $puestosProvisionales = [];

    /**
     * @param  list<Departamento>  $departamentos
     * @param  list<Puesto>  $puestos
     */
    public function conProvisionales(array $departamentos, array $puestos): static
    {
        $this->departamentosProvisionales = $departamentos;
        $this->puestosProvisionales = $puestos;

        return $this->recargar();
    }

    public function sinProvisionales(): static
    {
        $this->departamentosProvisionales = [];
        $this->puestosProvisionales = [];

        return $this->recargar();
    }

    /** Vuelve a leer los catálogos (cada análisis parte de lo que hay en BD). */
    public function recargar(): static
    {
        $this->departamentos = Departamento::query()->get(['id', 'nombre'])->concat($this->departamentosProvisionales);
        $this->puestos = Puesto::query()->orderByDesc('activo')->get(['id', 'nombre', 'departamento_id', 'activo'])->concat($this->puestosProvisionales);
        $this->empresas = Empresa::query()->get(['id', 'nombre']);
        $this->regionPorSucursal = NodoComercial::query()
            ->where('tipo', TipoNodoComercial::Zona->value)
            ->whereNotNull('sucursal_id')
            ->whereNotNull('region')
            ->pluck('region', 'sucursal_id')
            ->map(fn ($r) => (string) $r)
            ->all();

        return $this;
    }

    public function empresa(?string $nombre): ?Empresa
    {
        $clave = Normalizador::clave($nombre);

        return $clave === '' ? null : $this->empresas()->first(fn (Empresa $e) => Normalizador::clave($e->nombre) === $clave);
    }

    public function departamento(?string $nombre): ?Departamento
    {
        if (Normalizador::clave($nombre) === '') {
            return null;
        }

        $buscado = $this->conAlias('alias_departamentos', (string) $nombre);

        return $this->departamentos()->first(fn (Departamento $d) => Normalizador::clave($d->nombre) === $buscado);
    }

    /**
     * @return array{puesto: Puesto|null, regla: string|null, motivo: string|null}
     */
    public function puesto(?string $nombre, ?Departamento $departamentoExcel, ?Sucursal $sucursal): array
    {
        $clave = Normalizador::clave($nombre);

        if ($clave === '') {
            return ['puesto' => null, 'regla' => null, 'motivo' => 'Falta «Puesto».'];
        }

        $contexto = $this->porContexto($clave, $departamentoExcel, $sucursal);

        if ($contexto !== null) {
            return $contexto;
        }

        $buscado = $this->conAlias('alias_puestos', (string) $nombre);
        $puesto = $this->porNombre($buscado);

        return $puesto !== null
            ? ['puesto' => $puesto, 'regla' => $buscado !== $clave ? 'alias' : 'exacto', 'motivo' => null]
            : ['puesto' => null, 'regla' => null, 'motivo' => sprintf('CONFLICTO: PUESTO NO ENCONTRADO «%s» (agrega el puesto o un alias explícito).', $nombre)];
    }

    /**
     * Región Q1/Q3 de la sucursal según la matriz comercial (zona → región).
     */
    public function regionDe(?Sucursal $sucursal): ?string
    {
        if ($sucursal === null) {
            return null;
        }

        if ($this->regionPorSucursal === null) {
            $this->recargar();
        }

        return ($this->regionPorSucursal ?? [])[$sucursal->id] ?? null;
    }

    /** ¿El nombre se resuelve por regla de contexto (sucursal/región/departamento) y no por catálogo? */
    public function esDeContexto(?string $nombre): bool
    {
        return in_array(Normalizador::clave($nombre), [self::REGIONAL_OPERACIONES, self::ASISTENTE_DIRECCION, self::COORDINADORA_ADMINISTRATIVA, self::GERENTE_REGIONAL, self::GERENTE, self::COORDINADORA], true);
    }

    /** Nombre ya traducido por alias explícito (normalizado), para auditar qué alias se aplicó. */
    public function aliasPuesto(?string $nombre): ?string
    {
        $clave = Normalizador::clave($nombre);
        $buscado = $this->conAlias('alias_puestos', (string) $nombre);

        return $clave !== '' && $buscado !== $clave ? ($this->porNombre($buscado)->nombre ?? null) : null;
    }

    /**
     * @return array{puesto: Puesto|null, regla: string|null, motivo: string|null}|null
     */
    private function porContexto(string $clave, ?Departamento $departamentoExcel, ?Sucursal $sucursal): ?array
    {
        $esCorporativo = $sucursal !== null && $sucursal->clave === self::CLAVE_CORPORATIVO;

        return match ($clave) {
            // El regional depende de la REGIÓN de su sucursal (matriz).
            self::REGIONAL_OPERACIONES, self::GERENTE_REGIONAL => (function () use ($sucursal, $esCorporativo): array {
                $region = $esCorporativo ? null : $this->regionDe($sucursal);

                return $region !== null && ($puesto = $this->porNombre(Normalizador::clave('Gerente Regional '.$region))) !== null
                    ? ['puesto' => $puesto, 'regla' => 'region_'.$region, 'motivo' => null]
                    : ['puesto' => null, 'regla' => null, 'motivo' => sprintf(
                        'CONFLICTO: «Regional de Operaciones» con sucursal %s: no se puede saber si es Gerente Regional Q1 o Q3 (la región sale de la sucursal en la matriz). Corrige su «Sucursal oficial» a una sucursal de su región o asígnale el puesto a mano.',
                        $sucursal->nombre ?? '(sin sucursal)',
                    )];
            })(),
            // Asistente: la Dirección General vive en «Dirección»; la Comercial en Ventas/Operaciones.
            self::ASISTENTE_DIRECCION => match (Normalizador::clave($departamentoExcel?->nombre)) {
                'DIRECCION' => $this->resultado('Asistente de Dirección General', 'departamento_direccion'),
                'VENTAS', 'OPERACIONES' => $this->resultado('Asistente de Dirección Comercial', 'departamento_comercial'),
                default => ['puesto' => null, 'regla' => null, 'motivo' => sprintf('CONFLICTO: «Asistente de Dirección» en departamento «%s»: no se puede saber si es de Dirección General o Comercial.', $departamentoExcel === null ? 'sin departamento' : $departamentoExcel->nombre)],
            },
            // Coordinadora de sucursal: una por sucursal; Corporativo no tiene (ahí es la Regional).
            self::COORDINADORA_ADMINISTRATIVA => $esCorporativo
                ? ['puesto' => null, 'regla' => null, 'motivo' => 'CONFLICTO: «Coordinadora Administrativa» en Corporativo: en el organigrama Corporativo no tiene Coordinadora de Sucursal (¿es la Coordinadora Regional / «Regional Administrativa»?).']
                : $this->resultado('Coordinadora de Sucursal', 'sucursal'),
            // «Coordinadora» a secas: en sucursal es la de sucursal; en
            // Corporativo, la única Coordinadora Regional del organigrama.
            self::COORDINADORA => $esCorporativo
                ? $this->resultado('Coordinadora Regional', 'corporativo')
                : $this->resultado('Coordinadora de Sucursal', 'sucursal'),
            // «Gerente» a secas en una sucursal = Gerente de Sucursal; en
            // Corporativo hay varias gerencias y no se adivina cuál.
            self::GERENTE => $esCorporativo || $sucursal === null
                ? ['puesto' => null, 'regla' => null, 'motivo' => 'CONFLICTO: «Gerente» sin sucursal operativa: en Corporativo hay varias gerencias; asígnale el puesto exacto.']
                : $this->resultado('Gerente de Sucursal', 'sucursal'),
            default => null,
        };
    }

    /**
     * @return array{puesto: Puesto|null, regla: string|null, motivo: string|null}
     */
    private function resultado(string $canonico, string $regla): array
    {
        $puesto = $this->porNombre(Normalizador::clave($canonico));

        return $puesto !== null
            ? ['puesto' => $puesto, 'regla' => $regla, 'motivo' => null]
            : ['puesto' => null, 'regla' => null, 'motivo' => sprintf('CONFLICTO: PUESTO NO ENCONTRADO «%s» (corre los seeders de catálogo).', $canonico)];
    }

    private function porNombre(string $clave): ?Puesto
    {
        // Activos primero (orderByDesc('activo') al cargar).
        return $this->puestos()->first(fn (Puesto $p) => Normalizador::clave($p->nombre) === $clave);
    }

    /** Valor del Excel ya traducido por un alias EXPLÍCITO de config, normalizado. */
    private function conAlias(string $config, string $valor): string
    {
        $alias = collect((array) config('expedientes.migracion_inicial.'.$config, []))
            ->mapWithKeys(fn ($destino, $origen) => [Normalizador::clave((string) $origen) => Normalizador::clave((string) $destino)]);

        return (string) $alias->get(Normalizador::clave($valor), Normalizador::clave($valor));
    }

    /**
     * @return Collection<int, Departamento>
     */
    private function departamentos(): Collection
    {
        return $this->departamentos ??= Departamento::query()->get(['id', 'nombre']);
    }

    /**
     * @return Collection<int, Puesto>
     */
    private function puestos(): Collection
    {
        return $this->puestos ??= Puesto::query()->orderByDesc('activo')->get(['id', 'nombre', 'departamento_id', 'activo']);
    }

    /**
     * @return Collection<int, Empresa>
     */
    private function empresas(): Collection
    {
        return $this->empresas ??= Empresa::query()->get(['id', 'nombre']);
    }
}
