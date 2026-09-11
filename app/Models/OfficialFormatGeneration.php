<?php

namespace App\Models;

use Database\Factories\OfficialFormatGenerationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un PDF generado a partir de un OfficialFormat para un colaborador o
 * candidato (docs/FORMATOS_OFICIALES.md). El archivo vive en el disco 'nas'
 * (App\Services\Formatos\OfficialFormatStorageService); esta tabla solo
 * guarda metadatos y el snapshot de los datos usados.
 *
 * @property int $id
 * @property int $official_format_id
 * @property int|null $user_id
 * @property int|null $candidato_id
 * @property int $generated_by_id
 * @property string $generated_disk
 * @property string $generated_path
 * @property string $generated_name
 * @property array<string, string>|null $data_snapshot
 */
class OfficialFormatGeneration extends Model
{
    /** @use HasFactory<OfficialFormatGenerationFactory> */
    use HasFactory;

    protected $hidden = ['generated_disk', 'generated_path'];

    protected $fillable = [
        'official_format_id',
        'user_id',
        'candidato_id',
        'generated_by_id',
        'generated_disk',
        'generated_path',
        'generated_name',
        'data_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'data_snapshot' => 'array',
        ];
    }

    /**
     * @return BelongsTo<OfficialFormat, $this>
     */
    public function formato(): BelongsTo
    {
        return $this->belongsTo(OfficialFormat::class, 'official_format_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<Candidato, $this>
     */
    public function candidato(): BelongsTo
    {
        return $this->belongsTo(Candidato::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function generadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by_id');
    }
}
