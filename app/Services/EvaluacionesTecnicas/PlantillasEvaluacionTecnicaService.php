<?php

namespace App\Services\EvaluacionesTecnicas;

use App\Enums\ClaveFormularioEvaluacion;
use App\Enums\EstadoFormularioEvaluacion;
use App\Models\FormularioEvaluacion;
use App\Models\PlantillaEvaluacionTecnica;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Evaluaciones\FormularioEvaluacionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Formularios técnicos reutilizables. Cada plantilla es una «familia» de
 * versiones en formularios_evaluacion (clave «tecnica»): se edita, publica y
 * descarta con FormularioEvaluacionService, igual que inducción y periodo
 * de prueba. Aquí solo vive el catálogo (crear, renombrar, activar).
 */
class PlantillasEvaluacionTecnicaService
{
    public function __construct(
        private readonly FormularioEvaluacionService $formularios,
        private readonly DefinicionFormularioTecnicoService $definicion,
        private readonly AuditoriaService $auditoria,
    ) {}

    /**
     * Crea la plantilla con su versión 1 en borrador (estructura de ejemplo).
     *
     * @param  array{nombre: string, descripcion?: string|null}  $datos
     */
    public function crear(User $actor, array $datos): PlantillaEvaluacionTecnica
    {
        $plantilla = DB::transaction(function () use ($actor, $datos): PlantillaEvaluacionTecnica {
            $plantilla = PlantillaEvaluacionTecnica::query()->create([
                'nombre' => Str::limit(trim($datos['nombre']), 160, ''),
                'descripcion' => $this->textoOpcional($datos['descripcion'] ?? null),
                'activa' => true,
                'creado_por' => $actor->id,
            ]);

            $this->formularios->crearBorrador(ClaveFormularioEvaluacion::Tecnica, $actor, null, $plantilla);

            return $plantilla;
        });

        $this->auditoria->registrar('plantilla_evaluacion_tecnica_creada', $plantilla, $actor, ['nombre' => $plantilla->nombre]);

        return $plantilla;
    }

    /**
     * @param  array{nombre: string, descripcion?: string|null, activa?: bool}  $datos
     */
    public function actualizar(PlantillaEvaluacionTecnica $plantilla, User $actor, array $datos): PlantillaEvaluacionTecnica
    {
        $plantilla->update([
            'nombre' => Str::limit(trim($datos['nombre']), 160, ''),
            'descripcion' => $this->textoOpcional($datos['descripcion'] ?? null),
            'activa' => $datos['activa'] ?? $plantilla->activa,
        ]);

        $this->auditoria->registrar('plantilla_evaluacion_tecnica_actualizada', $plantilla, $actor, $plantilla->only(['nombre', 'activa']));

        return $plantilla->refresh();
    }

    /**
     * Catálogo para el configurador: estado de versiones y en qué pasos se usa.
     *
     * @return list<array<string, mixed>>
     */
    public function catalogo(): array
    {
        $plantillas = PlantillaEvaluacionTecnica::query()
            ->withCount('pasos')
            ->orderByDesc('activa')
            ->orderBy('nombre')
            ->get();

        $versiones = FormularioEvaluacion::query()
            ->where('clave', ClaveFormularioEvaluacion::Tecnica->value)
            ->whereIn('plantilla_evaluacion_tecnica_id', $plantillas->modelKeys())
            ->whereIn('estado', [EstadoFormularioEvaluacion::Publicado->value, EstadoFormularioEvaluacion::Borrador->value])
            ->get()
            ->groupBy('plantilla_evaluacion_tecnica_id');

        return array_values($plantillas->map(function (PlantillaEvaluacionTecnica $p) use ($versiones): array {
            $filas = $versiones->get($p->id, collect());
            $publicado = $filas->first(fn (FormularioEvaluacion $f): bool => $f->estado === EstadoFormularioEvaluacion::Publicado);
            $borrador = $filas->first(fn (FormularioEvaluacion $f): bool => $f->estado === EstadoFormularioEvaluacion::Borrador);
            $vigente = $publicado ?? $borrador;
            $metricas = $vigente !== null ? $this->definicion->metricas($this->definicion->normalizar($vigente->definicion)) : null;

            return [
                'id' => $p->id,
                'nombre' => $p->nombre,
                'descripcion' => $p->descripcion,
                'activa' => $p->activa,
                'pasos' => $p->pasos_count,
                'version_publicada' => $publicado?->version,
                'tiene_borrador' => $borrador !== null,
                'acento' => is_array($vigente?->diseno) ? ($vigente->diseno['acento'] ?? null) : null,
                'preguntas' => $metricas['preguntas'] ?? 0,
                'total_puntos' => $metricas['total_puntos'] ?? 0,
                'actualizado_en' => ($vigente->updated_at ?? $p->updated_at)?->toIso8601String(),
            ];
        })->all());
    }

    private function textoOpcional(?string $texto): ?string
    {
        $limpio = trim((string) $texto);

        return $limpio === '' ? null : Str::limit($limpio, 2000, '');
    }
}
