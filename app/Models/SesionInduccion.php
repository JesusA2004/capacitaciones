<?php

namespace App\Models;

use App\Enums\EstadoSesionInduccion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Sesión de presentación de la Inducción Mr. Lana: quién la presentó,
 * cuándo y a quién. Ver App\Services\Onboarding\SesionInduccionService.
 *
 * @property int $id
 * @property int $presentada_por
 * @property EstadoSesionInduccion $estado
 * @property string|null $notas
 * @property Carbon $iniciada_en
 * @property Carbon|null $presentada_en
 * @property Carbon|null $cancelada_en
 * @property-read User $presentador
 * @property-read Collection<int, SesionInduccionColaborador> $asistentes
 */
class SesionInduccion extends Model
{
    protected $table = 'sesiones_induccion';

    protected $fillable = ['presentada_por', 'estado', 'notas', 'iniciada_en', 'presentada_en', 'cancelada_en'];

    protected function casts(): array
    {
        return [
            'estado' => EstadoSesionInduccion::class,
            'iniciada_en' => 'datetime',
            'presentada_en' => 'datetime',
            'cancelada_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function presentador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'presentada_por')->withTrashed();
    }

    /**
     * @return HasMany<SesionInduccionColaborador, $this>
     */
    public function asistentes(): HasMany
    {
        return $this->hasMany(SesionInduccionColaborador::class)->orderBy('id');
    }
}
