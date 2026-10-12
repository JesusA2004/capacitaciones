<?php

namespace App\Models;

use Database\Factories\HistorialSalarioFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Ledger append-only de cambios de salario (CompensacionService::actualizarSalario()).
 * Nunca se edita ni se borra una fila — una corrección agrega una nueva fila
 * con el valor inverso/correcto (CLAUDE.md "nunca sobrescribir sin rastro").
 *
 * @property int $id
 * @property int $colaborador_id
 * @property string|null $salario_diario_anterior
 * @property string $salario_diario_nuevo
 * @property string|null $salario_diario_integrado_anterior
 * @property string|null $salario_diario_integrado_nuevo
 * @property Carbon $fecha_efectiva
 * @property string $fuente
 * @property int|null $importacion_id
 * @property int|null $actor_id
 * @property string|null $motivo
 * @property-read Colaborador $colaborador
 * @property-read User|null $actor
 */
class HistorialSalario extends Model
{
    /** @use HasFactory<HistorialSalarioFactory> */
    use HasFactory;

    protected $table = 'historial_salarios';

    protected $fillable = [
        'colaborador_id', 'salario_diario_anterior', 'salario_diario_nuevo',
        'salario_diario_integrado_anterior', 'salario_diario_integrado_nuevo',
        'fecha_efectiva', 'fuente', 'importacion_id', 'actor_id', 'motivo',
    ];

    protected function casts(): array
    {
        return [
            'salario_diario_anterior' => 'decimal:2',
            'salario_diario_nuevo' => 'decimal:2',
            'salario_diario_integrado_anterior' => 'decimal:2',
            'salario_diario_integrado_nuevo' => 'decimal:2',
            'fecha_efectiva' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Colaborador, $this>
     */
    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
