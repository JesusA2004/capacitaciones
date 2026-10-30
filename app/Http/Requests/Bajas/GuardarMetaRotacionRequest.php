<?php

namespace App\Http\Requests\Bajas;

use App\Enums\SegmentoRotacion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Nueva meta mensual de rotación de un segmento. La autorización real
 * (bajas.gestionar) la aplica el controlador.
 */
class GuardarMetaRotacionRequest extends FormRequest
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
            'segmento' => ['required', Rule::enum(SegmentoRotacion::class)],
            'verde_hasta' => ['required', 'numeric', 'min:0', 'max:100'],
            'ambar_hasta' => ['required', 'numeric', 'gt:verde_hasta', 'max:100'],
            'vigente_desde' => ['required', 'date_format:Y-m'],
            'motivo' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'verde_hasta.required' => 'Captura el límite verde.',
            'ambar_hasta.required' => 'Captura el límite ámbar.',
            'ambar_hasta.gt' => 'El límite ámbar debe ser mayor que el verde.',
            'vigente_desde.required' => 'Elige el mes desde el que aplica.',
            'vigente_desde.date_format' => 'Elige el mes desde el que aplica.',
            'motivo.required' => 'Explica el motivo del cambio: queda en el historial.',
            'motivo.min' => 'Explica el motivo del cambio: queda en el historial.',
        ];
    }
}
