<?php

namespace App\Models;

use App\Enums\EstadoFormatoOficialGeneracion;
use Database\Factories\OfficialFormatGenerationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un PDF generado a partir de un OfficialFormat para un colaborador o
 * candidato (docs/FORMATOS_OFICIALES.md). El archivo vive en el disco 'nas'
 * (App\Services\Formatos\OfficialFormatStorageService); esta tabla solo
 * guarda metadatos y el snapshot de los datos usados.
 *
 * Cuando `solicitud_interna_id` no es null, esta generación fue disparada
 * automáticamente por App\Services\Solicitudes\SolicitudFormatoOficialService
 * al aprobar esa solicitud (config/solicitudes.php) — a diferencia de una
 * generación manual desde /rh/formatos-oficiales.
 *
 * @property int $id
 * @property int $official_format_id
 * @property int|null $solicitud_interna_id
 * @property int|null $user_id
 * @property int|null $colaborador_id
 * @property int|null $candidato_id
 * @property int $generated_by_id
 * @property string $generated_disk
 * @property string $generated_path
 * @property string $generated_name
 * @property array<string, string>|null $data_snapshot
 * @property EstadoFormatoOficialGeneracion $status
 * @property string|null $signed_disk
 * @property string|null $signed_path
 * @property string|null $signed_name
 * @property int|null $signed_uploaded_by
 * @property Carbon|null $signed_uploaded_at
 */
class OfficialFormatGeneration extends Model
{
    /** @use HasFactory<OfficialFormatGenerationFactory> */
    use HasFactory;

    protected $hidden = ['generated_disk', 'generated_path', 'signed_disk', 'signed_path'];

    protected $fillable = [
        'official_format_id',
        'solicitud_interna_id',
        'user_id',
        'colaborador_id',
        'candidato_id',
        'generated_by_id',
        'generated_disk',
        'generated_path',
        'generated_name',
        'data_snapshot',
        'status',
        'signed_disk',
        'signed_path',
        'signed_name',
        'signed_uploaded_by',
        'signed_uploaded_at',
    ];

    protected function casts(): array
    {
        return [
            'data_snapshot' => 'array',
            'status' => EstadoFormatoOficialGeneracion::class,
            'signed_uploaded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SolicitudInterna, $this>
     */
    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(SolicitudInterna::class, 'solicitud_interna_id');
    }

    /**
     * Alias explícito de solicitud() — mismo `solicitud_interna_id`, para
     * quien busque el nombre completo de la relación.
     *
     * @return BelongsTo<SolicitudInterna, $this>
     */
    public function solicitudInterna(): BelongsTo
    {
        return $this->belongsTo(SolicitudInterna::class, 'solicitud_interna_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function firmadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_uploaded_by');
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
     * Colaborador (persona/empleo) para quien se generó este documento —
     * fuente de verdad preferida sobre usuario()/user_id, que ahora es
     * legacy (ver docs/ROLES_Y_NAVEGACION.md).
     *
     * @return BelongsTo<Colaborador, $this>
     */
    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'colaborador_id');
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
