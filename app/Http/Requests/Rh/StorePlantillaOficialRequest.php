<?php

namespace App\Http\Requests\Rh;

use App\Enums\AplicaFormato;
use App\Enums\TipoFormatoOficial;
use App\Models\OfficialFormat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Nueva plantilla oficial. La validación de contenido real del archivo
 * (firma PDF, imagen decodificable, DOCX sin macros) la hace
 * App\Services\Formatos\Motor\ValidadorArchivoPlantilla.
 */
class StorePlantillaOficialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', OfficialFormat::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:150'],
            'tipo' => ['required', Rule::enum(TipoFormatoOficial::class)],
            'aplica_a' => ['required', Rule::enum(AplicaFormato::class)],
            'empresa_id' => ['nullable', 'integer', Rule::exists('empresas', 'id')],
            'descripcion' => ['nullable', 'string', 'max:1000'],
            'archivo' => ['required', 'file', 'mimes:pdf,docx,png,jpg,jpeg,webp', sprintf('max:%d', (int) config('formatos_oficiales.max_kb', 20480))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'archivo.required' => 'Sube el archivo base del formato.',
            'archivo.mimes' => 'Formato no admitido. Sube un PDF, un Word (DOCX) o una imagen PNG, JPG o WEBP.',
            'archivo.max' => 'El archivo es demasiado grande.',
        ];
    }
}
