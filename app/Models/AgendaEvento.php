<?php

namespace App\Models;

use App\Enums\EstadoAgendaEvento;
use App\Enums\MotivoNoViableAgenda;
use App\Enums\OrigenAgendaEvento;
use App\Enums\ResultadoAgendaEvento;
use App\Enums\TipoAgendaEvento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Cita de la agenda de reclutamiento. No guarda el proceso del candidato: eso
 * vive en candidato_entrevistas / candidato_socioeconomicos vía el workflow.
 *
 * @property int $id
 * @property int $candidato_id
 * @property int $sucursal_id
 * @property TipoAgendaEvento $tipo
 * @property Carbon $fecha_hora
 * @property EstadoAgendaEvento $estado
 * @property OrigenAgendaEvento $origen
 * @property int|null $agendado_por
 * @property int|null $responsable_user_id
 * @property string|null $notas
 * @property string|null $domicilio
 * @property ResultadoAgendaEvento|null $resultado
 * @property MotivoNoViableAgenda|null $motivo_no_viable
 * @property string|null $comentario_resultado
 * @property int|null $resultado_por
 * @property Carbon|null $resultado_en
 * @property int|null $reprogramado_de_id
 * @property int|null $reprogramado_a_id
 * @property string|null $motivo_cancelacion
 * @property string|null $clave_idempotencia
 * @property string|null $clave_activa
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Candidato $candidato
 * @property-read Sucursal $sucursal
 * @property-read User|null $agendadoPor
 */
class AgendaEvento extends Model
{
    protected $table = 'agenda_eventos';

    protected $fillable = [
        'candidato_id', 'sucursal_id', 'tipo', 'fecha_hora', 'estado', 'origen', 'agendado_por', 'responsable_user_id',
        'notas', 'domicilio', 'resultado', 'motivo_no_viable', 'comentario_resultado', 'resultado_por', 'resultado_en',
        'reprogramado_de_id', 'reprogramado_a_id', 'motivo_cancelacion', 'clave_idempotencia', 'clave_activa',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoAgendaEvento::class,
            'fecha_hora' => 'datetime',
            'estado' => EstadoAgendaEvento::class,
            'origen' => OrigenAgendaEvento::class,
            'resultado' => ResultadoAgendaEvento::class,
            'motivo_no_viable' => MotivoNoViableAgenda::class,
            'resultado_en' => 'datetime',
        ];
    }

    /** @return BelongsTo<Candidato, $this> */
    public function candidato(): BelongsTo
    {
        return $this->belongsTo(Candidato::class);
    }

    /** @return BelongsTo<Sucursal, $this> */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    /** @return BelongsTo<User, $this> */
    public function agendadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agendado_por');
    }

    /** @return HasMany<AgendaEventoHistorial, $this> */
    public function historial(): HasMany
    {
        return $this->hasMany(AgendaEventoHistorial::class)->orderBy('id');
    }
}
