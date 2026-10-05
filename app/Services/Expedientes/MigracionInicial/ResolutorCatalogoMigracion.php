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

    private const CLAVE_CORPORATIVO = 'CORP01';

    /** @var Collection<int, Departamento>|null */
    private ?Collection $departamentos = null;

    /** @var Collection<int, Puesto>|null */
    private ?Collection $puestos = null;

    /** @var Collection<int, Empresa>|null */
    private ?Collection $empresas = null;

    /** @var array<int, string>|null sucursal_id => Q1|Q3 (matriz comercial) */
    private ?array $regionPorSucursal = null;

    /** Vuelve a leer los catálogos (cada análisis parte de lo que hay en BD). */
    public function recargar(): static
    {
        $this->departamentos = Departamento::query()->get(['id', 'nombre']);
        $this->puestos = Puesto::query()->orderByDesc('activo')->get(['id', 'nombre', 'departamento_id', 'activo']);
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

    /**
     * @return array{puesto: Puesto|null, regla: string|null, motivo: string|null}|null
     */
    private function porContexto(string $clave, ?Departamento $departamentoExcel, ?Sucursal $sucursal): ?array
    {
        $esCorporativo = $sucursal !== null && $sucursal->clave === self::CLAVE_CORPORATIVO;

        return match ($clave) {
            // El regional depende de la REGIÓN de su sucursal (matriz).
            self::REGIONAL_OPERACIONES => (function () use ($sucursal, $esCorporativo): array {
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
