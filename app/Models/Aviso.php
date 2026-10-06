<?php

namespace App\Models;

use App\Enums\AlcanceAviso;
use Database\Factories\AvisoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Aviso de RH a la empresa (mensaje + imagen opcional), a toda la plantilla
 * activa o a un colaborador específico. Ver App\Services\Avisos\AvisoService.
 *
 * @property int $id
 * @property string $titulo
 * @property string $mensaje
 * @property AlcanceAviso $alcance
 * @property int|null $colaborador_objetivo_id
 * @property string|null $imagen_disk
 * @property string|null $imagen_path
 * @property string|null $imagen_mime
 * @property int|null $creado_por
 * @property Carbon|null $enviado_en
 */
class Aviso extends Model
{
    /** @use HasFactory<AvisoFactory> */
    use HasFactory;

    protected $fillable = [
        'titulo',
        'mensaje',
        'alcance',
        'colaborador_objetivo_id',
        'imagen_disk',
        'imagen_path',
        'imagen_mime',
        'creado_por',
        'enviado_en',
    ];

    protected function casts(): array
    {
        return [
            'alcance' => AlcanceAviso::class,
            'enviado_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Colaborador, $this>
     */
    public function colaboradorObjetivo(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_objetivo_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    /**
     * @return HasMany<AvisoLectura, $this>
     */
    public function lecturas(): HasMany
    {
        return $this->hasMany(AvisoLectura::class);
    }

    public function tieneImagen(): bool
    {
        return $this->imagen_path !== null;
    }
}
