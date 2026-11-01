<?php

namespace App\Http\Requests\Correcciones;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Solo la forma de la petición. Motivo, permiso, alcance y reglas de cada dato los
 * valida CorreccionService, igual para web y API.
 */
class VistaPreviaCorreccionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'valores' => ['required', 'array'],
            'tipo' => ['nullable', 'string', 'max:30'],
            'vigente_desde' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function valores(): array
    {
        /** @var array<string, mixed> $valores */
        $valores = (array) $this->input('valores', []);

        return $valores;
    }

    /**
     * @return array{tipo: string|null, vigente_desde: string|null, huella_afectados: string|null}
     */
    public function opciones(): array
    {
        return [
            'tipo' => $this->filled('tipo') ? (string) $this->input('tipo') : null,
            'vigente_desde' => $this->filled('vigente_desde') ? (string) $this->input('vigente_desde') : null,
            'huella_afectados' => $this->filled('huella_afectados') ? (string) $this->input('huella_afectados') : null,
        ];
    }
}
