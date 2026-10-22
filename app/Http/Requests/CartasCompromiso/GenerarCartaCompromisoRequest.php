<?php

namespace App\Http\Requests\CartasCompromiso;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Documentos que cubrirá la carta compromiso (vista previa y generación).
 * Quién puede generarla lo decide CartaCompromisoPolicy::generar; que los
 * documentos sean pendientes de la persona, el máximo por carta y si la
 * persona puede recibir carta, GestionCartasCompromisoService /
 * CartaCompromisoService (422 con el motivo).
 */
class GenerarCartaCompromisoRequest extends FormRequest
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
            'documentos' => ['required', 'array', 'min:1', 'max:50'],
            'documentos.*' => ['integer', 'distinct', 'exists:document_types,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'documentos.required' => 'Elige al menos un documento pendiente.',
            'documentos.array' => 'Los documentos deben enviarse como una lista.',
            'documentos.min' => 'Elige al menos un documento pendiente.',
            'documentos.max' => 'Son demasiados documentos.',
            'documentos.*.integer' => 'Cada documento debe identificarse con un número.',
            'documentos.*.distinct' => 'Elegiste el mismo documento dos veces.',
            'documentos.*.exists' => 'Uno de los documentos elegidos no existe.',
        ];
    }

    /**
     * @return list<int>
     */
    public function documentos(): array
    {
        $ids = $this->validated('documentos');

        return is_array($ids) ? array_values(array_map('intval', $ids)) : [];
    }
}
