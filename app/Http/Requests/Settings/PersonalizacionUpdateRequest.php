<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PersonalizacionUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tema_color' => ['required', Rule::in(['verde', 'azul', 'morado', 'naranja', 'rosa', 'gris'])],
            'avatar_color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'animaciones' => ['required', 'boolean'],
        ];
    }
}
