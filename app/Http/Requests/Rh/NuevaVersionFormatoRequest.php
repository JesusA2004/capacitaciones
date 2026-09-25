<?php

namespace App\Http\Requests\Rh;

use App\Models\OfficialFormat;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Versión nueva de una plantilla oficial: con archivo (documento base
 * nuevo) o sin él (reajustar el mapeo del mismo documento).
 */
class NuevaVersionFormatoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $formato = $this->route('formato');

        return $formato instanceof OfficialFormat && ($this->user()?->can('versionar', $formato) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'archivo' => ['nullable', 'file', 'mimes:pdf,docx,png,jpg,jpeg,webp', sprintf('max:%d', (int) config('formatos_oficiales.max_kb', 20480))],
            'notas' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'archivo.mimes' => 'Formato no admitido. Sube un PDF, un Word (DOCX) o una imagen PNG, JPG o WEBP.',
        ];
    }
}
