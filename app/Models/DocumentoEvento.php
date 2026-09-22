<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Bitácora append-only del flujo de un documento laboral: quién, qué
 * acción, cuándo, desde qué IP/user agent y con qué observaciones. Nunca
 * guarda contraseñas ni tokens.
 *
 * @property int $id
 * @property int $generated_document_id
 * @property string $accion
 * @property string|null $estado_anterior
 * @property string|null $estado_nuevo
 * @property int|null $user_id
 * @property string|null $ip
 * @property string|null $user_agent
 * @property string|null $observaciones
 * @property array<string, mixed>|null $datos
 * @property Carbon|null $created_at
 */
class DocumentoEvento extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'documento_eventos';

    protected $fillable = ['generated_document_id', 'accion', 'estado_anterior', 'estado_nuevo', 'user_id', 'ip', 'user_agent', 'observaciones', 'datos'];

    protected function casts(): array
    {
        return ['datos' => 'array'];
    }

    /**
     * @return BelongsTo<GeneratedDocument, $this>
     */
    public function documento(): BelongsTo
    {
        return $this->belongsTo(GeneratedDocument::class, 'generated_document_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
