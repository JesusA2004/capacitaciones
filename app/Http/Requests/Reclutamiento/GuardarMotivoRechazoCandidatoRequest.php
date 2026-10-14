<?php

namespace App\Http\Requests\Reclutamiento;

use App\Enums\CategoriaMotivoRechazo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarMotivoRechazoCandidatoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('configuracion.rh') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $motivo = $this->route('motivoRechazo');

        return [
            'clave' => [
                'required', 'string', 'max:60', 'alpha_dash',
                Rule::unique('motivos_rechazo_candidato', 'clave')->ignore($motivo),
            ],
            'nombre' => ['required', 'string', 'max:120'],
            'categoria' => ['nullable', Rule::enum(CategoriaMotivoRechazo::class)],
            'activo' => ['boolean'],
            'no_recontratable_por_defecto' => ['boolean'],
        ];
    }
}
