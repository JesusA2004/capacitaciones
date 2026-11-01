<?php

namespace App\Http\Requests\Administracion;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Spatie\Permission\Models\Role;

class UpdateRolNotificacionesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $rol = $this->route('rol');

        return $rol instanceof Role && (bool) $this->user()?->can('editarNotificaciones', $rol);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'eventos' => ['present', 'array'],
            'eventos.*' => ['string', 'max:80'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['eventos.present' => 'Falta la lista de avisos marcados.'];
    }
}
