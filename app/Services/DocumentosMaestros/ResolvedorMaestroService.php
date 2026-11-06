<?php

namespace App\Services\DocumentosMaestros;

use App\Exceptions\DocumentoMaestroFaltanteException;
use App\Models\Colaborador;
use App\Models\DocumentTemplate;
use Illuminate\Support\Collection;

/**
 * Elige el documento maestro correcto para una persona, sin adivinar:
 *
 *   1. puesto exacto (+ empresa)
 *   2. grupo de puesto (+ empresa)
 *   3. formato general, cuando RH decidió que para ESE tipo de documento y
 *      grupo la general va antes que la variante cercana
 *      (config documentos_maestros.fallback_general).
 *   4. variante del grupo MÁS CERCANO (config documentos_maestros.
 *      fallback_cercano.equivalencias, en orden de preferencia: un
 *      Subgerente sin formato propio usa el de Gerente). Un Gestor nunca
 *      recibe el de Gerente: su lista de equivalencias está vacía.
 *   5. formato general, como último recurso (fallback_cercano.general).
 *   6. nada → DocumentoMaestroFaltanteException (422 DOCUMENT_TEMPLATE_MISSING)
 *
 * En todos los casos el documento se llena con el puesto y la sucursal de la
 * PERSONA (DatosDocumentoService), nunca con los del formato prestado; la
 * pantalla muestra cuál se usó con respaldoPara() («Usa el de Gerente…»).
 * Solo masters activos y listos. Un mismo servicio para web y API móvil.
 */
class ResolvedorMaestroService
{
    /** Pesos por nivel: separados por miles para que el bono de empresa nunca cruce de nivel. */
    private const PUNTOS_PUESTO = 4000;

    private const PUNTOS_GRUPO = 3000;

    private const PUNTOS_GENERAL_PREFERIDA = 2500;

    private const PUNTOS_CERCANO = 2000;

    private const PUNTOS_GENERAL = 1000;

    private const BONO_EMPRESA = 100;

    public function __construct(private readonly CatalogoMaestrosService $catalogo) {}

    /**
     * true si la clave la manejan documentos maestros (y no una plantilla
     * heredada de las que RH capturaba a mano).
     */
    public function esClaveMaestra(string $clave): bool
    {
        // Solo claves con al menos un master en el registro. Las que el flujo
        // pide sin formato de Jurídico (periodo de prueba, responsiva…)
        // siguen usando la plantilla heredada si RH tiene una activa.
        foreach ($this->catalogo->definiciones() as $definicion) {
            if (($definicion['clave'] ?? null) === $clave && ($definicion['operativo'] ?? true)) {
                // Transición: mientras no se haya importado ningún master de
                // esta clave (people:importar-formatos-juridicos), se usa la
                // plantilla que RH ya tenía cargada. En cuanto existe uno, la
                // resolución es estricta (variante correcta, respaldo
                // configurado o 422).
                return DocumentTemplate::query()->where('clave', $clave)->whereNotNull('estado_master')->exists();
            }
        }

        return false;
    }

    /**
     * true si el flujo pide esta clave (paquete o causa), tenga o no master.
     */
    public function esClaveDeFlujo(string $clave): bool
    {
        return in_array($clave, $this->clavesDeFlujo(), true);
    }

    public function resolver(string $clave, Colaborador $colaborador, ?string $proceso = null): DocumentTemplate
    {
        $encontrado = $this->buscar($clave, $colaborador);

        if ($encontrado !== null) {
            return $encontrado;
        }

        $colaborador->loadMissing(['puesto', 'sucursalPrincipal.empresa']);
        $grupo = $colaborador->puesto?->grupo_documental;

        throw new DocumentoMaestroFaltanteException([
            'documento' => $this->catalogo->nombreClave($clave),
            'clave' => $clave,
            'puesto' => $colaborador->puesto?->nombre,
            'grupo' => $this->catalogo->etiquetaGrupo($grupo),
            'empresa' => $colaborador->sucursalPrincipal?->empresa?->nombre,
            'proceso' => $this->catalogo->etiquetaProceso($proceso),
        ]);
    }

    public function buscar(string $clave, Colaborador $colaborador): ?DocumentTemplate
    {
        $colaborador->loadMissing(['puesto', 'sucursalPrincipal']);

        return $this->buscarPara($clave, $colaborador->puesto_id, $colaborador->puesto?->grupo_documental, $colaborador->sucursalPrincipal?->empresa_id);
    }

    /**
     * Misma resolución sin colaborador concreto (cobertura documental por
     * puesto).
     */
    public function buscarPara(string $clave, ?int $puestoId, ?string $grupo, ?int $empresaId): ?DocumentTemplate
    {
        $mejor = $this->candidatosPara($clave, $puestoId, $grupo, $empresaId)->first();

        return is_array($mejor) ? $mejor['master'] : null;
    }

    /**
     * Cómo se resolvió: 'puesto' | 'grupo' | 'cercano' | 'general' | null.
     */
    public function nivelPara(string $clave, ?int $puestoId, ?string $grupo, ?int $empresaId): ?string
    {
        $mejor = $this->candidatosPara($clave, $puestoId, $grupo, $empresaId)->first();

        return is_array($mejor) ? $mejor['nivel'] : null;
    }

    /**
     * Texto para la pantalla cuando el master elegido NO es el propio del
     * puesto de la persona («No hay formato propio de Subgerente: usa el de
     * Gerente de sucursal»). null = es su formato (o el tipo de documento
     * solo tiene versión general, como la solicitud de empleo).
     */
    public function respaldoPara(DocumentTemplate $master, Colaborador $colaborador): ?string
    {
        $colaborador->loadMissing('puesto');

        return $this->respaldoDe($master, $colaborador->puesto_id, $colaborador->puesto?->grupo_documental, $colaborador->puesto?->nombre);
    }

    /**
     * Igual que respaldoPara(), sin colaborador (cobertura por puesto).
     */
    public function respaldoDe(DocumentTemplate $master, ?int $puestoId, ?string $grupo, ?string $nombrePuesto = null): ?string
    {
        if ($master->puesto_id !== null) {
            return null;
        }

        $grupos = $this->gruposDe($master);

        if ($grupo !== null && in_array($grupo, $grupos, true)) {
            return null;
        }

        $propio = $this->catalogo->etiquetaGrupo($grupo) ?? $nombrePuesto ?? 'este puesto';

        if ($grupos !== []) {
            return sprintf(
                'No hay formato propio de %s: usa el de %s, con el puesto y la sucursal de la persona.',
                $propio,
                implode(' / ', array_map(fn (string $g): string => (string) $this->catalogo->etiquetaGrupo($g), $grupos)),
            );
        }

        // Formato general: solo se avisa si el tipo de documento tiene
        // variantes por puesto (si no, la general ES el formato de todos).
        return $this->tieneVariantes((string) $master->clave)
            ? sprintf('No hay formato propio de %s: usa el general, con el puesto y la sucursal de la persona.', $propio)
            : null;
    }

    /**
     * Grupos "más cercanos" de un grupo, en orden de preferencia.
     *
     * @return list<string>
     */
    public function equivalentesDe(?string $grupo): array
    {
        if ($grupo === null || ! $this->fallbackCercanoHabilitado()) {
            return [];
        }

        $equivalencias = (array) config('documentos_maestros.fallback_cercano.equivalencias', []);

        return array_values(array_filter((array) ($equivalencias[$grupo] ?? []), fn (mixed $g): bool => is_string($g) && $g !== $grupo));
    }

    /**
     * @return Collection<int, array{master: DocumentTemplate, puntos: int, nivel: string}>
     */
    private function candidatosPara(string $clave, ?int $puestoId, ?string $grupo, ?int $empresaId): Collection
    {
        /** @var Collection<int, DocumentTemplate> $candidatos */
        $candidatos = DocumentTemplate::query()
            ->where('clave', $clave)
            ->whereNotNull('estado_master')
            ->where('estado_master', 'listo')
            ->where('activo', true)
            ->where('operativo', true)
            ->get();

        return $candidatos
            ->map(fn (DocumentTemplate $m): ?array => $this->evaluar($m, $empresaId, $puestoId, $grupo, $clave))
            ->filter(fn (?array $c): bool => $c !== null)
            ->sortByDesc('puntos')
            ->values();
    }

    /**
     * null = no aplica a esta persona.
     *
     * @return array{master: DocumentTemplate, puntos: int, nivel: string}|null
     */
    private function evaluar(DocumentTemplate $master, ?int $empresaId, ?int $puestoId, ?string $grupo, string $clave): ?array
    {
        if ($master->empresa_id !== null && $master->empresa_id !== $empresaId) {
            return null;
        }

        $deEmpresa = $master->empresa_id !== null ? self::BONO_EMPRESA : 0;
        $resultado = fn (int $puntos, string $nivel): array => ['master' => $master, 'puntos' => $puntos + $deEmpresa, 'nivel' => $nivel];

        if ($master->puesto_id !== null) {
            return $master->puesto_id === $puestoId ? $resultado(self::PUNTOS_PUESTO, 'puesto') : null;
        }

        $grupos = $this->gruposDe($master);

        if ($grupos !== []) {
            if ($grupo !== null && in_array($grupo, $grupos, true)) {
                return $resultado(self::PUNTOS_GRUPO, 'grupo');
            }

            // Variante de un grupo cercano: el primero de la lista gana.
            foreach ($this->equivalentesDe($grupo) as $indice => $equivalente) {
                if (in_array($equivalente, $grupos, true)) {
                    return $resultado(self::PUNTOS_CERCANO - $indice, 'cercano');
                }
            }

            return null;
        }

        // Formato general.
        if ($this->generalPreferida($clave, $grupo)) {
            return $resultado(self::PUNTOS_GENERAL_PREFERIDA, 'general');
        }

        return $this->permiteGeneral($clave) ? $resultado(self::PUNTOS_GENERAL, 'general') : null;
    }

    /**
     * RH decidió que para este tipo de documento y grupo se firma la
     * general antes que una variante cercana (p. ej. el Gerente regional
     * firma la confidencialidad general).
     */
    private function generalPreferida(string $clave, ?string $grupo): bool
    {
        if (! $this->tieneVariantes($clave)) {
            return true;
        }

        $permitidos = (array) (config('documentos_maestros.fallback_general', [])[$clave] ?? []);

        return in_array('*', $permitidos, true) || ($grupo !== null && in_array($grupo, $permitidos, true));
    }

    /**
     * La general como último recurso (sin propio ni cercano).
     */
    private function permiteGeneral(string $clave): bool
    {
        return ! $this->tieneVariantes($clave) || (bool) config('documentos_maestros.fallback_cercano.general', true);
    }

    private function fallbackCercanoHabilitado(): bool
    {
        return (bool) config('documentos_maestros.fallback_cercano.habilitado', true);
    }

    private function tieneVariantes(string $clave): bool
    {
        return DocumentTemplate::query()
            ->where('clave', $clave)
            ->whereNotNull('estado_master')
            ->whereNotNull('grupos_puesto')
            ->exists();
    }

    /**
     * @return list<string>
     */
    private function gruposDe(DocumentTemplate $master): array
    {
        return array_values(array_filter((array) ($master->grupos_puesto ?? []), 'is_string'));
    }

    /**
     * @return list<string>
     */
    private function clavesDeFlujo(): array
    {
        $claves = [];
        $paquetes = (array) config('documentos_maestros.paquetes', []);

        array_walk_recursive($paquetes, function (mixed $valor) use (&$claves): void {
            if (is_string($valor)) {
                $claves[] = $valor;
            }
        });

        foreach ((array) config('documentos_maestros.documentos_por_causa', []) as $lista) {
            foreach ((array) $lista as $clave) {
                $claves[] = (string) $clave;
            }
        }

        return array_values(array_unique([...$claves, 'carta_responsiva']));
    }
}
