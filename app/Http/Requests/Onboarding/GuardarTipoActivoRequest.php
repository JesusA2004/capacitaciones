<?php

namespace App\Http\Requests\Onboarding;

use App\Models\TipoActivo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarTipoActivoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('onboarding.gestionar') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tipo = $this->route('tipoActivo');

        return [
            'clave' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/', Rule::unique('tipos_activo', 'clave')->ignore($tipo instanceof TipoActivo ? $tipo->id : null)],
            'nombre' => ['required', 'string', 'max:190'],
            'requiere_identificador' => ['boolean'],
            'obligatorio' => ['boolean'],
            'activo' => ['boolean'],
            'puesto_ids' => ['nullable', 'array'],
            'puesto_ids.*' => ['integer', 'exists:puestos,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['clave.regex' => 'La clave solo admite minúsculas, números y guion bajo.'];
    }
}
