<?php

namespace App\Http\Requests\CicloLaboral;

use App\Enums\EstadoUsuario;
use App\Enums\TipoActa;
use App\Models\ActaAdministrativa;
use App\Models\Colaborador;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'tipo' => [$this->isMethod('post') ? 'required' : 'prohibited', Rule::enum(TipoActa::class)],
            // Encabezado del formato oficial («ACTA ADMINISTRATIVA POR …»):
            // obligatorio al levantar un acta administrativa.
            'motivo' => [Rule::requiredIf($this->isMethod('post') && $this->input('tipo') === TipoActa::Administrativa->value), 'nullable', 'string', 'max:191'],
            'fecha' => [$requerido, 'date', 'before_or_equal:today'],
            'hora' => ['nullable', 'date_format:H:i'],
            'lugar' => ['nullable', 'string', 'max:255'],
            'sucursal_id' => ['nullable', 'integer', 'exists:sucursales,id'],
            'hechos' => [$requerido, 'string', 'max:20000'],
            'responsable_id' => ['nullable', 'integer', 'exists:users,id'],
            // Captura DIFERIDA permitida (el acta puede nacer sin testigos y
            // completarse al editar, antes de generar el formato — el motor
            // documental es quien exige los dos al generar). Pero si llega
            // algún testigo, el formato oficial exige exactamente dos,
            // elegidos de un buscador (nunca texto libre) — CLAUDE.md §76-79.
            'testigos' => ['nullable', 'array', 'max:2'],
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
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'testigos.*.colaborador_id.distinct' => 'Los dos testigos deben ser personas distintas.',
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
