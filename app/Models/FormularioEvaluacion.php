<?php

namespace App\Models;

use App\Enums\ClaveFormularioEvaluacion;
use App\Enums\EstadoFormularioEvaluacion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Una versión de un formulario de evaluación configurable (inducción o
 * periodo de prueba). Publicada = inmutable. `definicion` guarda preguntas,
 * respuestas correctas, rúbricas y reglas — NUNCA sale completa al
 * colaborador (ver FormularioEvaluacionService::contenidoPublicoInduccion()).
 *
 * @property int $id
 * @property ClaveFormularioEvaluacion $clave
 * @property int $version
 * @property string $codigo
 * @property string $titulo
 * @property string|null $descripcion
 * @property array<string, mixed>|null $diseno
 * @property array<string, mixed> $definicion
 * @property EstadoFormularioEvaluacion $estado
 * @property Carbon|null $publicado_en
 * @property int|null $publicado_por
 * @property int|null $creado_por
 * @property int|null $actualizado_por
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $publicadoPor
 * @property-read User|null $actualizadoPor
 */
class FormularioEvaluacion extends Model
{
    protected $table = 'formularios_evaluacion';

    protected $fillable = [
        'clave', 'version', 'codigo', 'titulo', 'descripcion', 'diseno', 'definicion',
        'estado', 'publicado_en', 'publicado_por', 'creado_por', 'actualizado_por',
    ];

    protected function casts(): array
    {
        return [
            'clave' => ClaveFormularioEvaluacion::class,
            'estado' => EstadoFormularioEvaluacion::class,
            'version' => 'integer',
            'diseno' => 'array',
            'definicion' => 'array',
            'publicado_en' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function publicadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'publicado_por');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actualizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actualizado_por');
    }

    public function esBorrador(): bool
    {
        return $this->estado === EstadoFormularioEvaluacion::Borrador;
    }

    /** Etiqueta de versión que se guarda en la evaluación (p. ej. FO-RH-EI-2026-v1). */
    public function etiquetaVersion(): string
    {
        return sprintf('%s-v%d', $this->codigo, $this->version);
    }
}
