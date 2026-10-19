<?php

namespace App\Models;

use App\Enums\TipoMaterialInduccion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Material de explicación de la Inducción Mr. Lana (audio XOR video) que el
 * colaborador ve ENTRE las hojas del PDF oficial y su evaluación. Uno solo
 * activo a la vez por módulo institucional — ver
 * App\Services\Onboarding\MaterialInduccionService.
 *
 * El binario vive en el disco 'nas' a través de RecursoMultimedia; esta tabla
 * solo liga el recurso con el módulo y marca cuál está activo.
 *
 * `activo` es true para el único material vigente y NULL para los retirados:
 * así el índice único (onboarding_modulo_id, activo) admite varios retirados
 * (historial) pero un solo activo por módulo, sin colisionar con MariaDB.
 *
 * @property int $id
 * @property int $onboarding_modulo_id
 * @property int|null $recurso_multimedia_id
 * @property TipoMaterialInduccion $tipo
 * @property bool|null $activo
 * @property int $subido_por
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read OnboardingModulo $modulo
 * @property-read RecursoMultimedia|null $recurso
 * @property-read User $subidoPor
 */
class InduccionMaterialExplicacion extends Model
{
    use SoftDeletes;

    protected $table = 'induccion_materiales_explicacion';

    protected $fillable = [
        'onboarding_modulo_id', 'recurso_multimedia_id', 'tipo', 'activo', 'subido_por',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoMaterialInduccion::class,
            'activo' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<OnboardingModulo, $this>
     */
    public function modulo(): BelongsTo
    {
        return $this->belongsTo(OnboardingModulo::class, 'onboarding_modulo_id');
    }

    /**
     * @return BelongsTo<RecursoMultimedia, $this>
     */
    public function recurso(): BelongsTo
    {
        return $this->belongsTo(RecursoMultimedia::class, 'recurso_multimedia_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function subidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subido_por');
    }
}
