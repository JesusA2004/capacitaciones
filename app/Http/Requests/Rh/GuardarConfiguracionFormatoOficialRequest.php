<?php

namespace App\Http\Requests\Rh;

use App\Services\Formatos\OfficialFormatOverlayService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Guarda/valida OfficialFormat::$overlay_config (docs/FORMATOS_OFICIALES.md):
 * dónde se pinta cada campo sobre el PDF oficial. Coordenadas en milímetros
 * (mismo default de FPDF/FPDI).
 */
class GuardarConfiguracionFormatoOficialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('formatos_oficiales.configurar') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'overlay_config' => ['required', 'array'],
            'overlay_config.*.pagina' => ['nullable', 'integer', 'min:1', 'max:50'],
            'overlay_config.*.x' => ['nullable', 'numeric', 'min:0'],
            'overlay_config.*.y' => ['nullable', 'numeric', 'min:0'],
            'overlay_config.*.font_size' => ['nullable', 'numeric', 'min:4', 'max:72'],
            'overlay_config.*.align' => ['nullable', Rule::in(['left', 'center', 'right'])],
            'overlay_config.*.max_width' => ['nullable', 'numeric', 'min:1'],
            'overlay_config.*.color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'overlay_config.*.enabled' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $desconocidos = array_diff(
                array_keys($this->input('overlay_config', [])),
                array_keys(OfficialFormatOverlayService::CAMPOS_DISPONIBLES),
            );

            if ($desconocidos !== []) {
                $validator->errors()->add('overlay_config', 'Hay campos desconocidos en la configuración: '.implode(', ', $desconocidos));
            }
        });
    }
}
