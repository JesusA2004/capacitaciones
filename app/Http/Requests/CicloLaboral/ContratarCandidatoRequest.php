<?php

namespace App\Http\Requests\CicloLaboral;

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

        return [...$identidad, ...$laborales];
    }
}
