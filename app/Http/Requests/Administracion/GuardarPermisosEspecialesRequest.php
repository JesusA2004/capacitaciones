<?php

namespace App\Http\Requests\Administracion;

use App\Enums\TipoAjustePermiso;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ajustes especiales COMPLETOS de una persona (los que no vienen se
 * retiran). Las reglas finas (catálogo, no conceder lo que uno no tiene,
 * motivo obligatorio si hay cambios) las aplica PermisosEspecialesService.
 */
class GuardarPermisosEspecialesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $objetivo = $this->route('usuario');

        return $objetivo instanceof User && $this->user()?->can('gestionarPermisosEspeciales', $objetivo) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ajustes' => ['present', 'array', 'max:2000'],
            'ajustes.*.permiso' => ['required', 'string', 'max:150', 'distinct'],
            'ajustes.*.tipo' => ['required', Rule::enum(TipoAjustePermiso::class)],
            'motivo' => ['nullable', 'string', 'max:500'],
            'hasta' => ['nullable', 'date', 'after_or_equal:today'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'hasta.after_or_equal' => 'La vigencia debe ser hoy o una fecha futura.',
            'ajustes.*.permiso.distinct' => 'Un permiso viene repetido.',
        ];
    }
}
