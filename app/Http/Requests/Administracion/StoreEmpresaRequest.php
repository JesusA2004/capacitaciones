<?php

namespace App\Http\Requests\Administracion;

use App\Models\Empresa;
use Illuminate\Foundation\Http\FormRequest;

class StoreEmpresaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Empresa::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:150'],
            'razon_social' => ['nullable', 'string', 'max:255'],
            'rfc' => ['nullable', 'string', 'max:13'],
            'logo' => ['nullable', 'image', 'max:1024'],
            'activo' => ['boolean'],
        ];
    }
}
