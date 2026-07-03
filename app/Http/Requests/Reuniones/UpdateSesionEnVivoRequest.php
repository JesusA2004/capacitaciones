<?php

namespace App\Http\Requests\Reuniones;

use App\Enums\ProveedorSesion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateSesionEnVivoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('sesion')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'titulo' => ['required', 'string', 'max:180'],
            'descripcion' => ['nullable', 'string', 'max:2000'],
            'proveedor' => ['required', new Enum(ProveedorSesion::class)],
            'fecha_inicio' => ['required', 'date'],
            'duracion_minutos' => ['required', 'integer', 'min:1'],
            'enlace_reunion' => ['nullable', 'url', 'max:2048'],
        ];
    }
}
