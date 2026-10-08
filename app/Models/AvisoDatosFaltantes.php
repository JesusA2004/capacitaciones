<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Aviso «Completa tu información» enviado por RH (snapshot de los campos
 * que faltaban). Ver App\Services\Expedientes\DatosFaltantesService.
 *
 * @property int $id
 * @property int $colaborador_id
 * @property list<array{campo: string, etiqueta: string}> $campos
 * @property int|null $enviado_por
 * @property Carbon|null $created_at
 * @property-read User|null $enviadoPor
 */
class AvisoDatosFaltantes extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'avisos_datos_faltantes';

    protected $fillable = ['colaborador_id', 'campos', 'enviado_por'];

    protected function casts(): array
    {
        return [
            'campos' => 'array',
            'created_at' => 'datetime',
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
    public function enviadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enviado_por');
    }
}
