<?php

namespace App\Models;

use App\Enums\EstadoDivisionHistorico;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Una corrida de división del PDF histórico único por checklist
 * (docs/DIVISION_EXPEDIENTE_HISTORICO.md). El PDF original
 * (ExpedienteHistorico) nunca se modifica: esta fila guarda cómo se leyó y
 * clasificó cada página, y sus partes (ParteDivisionHistorico) son copias.
 *
 * `paginas`: una entrada por página {pagina, tipo (clave|null), confianza,
 * metodo (MetodoLecturaPagina), puntaje, coincidencias, motivo}.
 * `datos_detectados`: reporte de datos del PDF contra el colaborador
 * (ver ComparadorDatosHistorico).
 *
 * @property int $id
 * @property int $expediente_historico_id
 * @property int $colaborador_id
 * @property EstadoDivisionHistorico $estado
 * @property string|null $hash_origen
 * @property int $version_reglas
 * @property int $total_paginas
 * @property int $paginas_clasificadas
 * @property int $paginas_sin_clasificar
 * @property int $paginas_sin_texto
 * @property bool $ocr_disponible
 * @property string|null $ocr_motor
 * @property list<array<string, mixed>>|null $paginas
 * @property list<array<string, mixed>>|null $datos_detectados
 * @property list<string>|null $advertencias
 * @property string|null $error_message
 * @property int|null $ejecutado_por
 * @property Carbon|null $created_at
 * @property-read ExpedienteHistorico $historico
 * @property-read Colaborador $colaborador
 * @property-read User|null $ejecutadoPor
 */
class DivisionExpedienteHistorico extends Model
{
    protected $table = 'divisiones_expediente_historico';

    protected $fillable = [
        'expediente_historico_id', 'colaborador_id', 'estado', 'hash_origen', 'version_reglas', 'total_paginas',
        'paginas_clasificadas', 'paginas_sin_clasificar', 'paginas_sin_texto', 'ocr_disponible', 'ocr_motor',
        'paginas', 'datos_detectados', 'advertencias', 'error_message', 'ejecutado_por',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoDivisionHistorico::class,
            'version_reglas' => 'integer',
            'total_paginas' => 'integer',
            'paginas_clasificadas' => 'integer',
            'paginas_sin_clasificar' => 'integer',
            'paginas_sin_texto' => 'integer',
            'ocr_disponible' => 'boolean',
            'paginas' => 'array',
            'datos_detectados' => 'array',
            'advertencias' => 'array',
        ];
    }

    /**
     * @return BelongsTo<ExpedienteHistorico, $this>
     */
    public function historico(): BelongsTo
    {
        return $this->belongsTo(ExpedienteHistorico::class, 'expediente_historico_id');
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
    public function ejecutadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ejecutado_por');
    }

    /**
     * @return HasMany<ParteDivisionHistorico, $this>
     */
    public function partes(): HasMany
    {
        return $this->hasMany(ParteDivisionHistorico::class, 'division_id')->orderBy('orden');
    }
}
