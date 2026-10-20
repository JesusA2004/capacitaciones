<?php

namespace App\Models;

use App\Enums\FormaSalida;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Documento requerido para cerrar una baja (cat_documento_baja): aplica a
 * toda una forma de salida (forma_salida) o es adicional de una causal
 * (causal_rotacion_id). `documento_sistema` indica que el espacio también se
 * cumple con lo que el cierre laboral ya lleva (carta_renuncia,
 * aviso_terminacion firmados o el finiquito entregado).
 *
 * @property int $id
 * @property string $clave
 * @property FormaSalida|null $forma_salida
 * @property int|null $causal_rotacion_id
 * @property string $nombre
 * @property bool $obligatorio_para_cerrar
 * @property string|null $documento_sistema
 * @property int $orden
 * @property bool $activo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read CausalRotacion|null $causal
 */
class DocumentoRequeridoBaja extends Model
{
    protected $table = 'documentos_requeridos_baja';

    protected $fillable = ['clave', 'forma_salida', 'causal_rotacion_id', 'nombre', 'obligatorio_para_cerrar', 'documento_sistema', 'orden', 'activo'];

    protected function casts(): array
    {
        return [
            'forma_salida' => FormaSalida::class,
            'obligatorio_para_cerrar' => 'boolean',
            'orden' => 'integer',
            'activo' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<CausalRotacion, $this>
     */
    public function causal(): BelongsTo
    {
        return $this->belongsTo(CausalRotacion::class, 'causal_rotacion_id');
    }
}
