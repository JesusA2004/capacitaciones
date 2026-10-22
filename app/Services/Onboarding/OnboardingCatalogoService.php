<?php

namespace App\Services\Onboarding;

use App\Enums\TipoModuloOnboarding;
use App\Models\OnboardingModulo;
use App\Models\TipoActivo;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Evaluaciones\FormularioEvaluacionService;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

/**
 * Catálogo configurable del onboarding: módulos (material + evaluación) y
 * tipos de activo. Lo administra RH; los procesos ya abiertos conservan sus
 * avances (los cambios aplican a onboardings nuevos).
 */
class OnboardingCatalogoService
{
    public function __construct(private readonly AuditoriaService $auditoria) {}

    /**
     * Cómo se evalúa la Inducción Mr. Lana (módulos con evaluacion_formal_clave)
     * para «Configuración de onboarding»: título/código, puntos y mínimo del
     * formulario VIGENTE (FormularioEvaluacionService; sin versión publicada,
     * el formato original de config/) y dónde se configura.
     *
     * @return array{titulo: string, codigo: string, version: string, total_puntos: float, calificacion_aprobatoria: float, configurar_url: string|null}
     */
    public function resumenEvaluacionFormal(User $actor): array
    {
        $definicion = app(FormularioEvaluacionService::class)->definicionInduccion();
        $numero = fn (mixed $v): float => is_numeric($v) ? (float) $v : 0.0;
        $texto = fn (mixed $v, string $defecto): string => is_string($v) && $v !== '' ? $v : $defecto;

        return [
            'titulo' => $texto($definicion['titulo'] ?? null, 'Evaluación del Programa de Inducción'),
            'codigo' => $texto($definicion['codigo'] ?? null, 'FO-RH-EI'),
            'version' => $texto(isset($definicion['version']) && is_scalar($definicion['version']) ? (string) $definicion['version'] : null, ''),
            'total_puntos' => $numero($definicion['total_puntos'] ?? null),
            'calificacion_aprobatoria' => $numero($definicion['calificacion_aprobatoria'] ?? null),
            'configurar_url' => Route::has('rh.formularios-evaluacion.editar') && $actor->can('formularios_evaluacion.administrar')
                ? route('rh.formularios-evaluacion.editar', 'induccion')
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $datos  Validado por GuardarModuloOnboardingRequest.
     */
    public function guardarModulo(array $datos, User $actor, ?OnboardingModulo $modulo = null): OnboardingModulo
    {
        $tipo = TipoModuloOnboarding::from((string) $datos['tipo']);

        // Módulo con evaluación formal (Inducción Mr. Lana): su contenido es el
        // PDF oficial + material y se evalúa con el FO-RH-EI. Solo se editan
        // sus datos generales; nunca su quiz, su contenido ni su tipo.
        if ($modulo !== null && $modulo->evaluacion_formal_clave !== null) {
            $modulo->update([
                'titulo' => (string) $datos['titulo'],
                'descripcion' => $datos['descripcion'] ?? null,
                'orden' => (int) ($datos['orden'] ?? $modulo->orden),
                'obligatorio' => (bool) ($datos['obligatorio'] ?? true),
                'activo' => (bool) ($datos['activo'] ?? true),
            ]);
            $this->auditoria->registrar('onboarding_modulo_guardado', $modulo, $actor, ['titulo' => $modulo->titulo, 'formal' => true]);

            return $modulo;
        }

        if ($tipo === TipoModuloOnboarding::Puesto && empty($datos['puesto_id'])) {
            throw ValidationException::withMessages(['puesto_id' => 'Elige el puesto al que aplica este módulo.']);
        }

        $preguntas = [];

        foreach ((array) ($datos['preguntas'] ?? []) as $pregunta) {
            if (! is_array($pregunta)) {
                continue;
            }

            $opciones = array_values(array_filter(array_map(fn ($o) => trim((string) $o), (array) ($pregunta['opciones'] ?? [])), fn (string $o) => $o !== ''));
            $correcta = (int) ($pregunta['correcta'] ?? 0);

            if (trim((string) ($pregunta['pregunta'] ?? '')) === '' || count($opciones) < 2 || ! array_key_exists($correcta, $opciones)) {
                throw ValidationException::withMessages(['preguntas' => 'Cada pregunta necesita texto, al menos dos opciones y la respuesta correcta marcada.']);
            }

            $preguntas[] = ['pregunta' => trim((string) $pregunta['pregunta']), 'opciones' => $opciones, 'correcta' => $correcta];
        }

        if ($preguntas === []) {
            throw ValidationException::withMessages(['preguntas' => 'Agrega al menos una pregunta de evaluación.']);
        }

        $atributos = [
            'titulo' => (string) $datos['titulo'],
            'descripcion' => $datos['descripcion'] ?? null,
            'tipo' => $tipo,
            'puesto_id' => $tipo === TipoModuloOnboarding::Puesto ? (int) $datos['puesto_id'] : null,
            'orden' => (int) ($datos['orden'] ?? 1),
            'contenido_url' => $datos['contenido_url'] ?? null,
            'contenido' => $datos['contenido'] ?? null,
            'preguntas' => $preguntas,
            'calificacion_minima' => (float) ($datos['calificacion_minima'] ?? config('ciclo_laboral.onboarding.calificacion_minima', 8)),
            'obligatorio' => (bool) ($datos['obligatorio'] ?? true),
            'activo' => (bool) ($datos['activo'] ?? true),
        ];

        if ($modulo === null) {
            $modulo = OnboardingModulo::query()->create($atributos);
        } else {
            $modulo->update($atributos);
        }

        $this->auditoria->registrar('onboarding_modulo_guardado', $modulo, $actor, ['titulo' => $modulo->titulo]);

        return $modulo;
    }

    /**
     * @param  array<string, mixed>  $datos  Validado por GuardarTipoActivoRequest.
     */
    public function guardarTipoActivo(array $datos, User $actor, ?TipoActivo $tipo = null): TipoActivo
    {
        $puestos = array_values(array_map('intval', (array) ($datos['puesto_ids'] ?? [])));
        $atributos = [
            'clave' => (string) $datos['clave'],
            'nombre' => (string) $datos['nombre'],
            'requiere_identificador' => (bool) ($datos['requiere_identificador'] ?? false),
            'obligatorio' => (bool) ($datos['obligatorio'] ?? true),
            'activo' => (bool) ($datos['activo'] ?? true),
            'puesto_ids' => $puestos === [] ? null : $puestos,
        ];

        if ($tipo === null) {
            $tipo = TipoActivo::query()->create($atributos);
        } else {
            $tipo->update($atributos);
        }

        $this->auditoria->registrar('tipo_activo_guardado', $tipo, $actor, ['clave' => $tipo->clave]);

        return $tipo;
    }
}
