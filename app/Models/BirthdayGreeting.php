<?php

namespace App\Models;

use Database\Factories\BirthdayGreetingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Felicitacion de cumpleanos de un colaborador para una fecha dada. Unica
 * por user_id+fecha (ver migracion): una vez generada, la frase y la tarjeta
 * quedan fijas para ese dia aunque se vuelva a ejecutar el command o se
 * recargue la pantalla (ver App\Services\Cumpleanos\CumpleanosService).
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $birthday_phrase_id
 * @property Carbon $fecha
 * @property string $nombre_mostrado
 * @property string $frase
 * @property string|null $card_path
 * @property Carbon|null $enviada_at
 * @property int|null $enviada_por_id
 * @property bool $auto_generada
 * @property array<string, mixed>|null $metadata
 * @property-read User $colaborador
 * @property-read BirthdayPhrase|null $frasePlantilla
 */
class BirthdayGreeting extends Model
{
    /** @use HasFactory<BirthdayGreetingFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id', 'birthday_phrase_id', 'fecha', 'nombre_mostrado', 'frase',
        'card_path', 'enviada_at', 'enviada_por_id', 'auto_generada', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'enviada_at' => 'datetime',
            'auto_generada' => 'boolean',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
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
