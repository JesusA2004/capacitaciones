<?php

namespace App\Http\Requests\CicloLaboral;

use App\Enums\EntregaAvisoRescision;
use App\Enums\Recontratable;
use App\Enums\TipoBaja;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mismas reglas que SolicitarCierreRequest (tipo de baja de tres, causal de
 * rotación del tipo, detalle libre opcional).
 */
class IniciarCierreRequest extends FormRequest
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
            'tipo_baja' => ['required', Rule::enum(TipoBaja::class)],
            'causal_rotacion_id' => ['nullable', 'integer'],
            'motivo' => ['nullable', 'string', 'max:2000'],
            'fecha_efectiva' => ['required', 'date'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            // Expediente de la baja (módulo de Bajas). Opcionales: la API
            // móvil instalada no los manda y RH los completa al validar.
            'recontratable' => ['nullable', Rule::enum(Recontratable::class)],
            'fecha_conocimiento_falta' => ['nullable', 'date'],
            'entrega_aviso_rescision' => ['nullable', Rule::enum(EntregaAvisoRescision::class)],
            'fecha_entrega_aviso' => ['nullable', 'date'],
        ];
    }
}
