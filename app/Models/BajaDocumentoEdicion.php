<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Corrección del contenido de un documento de salida: qué campos se editaron,
 * cómo estaban, por qué y quién. La última de cada documento es la vigente.
 *
 * @property int $id
 * @property int $baja_id
 * @property string $clave
 * @property array<string, string> $campos
 * @property array<string, string>|null $campos_anteriores
 * @property string $motivo
 * @property int|null $user_id
 * @property int|null $generated_document_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class BajaDocumentoEdicion extends Model
{
    protected $table = 'baja_documento_ediciones';

    protected $fillable = ['baja_id', 'clave', 'campos', 'campos_anteriores', 'motivo', 'user_id', 'generated_document_id'];

    protected function casts(): array
    {
        return [
            'campos' => 'array',
            'campos_anteriores' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
