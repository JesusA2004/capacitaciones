<?php

namespace App\Http\Requests\Rh;

use Illuminate\Foundation\Http\FormRequest;

class AsignarColaboradorImportacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('colaboradores.importar_salarios_vacaciones') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'colaborador_id' => ['required', 'integer', 'exists:colaboradores,id'],
        ];
    }
}
