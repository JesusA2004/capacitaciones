<?php

namespace App\Services\Correcciones\Dominios;

use App\Enums\EstadoReciboNomina;
use App\Enums\ModoCorreccion;
use App\Models\Colaborador;
use App\Models\Incapacidad;
use App\Models\ReciboNomina;
use App\Models\User;
use App\Services\Incapacidades\IncapacidadService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Delega en IncapacidadService::corregir(), que conserva sus reglas (ST-2,
 * folio libre, traslapes) y deja rectificación en los recibos emitidos.
 */
class IncapacidadDominio extends DominioBase
{
    public function __construct(private readonly IncapacidadService $incapacidades) {}

    public function clave(): string
    {
        return 'incapacidad';
    }

    public function sujeto(int $id, bool $bloquear = false): ?Model
    {
        return Incapacidad::query()->whereKey($id)->when($bloquear, fn ($q) => $q->lockForUpdate())->first();
    }

    public function etiquetaSujeto(Model $sujeto): string
    {
        return $sujeto instanceof Incapacidad ? sprintf('Incapacidad %s', $sujeto->serie_folio_imss) : '';
    }

    public function colaboradorDe(Model $sujeto): ?Colaborador
    {
        return $sujeto instanceof Incapacidad ? $sujeto->colaborador : null;
    }

    public function puedeLeer(User $actor, Model $sujeto): bool
    {
        return $actor->can('ver', $sujeto);
    }

    public function puedeEscribir(User $actor, Model $sujeto): bool
    {
        return ! $this->esPropio($actor, $this->colaboradorDe($sujeto)) && $actor->can('corregir', $sujeto);
    }

    protected function restriccion(Model $sujeto, string $campo): ?array
    {
        if ($sujeto instanceof Incapacidad && $sujeto->cancelada_en !== null && $campo !== 'tipo') {
            return [ModoCorreccion::NoCorregible, 'Esta incapacidad está cancelada: se conserva como quedó. Captúrala de nuevo si procede.'];
        }

        return null;
    }

    public function afectados(Model $sujeto, array $valores, array $opciones): array
    {
        if (! $sujeto instanceof Incapacidad || ! (isset($valores['fecha_inicio']) || isset($valores['dias']))) {
            return [];
        }

        $inicioNuevo = Carbon::parse($valores['fecha_inicio'] ?? $sujeto->fecha_inicio->toDateString());
        $finNuevo = $inicioNuevo->copy()->addDays((int) ($valores['dias'] ?? $sujeto->dias) - 1);
        $desde = $inicioNuevo->lt($sujeto->fecha_inicio) ? $inicioNuevo : $sujeto->fecha_inicio;
        $hasta = $finNuevo->gt($sujeto->fecha_fin) ? $finNuevo : $sujeto->fecha_fin;
        $afectados = [];

        $recibos = ReciboNomina::query()
            ->where('colaborador_id', $sujeto->colaborador_id)
            ->whereNull('rectificado_por_id')
            ->where('estado', '!=', EstadoReciboNomina::Cancelado->value)
            ->whereDate('periodo_inicio', '<=', $hasta->toDateString())
            ->whereDate('periodo_fin', '>=', $desde->toDateString())
            ->get();

        foreach ($recibos as $recibo) {
            $emitido = $recibo->estado === EstadoReciboNomina::Emitido;
            $afectados[] = [
                'tipo' => 'recibo',
                'etiqueta' => sprintf('Recibo %s (%s – %s)', (string) $recibo->folio, $recibo->periodo_inicio->format('d/m/Y'), $recibo->periodo_fin->format('d/m/Y')),
                'detalle' => $emitido ? 'El emitido no cambia: si los días difieren, queda una rectificación pendiente.' : 'El preparado se recalcula.',
                'accion' => $emitido ? 'requiere_rectificacion' : 'se_actualiza',
            ];
        }

        return $afectados;
    }

    public function aplicar(Model $sujeto, array $valores, string $motivo, array $opciones, User $actor): array
    {
        if (! $sujeto instanceof Incapacidad) {
            throw ValidationException::withMessages(['valores' => 'Registro no válido.']);
        }

        $this->incapacidades->corregir($sujeto, [
            'serie_folio_imss' => $valores['serie_folio_imss'] ?? $sujeto->serie_folio_imss,
            'fecha_inicio' => $valores['fecha_inicio'] ?? $sujeto->fecha_inicio->toDateString(),
            'dias' => $valores['dias'] ?? $sujeto->dias,
            'observaciones' => array_key_exists('observaciones', $valores) ? $valores['observaciones'] : $sujeto->observaciones,
        ], $motivo, $actor);

        return ['modo' => ModoCorreccion::Directo->value];
    }
}
