<?php

namespace App\Http\Requests\Rh;

use App\Enums\EstadoCandidato;
use App\Models\AltaDigital;
use App\Models\Candidato;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;

class StoreAltaDigitalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', AltaDigital::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'candidato_id' => ['nullable', 'integer', 'exists:candidatos,id'],
            'vacante_id' => ['nullable', 'integer', 'exists:vacantes,id'],
            'empresa_id' => ['nullable', 'integer', 'exists:empresas,id'],
            'sucursal_id' => ['nullable', 'integer', 'exists:sucursales,id'],
            'departamento_id' => ['nullable', 'integer', 'exists:departamentos,id'],
            'puesto_id' => ['nullable', 'integer', 'exists:puestos,id'],
            'nombre' => ['nullable', 'string', 'max:150'],
            'apellidos' => ['nullable', 'string', 'max:150'],
            'correo' => ['nullable', 'email', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'fecha_ingreso_propuesta' => ['nullable', 'date'],
        ];
    }

    /**
     * Cuando la alta digital sí viene ligada a un candidato (el flujo
     * normal Candidato -> Alta digital -> QR), exige que ya esté
     * seleccionado: nunca se genera una alta a partir de un candidato
     * todavía en evaluación. Sin candidato_id (alta directa de alguien
     * fuera del embudo de reclutamiento) no aplica esta regla.
     */
    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $validator): void {
            $candidatoId = $this->input('candidato_id');

            if ($candidatoId === null) {
                return;
            }

            $candidato = Candidato::query()->where('id', $candidatoId)->first();
            $estadosElegibles = [EstadoCandidato::ListoParaContratacion->value, EstadoCandidato::Contratado->value];

            if ($candidato !== null && ! in_array($candidato->estado->value, $estadosElegibles, true)) {
                $validator->errors()->add(
                    'candidato_id',
                    'El candidato debe estar "Listo para contratación" antes de generar su alta digital.',
                );
            }
        });
    }
}
