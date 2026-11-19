<?php

namespace App\Http\Requests\RecepcionDocumental;

use App\Models\RecepcionDocumentalCatalogo;
use Illuminate\Validation\Rule;

/**
 * Simular (sin guardar) el recálculo que provocaría agregar, cambiar o
 * borrar una entrada del catálogo. Mismos campos que guardar.
 */
class SimularCatalogoRecepcionRequest extends ActualizarCatalogoRecepcionRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('configurar', RecepcionDocumentalCatalogo::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'accion' => ['required', Rule::in(['crear', 'actualizar', 'eliminar'])],
            'entrada_id' => ['nullable', 'integer', 'required_unless:accion,crear'],
        ];
    }

    /** @return 'crear'|'actualizar'|'eliminar' */
    public function accion(): string
    {
        return match ($this->string('accion')->toString()) {
            'crear' => 'crear',
            'eliminar' => 'eliminar',
            default => 'actualizar',
        };
    }

    public function entrada(): ?RecepcionDocumentalCatalogo
    {
        $id = $this->integer('entrada_id');

        return $id > 0 ? RecepcionDocumentalCatalogo::query()->where('id', $id)->firstOrFail() : null;
    }
}
