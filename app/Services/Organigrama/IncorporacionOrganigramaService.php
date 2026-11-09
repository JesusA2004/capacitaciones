<?php

namespace App\Services\Organigrama;

use App\Enums\EstadoCandidato;
use App\Enums\EstadoUsuario;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Services\Reclutamiento\FaseContratadoService;

/**
 * Personas que ya trabajan pero todavía no están «activas» y candidatos
 * autorizados que aún no tienen colaborador, para que el organigrama, las
 * láminas y las fichas de puesto no muestren VACANTE una plaza que ya
 * tiene a alguien (misma definición de «vigente» que el headcount:
 * EstadoUsuario::valoresVigentes()).
 *
 *  - fasesDe(): chip de la tarjeta de un colaborador en incorporación. Si
 *    viene de un candidato (candidatos.colaborador_id) se muestra su fase real
 *    del tablero (Contratación, Inducción, Evaluación de inducción…, la misma
 *    de FaseContratadoService); si no, «En incorporación».
 *  - porIngresar(): candidatos con la autorización final de RH ya registrada
 *    (estado AutorizadoRh, fase 6 «Contratación» del tablero) ligados a una
 *    vacante y SIN colaborador todavía (ContratacionCandidatoService crea el
 *    colaborador al iniciar la contratación). Su plaza sigue vacante en
 *    headcount, pero la tarjeta dice «Por ingresar: <nombre>». Un candidato en
 *    «Autorización de RH» todavía puede ser rechazado: no cuenta.
 */
class IncorporacionOrganigramaService
{
    public const ETIQUETA_GENERICA = 'En incorporación';

    /** Estados de un candidato que ya pasó la autorización final de RH. */
    private const ESTADOS_POR_INGRESAR = [EstadoCandidato::AutorizadoRh, EstadoCandidato::EnContratacion, EstadoCandidato::Contratado];

    public function __construct(private readonly FaseContratadoService $fases) {}

    /**
     * @param  iterable<Colaborador>  $colaboradores
     * @return array<int, string> colaborador_id => etiqueta del chip (solo los «en incorporación»)
     */
    public function fasesDe(iterable $colaboradores): array
    {
        $ids = [];

        foreach ($colaboradores as $colaborador) {
            if ($this->estaEnIncorporacion($colaborador)) {
                $ids[] = $colaborador->id;
            }
        }

        if ($ids === []) {
            return [];
        }

        $candidatos = Candidato::query()
            ->whereIn('colaborador_id', $ids)
            ->orderByDesc('id')
            ->get(['id', 'colaborador_id', 'estado']);
        $this->fases->precargar($candidatos);
        $etiquetas = EstadoCandidato::fases();
        $resultado = array_fill_keys($ids, self::ETIQUETA_GENERICA);

        foreach ($candidatos as $candidato) {
            $id = (int) $candidato->colaborador_id;

            if ($resultado[$id] !== self::ETIQUETA_GENERICA) {
                continue;
            }

            $resultado[$id] = $etiquetas[$candidato->fase] ?? self::ETIQUETA_GENERICA;
        }

        return $resultado;
    }

    /**
     * @return list<array{candidato_id: int, nombre: string, puesto_id: int, sucursal_id: int|null}>
     */
    public function porIngresar(): array
    {
        $candidatos = Candidato::query()
            ->whereIn('estado', array_map(fn (EstadoCandidato $e) => $e->value, self::ESTADOS_POR_INGRESAR))
            ->whereNull('colaborador_id')
            ->whereNotNull('vacante_id')
            ->whereHas('vacante')
            ->with('vacante:id,puesto_id,sucursal_id')
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'apellidos', 'vacante_id']);

        $lista = [];

        foreach ($candidatos as $candidato) {
            $vacante = $candidato->vacante;

            if ($vacante === null || $vacante->puesto_id === null) {
                continue;
            }

            $lista[] = [
                'candidato_id' => $candidato->id,
                'nombre' => trim(sprintf('%s %s', $candidato->nombre, $candidato->apellidos ?? '')),
                'puesto_id' => (int) $vacante->puesto_id,
                'sucursal_id' => $vacante->sucursal_id !== null ? (int) $vacante->sucursal_id : null,
            ];
        }

        return $lista;
    }

    public function estaEnIncorporacion(Colaborador $colaborador): bool
    {
        $estatus = $colaborador->getAttribute('estatus');

        return $estatus === EstadoUsuario::EnIncorporacion || $estatus === EstadoUsuario::EnIncorporacion->value;
    }
}
