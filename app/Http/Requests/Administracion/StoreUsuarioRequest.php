<?php

namespace App\Http\Requests\Administracion;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Crea una CUENTA DE ACCESO para un colaborador que ya existe (Administración
 * > Usuarios no crea colaboradores — eso vive en Alta digital/Expedientes,
 * ver docs/ROLES_Y_NAVEGACION.md). `colaborador_id` debe ser uno sin cuenta
 * enlazada todavía (ver UsuarioController::store()).
 */
class StoreUsuarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'colaborador_id' => ['required', 'integer', 'exists:colaboradores,id', Rule::unique('users', 'colaborador_id')],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'roles' => ['array'],
            'roles.*' => ['string', 'exists:roles,name'],
        ];
    }

    public function attributes(): array
    {
        return [
            'colaborador_id' => 'colaborador',
        ];
    }
}
