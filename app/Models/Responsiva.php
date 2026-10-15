<?php

namespace App\Models;

use App\Enums\EstadoResponsiva;
use App\Enums\TipoResponsiva;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Responsiva de equipo entregado a un colaborador (ver
 * App\Services\Responsivas\ResponsivaService). El PDF generado y el escaneo
 * firmado viven en el NAS, en la carpeta «Actas» (Actas y responsivas) del
 * expediente del colaborador; aquí solo la ruta relativa, nunca expuesta.
 *
 * @property int $id
 * @property string|null $folio
 * @property int $colaborador_id
 * @property TipoResponsiva $tipo
 * @property string $lugar
 * @property Carbon $fecha_entrega
 * @property int $entregado_por
 * @property string|null $observaciones
 * @property array<string, mixed> $snapshot
 * @property EstadoResponsiva $estado
 * @property string|null $pdf_path
 * @property string|null $firmada_path
 * @property Carbon|null $firmada_subida_en
 * @property int|null $firmada_subida_por
 * @property Carbon|null $devuelta_en
 * @property string|null $observaciones_devolucion
 * @property int|null $devolucion_registrada_por
 * @property Carbon|null $cancelada_en
 * @property int|null $cancelada_por
 * @property string|null $motivo_cancelacion
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Colaborador $colaborador
 * @property-read User $entregadoPor
 * @property-read Collection<int, ResponsivaActivo> $activos
 */
class Responsiva extends Model
{
    protected $table = 'responsivas';

    protected $fillable = [
        'folio', 'colaborador_id', 'tipo', 'lugar', 'fecha_entrega', 'entregado_por', 'observaciones', 'snapshot', 'estado',
        'pdf_path', 'firmada_path', 'firmada_subida_en', 'firmada_subida_por',
        'devuelta_en', 'observaciones_devolucion', 'devolucion_registrada_por',
        'cancelada_en', 'cancelada_por', 'motivo_cancelacion',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoResponsiva::class,
            'estado' => EstadoResponsiva::class,
            'fecha_entrega' => 'date',
            'snapshot' => 'array',
            'firmada_subida_en' => 'datetime',
            'devuelta_en' => 'date',
            'cancelada_en' => 'datetime',
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
    public function entregadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entregado_por');
    }

    /**
     * @return HasMany<ResponsivaActivo, $this>
     */
    public function activos(): HasMany
    {
        return $this->hasMany(ResponsivaActivo::class)->orderBy('orden');
    }
}
