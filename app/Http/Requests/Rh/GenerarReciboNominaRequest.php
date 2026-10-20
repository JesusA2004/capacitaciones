<?php

namespace App\Http\Requests\Rh;

use App\Models\Colaborador;
use App\Services\AlcanceOrganizacionalService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Datos capturados en el formulario "Generar recibo de nómina" del
 * expediente (ver App\Services\Nomina\ReciboNominaService::generar()).
 * `sueldo_base` es el sueldo de ESTE periodo (RH lo confirma/edita en el
 * diálogo, precargado con una sugerencia simple según el rango de fechas),
 * nunca el sueldo mensual completo aplicado ciegamente a un periodo
 * quincenal — ver ReciboNominaService::generar().
 */
class GenerarReciboNominaRequest extends FormRequest
{
    /**
     * Solo RH (nomina.recibos.crear) genera recibos, dentro de su alcance y
     * nunca el suyo propio: el colaborador no genera ni ve sus recibos.
     */
    public function authorize(): bool
    {
        $usuario = $this->user();
        $colaborador = $this->route('colaborador');

        if ($usuario === null || ! $colaborador instanceof Colaborador || ! $usuario->can('nomina.recibos.crear')) {
            return false;
        }

        if ($usuario->colaborador_id === $colaborador->id) {
            return false;
        }

        return app(AlcanceOrganizacionalService::class)->puedeVerExpediente($usuario, $colaborador);
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
            'sueldo_base' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
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
