<?php

namespace App\Models;

use App\Enums\EntregaAvisoRescision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Hechos circunstanciados y entrega del aviso de rescisión de una baja (PDF
 * Etapa 1, Pantalla 4, instrucciones 5 y 6). Los cambios quedan en baja_bitacora.
 *
 * @property int $id
 * @property int $baja_id
 * @property string $fundamento
 * @property string $que_ocurrio
 * @property Carbon $hechos_en
 * @property string $lugar
 * @property string $constataron
 * @property string $relato
 * @property list<array{nombre: string, cargo: string}> $testigos
 * @property Carbon $fecha_aviso
 * @property EntregaAvisoRescision $metodo_entrega
 * @property Carbon $fecha_entrega
 * @property bool $plazo_validado
 * @property int|null $plazo_validado_por
 * @property Carbon|null $plazo_validado_en
 * @property int|null $capturado_por
 * @property int|null $actualizado_por
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Baja $baja
 * @property-read User|null $validadoPor
 */
class BajaAvisoRescision extends Model
{
    protected $table = 'baja_avisos_rescision';

    protected $fillable = [
        'baja_id', 'fundamento', 'que_ocurrio', 'hechos_en', 'lugar', 'constataron', 'relato', 'testigos', 'fecha_aviso',
        'metodo_entrega', 'fecha_entrega', 'plazo_validado', 'plazo_validado_por', 'plazo_validado_en', 'capturado_por', 'actualizado_por',
    ];

    protected function casts(): array
    {
        return [
            'hechos_en' => 'datetime',
            'testigos' => 'array',
            'fecha_aviso' => 'date',
            'metodo_entrega' => EntregaAvisoRescision::class,
            'fecha_entrega' => 'date',
            'plazo_validado' => 'boolean',
            'plazo_validado_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Baja, $this>
     */
    public function baja(): BelongsTo
    {
        return $this->belongsTo(Baja::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function validadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'plazo_validado_por');
    }
}
