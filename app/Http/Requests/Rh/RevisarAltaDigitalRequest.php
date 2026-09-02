<?php

namespace App\Http\Requests\Rh;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RevisarAltaDigitalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('revisar', $this->route('alta')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'estado' => ['required', Rule::in(['en_revision_rh', 'requiere_correccion'])],
            'comentarios' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
