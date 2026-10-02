<?php

namespace App\Models;

use App\Enums\EstadoSolicitudInterna;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Timeline de una solicitud interna: una fila por cada cambio de estado o
 * comentario. `accion` usa los mismos valores que EstadoSolicitudInterna,
 * más 'comentario' para notas que no cambian el estado.
 *
 * @property int $id
 * @property int $solicitud_interna_id
 * @property int|null $user_id
 * @property string $accion
 * @property string|null $comentario
 * @property-read string $accion_etiqueta
 * @property Carbon $created_at
 */
class SolicitudInternaHistorial extends Model
{
    public $timestamps = false;

    protected $table = 'solicitud_interna_historial';

    protected $fillable = [
        'solicitud_interna_id',
        'user_id',
        'accion',
        'comentario',
        'created_at',
    ];

    /** @var list<string> */
    protected $appends = ['accion_etiqueta'];

    /**
     * Texto en español de `accion` para el timeline (web y app): los
     * cambios de estado usan EstadoSolicitudInterna::etiqueta(); el resto
     * son eventos propios del historial (finiquito, vistos buenos).
     *
     * @return Attribute<string, never>
     */
    protected function accionEtiqueta(): Attribute
    {
        return Attribute::get(fn (): string => self::etiquetaDe($this->accion));
    }

    public static function etiquetaDe(string $accion): string
    {
        $estado = EstadoSolicitudInterna::tryFrom($accion);

        if ($estado !== null) {
            return $estado->etiqueta();
        }

        if (preg_match('/^(sin_)?visto_bueno_(.+)$/', $accion, $m) === 1) {
            return sprintf('%s del %s', $m[1] === '' ? 'Visto bueno' : 'Sin visto bueno', $m[2]);
        }

        return match ($accion) {
            'comentario' => 'Comentario',
            'finiquito_calculado' => 'Finiquito calculado',
            'finiquito_recalculado' => 'Finiquito recalculado',
            'finiquito_ajustado' => 'Finiquito ajustado',
            'finiquito_concepto' => 'Concepto de finiquito',
            'finiquito_revisado' => 'Finiquito revisado',
            'finiquito_firmado_subido' => 'Finiquito firmado',
            'finiquito_pagado' => 'Finiquito pagado',
            default => ucfirst(str_replace('_', ' ', $accion)),
        };
    }

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SolicitudInterna, $this>
     */
    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(SolicitudInterna::class, 'solicitud_interna_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
