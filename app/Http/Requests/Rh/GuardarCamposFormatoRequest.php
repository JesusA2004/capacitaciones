<?php

namespace App\Http\Requests\Rh;

use App\Models\OfficialFormatVersion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mapeo de campos de un borrador de plantilla (editor visual). Esquema de
 * cada campo documentado en App\Models\OfficialFormatVersion.
 */
class GuardarCamposFormatoRequest extends FormRequest
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
            'campos' => ['present', 'array', 'max:300'],
            'campos.*.id' => ['required', 'string', 'max:40'],
            'campos.*.tipo' => ['required', Rule::in(['variable', 'manual', 'texto', 'imagen'])],
            'campos.*.variable' => ['nullable', 'required_if:campos.*.tipo,variable,imagen', 'string', 'max:80'],
            'campos.*.placeholder' => ['nullable', 'string', 'max:120'],
            'campos.*.etiqueta' => ['nullable', 'required_if:campos.*.tipo,manual', 'string', 'max:120'],
            'campos.*.texto' => ['nullable', 'required_if:campos.*.tipo,texto', 'string', 'max:500'],
            'campos.*.pagina' => ['nullable', 'integer', 'min:1', 'max:200'],
            'campos.*.x' => ['nullable', 'numeric', 'min:0', 'max:2000'],
            'campos.*.y' => ['nullable', 'numeric', 'min:0', 'max:2000'],
            'campos.*.ancho' => ['nullable', 'numeric', 'min:2', 'max:2000'],
            'campos.*.alto' => ['nullable', 'numeric', 'min:2', 'max:2000'],
            'campos.*.font_size' => ['nullable', 'numeric', 'min:5', 'max:48'],
            'campos.*.align' => ['nullable', Rule::in(['left', 'center', 'right'])],
            'campos.*.negrita' => ['nullable', 'boolean'],
            'campos.*.color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'campos.*.formato' => ['nullable', 'string', 'max:30'],
            'campos.*.max_caracteres' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'campos.*.multilinea' => ['nullable', 'boolean'],
            'campos.*.requerido' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'campos.*.variable.required_if' => 'Cada campo del sistema necesita una variable.',
            'campos.*.etiqueta.required_if' => 'Cada campo manual necesita un nombre (lo que se le pedirá a RH al generar).',
            'campos.*.texto.required_if' => 'Un texto fijo no puede ir vacío.',
        ];
    }
}
