<?php

namespace App\Models;

use Database\Factories\CertificadoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Constancia de finalizacion de un curso con genera_constancia = true. El
 * folio es el identificador publico y verificable (no expone el ID interno
 * ni datos sensibles); la verificacion solo confirma que ese folio
 * corresponde a un certificado real, con quien y de que curso.
 *
 * @property int $id
 * @property string $folio
 * @property int $user_id
 * @property int $curso_id
 * @property int $inscripcion_curso_id
 * @property Carbon $emitido_en
 */
class Certificado extends Model
{
    /** @use HasFactory<CertificadoFactory> */
    use HasFactory;

    protected $table = 'certificados';

    protected $fillable = ['folio', 'user_id', 'curso_id', 'inscripcion_curso_id', 'emitido_en'];

    protected function casts(): array
    {
        return [
            'emitido_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<Curso, $this>
     */
    public function curso(): BelongsTo
    {
        return $this->belongsTo(Curso::class);
    }

    /**
     * @return BelongsTo<InscripcionCurso, $this>
     */
    public function inscripcion(): BelongsTo
    {
        return $this->belongsTo(InscripcionCurso::class, 'inscripcion_curso_id');
    }
}
