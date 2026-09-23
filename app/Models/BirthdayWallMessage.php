<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Mensaje (texto y/o foto) en el muro de cumpleaños de un colaborador.
 * `foto_path` vive en el disco de cumpleaños y nunca se expone: la foto se
 * sirve en streaming por Api\V1\MuroCumpleanosController::foto().
 *
 * @property int $id
 * @property int $birthday_greeting_id
 * @property int $user_id
 * @property string|null $mensaje
 * @property string|null $foto_path
 * @property string|null $foto_mime
 * @property Carbon $created_at
 * @property-read BirthdayGreeting $greeting
 * @property-read User $autor
 */
class BirthdayWallMessage extends Model
{
    protected $fillable = ['birthday_greeting_id', 'user_id', 'mensaje', 'foto_path', 'foto_mime'];

    protected function casts(): array
    {
        return ['birthday_greeting_id' => 'integer', 'user_id' => 'integer'];
    }

    /**
     * @return BelongsTo<BirthdayGreeting, $this>
     */
    public function greeting(): BelongsTo
    {
        return $this->belongsTo(BirthdayGreeting::class, 'birthday_greeting_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
