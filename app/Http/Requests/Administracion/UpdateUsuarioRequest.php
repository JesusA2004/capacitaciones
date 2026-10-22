<?php

namespace App\Http\Requests\Administracion;

use App\Models\User;
use App\Services\Autenticacion\NombreUsuarioService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edita solo la CUENTA DE ACCESO (usuario, correo, zona horaria, roles) —
 * los datos de persona/empleo se editan desde Rh\ExpedienteController::actualizarDatosPersonales().
 * El usuario es único sin distinguir mayúsculas/acentos; el correo es opcional.
 */
class UpdateUsuarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        $usuario = $this->user();
        $objetivo = $this->route('usuario');

        if ($usuario === null || ! $objetivo instanceof User || ! $usuario->can('update', $objetivo)) {
            return false;
        }

        // Cambiar los roles exige además «Asignar roles»; mandar los mismos
        // que ya tiene (formulario sin ese permiso) no cuenta como cambio.
        return ! $this->cambiaRoles($objetivo) || $usuario->can('asignarRoles', User::class);
    }

    /**
     * true si la petición trae roles distintos de los que ya tiene la cuenta.
     */
    public function cambiaRoles(User $objetivo): bool
    {
        if (! $this->has('roles')) {
            return false;
        }

        $pedidos = collect((array) $this->input('roles', []))->map(fn ($rol): string => (string) $rol)->sort()->values()->all();
        $actuales = $objetivo->roles()->pluck('name')->map(fn ($rol): string => (string) $rol)->sort()->values()->all();

        return $pedidos !== $actuales;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('username'))) {
            $this->merge(['username' => app(NombreUsuarioService::class)->normalizar($this->input('username'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $cuenta = $this->route('usuario');
        $cuentaId = $cuenta instanceof User ? $cuenta->id : null;

        return [
            'username' => ['sometimes', 'required', 'string', 'min:3', 'max:100', 'regex:/^[\pL0-9 .\'-]+$/u', function (string $atributo, mixed $valor, Closure $falla) use ($cuentaId): void {
                $clave = app(NombreUsuarioService::class)->clave((string) $valor);

                if (User::withTrashed()->whereRaw('LOWER(username) = ?', [$clave])->where('id', '!=', $cuentaId)->exists()) {
                    $falla('Ese usuario ya lo tiene otra cuenta.');
                }
            }],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($cuentaId)],
            'zona_horaria' => ['nullable', 'string', 'max:60'],
            'roles' => ['array'],
            'roles.*' => ['string', 'exists:roles,name'],
        ];
    }

    public function attributes(): array
    {
        return ['username' => 'usuario'];
    }
}
