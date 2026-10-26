<?php

namespace App\Http\Requests\Settings;

use App\Services\Notificaciones\SucursalesSilenciadasService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * «Avisos por sucursal»: sucursales de las que la persona NO quiere avisos.
 * Lista vacía = recibir de todas.
 */
class AvisosSucursalUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(SucursalesSilenciadasService::PERMISO) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'sucursales' => ['present', 'array'],
            'sucursales.*' => ['integer', 'distinct', 'exists:sucursales,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sucursales.*.exists' => 'Alguna de las sucursales elegidas ya no existe. Recarga la página.',
        ];
    }
}
