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
 * @property int|null $registrado_por
 * @property string|null $observaciones
 * @property array<string, mixed> $snapshot
 * @property array<string, mixed>|null $datos Campos legales propios de la familia (bien mueble / equipo / vehículo).
 * @property EstadoResponsiva $estado
 * @property string|null $pdf_path
 * @property string|null $firmada_path
 * @property Carbon|null $firmada_subida_en
 * @property int|null $firmada_subida_por
 * @property Carbon|null $firmada_fisicamente_en
 * @property int|null $firmada_fisicamente_por
 * @property Carbon|null $devuelta_en
 * @property string|null $observaciones_devolucion
 * @property int|null $devolucion_registrada_por
 * @property Carbon|null $cancelada_en
 * @property int|null $cancelada_por
 * @property string|null $motivo_cancelacion
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Colaborador $colaborador
 * @property-read User $entregadoPor  Responsable de entrega: quien realmente entregó el equipo.
 * @property-read User|null $registradoPor  Quien capturó la responsiva en PEOPLE.
 * @property-read Collection<int, ResponsivaActivo> $activos
 *
 * Semántica entregado_por vs registrado_por (nueva regla):
 *  - entregado_por = responsable de entrega (la persona que entregó el equipo).
 *  - registrado_por = usuario que capturó la responsiva en el sistema.
 * Cuando quien captura entrega él mismo (gerente), ambos coinciden; cuando RH
 * captura por otro, registrado_por es RH y entregado_por el responsable real.
 */
class Responsiva extends Model
{
    protected $table = 'responsivas';

    protected $fillable = [
        'folio', 'colaborador_id', 'tipo', 'lugar', 'fecha_entrega', 'entregado_por', 'registrado_por', 'observaciones', 'snapshot', 'datos', 'estado',
        'pdf_path', 'firmada_path', 'firmada_subida_en', 'firmada_subida_por', 'firmada_fisicamente_en', 'firmada_fisicamente_por',
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
            'datos' => 'array',
            'firmada_subida_en' => 'datetime',
            'firmada_fisicamente_en' => 'datetime',
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
     * Responsable de entrega: la persona que realmente entregó el equipo.
     *
     * @return BelongsTo<User, $this>
     */
    public function entregadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entregado_por');
    }

    /**
     * Quien capturó la responsiva en PEOPLE (puede diferir de quien entregó).
     *
     * @return BelongsTo<User, $this>
     */
    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    /**
     * @return HasMany<ResponsivaActivo, $this>
     */
    public function activos(): HasMany
    {
        return $this->hasMany(ResponsivaActivo::class)->orderBy('orden');
    }
}
