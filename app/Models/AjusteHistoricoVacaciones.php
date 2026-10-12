<?php

namespace App\Models;

use Database\Factories\AjusteHistoricoVacacionesFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Ledger append-only de ajustes históricos de vacaciones (CLAUDE.md
 * "nunca editar/borrar histórico en-place"). `dias` negativo = consumo
 * histórico (p. ej. "8 días tomados antes de PEOPLE" → -8); positivo =
 * corrección a favor. VacacionesService::saldoColaborador() los suma SOLO
 * si `fecha_corte` cae dentro de la vigencia (año de servicio) que se está
 * calculando — un ajuste de un año de servicio ya cerrado no debe seguir
 * restando saldo del año actual para siempre.
 *
 * @property int $id
 * @property int $colaborador_id
 * @property string $tipo 'historico_detallado'|'ajuste_acumulado'
 * @property string $dias
 * @property Carbon|null $periodo_inicio
 * @property Carbon|null $periodo_fin
 * @property Carbon $fecha_corte
 * @property string $fuente
 * @property int|null $importacion_id
 * @property string|null $motivo
 * @property int|null $actor_id
 * @property-read Colaborador $colaborador
 * @property-read ImportacionExcel|null $importacion
 * @property-read User|null $actor
 */
class AjusteHistoricoVacaciones extends Model
{
    /** @use HasFactory<AjusteHistoricoVacacionesFactory> */
    use HasFactory;

    protected $table = 'ajustes_historicos_vacaciones';

    protected $fillable = [
        'colaborador_id', 'tipo', 'dias', 'periodo_inicio', 'periodo_fin',
        'fecha_corte', 'fuente', 'importacion_id', 'motivo', 'actor_id',
    ];

    protected function casts(): array
    {
        return [
            'dias' => 'decimal:2',
            'periodo_inicio' => 'date',
            'periodo_fin' => 'date',
            'fecha_corte' => 'date',
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
     * @return BelongsTo<ImportacionExcel, $this>
     */
    public function importacion(): BelongsTo
    {
        return $this->belongsTo(ImportacionExcel::class, 'importacion_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
