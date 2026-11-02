<?php

namespace App\Services\Puestos;

use App\Enums\EstadoUsuario;
use App\Models\Colaborador;
use App\Models\Puesto;
use App\Models\TabuladorSalarial;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Colaboradores\FotoColaboradorService;
use App\Services\Expedientes\DatosExpedienteService;
use App\Services\Salarios\TabuladorSalarialService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * «Puestos y salarios → trasladar personas»: quién ocupa hoy un puesto y el
 * cambio de puesto, en lote, de las personas seleccionadas a otro puesto.
 *
 * El cambio en sí NO se reimplementa: pasa por
 * DatosExpedienteService::cambiarPuestoEnLote(), la misma regla que la
 * pestaña «Datos laborales» del expediente (movimiento laboral por persona,
 * jefe derivado del organigrama; ColaboradorPlantillaObserver resincroniza
 * headcount y vacantes del puesto de origen y del destino).
 *
 * Reglas del lote (cada persona que no cumple se OMITE con su motivo, nunca
 * se ignora en silencio):
 *  - solo colaboradores vigentes (activo / en incorporación) que hoy estén en
 *    el puesto de origen;
 *  - dentro del alcance del módulo «Expedientes» de quien traslada;
 *  - nadie cambia su propio puesto;
 *  - idempotente: quien ya está en el destino se omite y no genera un
 *    movimiento duplicado.
 */
class MovimientoPuestoService
{
    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly DatosExpedienteService $datosExpediente,
        private readonly FotoColaboradorService $fotos,
        private readonly TabuladorSalarialService $tabulador,
    ) {}

    /**
     * Personas vigentes del puesto dentro del alcance de quien mira.
     *
     * @return list<array{id: int, nombre: string, numero_empleado: string|null, sucursal: string|null, departamento: string|null, foto_url: string|null, en_incorporacion: bool, fecha_ingreso: string|null, movible: bool, motivo_no_movible: string|null}>
     */
    public function colaboradoresDe(Puesto $puesto, User $usuario): array
    {
        $consulta = Colaborador::query()
            ->where('puesto_id', $puesto->id)
            ->whereIn('estatus', EstadoUsuario::valoresVigentes())
            ->with(['sucursalPrincipal:id,nombre', 'departamento:id,nombre'])
            ->orderBy('name')
            ->orderBy('apellidos');

        $personas = [];

        foreach ($this->alcance->limitarExpedientesPorAlcance($consulta, $usuario)->get() as $colaborador) {
            $esUnoMismo = $usuario->colaborador_id === $colaborador->id;

            $personas[] = [
                'id' => (int) $colaborador->id,
                'nombre' => $colaborador->nombreCompleto(),
                'numero_empleado' => $colaborador->numero_empleado !== null ? (string) $colaborador->numero_empleado : null,
                'sucursal' => $colaborador->sucursalPrincipal?->nombre,
                'departamento' => $colaborador->departamento?->nombre,
                'foto_url' => $this->fotos->url($colaborador),
                'en_incorporacion' => $colaborador->estatus === EstadoUsuario::EnIncorporacion,
                'fecha_ingreso' => $colaborador->fecha_ingreso?->toDateString(),
                'movible' => ! $esUnoMismo,
                'motivo_no_movible' => $esUnoMismo ? 'Eres tú: nadie cambia su propio puesto.' : null,
            ];
        }

        return $personas;
    }

    /**
     * Puestos activos a los que se puede trasladar (todos menos el origen),
     * con cuántas personas vigentes tienen hoy y, si quien mira ve salarios,
     * su rango vigente del tabulador (para mostrar cómo queda cada sueldo).
     *
     * @return list<array{id: int, nombre: string, departamento: string|null, personas: int, rango: array{minimo: float, medio: float, maximo: float}|null}>
     */
    public function destinos(Puesto $origen, bool $conRango): array
    {
        $vigentes = $conRango ? $this->tabulador->vigentes() : collect();

        return array_values(Puesto::query()
            ->where('activo', true)
            ->whereKeyNot($origen->id)
            ->with('departamento:id,nombre')
            ->withCount(['colaboradores as personas' => fn (Builder $q) => $q->whereIn('estatus', EstadoUsuario::valoresVigentes())])
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'departamento_id'])
            ->map(function (Puesto $p) use ($vigentes): array {
                $rango = $vigentes->get($p->id);

                return [
                    'id' => (int) $p->id,
                    'nombre' => (string) $p->nombre,
                    'departamento' => $p->departamento?->nombre,
                    'personas' => (int) $p->getAttribute('personas'),
                    'rango' => $rango instanceof TabuladorSalarial
                        ? ['minimo' => (float) $rango->minimo, 'medio' => (float) $rango->medio, 'maximo' => (float) $rango->maximo]
                        : null,
                ];
            })
            ->all());
    }

    /**
     * @param  list<int>  $colaboradorIds
     * @return array{origen: string, destino: string, movidos: list<array{id: int, nombre: string}>, omitidos: list<array{id: int, nombre: string, motivo: string}>}
     */
    public function mover(Puesto $origen, Puesto $destino, array $colaboradorIds, User $actor, ?string $motivo = null): array
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
            : sprintf('Cambio de puesto en lote: %s → %s.', $origen->nombre, $destino->nombre);

        DB::transaction(fn () => $this->datosExpediente->cambiarPuestoEnLote(
            $aMover,
            (int) $destino->id,
            $destino->departamento_id !== null ? (int) $destino->departamento_id : null,
            $actor,
            $motivoMovimiento,
        ));

        return [
            'origen' => (string) $origen->nombre,
            'destino' => (string) $destino->nombre,
            'movidos' => $movidos,
            'omitidos' => $omitidos,
        ];
    }

    private function motivoOmision(Colaborador $colaborador, Puesto $origen, Puesto $destino, User $actor): ?string
    {
        if ($actor->colaborador_id === $colaborador->id) {
            return 'Nadie cambia su propio puesto.';
        }

        if ((int) $colaborador->puesto_id === (int) $destino->id) {
            return sprintf('Ya es %s.', $destino->nombre);
        }

        if ((int) $colaborador->puesto_id !== (int) $origen->id) {
            return sprintf('Ya no ocupa el puesto %s.', $origen->nombre);
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
