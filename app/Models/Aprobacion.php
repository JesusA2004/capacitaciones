<?php

namespace App\Models;

use App\Enums\EstadoAprobacion;
use App\Enums\EtapaAprobacion;
use App\Enums\ProcesoAprobacion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Una etapa de aprobación (preautorización operativa o autorización final de
 * RH) sobre cualquier objeto del ciclo laboral: candidato, evaluación de
 * periodo de prueba, cierre laboral, reingreso. Conserva un snapshot de
 * quién decidió (nombre, puesto, roles) para que el historial no cambie si
 * después cambian el organigrama o los roles. Ver
 * App\Services\CicloLaboral\AprobacionService.
 *
 * @property int $id
 * @property string $aprobable_type
 * @property int $aprobable_id
 * @property ProcesoAprobacion $proceso
 * @property EtapaAprobacion $etapa
 * @property int $ronda
 * @property int|null $colaborador_id
 * @property int|null $candidato_id
 * @property int|null $solicitante_user_id
 * @property int|null $aprobador_colaborador_id
 * @property int|null $aprobador_user_id
 * @property string|null $capacidad_requerida
 * @property EstadoAprobacion $estado
 * @property string|null $decision
 * @property string|null $comentario
 * @property int|null $decidido_por_user_id
 * @property Carbon|null $decidido_en
 * @property array<string, mixed>|null $decisor_snapshot
 * @property string|null $ip
 * @property string|null $user_agent
 * @property Carbon|null $created_at
 * @property-read Model|null $aprobable
 * @property-read Colaborador|null $aprobadorColaborador
 * @property-read User|null $aprobadorUsuario
 * @property-read User|null $decididoPor
 * @property-read User|null $solicitante
 */
class Aprobacion extends Model
{
    protected $table = 'aprobaciones';

    protected $fillable = [
        'aprobable_type', 'aprobable_id', 'proceso', 'etapa', 'ronda', 'colaborador_id', 'candidato_id',
        'solicitante_user_id', 'aprobador_colaborador_id', 'aprobador_user_id', 'capacidad_requerida',
        'estado', 'decision', 'comentario', 'decidido_por_user_id', 'decidido_en', 'decisor_snapshot',
        'ip', 'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'proceso' => ProcesoAprobacion::class,
            'etapa' => EtapaAprobacion::class,
            'estado' => EstadoAprobacion::class,
            'ronda' => 'integer',
            'decidido_en' => 'datetime',
            'decisor_snapshot' => 'array',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function aprobable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<Colaborador, $this>
     */
    public function aprobadorColaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class, 'aprobador_colaborador_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function aprobadorUsuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobador_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decididoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decidido_por_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitante_user_id');
    }

    /**
     * @return BelongsTo<Colaborador, $this>
     */
    public function colaborador(): BelongsTo
    {
        return $this->belongsTo(Colaborador::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Candidato, $this>
     */
    public function candidato(): BelongsTo
    {
        return $this->belongsTo(Candidato::class);
    }
}
