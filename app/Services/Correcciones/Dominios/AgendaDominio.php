<?php

namespace App\Services\Correcciones\Dominios;

use App\Enums\EstadoAgendaEvento;
use App\Enums\ModoCorreccion;
use App\Models\AgendaEvento;
use App\Models\Colaborador;
use App\Models\User;
use App\Services\Reclutamiento\AgendaEventoService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Corregir fecha y hora es reprogramar: la cita anterior se conserva y se crea
 * otra (nueva versión). Las notas se corrigen en sitio con bitácora.
 */
class AgendaDominio extends DominioBase
{
    public function __construct(private readonly AgendaEventoService $agenda) {}

    public function clave(): string
    {
        return 'agenda';
    }

    public function sujeto(int $id, bool $bloquear = false): ?Model
    {
        return AgendaEvento::query()->whereKey($id)->when($bloquear, fn ($q) => $q->lockForUpdate())->first();
    }

    public function etiquetaSujeto(Model $sujeto): string
    {
        return $sujeto instanceof AgendaEvento ? sprintf('%s del %s', $sujeto->tipo->etiqueta(), $sujeto->fecha_hora->format('d/m/Y H:i')) : '';
    }

    public function colaboradorDe(Model $sujeto): ?Colaborador
    {
        return null;
    }

    public function puedeLeer(User $actor, Model $sujeto): bool
    {
        return $actor->can('view', $sujeto);
    }

    public function puedeEscribir(User $actor, Model $sujeto): bool
    {
        return $actor->can('gestionar', $sujeto);
    }

    protected function valor(Model $sujeto, string $campo): mixed
    {
        if ($campo === 'fecha_hora' && $sujeto instanceof AgendaEvento) {
            return $sujeto->fecha_hora->format('Y-m-d H:i');
        }

        return parent::valor($sujeto, $campo);
    }

    protected function restriccion(Model $sujeto, string $campo): ?array
    {
        if (! $sujeto instanceof AgendaEvento) {
            return null;
        }

        if ($sujeto->reprogramado_a_id !== null) {
            return [ModoCorreccion::NoCorregible, 'Esta cita ya se reprogramó: abre la cita vigente.'];
        }

        if ($campo === 'fecha_hora') {
            return in_array($sujeto->estado, [EstadoAgendaEvento::Programado, EstadoAgendaEvento::NoAsistio], true)
                ? [ModoCorreccion::Directo, 'La cita actual se conserva como reprogramada y se crea una nueva.']
                : [ModoCorreccion::NoCorregible, sprintf('Una cita «%s» no se puede reprogramar.', $sujeto->estado->etiqueta())];
        }

        return $sujeto->estado === EstadoAgendaEvento::Programado
            ? null
            : [ModoCorreccion::NoCorregible, sprintf('Las notas de una cita «%s» se conservan como quedaron.', $sujeto->estado->etiqueta())];
    }

    public function afectados(Model $sujeto, array $valores, array $opciones): array
    {
        if (! isset($valores['fecha_hora'])) {
            return [];
        }

        return [[
            'tipo' => 'agenda',
            'etiqueta' => 'Cita actual',
            'detalle' => 'Se conserva como reprogramada; se crea otra cita y se avisa a los responsables.',
            'accion' => 'nueva_version',
        ]];
    }

    public function aplicar(Model $sujeto, array $valores, string $motivo, array $opciones, User $actor): array
    {
        if (! $sujeto instanceof AgendaEvento) {
            throw ValidationException::withMessages(['valores' => 'Registro no válido.']);
        }

        if (array_key_exists('notas', $valores)) {
            $this->agenda->corregirNotas($sujeto, $valores['notas'], $motivo, $actor);
        }

        if (! isset($valores['fecha_hora'])) {
            return ['modo' => ModoCorreccion::Directo->value];
        }

        [$fecha, $hora] = explode(' ', $valores['fecha_hora']);
        $resultado = $this->agenda->reprogramar($sujeto->refresh(), $fecha, $hora, $motivo, $actor);

        return ['modo' => ModoCorreccion::NuevaVersion->value, 'resultado' => $resultado['evento']];
    }
}
