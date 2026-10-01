<?php

namespace App\Http\Requests\CicloLaboral;

use App\Enums\TipoContratacion;
use Illuminate\Validation\Rule;

/**
 * Datos laborales para convertir un candidato en colaborador. Los datos de
 * identidad/contacto salen del candidato (no se recapturan); RH puede
 * complementarlos (CURP/RFC/NSS...) y siempre define lo laboral.
 */
class ContratarCandidatoRequest extends AltaColaboradorRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('candidatos.contratar') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $identidad = $this->reglasIdentidad();
        $identidad['name'] = ['sometimes', 'string', 'max:120'];

        $laborales = $this->reglasLaborales();
        $laborales['sucursal_principal_id'] = ['nullable', 'integer', 'exists:sucursales,id'];
        $laborales['puesto_id'] = ['nullable', 'integer', 'exists:puestos,id'];
        // Periodo de prueba por defecto; el vencimiento sale del puesto
        // (meses_periodo_prueba) si RH no lo captura.
        $laborales['tipo_contratacion'] = ['sometimes', Rule::enum(TipoContratacion::class)];
        $laborales['fecha_fin_contrato'] = ['nullable', 'date', 'after_or_equal:fecha_ingreso'];

        return [
            ...$identidad,
            ...$laborales,
            'duracion_horas' => ['nullable', 'integer', 'min:1', 'max:24'],
        ];
    }
}
