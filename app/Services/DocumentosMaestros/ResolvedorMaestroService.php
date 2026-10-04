<?php

namespace App\Services\DocumentosMaestros;

use App\Exceptions\DocumentoMaestroFaltanteException;
use App\Models\Colaborador;
use App\Models\DocumentTemplate;
use Illuminate\Support\Collection;

/**
 * Elige el documento maestro correcto para una persona, sin adivinar:
 *
 *   1. puesto exacto + empresa
 *   2. puesto exacto (global)
 *   3. grupo de puesto + empresa
 *   4. grupo de puesto (global)
 *   5. formato general — solo si el tipo de documento no tiene variantes
 *      por grupo, o si config documentos_maestros.fallback_general lo
 *      permite para ese grupo.
 *   6. nada → DocumentoMaestroFaltanteException (422 DOCUMENT_TEMPLATE_MISSING)
 *
 * Solo masters activos y listos. Nunca se usa el contrato de un Gestor para
 * un Gerente.
 */
class ResolvedorMaestroService
{
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
                // resolución es estricta (variante correcta o 422).
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
        $empresaId = $colaborador->sucursalPrincipal?->empresa_id;
        $puestoId = $colaborador->puesto_id;
        $grupo = $colaborador->puesto?->grupo_documental;

        /** @var Collection<int, DocumentTemplate> $candidatos */
        $candidatos = DocumentTemplate::query()
            ->where('clave', $clave)
            ->whereNotNull('estado_master')
            ->where('estado_master', 'listo')
            ->where('activo', true)
            ->where('operativo', true)
            ->get();

        $puntuados = $candidatos
            ->map(fn (DocumentTemplate $m): array => ['master' => $m, 'puntos' => $this->puntos($m, $empresaId, $puestoId, $grupo, $clave)])
            ->filter(fn (array $c): bool => $c['puntos'] > 0)
            ->sortByDesc('puntos');

        $mejor = $puntuados->first();

        return is_array($mejor) ? $mejor['master'] : null;
    }

    /**
     * 0 = no aplica a esta persona.
     */
    private function puntos(DocumentTemplate $master, ?int $empresaId, ?int $puestoId, ?string $grupo, string $clave): int
    {
        if ($master->empresa_id !== null && $master->empresa_id !== $empresaId) {
            return 0;
        }

        $deEmpresa = $master->empresa_id !== null ? 5 : 0;

        if ($master->puesto_id !== null) {
            return $master->puesto_id === $puestoId ? 40 + $deEmpresa : 0;
        }

        $grupos = array_values(array_filter((array) ($master->grupos_puesto ?? []), 'is_string'));

        if ($grupos !== []) {
            return $grupo !== null && in_array($grupo, $grupos, true) ? 20 + $deEmpresa : 0;
        }

        // Formato general.
        return $this->permiteGeneral($clave, $grupo) ? 10 + $deEmpresa : 0;
    }

    private function permiteGeneral(string $clave, ?string $grupo): bool
    {
        $tieneVariantes = DocumentTemplate::query()
            ->where('clave', $clave)
            ->whereNotNull('estado_master')
            ->whereNotNull('grupos_puesto')
            ->exists();

        if (! $tieneVariantes) {
            return true;
        }

        $permitidos = (array) (config('documentos_maestros.fallback_general', [])[$clave] ?? []);

        return in_array('*', $permitidos, true) || ($grupo !== null && in_array($grupo, $permitidos, true));
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
