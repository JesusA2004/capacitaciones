<?php

namespace App\Http\Requests\Bajas;

use App\Enums\EntregaAvisoRescision;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Hechos y entrega del aviso de rescisión. La autorización y las reglas de
 * negocio viven en BajaPolicy y BajaDocumentoSalidaService.
 */
class GuardarAvisoRescisionRequest extends FormRequest
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
        return [
            'fundamento' => ['required', 'string', 'max:255'],
            'que_ocurrio' => ['required', 'string', 'max:2000'],
            'hechos_en' => ['required', 'date', 'before_or_equal:now'],
            'lugar' => ['required', 'string', 'max:255'],
            'constataron' => ['required', 'string', 'max:500'],
            'relato' => ['required', 'string', 'max:5000'],
            'testigos' => ['required', 'array', 'max:6'],
            'testigos.*.nombre' => ['nullable', 'string', 'max:150'],
            'testigos.*.cargo' => ['nullable', 'string', 'max:150'],
            'fecha_aviso' => ['required', 'date', 'before_or_equal:today'],
            'metodo_entrega' => ['required', Rule::enum(EntregaAvisoRescision::class)->only(EntregaAvisoRescision::vigentes())],
            'fecha_entrega' => ['required', 'date', 'before_or_equal:today'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fundamento.required' => 'Indica la causal y fracción aplicable.',
            'que_ocurrio.required' => 'Describe qué ocurrió.',
            'hechos_en.required' => 'Indica la fecha y hora de los hechos.',
            'hechos_en.before_or_equal' => 'La fecha de los hechos no puede ser futura.',
            'lugar.required' => 'Indica el lugar de los hechos.',
            'constataron.required' => 'Indica quién constató los hechos.',
            'relato.required' => 'Escribe el relato circunstanciado.',
            'testigos.required' => 'Registra a los testigos.',
            'fecha_aviso.required' => 'Indica la fecha del aviso.',
            'metodo_entrega.required' => 'Indica cómo se entregó el aviso.',
            'fecha_entrega.required' => 'Indica la fecha real de entrega o de negativa.',
        ];
    }
}
