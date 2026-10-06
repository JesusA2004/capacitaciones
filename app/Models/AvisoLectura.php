<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Quién ya abrió un aviso. Se crea solo al leerlo (nunca pre-llenado para
 * "toda la empresa"). Ver App\Models\Aviso.
 *
 * @property int $id
 * @property int $aviso_id
 * @property int $user_id
 * @property Carbon $leido_en
 */
class AvisoLectura extends Model
{
    public $timestamps = false;

    protected $fillable = ['aviso_id', 'user_id', 'leido_en'];

    protected function casts(): array
    {
        return ['leido_en' => 'datetime'];
    }

    /**
     * @return BelongsTo<Aviso, $this>
     */
    public function aviso(): BelongsTo
    {
        return $this->belongsTo(Aviso::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
