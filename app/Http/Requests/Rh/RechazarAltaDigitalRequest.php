<?php

namespace App\Http\Requests\Rh;

use Illuminate\Foundation\Http\FormRequest;

class RechazarAltaDigitalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('aprobar', $this->route('alta')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'motivo_rechazo' => ['required', 'string', 'max:2000'],
        ];
    }
}
