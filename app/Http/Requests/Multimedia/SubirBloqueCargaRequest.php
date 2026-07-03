<?php

namespace App\Http\Requests\Multimedia;

use App\Models\RecursoMultimedia;
use Illuminate\Foundation\Http\FormRequest;

class SubirBloqueCargaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', RecursoMultimedia::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'numero_bloque' => ['required', 'integer', 'min:0'],
            // Un poco más que el tamaño de bloque configurado, de margen
            // para no rechazar el último bloque por redondeos del cliente.
            'bloque' => ['required', 'file', 'max:'.((config('media.carga_resumible.tamano_bloque_mb') + 1) * 1024)],
        ];
    }
}
