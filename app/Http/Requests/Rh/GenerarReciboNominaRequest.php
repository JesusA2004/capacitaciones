<?php

namespace App\Http\Requests\Rh;

use App\Models\Colaborador;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Datos capturados en el formulario "Generar recibo de nómina" del
 * expediente (ver App\Services\Nomina\ReciboNominaService::generar()). El
 * sueldo base nunca viene en el request: siempre se toma de
 * `Colaborador::sueldo_mensual`, aquí solo se validan los conceptos
 * adicionales que RH agrega/ajusta a mano.
 */
class GenerarReciboNominaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $colaborador = $this->route('colaborador');
        $colaboradorId = $colaborador instanceof Colaborador ? $colaborador->id : null;

        return ($this->user()?->can('expedientes.editar') ?? false)
            || $this->user()?->colaborador_id === $colaboradorId;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'periodo_inicio' => ['required', 'date'],
            'periodo_fin' => ['required', 'date', 'after_or_equal:periodo_inicio'],
            'fecha_pago' => ['required', 'date'],
            'percepciones' => ['array'],
            'percepciones.*.concepto' => ['required_with:percepciones', 'string', 'max:150'],
            'percepciones.*.monto' => ['required_with:percepciones', 'numeric', 'min:0', 'max:9999999.99'],
            'deducciones' => ['array'],
            'deducciones.*.concepto' => ['required_with:deducciones', 'string', 'max:150'],
            'deducciones.*.monto' => ['required_with:deducciones', 'numeric', 'min:0', 'max:9999999.99'],
            'deducciones.*.tipo' => ['nullable', 'string', 'max:30'],
            'deducciones.*.prestamo_id' => ['nullable', 'integer', 'exists:prestamos,id'],
        ];
    }
}
