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
        $usuario = $this->user();

        if ($usuario === null || ! $usuario->can('create', User::class)) {
            return false;
        }

        // Dar roles al crear la cuenta exige además «Asignar roles».
        return (array) $this->input('roles', []) === [] || $usuario->can('asignarRoles', User::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'colaborador_id' => ['required', 'integer', 'exists:colaboradores,id', Rule::unique('users', 'colaborador_id')],
            // Opcional: el login es por usuario (se genera solo, ver
            // App\Services\Autenticacion\NombreUsuarioService).
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')],
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
