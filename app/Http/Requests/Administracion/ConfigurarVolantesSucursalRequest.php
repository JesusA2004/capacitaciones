<?php

namespace App\Http\Requests\Administracion;

use App\Models\Sucursal;
use Illuminate\Foundation\Http\FormRequest;

/**
 * «¿Esta sucursal tendrá volantes?»: Sí/No + cuántos — solo RH
 * (SucursalPolicy::editarPlantilla, permiso headcount.editar). El motivo
 * queda en la bitácora de plantilla.
 */
class ConfigurarVolantesSucursalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $sucursal = $this->route('sucursal');

        return $sucursal instanceof Sucursal && ($this->user()?->can('editarPlantilla', $sucursal) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tendra_volantes' => ['required', 'boolean'],
            'cantidad' => ['exclude_if:tendra_volantes,false', 'exclude_if:tendra_volantes,0', 'required', 'integer', 'min:1', 'max:50'],
            'motivo' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    public function cantidad(): int
    {
        return $this->boolean('tendra_volantes') ? $this->integer('cantidad') : 0;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'tendra_volantes' => '¿tendrá volantes?',
            'cantidad' => 'cuántos volantes',
            'motivo' => 'motivo del cambio',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cantidad.required' => 'Indica cuántos volantes tendrá la sucursal.',
            'cantidad.min' => 'Si la sucursal tendrá volantes, indica al menos 1.',
        ];
    }
}
