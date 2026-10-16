<?php

namespace App\Http\Requests\CicloLaboral;

use App\Enums\FormaPagoFiniquito;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * «Registrar entrega de finiquito»: fecha en que RH lo entregó, forma de
 * pago y comentario opcional. La regla de negocio (congelar el finiquito y
 * cerrar el cierre) vive en CierreLaboralService::registrarEntregaFiniquito().
 */
class EntregaFiniquitoRequest extends FormRequest
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
            'fecha' => ['required', 'date', 'before_or_equal:today'],
            'forma_pago' => ['nullable', Rule::enum(FormaPagoFiniquito::class)],
            'comentario' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fecha.required' => 'Indica la fecha de entrega del finiquito.',
            'fecha.before_or_equal' => 'La fecha de entrega no puede ser futura.',
        ];
    }

    /**
     * @return array{fecha: string, forma_pago: string|null, comentario: string|null}
     */
    public function datosEntrega(): array
    {
        $formaPago = $this->validated('forma_pago');
        $comentario = $this->validated('comentario');

        return [
            'fecha' => (string) $this->validated('fecha'),
            'forma_pago' => is_string($formaPago) && $formaPago !== '' ? $formaPago : null,
            'comentario' => is_string($comentario) && trim($comentario) !== '' ? trim($comentario) : null,
        ];
    }
}
