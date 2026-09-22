<?php

namespace App\Models;

use App\Enums\EstadoActa;
use App\Enums\TipoActa;
use Database\Factories\ActaAdministrativaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Acta administrativa, acta de hechos, carta responsiva o acta de auditoría
 * de un colaborador. El formato se genera con el motor documental
 * (plantilla TipoActa::clavePlantilla()) y se archiva en el expediente
 * (categoría Actas). Ver App\Services\Actas\ActaService.
 *
 * @property int $id
 * @property string|null $folio
 * @property int $colaborador_id
 * @property TipoActa $tipo
 * @property EstadoActa $estado
 * @property Carbon $fecha
 * @property string|null $hora
 * @property string|null $lugar
 * @property int|null $sucursal_id
 * @property string $hechos
 * @property int|null $responsable_id
 * @property array<int, array{nombre: string, puesto?: string|null, colaborador_id?: int|null}>|null $testigos
 * @property array<int, array{persona: string, declaracion: string}>|null $declaraciones
 * @property bool $negativa_firma
 * @property string|null $motivo_negativa
 * @property array<int, array{fecha: string, nota: string, user_id: int|null}>|null $seguimiento
 * @property int|null $generated_document_id
 * @property int|null $creado_por
 * @property Carbon|null $cerrada_en
 * @property-read Colaborador $colaborador
 * @property-read GeneratedDocument|null $documento
 */
class ActaAdministrativa extends Model
{
    /** @use HasFactory<ActaAdministrativaFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    protected $table = 'actas_administrativas';

    protected $fillable = [
        'folio', 'colaborador_id', 'tipo', 'estado', 'fecha', 'hora', 'lugar', 'sucursal_id',
        'hechos', 'responsable_id', 'testigos', 'declaraciones', 'negativa_firma',
        'motivo_negativa', 'seguimiento', 'generated_document_id', 'creado_por', 'cerrada_en',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoActa::class,
            'estado' => EstadoActa::class,
            'fecha' => 'date',
            'testigos' => 'array',
            'declaraciones' => 'array',
            'seguimiento' => 'array',
            'negativa_firma' => 'boolean',
            'cerrada_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Colaborador, $this>
     */
    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }

    /**
     * @return BelongsTo<Sucursal, $this>
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    /**
     * @return BelongsTo<GeneratedDocument, $this>
     */
    public function documento(): BelongsTo
    {
        return $this->belongsTo(GeneratedDocument::class, 'generated_document_id');
    }

    /**
     * @return HasMany<ActaAnexo, $this>
     */
    public function anexos(): HasMany
    {
        return $this->hasMany(ActaAnexo::class, 'acta_administrativa_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['tipo', 'estado', 'fecha', 'negativa_firma', 'generated_document_id', 'cerrada_en'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
