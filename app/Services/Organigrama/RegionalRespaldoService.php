<?php

namespace App\Services\Organigrama;

use App\Enums\EstadoUsuario;
use App\Enums\TipoNodoComercial;
use App\Models\CoberturaPuesto;
use App\Models\Colaborador;
use App\Models\NodoComercial;
use App\Models\Puesto;
use App\Services\Expedientes\MigracionInicial\Normalizador;

/**
 * Regional de RESPALDO: a quién reporta (y quién aprueba a) el personal de
 * sucursal cuando la región de su sucursal no tiene Gerente Regional
 * (ni titular ni «Cubre temporalmente»).
 *
 * Regla del dueño (2026-10-21): un Gestor, Subgerente, Gerente de Sucursal
 * o cualquier puesto de sucursal NUNCA reporta a Dirección Comercial. Su
 * jefe es la gerencia de su sucursal; si está vacante, el regional de su
 * región; si tampoco hay, la regional de respaldo que elige esta clase.
 * Solo si no existe NINGÚN regional queda sin jefe («sin jefe asignable»).
 *
 * Orden determinista (config/organigrama.php → `regional_respaldo`):
 *  1. la persona fijada por RH/sistemas (`colaborador`: id o número de
 *     empleado, env ORGANIGRAMA_REGIONAL_RESPALDO), si está vigente;
 *  2. titulares vigentes de un puesto regional (los ligados a una región
 *     de la matriz + `puestos` de config), por id ascendente;
 *  3. quienes cubren temporalmente una plaza regional, por id de cobertura.
 *
 * Con un solo regional en la empresa (hoy Mónica García Cancino) siempre
 * es ella, sin importar de qué región sea titular.
 */
class RegionalRespaldoService
{
    /**
     * Ids de puestos regionales: los que la matriz liga a una región
     * (nodos_comerciales.puesto_id) y los nombrados en config.
     *
     * @return list<int>
     */
    public function puestos(): array
    {
        $ligados = NodoComercial::query()
            ->where('tipo', TipoNodoComercial::Region->value)
            ->whereNotNull('puesto_id')
            ->pluck('puesto_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        /** @var list<string> $nombres */
        $nombres = (array) config('organigrama.regional_respaldo.puestos', []);
        $claves = array_map(fn (string $n) => Normalizador::clave($n), $nombres);
        $porNombre = Puesto::query()
            ->get(['id', 'nombre'])
            ->filter(fn (Puesto $p) => in_array(Normalizador::clave($p->nombre), $claves, true))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_unique([...$ligados, ...$porNombre]));
    }

    /**
     * Candidatos en orden de preferencia (ids de colaborador vigentes).
     *
     * @return list<int>
     */
    public function candidatos(): array
    {
        $puestos = $this->puestos();
        $vigentes = EstadoUsuario::valoresVigentes();
        $ids = [];

        $fijo = config('organigrama.regional_respaldo.colaborador');

        if (is_scalar($fijo) && trim((string) $fijo) !== '') {
            $valor = trim((string) $fijo);
            $persona = Colaborador::query()
                ->whereIn('estatus', $vigentes)
                ->where(fn ($q) => $q->where('numero_empleado', $valor)->when(ctype_digit($valor), fn ($q) => $q->orWhere('id', (int) $valor)))
                ->orderBy('id')
                ->first(['id']);

            if ($persona !== null) {
                $ids[] = $persona->id;
            }
        }

        if ($puestos !== []) {
            foreach (Colaborador::query()->whereIn('estatus', $vigentes)->whereIn('puesto_id', $puestos)->orderBy('id')->pluck('id') as $id) {
                $ids[] = (int) $id;
            }

            $coberturas = CoberturaPuesto::query()
                ->where('activa', true)
                ->whereIn('puesto_id', $puestos)
                ->whereHas('colaborador', fn ($q) => $q->where('estatus', EstadoUsuario::Activo->value))
                ->orderBy('id')
                ->pluck('colaborador_id');

            foreach ($coberturas as $id) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Primer candidato que no sea `$excluirId` (nadie es su propio respaldo).
     *
     * @param  list<int>|null  $candidatos  lista ya calculada (evita repetir consultas en un barrido)
     */
    public function primero(?int $excluirId, ?array $candidatos = null): ?int
    {
        foreach ($candidatos ?? $this->candidatos() as $id) {
            if ($id !== $excluirId) {
                return $id;
            }
        }

        return null;
    }
}
