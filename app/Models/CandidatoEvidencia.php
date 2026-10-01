<?php

namespace App\Models;

use App\Enums\TipoEvidenciaCandidato;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Evidencia privada del reclutamiento (fotografías/video del estudio
 * socioeconómico, resultados psicométricos). El archivo vive en el disco
 * privado del NAS; disco y ruta nunca se exponen al cliente — la descarga
 * pasa por un controlador autorizado.
 *
 * @property int $id
 * @property int $candidato_id
 * @property string|null $evidenciable_type
 * @property int|null $evidenciable_id
 * @property TipoEvidenciaCandidato $tipo
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string|null $mime
 * @property int|null $size
 * @property string|null $checksum
 * @property int|null $subida_por
 * @property Carbon|null $created_at
 */
class CandidatoEvidencia extends Model
{
    protected $table = 'candidato_evidencias';

    protected $hidden = ['disk', 'path'];

    protected $fillable = [
        'candidato_id', 'evidenciable_type', 'evidenciable_id', 'tipo', 'disk', 'path',
        'original_name', 'mime', 'size', 'checksum', 'subida_por',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoEvidenciaCandidato::class,
            'size' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Candidato, $this>
     */
    public function candidato(): BelongsTo
    {
        return $this->belongsTo(Candidato::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function evidenciable(): MorphTo
    {
        return $this->morphTo();
    }
}
