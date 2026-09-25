<?php

namespace App\Http\Requests\Rh;

use App\Models\OfficialFormatVersion;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Texto con posición exacta que mide pdf.js en el editor, para rehacer la
 * detección de campos con coordenadas precisas (milímetros, origen
 * arriba-izquierda).
 */
class RefinarAnalisisFormatoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $version = $this->route('version');

        return $version instanceof OfficialFormatVersion && ($this->user()?->can('configurar', $version->formato) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'bloques' => ['present', 'array', 'max:3000'],
            'bloques.*.pagina' => ['required', 'integer', 'min:1', 'max:200'],
            'bloques.*.texto' => ['required', 'string', 'max:500'],
            'bloques.*.x' => ['required', 'numeric'],
            'bloques.*.y' => ['required', 'numeric'],
            'bloques.*.ancho' => ['required', 'numeric', 'min:0'],
            'bloques.*.alto' => ['required', 'numeric', 'min:0'],
        ];
    }
}
