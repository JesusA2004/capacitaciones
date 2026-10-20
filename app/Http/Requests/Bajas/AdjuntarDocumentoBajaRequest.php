<?php

namespace App\Http\Requests\Bajas;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Archivo para un espacio de documento de la baja (PDF o imagen).
 */
class AdjuntarDocumentoBajaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'archivo' => ['required', 'file', 'mimes:'.implode(',', (array) config('bajas.documentos.mimes', ['pdf', 'jpg', 'jpeg', 'png', 'webp'])), 'max:'.(int) config('bajas.documentos.max_kb', 15360)],
            'documento_requerido_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'archivo.required' => 'Elige el archivo (PDF o imagen).',
            'archivo.mimes' => 'Solo se aceptan PDF o imágenes (JPG, PNG, WEBP).',
            'archivo.max' => 'El archivo es demasiado grande (máximo :max KB).',
        ];
    }
}
