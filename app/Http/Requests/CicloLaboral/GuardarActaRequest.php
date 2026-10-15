<?php

namespace App\Http\Requests\CicloLaboral;

use App\Enums\CaracterLevantamientoActa;
use App\Enums\EstadoUsuario;
use App\Enums\RelacionTestigoActa;
use App\Enums\TipoActa;
use App\Enums\TipoIncidenciaActa;
use App\Models\ActaAdministrativa;
use App\Models\Colaborador;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class GuardarActaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $requerido = $this->isMethod('post') ? 'required' : 'sometimes';
        $colaboradorDelActa = $this->colaboradorDelActa();

        return [
            // Solo el acta administrativa circunstanciada (las históricas se siguen leyendo).
            'tipo' => [$this->isMethod('post') ? 'required' : 'prohibited', Rule::enum(TipoActa::class)->only(TipoActa::creables())],
            // Resumen para listados: en el acta circunstanciada sale del tipo
            // de incidencia (ActaService); ya no se captura aparte.
            'motivo' => ['nullable', 'string', 'max:191'],
            'fecha' => [$requerido, 'date', 'before_or_equal:today'],
            'hora' => ['nullable', 'date_format:H:i'],
            'lugar' => ['nullable', 'string', 'max:255'],
            'sucursal_id' => ['nullable', 'integer', 'exists:sucursales,id'],
            'hechos' => [$requerido, 'string', 'max:20000'],
            // Quien levanta el acta es siempre quien la registra (ActaService):
            // no existe permiso para asignarla a otra cuenta.
            'responsable_id' => ['prohibited'],
            // Captura DIFERIDA permitida (el acta puede nacer sin testigos y
            // completarse al editar, antes de generar el formato — el motor
            // documental es quien exige los dos al generar). Pero si llega
            // algún testigo, el formato oficial exige exactamente dos,
            // elegidos de un buscador (nunca texto libre) — CLAUDE.md §76-79.
            'testigos' => ['nullable', 'array', 'max:2', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_array($value) && count($value) === 1) {
                    $fail('El acta lleva exactamente dos testigos distintos.');
                }
            }],
            // nombre/puesto del testigo NUNCA se guardan tal cual: el
            // backend los resuelve del Colaborador real (ActaService) — lo
            // que el cliente mande aquí es ignorado, solo se exige para no
            // romper un formulario que todavía los envíe.
            'testigos.*.nombre' => ['nullable', 'string', 'max:160'],
            'testigos.*.puesto' => ['nullable', 'string', 'max:120'],
            'testigos.*.colaborador_id' => [
                'required', 'integer', 'exists:colaboradores,id', 'distinct',
                function (string $attribute, mixed $value, \Closure $fail) use ($colaboradorDelActa): void {
                    if ($colaboradorDelActa !== null && (int) $value === $colaboradorDelActa) {
                        $fail('Un testigo no puede ser el colaborador del acta.');

                        return;
                    }

                    // withTrashed(): un colaborador dado de baja (soft delete)
                    // sigue existiendo en la tabla — la regla `exists` de
                    // arriba no distingue eso, así que aquí sí hay que
                    // revisarlo explícitamente para no aceptar un testigo
                    // de baja (encargo §26).
                    $testigo = Colaborador::withTrashed()->find((int) $value);

                    if ($testigo === null || $testigo->trashed() || $testigo->estatus !== EstadoUsuario::Activo) {
                        $fail('Un testigo debe ser un colaborador activo.');
                    }
                },
            ],
            'declaraciones' => ['nullable', 'array', 'max:20'],
            'declaraciones.*.persona' => ['required_with:declaraciones', 'string', 'max:160'],
            'declaraciones.*.declaracion' => ['required_with:declaraciones', 'string', 'max:5000'],

            // --- Acta administrativa circunstanciada (docs/acta/…docx) ---
            // Se guarda por partes (borrador); lo indispensable se exige al
            // generar (ActaPdfService::faltante). Personas SIEMPRE de un
            // buscador: el nombre/puesto/área los pone el backend.
            'circunstanciada' => ['nullable', 'array'],
            'circunstanciada.disposiciones' => ['nullable', 'string', 'max:5000'],
            'circunstanciada.incidencia.tipo' => ['nullable', Rule::enum(TipoIncidenciaActa::class)],
            'circunstanciada.incidencia.otro' => ['nullable', 'required_if:circunstanciada.incidencia.tipo,otra', 'string', 'max:160'],
            'circunstanciada.incidencia.fecha_hechos' => ['nullable', 'string', 'max:120'],
            // Fecha o período elegido con DatePicker/TimePicker: el texto
            // natural («del 6 al 8 de octubre de 2026») lo compone ActaService.
            'circunstanciada.incidencia.fecha_desde' => ['nullable', 'date', 'before_or_equal:today'],
            'circunstanciada.incidencia.fecha_hasta' => ['nullable', 'date', 'after_or_equal:circunstanciada.incidencia.fecha_desde', 'before_or_equal:today'],
            'circunstanciada.incidencia.hora_desde' => ['nullable', 'date_format:H:i'],
            'circunstanciada.incidencia.hora_hasta' => ['nullable', 'date_format:H:i'],
            'circunstanciada.incidencia.hora_hechos' => ['nullable', 'string', 'max:60'],
            'circunstanciada.incidencia.lugar_hechos' => ['nullable', 'string', 'max:255'],
            'circunstanciada.responsable.caracter' => ['nullable', Rule::enum(CaracterLevantamientoActa::class)],
            'circunstanciada.responsable.manifestacion' => ['nullable', 'string', 'max:10000'],
            'circunstanciada.jefe.participa' => ['nullable', 'boolean'],
            'circunstanciada.jefe.colaborador_id' => ['nullable', 'required_if:circunstanciada.jefe.participa,true', 'integer', $this->activo(), $this->noEsElTrabajador($colaboradorDelActa)],
            'circunstanciada.jefe.manifestacion' => ['nullable', 'string', 'max:10000'],
            // 0, 1 o 2 testigos de HECHOS según lo que realmente ocurrió:
            // nunca se obliga a «llenar» el formato con personas.
            'circunstanciada.testigos_hechos' => ['nullable', 'array', 'max:2'],
            'circunstanciada.testigos_hechos.*.colaborador_id' => ['required', 'integer', 'distinct', $this->activo(), $this->noEsElTrabajador($colaboradorDelActa)],
            'circunstanciada.testigos_hechos.*.relacion' => ['required', Rule::enum(RelacionTestigoActa::class)],
            'circunstanciada.testigos_hechos.*.relacion_otro' => ['nullable', 'string', 'max:160'],
            'circunstanciada.testigos_hechos.*.declaro' => ['nullable', 'boolean'],
            'circunstanciada.testigos_hechos.*.manifestacion' => ['nullable', 'string', 'max:10000'],
            'circunstanciada.testigos_asistencia' => ['nullable', 'array', 'max:2'],
            'circunstanciada.testigos_asistencia.*.colaborador_id' => ['required', 'integer', 'distinct', $this->activo(), $this->noEsElTrabajador($colaboradorDelActa)],
            'circunstanciada.testigos_asistencia.*.manifestacion' => ['nullable', 'string', 'max:5000'],
            'circunstanciada.trabajador.manifesto' => ['nullable', 'boolean'],
            'circunstanciada.trabajador.manifestacion' => ['nullable', 'required_if:circunstanciada.trabajador.manifesto,true', 'string', 'max:20000'],
            'circunstanciada.trabajador.elementos' => ['nullable', 'string', 'max:5000'],
            'circunstanciada.incidencias_levantamiento' => ['nullable', 'string', 'max:5000'],
            'circunstanciada.cierre.fecha' => ['nullable', 'date'],
            'circunstanciada.cierre.hora' => ['nullable', 'date_format:H:i'],
        ];
    }

    /**
     * Una persona interviene una sola vez en el acta: no puede ser a la vez
     * jefe que participa, testigo de hechos y testigo de asistencia.
     *
     * @return list<\Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $c = $this->input('circunstanciada');

            if (! is_array($c)) {
                return;
            }

            $vistos = [];

            if ((bool) ($c['jefe']['participa'] ?? false) && is_numeric($c['jefe']['colaborador_id'] ?? null)) {
                $vistos[(int) $c['jefe']['colaborador_id']] = 'jefe';
            }

            foreach (['testigos_hechos', 'testigos_asistencia'] as $lista) {
                foreach (is_array($c[$lista] ?? null) ? $c[$lista] : [] as $i => $t) {
                    $id = is_array($t) && is_numeric($t['colaborador_id'] ?? null) ? (int) $t['colaborador_id'] : null;

                    if ($id === null) {
                        continue;
                    }

                    if (isset($vistos[$id]) && $vistos[$id] !== $lista) {
                        $validator->errors()->add(sprintf('circunstanciada.%s.%s.colaborador_id', $lista, $i), 'Esta persona ya interviene en el acta con otro carácter.');
                    }

                    $vistos[$id] ??= $lista;
                }
            }
        }];
    }

    /** Colaborador ACTIVO (no de baja ni eliminado). */
    private function activo(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $persona = Colaborador::withTrashed()->where('id', (int) $value)->first();

            if ($persona === null || $persona->trashed() || $persona->estatus !== EstadoUsuario::Activo) {
                $fail('Elige un colaborador activo.');
            }
        };
    }

    /** El trabajador involucrado no puede ser testigo de su propia acta. */
    private function noEsElTrabajador(?int $colaboradorDelActa): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($colaboradorDelActa): void {
            if ($colaboradorDelActa !== null && (int) $value === $colaboradorDelActa) {
                $fail('El trabajador involucrado no puede ser testigo de su propia acta.');
            }
        };
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'testigos.*.colaborador_id.distinct' => 'Los dos testigos deben ser personas distintas.',
            'circunstanciada.testigos_hechos.*.colaborador_id.distinct' => 'Una persona no puede aparecer dos veces como testigo de hechos.',
            'circunstanciada.testigos_asistencia.*.colaborador_id.distinct' => 'Una persona no puede aparecer dos veces como testigo de asistencia.',
            'circunstanciada.testigos_hechos.*.relacion.required' => 'Indica la relación del testigo con los hechos.',
            'circunstanciada.incidencia.otro.required_if' => 'Describe el tipo de incidencia.',
            'circunstanciada.incidencia.fecha_hasta.after_or_equal' => 'El final del período no puede ser anterior a su inicio.',
            'circunstanciada.incidencia.fecha_desde.before_or_equal' => 'Los hechos no pueden tener fecha futura.',
            'circunstanciada.incidencia.fecha_hasta.before_or_equal' => 'Los hechos no pueden tener fecha futura.',
            'circunstanciada.trabajador.manifestacion.required_if' => 'Captura lo que manifestó el trabajador o marca que no quiso manifestar.',
            'circunstanciada.jefe.colaborador_id.required_if' => 'Elige al jefe inmediato que interviene.',
            'responsable_id.prohibited' => 'Quien levanta el acta es quien la registra; no se puede asignar a otra persona.',
            'declaraciones.*.declaracion.required_with' => 'Escribe la manifestación del colaborador o indica que no quiso manifestar nada.',
        ];
    }

    private function colaboradorDelActa(): ?int
    {
        $colaborador = $this->route('colaborador');

        if ($colaborador instanceof Colaborador) {
            return $colaborador->id;
        }

        $acta = $this->route('acta');

        return $acta instanceof ActaAdministrativa ? $acta->colaborador_id : null;
    }
}
