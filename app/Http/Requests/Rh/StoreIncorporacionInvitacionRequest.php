<?php

namespace App\Http\Requests\Rh;

use App\Enums\EstadoCandidato;
use App\Models\Candidato;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;

class StoreIncorporacionInvitacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('rh.incorporacion.invitaciones.crear') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nombre_prellenado' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'empresa_id' => ['nullable', 'integer', 'exists:empresas,id'],
            'sucursal_id' => ['nullable', 'integer', 'exists:sucursales,id'],
            'departamento_id' => ['nullable', 'integer', 'exists:departamentos,id'],
            'puesto_id' => ['nullable', 'integer', 'exists:puestos,id'],
            'candidato_id' => ['nullable', 'integer', 'exists:candidatos,id'],
            // Alternativa a duracion_horas: fecha exacta de expiracion. El
            // formulario web ya no la usa (solo horas, 1-24), pero se deja
            // disponible para otros posibles llamadores del endpoint.
            'expires_at' => ['nullable', 'date', 'after:now'],
            // QR de acceso temporal a un formulario de incorporacion: rango
            // deliberadamente corto, nunca dias ni "hasta 1 año" como antes.
            'duracion_horas' => ['nullable', 'integer', 'min:1', 'max:24'],
            'max_usos' => ['nullable', 'integer', 'min:1', 'max:100'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Igual criterio que StoreAltaDigitalRequest: un QR ligado a un
     * candidato solo se genera si ya está seleccionado (nunca "suelto" para
     * alguien todavía en evaluación). Sin candidato_id (invitación directa
     * fuera del embudo de reclutamiento) no aplica.
     */
    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $validator): void {
            $candidatoId = $this->input('candidato_id');

            if ($candidatoId === null) {
                return;
            }

            $candidato = Candidato::query()->where('id', $candidatoId)->first();
            $estadosElegibles = [EstadoCandidato::AprobadoRh->value, EstadoCandidato::Contratado->value];

            if ($candidato !== null && ! in_array($candidato->estado->value, $estadosElegibles, true)) {
                $validator->errors()->add(
                    'candidato_id',
                    'El candidato debe estar "Aprobado por RH" (seleccionado) antes de generar su invitación QR.',
                );
            }
        });
    }
}
