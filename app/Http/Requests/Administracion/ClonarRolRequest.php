<?php

namespace App\Http\Requests\Administracion;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClonarRolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('clonar', $this->route('rol')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nombre' => [
                'required',
                'string',
                'max:100',
                Rule::unique('roles', 'name')->where('guard_name', 'web'),
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'nombre' => 'nombre del nuevo rol',
        ];
    }
}
