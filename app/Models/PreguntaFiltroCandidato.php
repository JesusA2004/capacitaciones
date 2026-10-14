<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pregunta rápida de requisito indispensable que se hace al registrar un
 * candidato (p. ej. «¿Maneja moto?» para Gestor). Si la respuesta no es la
 * que exige el puesto y la pregunta es eliminatoria, el candidato SÍ se
 * registra (cuenta en su campaña) pero queda «No viable» con el motivo del
 * catálogo. Nunca se borra: se desactiva.
 *
 * @property int $id
 * @property string $clave
 * @property string $pregunta
 * @property array<int, int|string>|null $puestos
 * @property bool $respuesta_requerida
 * @property bool $eliminatoria
 * @property int|null $motivo_rechazo_id
 * @property bool $activo
 * @property int $orden
 * @property-read MotivoRechazoCandidato|null $motivoRechazo
 */
class PreguntaFiltroCandidato extends Model
{
    protected $table = 'preguntas_filtro_candidato';

    protected $fillable = ['clave', 'pregunta', 'puestos', 'respuesta_requerida', 'eliminatoria', 'motivo_rechazo_id', 'activo', 'orden'];

    protected function casts(): array
    {
        return [
            'puestos' => 'array',
            'respuesta_requerida' => 'boolean',
            'eliminatoria' => 'boolean',
            'activo' => 'boolean',
            'orden' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<MotivoRechazoCandidato, $this>
     */
    public function motivoRechazo(): BelongsTo
    {
        return $this->belongsTo(MotivoRechazoCandidato::class);
    }

    /**
     * @param  Builder<PreguntaFiltroCandidato>  $query
     * @return Builder<PreguntaFiltroCandidato>
     */
    public function scopeActivas(Builder $query): Builder
    {
        return $query->where('activo', true);
    }

    /**
     * IDs de los puestos a los que aplica (vacío = todos).
     *
     * @return list<int>
     */
    public function idsPuestos(): array
    {
        return array_values(array_map(fn (int|string $id): int => (int) $id, $this->puestos ?? []));
    }

    public function aplicaA(?int $puestoId): bool
    {
        $puestos = $this->idsPuestos();

        return $puestos === [] || ($puestoId !== null && in_array($puestoId, $puestos, true));
    }

    public function cumple(bool $respuesta): bool
    {
        return $respuesta === $this->respuesta_requerida;
    }
}
