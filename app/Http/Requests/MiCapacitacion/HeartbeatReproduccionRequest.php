<?php

namespace App\Http\Requests\MiCapacitacion;

use Illuminate\Foundation\Http\FormRequest;

class HeartbeatReproduccionRequest extends FormRequest
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
            'sesion_id' => ['required', 'integer'],
            'posicion_segundos' => ['required', 'integer', 'min:0'],
        ];
    }
}
