<?php

namespace App\Http\Requests\Reclutamiento;

use Illuminate\Foundation\Http\FormRequest;

class EnviarPsicometricasRequest extends FormRequest
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
            'link' => ['required', 'url', 'max:500'],
        ];
    }
}
