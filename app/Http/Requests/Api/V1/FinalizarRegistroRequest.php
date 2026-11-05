<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\DescartaFormatosGeneradosDeApkAnterior;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Cierre del registro en la app (RegistroAppService::finalizar). Opcional:
 * los documentos que la persona marcó «No tengo este documento» (ids de
 * document_types); que estén entre sus faltantes lo decide el servicio.
 */
class FinalizarRegistroRequest extends FormRequest
{
    use DescartaFormatosGeneradosDeApkAnterior;

    public function authorize(): bool
    {
        return $this->user()?->colaborador !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'documentos_sin_tener' => ['nullable', 'array', 'max:50'],
            'documentos_sin_tener.*' => ['integer', 'distinct', 'exists:document_types,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'documentos_sin_tener.array' => 'Los documentos que no tienes deben enviarse como una lista.',
            'documentos_sin_tener.max' => 'Son demasiados documentos.',
            'documentos_sin_tener.*.integer' => 'Cada documento debe identificarse con un número.',
            'documentos_sin_tener.*.distinct' => 'Marcaste el mismo documento dos veces.',
            'documentos_sin_tener.*.exists' => 'Uno de los documentos marcados no existe.',
        ];
    }

    /**
     * Sin los formatos que genera PEOPLE cuando los marca la APK anterior
     * (DescartaFormatosGeneradosDeApkAnterior).
     *
     * @return list<int>
     */
    public function documentosSinTener(): array
    {
        $ids = $this->validated('documentos_sin_tener');

        return $this->sinFormatosGeneradosDeApkAnterior(is_array($ids) ? array_values(array_map('intval', $ids)) : []);
    }
}
