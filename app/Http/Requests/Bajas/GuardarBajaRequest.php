<?php

namespace App\Http\Requests\Bajas;

use App\Enums\EntregaAvisoRescision;
use App\Enums\FormaSalida;
use App\Enums\Recontratable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Captura de la baja por el gerente (Guardar borrador / Enviar a RH). Solo
 * valida formato: la completitud (RN-01…RN-10) la exige BajaReglasService
 * al enviar, y el permiso + alcance BajaService (mismo flujo web y API).
 */
class GuardarBajaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'colaborador_id' => [$this->route('baja') === null ? 'required' : 'prohibited', 'integer'],
            'forma_salida' => ['nullable', Rule::enum(FormaSalida::class)],
            'causal_rotacion_id' => ['nullable', 'integer'],
            'fecha_baja' => ['nullable', 'date'],
            'recontratable' => ['nullable', Rule::enum(Recontratable::class)],
            'comentarios' => ['nullable', 'string', 'max:'.(int) config('bajas.reglas.comentario_maximo', 500)],
            'fecha_conocimiento_falta' => ['nullable', 'date'],
            'entrega_aviso_rescision' => ['nullable', Rule::enum(EntregaAvisoRescision::class)],
            'fecha_entrega_aviso' => ['nullable', 'date'],
            'enviar' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'colaborador_id.required' => 'Elige al colaborador (busca por nombre o número de empleado).',
            'forma_salida.enum' => 'Elige cómo se va: renuncia voluntaria, no renovación o rescisión.',
            'recontratable.enum' => 'Indica si es recontratable: Sí, No o Con reservas.',
            'entrega_aviso_rescision.enum' => 'Indica cómo se entregó el aviso: personal o ante el Tribunal.',
            'comentarios.max' => 'El comentario admite hasta :max caracteres.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function datos(): array
    {
        return $this->safe()->except(['enviar']);
    }
}
