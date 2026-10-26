<?php

namespace App\Http\Requests\Bajas;

use App\Models\DocumentoRequeridoBaja;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // Un espacio que no existe se rechaza: antes el archivo caía en
            // silencio en «Otros documentos» y el espacio pedido (p. ej. el
            // finiquito firmado) seguía pendiente.
            'documento_requerido_id' => ['nullable', 'integer', Rule::exists(DocumentoRequeridoBaja::class, 'id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'archivo.required' => 'Elige el archivo (PDF o imagen).',
            'archivo.file' => 'Elige el archivo (PDF o imagen).',
            'archivo.uploaded' => 'El archivo no se pudo subir (puede exceder el tamaño permitido por el servidor). Intenta de nuevo.',
            'archivo.mimes' => 'Solo se aceptan PDF o imágenes (JPG, PNG, WEBP).',
            'archivo.max' => 'El archivo es demasiado grande (máximo :max KB).',
            'documento_requerido_id.integer' => 'Ese documento no corresponde a esta baja.',
            'documento_requerido_id.exists' => 'Ese documento no corresponde a esta baja.',
        ];
    }
}
