<?php

namespace App\Http\Requests\CicloLaboral;

use Illuminate\Foundation\Http\FormRequest;

class ResolverSolicitudExcepcionRequest extends FormRequest
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
            'aprobar' => ['required', 'boolean'],
            'comentario' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
