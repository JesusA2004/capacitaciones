<?php

namespace App\Models;

use Database\Factories\BirthdayGreetingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Felicitacion de cumpleanos de un colaborador para una fecha dada. Unica
 * por user_id+fecha (ver migracion): una vez generada, la frase y la tarjeta
 * quedan fijas para ese dia aunque se vuelva a ejecutar el command o se
 * recargue la pantalla (ver App\Services\Cumpleanos\CumpleanosService).
 *
 * @property int $id
 * @property int|null $user_id
 * @property int|null $colaborador_id
 * @property int|null $birthday_phrase_id
 * @property Carbon $fecha
 * @property string $nombre_mostrado
 * @property string $frase
 * @property string|null $card_path
 * @property Carbon|null $enviada_at
 * @property int|null $enviada_por_id
 * @property bool $auto_generada
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $muro_abierto_at
 * @property int|null $muro_abierto_por_id
 * @property Carbon|null $muro_cerrado_at
 * @property-read Colaborador $colaborador
 * @property-read BirthdayPhrase|null $frasePlantilla
 */
class BirthdayGreeting extends Model
{
    /** @use HasFactory<BirthdayGreetingFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id', 'colaborador_id', 'birthday_phrase_id', 'fecha', 'nombre_mostrado', 'frase',
        'card_path', 'enviada_at', 'enviada_por_id', 'auto_generada', 'metadata',
        'muro_abierto_at', 'muro_abierto_por_id', 'muro_cerrado_at',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'enviada_at' => 'datetime',
            'auto_generada' => 'boolean',
            'metadata' => 'array',
            'muro_abierto_at' => 'datetime',
            'muro_cerrado_at' => 'datetime',
        ];
    }

    /** El muro existe (RH lo abrió alguna vez), aunque ya esté cerrado. */
    public function muroPublicado(): bool
    {
        return $this->muro_abierto_at !== null;
    }

    /** Se pueden dejar mensajes: abierto y sin cerrar. */
    public function muroAbierto(): bool
    {
        return $this->muro_abierto_at !== null && $this->muro_cerrado_at === null;
    }

    /**
     * @return HasMany<BirthdayWallMessage, $this>
     */
    public function mensajesMuro(): HasMany
    {
        return $this->hasMany(BirthdayWallMessage::class, 'birthday_greeting_id');
    }

    /**
     * @return BelongsTo<Colaborador, $this>
     */
    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_id');
    }

    /**
     * @return BelongsTo<BirthdayPhrase, $this>
     */
    public function frasePlantilla(): BelongsTo
    {
        return $this->belongsTo(BirthdayPhrase::class, 'birthday_phrase_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function enviadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enviada_por_id');
    }
}
