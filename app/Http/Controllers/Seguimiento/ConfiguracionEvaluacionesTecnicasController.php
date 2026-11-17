<?php

namespace App\Http\Controllers\Seguimiento;

use App\Enums\QuienContestaEvaluacionTecnica;
use App\Http\Controllers\Controller;
use App\Models\EvaluacionTecnica;
use App\Models\PlantillaEvaluacionTecnica;
use App\Models\Puesto;
use App\Services\EvaluacionesTecnicas\CalendarioEvaluacionTecnicaService;
use App\Services\EvaluacionesTecnicas\PlantillasEvaluacionTecnicaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configurador de evaluaciones técnicas: calendario por puesto («Seguimiento
 * 15 / 30 / 60…») y catálogo de formularios técnicos. Permiso
 * evaluaciones_tecnicas.configurar (EvaluacionTecnicaPolicy::configurar).
 */
class ConfiguracionEvaluacionesTecnicasController extends Controller
{
    public function __construct(
        private readonly CalendarioEvaluacionTecnicaService $calendario,
        private readonly PlantillasEvaluacionTecnicaService $plantillas,
    ) {}

    public function index(): Response
    {
        $this->authorize('configurar', EvaluacionTecnica::class);

        return Inertia::render('Seguimiento/EvaluacionesTecnicas/Configuracion', [
            'puestos' => $this->calendario->puestos(),
            'plantillas' => $this->plantillas->catalogo(),
            'opcionesContesta' => array_map(fn (QuienContestaEvaluacionTecnica $q): array => ['value' => $q->value, 'label' => $q->etiqueta()], QuienContestaEvaluacionTecnica::cases()),
            'diasGracia' => (int) config('evaluaciones_tecnicas.dias_gracia_generacion', 15),
        ]);
    }

    public function guardarCalendario(Request $request, Puesto $puesto): RedirectResponse
    {
        $this->authorize('configurar', EvaluacionTecnica::class);
        $datos = $request->validate(['pasos' => ['present', 'array'], 'pasos.*' => ['array']]);

        $this->calendario->guardar($puesto, $request->user(), array_values((array) $datos['pasos']));

        return back()->with('toast', ['type' => 'success', 'message' => sprintf('Guardaste el calendario de %s.', $puesto->nombre)]);
    }

    public function copiarCalendario(Request $request, Puesto $puesto): RedirectResponse
    {
        $this->authorize('configurar', EvaluacionTecnica::class);
        $datos = $request->validate(
            ['destinos' => ['required', 'array', 'min:1'], 'destinos.*' => ['integer']],
            ['destinos.required' => 'Elige al menos un puesto destino.'],
        );

        $copiados = $this->calendario->copiar($puesto, $request->user(), array_values(array_map('intval', (array) $datos['destinos'])));

        return back()->with('toast', ['type' => 'success', 'message' => sprintf('Copiaste el calendario de %s a %d %s.', $puesto->nombre, $copiados, $copiados === 1 ? 'puesto' : 'puestos')]);
    }

    public function crearFormulario(Request $request): RedirectResponse
    {
        $this->authorize('configurar', EvaluacionTecnica::class);
        $datos = $this->validarPlantilla($request);

        $plantilla = $this->plantillas->crear($request->user(), $datos);

        return to_route('evaluaciones-tecnicas.formularios.editar', $plantilla->id)->with('toast', [
            'type' => 'success',
            'message' => 'Formulario creado. Arma sus preguntas y publícalo para poder usarlo en el calendario.',
        ]);
    }

    public function actualizarFormulario(Request $request, PlantillaEvaluacionTecnica $plantilla): RedirectResponse
    {
        $this->authorize('configurar', EvaluacionTecnica::class);
        $this->plantillas->actualizar($plantilla, $request->user(), $this->validarPlantilla($request));

        return back()->with('toast', ['type' => 'success', 'message' => 'Formulario actualizado.']);
    }

    /**
     * @return array{nombre: string, descripcion?: string|null, activa?: bool}
     */
    private function validarPlantilla(Request $request): array
    {
        $datos = $request->validate(
            [
                'nombre' => ['required', 'string', 'max:160'],
                'descripcion' => ['nullable', 'string', 'max:2000'],
                'activa' => ['sometimes', 'boolean'],
            ],
            [
                'nombre.required' => 'Ponle nombre al formulario (p. ej. «Técnica de cobranza — Gestor»).',
                'nombre.max' => 'El nombre puede tener hasta 160 caracteres.',
            ],
        );

        $limpios = [
            'nombre' => (string) $datos['nombre'],
            'descripcion' => isset($datos['descripcion']) ? (string) $datos['descripcion'] : null,
        ];

        if (array_key_exists('activa', $datos)) {
            $limpios['activa'] = (bool) $datos['activa'];
        }

        return $limpios;
    }
}
