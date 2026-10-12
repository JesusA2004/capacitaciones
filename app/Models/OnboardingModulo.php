<?php

namespace App\Models;

use App\Enums\TipoModuloOnboarding;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Módulo de onboarding configurable por RH: inducción institucional (todos)
 * o inducción al puesto (puesto_id). Cada módulo trae su material y su
 * evaluación (preguntas de opción múltiple). La respuesta correcta de cada
 * pregunta nunca sale al cliente (ver preguntasPublicas()).
 *
 * @property int $id
 * @property string|null $clave
 * @property string $titulo
 * @property string|null $descripcion
 * @property TipoModuloOnboarding $tipo
 * @property int|null $puesto_id
 * @property int $orden
 * @property string|null $contenido_url
 * @property string|null $contenido
 * @property array<int, array<string, mixed>>|null $preguntas Cada una {pregunta, opciones[], correcta}; viene de JSON capturado por RH.
 * @property string $calificacion_minima
 * @property bool $obligatorio
 * @property bool $activo
 * @property string|null $evaluacion_formal_clave Si no es null, el avance de este módulo lo aprueba una evaluación formal (ver App\Services\Onboarding\EvaluacionInduccionService), no el quiz de 'preguntas'.
 * @property Carbon|null $created_at
 * @property-read Puesto|null $puesto
 * @property-read list<array<mixed, mixed>> $contenido_estructurado
 */
class OnboardingModulo extends Model
{
    protected $table = 'onboarding_modulos';

    protected $fillable = [
        'clave', 'titulo', 'descripcion', 'tipo', 'puesto_id', 'orden', 'contenido_url', 'contenido',
        'preguntas', 'calificacion_minima', 'obligatorio', 'activo', 'evaluacion_formal_clave',
    ];

    protected function casts(): array
    {
        return [
            'tipo' => TipoModuloOnboarding::class,
            'preguntas' => 'array',
            'calificacion_minima' => 'decimal:2',
            'obligatorio' => 'boolean',
            'activo' => 'boolean',
            'orden' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Puesto, $this>
     */
    public function puesto(): BelongsTo
    {
        return $this->belongsTo(Puesto::class);
    }

    /**
     * `contenido` como lista de capítulos navegables ({clave, numero,
     * titulo, resumen, bloques[]}) cuando el módulo lo guarda como JSON (hoy
     * solo "Inducción Mr. Lana" — ver InduccionMrLanaModuloSeeder). Los
     * módulos que guardan texto plano en `contenido` devuelven una lista
     * vacía: nunca truena, solo no tienen visor de capítulos.
     *
     * @return Attribute<list<array<mixed, mixed>>, never>
     */
    protected function contenidoEstructurado(): Attribute
    {
        return Attribute::get(function (): array {
            if ($this->contenido === null || ! str_starts_with(trim($this->contenido), '[')) {
                return [];
            }

            $decodificado = json_decode($this->contenido, true);

            if (! is_array($decodificado)) {
                return [];
            }

            return array_values(array_filter($decodificado, 'is_array'));
        });
    }

    /**
     * true si el avance de este módulo NO se aprueba con el quiz genérico
     * (preguntasPublicas()/preguntas), sino con una evaluación formal
     * (EvaluacionInduccionService::habilitarPara()).
     */
    public function tieneEvaluacionFormal(): bool
    {
        return $this->evaluacion_formal_clave !== null;
    }

    /**
     * Preguntas sin la respuesta correcta (lo único que ve el colaborador).
     *
     * @return list<array{indice: int, pregunta: string, opciones: list<string>}>
     */
    public function preguntasPublicas(): array
    {
        $publicas = [];

        foreach ($this->preguntas ?? [] as $indice => $pregunta) {
            $opciones = [];

            foreach ((array) ($pregunta['opciones'] ?? []) as $opcion) {
                $opciones[] = is_scalar($opcion) ? (string) $opcion : '';
            }

            $publicas[] = [
                'indice' => $indice,
                'pregunta' => is_scalar($pregunta['pregunta'] ?? null) ? (string) $pregunta['pregunta'] : '',
                'opciones' => $opciones,
            ];
        }

        return $publicas;
    }
}
