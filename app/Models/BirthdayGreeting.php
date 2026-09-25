<?php

namespace App\Models;

use App\Enums\TipoCelebracion;
use Database\Factories\BirthdayGreetingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * CELEBRACIÓN de un colaborador en una fecha (docs/CELEBRACIONES.md):
 * cumpleaños o aniversario laboral (`tipo`). El nombre de la clase/tabla
 * viene de cuando solo existía cumpleaños; se generalizó en vez de duplicar
 * tablas. Única por colaborador + fecha + tipo: correr el scheduler dos
 * veces nunca crea dos eventos. La frase/mensaje y la tarjeta quedan fijas
 * para ese día hasta que RH las regenere.
 *
 * `avisada_todos_at` hace idempotente "Avisar a todos"; `enviada_at`, el
 * envío al homenajeado. Los mensajes (`mensajesMuro`) son PRIVADOS: ver
 * App\Policies\BirthdayGreetingPolicy.
 *
 * @property int $id
 * @property int|null $user_id
 * @property int|null $colaborador_id
 * @property TipoCelebracion $tipo
 * @property int|null $anios
 * @property Carbon|null $avisada_todos_at
 * @property int|null $avisada_todos_por_id
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
        'user_id', 'colaborador_id', 'tipo', 'anios', 'birthday_phrase_id', 'fecha', 'nombre_mostrado', 'frase',
        'card_path', 'enviada_at', 'enviada_por_id', 'avisada_todos_at', 'avisada_todos_por_id', 'auto_generada', 'metadata',
        'muro_abierto_at', 'muro_abierto_por_id', 'muro_cerrado_at',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoCelebracion::class,
            'anios' => 'integer',
            'avisada_todos_at' => 'datetime',
            'fecha' => 'date',
            'enviada_at' => 'datetime',
            'auto_generada' => 'boolean',
            'metadata' => 'array',
            'muro_abierto_at' => 'datetime',
            'muro_cerrado_at' => 'datetime',
        ];
    }

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'tipo' => 'cumpleanos',
    ];

    public function esAniversario(): bool
    {
        return $this->tipo === TipoCelebracion::AniversarioLaboral;
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
    public function avisadaTodosPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'avisada_todos_por_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function enviadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enviada_por_id');
    }
}
