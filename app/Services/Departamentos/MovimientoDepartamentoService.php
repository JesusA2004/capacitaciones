<?php

namespace App\Services\Departamentos;

use App\Enums\EstadoUsuario;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Colaboradores\FotoColaboradorService;
use App\Services\Expedientes\DatosExpedienteService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * «Departamentos → mover personas»: lista quién está en un departamento y
 * cambia de departamento, en lote, a las personas seleccionadas.
 *
 * El cambio en sí NO se reimplementa: pasa por
 * DatosExpedienteService::cambiarDepartamentoEnLote(), la misma regla que la
 * pestaña «Datos laborales» del expediente (movimiento laboral «Cambio de
 * departamento» por persona + jefe derivado del organigrama).
 *
 * Reglas del lote (cada persona que no cumple se OMITE con su motivo, nunca
 * se ignora en silencio):
 *  - solo colaboradores vigentes (activo / en incorporación) que hoy estén en
 *    el departamento de origen;
 *  - dentro del alcance del módulo «Expedientes» de quien mueve;
 *  - nadie cambia su propio departamento;
 *  - idempotente: quien ya está en el destino se omite («Ya está en …») y no
 *    genera un movimiento duplicado.
 */
class MovimientoDepartamentoService
{
    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly DatosExpedienteService $datosExpediente,
        private readonly FotoColaboradorService $fotos,
    ) {}

    /**
     * Personas vigentes del departamento dentro del alcance de quien mira.
     *
     * @return list<array{id: int, nombre: string, numero_empleado: string|null, puesto: string|null, sucursal: string|null, foto_url: string|null, en_incorporacion: bool, movible: bool, motivo_no_movible: string|null}>
     */
    public function colaboradoresDe(Departamento $departamento, User $usuario): array
    {
        $consulta = Colaborador::query()
            ->where('departamento_id', $departamento->id)
            ->whereIn('estatus', EstadoUsuario::valoresVigentes())
            ->with(['puesto:id,nombre', 'sucursalPrincipal:id,nombre'])
            ->orderBy('name')
            ->orderBy('apellidos');

        $personas = [];

        foreach ($this->alcance->limitarExpedientesPorAlcance($consulta, $usuario)->get() as $colaborador) {
            $esUnoMismo = $usuario->colaborador_id === $colaborador->id;

            $personas[] = [
                'id' => (int) $colaborador->id,
                'nombre' => $colaborador->nombreCompleto(),
                'numero_empleado' => $colaborador->numero_empleado !== null ? (string) $colaborador->numero_empleado : null,
                'puesto' => $colaborador->puesto?->nombre,
                'sucursal' => $colaborador->sucursalPrincipal?->nombre,
                'foto_url' => $this->fotos->url($colaborador),
                'en_incorporacion' => $colaborador->estatus === EstadoUsuario::EnIncorporacion,
                'movible' => ! $esUnoMismo,
                'motivo_no_movible' => $esUnoMismo ? 'Eres tú: nadie cambia su propio departamento.' : null,
            ];
        }

        return $personas;
    }

    /**
     * Departamentos activos a los que se puede mover (todos menos el origen),
     * con cuántas personas vigentes tienen hoy.
     *
     * @return list<array{id: int, nombre: string, descripcion: string|null, personas: int}>
     */
    public function destinos(Departamento $origen): array
    {
        return array_values(Departamento::query()
            ->where('activo', true)
            ->whereKeyNot($origen->id)
            ->withCount(['colaboradores as personas' => fn (Builder $q) => $q->whereIn('estatus', EstadoUsuario::valoresVigentes())])
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'descripcion'])
            ->map(fn (Departamento $d): array => [
                'id' => (int) $d->id,
                'nombre' => (string) $d->nombre,
                'descripcion' => $d->descripcion,
                'personas' => (int) $d->getAttribute('personas'),
            ])
            ->all());
    }

    /**
     * @param  list<int>  $colaboradorIds
     * @return array{origen: string, destino: string, movidos: list<array{id: int, nombre: string}>, omitidos: list<array{id: int, nombre: string, motivo: string}>}
     */
    public function mover(Departamento $origen, Departamento $destino, array $colaboradorIds, User $actor, ?string $motivo = null): array
    {
        $ids = array_values(array_unique(array_map('intval', $colaboradorIds)));
        $encontrados = Colaborador::query()->whereKey($ids)->get()->keyBy('id');

        $aMover = [];
        $movidos = [];
        $omitidos = [];

        foreach ($ids as $id) {
            $colaborador = $encontrados->get($id);

            if (! $colaborador instanceof Colaborador) {
                $omitidos[] = ['id' => $id, 'nombre' => sprintf('#%d', $id), 'motivo' => 'No existe en el sistema.'];

                continue;
            }

            $razon = $this->motivoOmision($colaborador, $origen, $destino, $actor);

            if ($razon !== null) {
                $omitidos[] = ['id' => $id, 'nombre' => $colaborador->nombreCompleto(), 'motivo' => $razon];

                continue;
            }

            $aMover[] = $colaborador;
            $movidos[] = ['id' => $id, 'nombre' => $colaborador->nombreCompleto()];
        }

        $motivoMovimiento = $motivo !== null && trim($motivo) !== ''
            ? trim($motivo)
            : sprintf('Cambio de departamento en lote: %s → %s.', $origen->nombre, $destino->nombre);

        DB::transaction(fn () => $this->datosExpediente->cambiarDepartamentoEnLote($aMover, (int) $destino->id, $actor, $motivoMovimiento));

        return [
            'origen' => (string) $origen->nombre,
            'destino' => (string) $destino->nombre,
            'movidos' => $movidos,
            'omitidos' => $omitidos,
        ];
    }

    private function motivoOmision(Colaborador $colaborador, Departamento $origen, Departamento $destino, User $actor): ?string
    {
        if ($actor->colaborador_id === $colaborador->id) {
            return 'Nadie cambia su propio departamento.';
        }

        if ((int) $colaborador->departamento_id === (int) $destino->id) {
            return sprintf('Ya está en %s.', $destino->nombre);
        }

        if ((int) $colaborador->departamento_id !== (int) $origen->id) {
            return sprintf('Ya no pertenece a %s.', $origen->nombre);
        }

        if (! in_array($colaborador->estatus->value, EstadoUsuario::valoresVigentes(), true)) {
            return sprintf('No está activo (%s).', $colaborador->estatus->etiqueta());
        }

        if (! $this->alcance->puedeVerExpediente($actor, $colaborador)) {
            return 'Fuera de tu alcance.';
        }

        return null;
    }
}
