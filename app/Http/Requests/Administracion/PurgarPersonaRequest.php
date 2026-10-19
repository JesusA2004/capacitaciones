<?php

namespace App\Http\Requests\Administracion;

use App\Models\User;
use App\Services\Administracion\PurgaPersonaService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Confirmación FUERTE de la purga integral (solo super_admin): exige la frase
 * exacta «ELIMINAR DEFINITIVAMENTE» y la identidad de la persona (su número de
 * empleado, su usuario o su nombre completo) para evitar borrar a la persona
 * equivocada. La autorización real y los bloqueos se revalidan en
 * PurgaPersonaService; aquí solo se comprueba que quien ejecuta sabe a quién
 * borra y escribió la frase.
 */
class PurgarPersonaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $objetivo = $this->route('usuario');

        return $objetivo instanceof User
            && ($this->user()?->can('purgarDefinitivamente', $objetivo) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'frase' => ['required', 'string'],
            'identidad' => ['required', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if (trim((string) $this->input('frase')) !== PurgaPersonaService::FRASE_CONFIRMACION) {
                $validator->errors()->add('frase', sprintf('Escribe exactamente «%s».', PurgaPersonaService::FRASE_CONFIRMACION));
            }

            if (! $this->identidadCoincide()) {
                $validator->errors()->add('identidad', 'La identidad no coincide con la persona que vas a eliminar.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'frase.required' => sprintf('Escribe «%s» para confirmar.', PurgaPersonaService::FRASE_CONFIRMACION),
            'identidad.required' => 'Escribe el número de empleado, el usuario o el nombre de la persona.',
        ];
    }

    /** Acepta número de empleado, usuario (username) o nombre completo. */
    private function identidadCoincide(): bool
    {
        $objetivo = $this->route('usuario');

        if (! $objetivo instanceof User) {
            return false;
        }

        $dado = $this->normalizar((string) $this->input('identidad'));
        $colaborador = $objetivo->colaborador;

        $aceptados = array_filter([
            $objetivo->username,
            $colaborador?->numero_empleado,
            $colaborador?->nombreCompleto(),
        ]);

        foreach ($aceptados as $valor) {
            if ($this->normalizar((string) $valor) === $dado) {
                return true;
            }
        }

        return false;
    }

    private function normalizar(string $valor): string
    {
        return Str::of($valor)->squish()->lower()->toString();
    }
}
