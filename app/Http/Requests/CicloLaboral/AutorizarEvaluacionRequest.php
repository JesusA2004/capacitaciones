<?php

namespace App\Http\Requests\CicloLaboral;

use Illuminate\Foundation\Http\FormRequest;

class AutorizarEvaluacionRequest extends FormRequest
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
            'renovar' => ['required', 'boolean'],
            'comentario' => ['nullable', 'string', 'max:1000'],
            'motivo_no_renovacion' => ['nullable', 'string', 'max:1000'],
            'fecha_efectiva' => ['nullable', 'date'],
        ];
    }
}
