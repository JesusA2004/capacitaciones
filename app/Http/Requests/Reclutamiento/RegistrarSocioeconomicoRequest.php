<?php

namespace App\Http\Requests\Reclutamiento;

use App\Enums\ResultadoEtapaCandidato;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Estudio socioeconómico. El checklist es informativo (vivienda en orden,
 * vive con familia, arraigo, resguardo de motocicleta...): registra la
 * evaluación del gerente, el sistema no decide por él.
 */
class RegistrarSocioeconomicoRequest extends FormRequest
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
            'fecha_visita' => ['required', 'date', 'before_or_equal:today'],
            'direccion' => ['required', 'string', 'max:500'],
            'checklist' => ['nullable', 'array'],
            'checklist.vivienda_en_orden' => ['nullable', 'boolean'],
            'checklist.vive_con_familia' => ['nullable', 'boolean'],
            'checklist.arraigo_anios' => ['nullable', 'numeric', 'min:0', 'max:80'],
            'checklist.resguardo_motocicleta' => ['nullable', 'boolean'],
            'riesgos' => ['nullable', 'string', 'max:4000'],
            'observaciones' => ['nullable', 'string', 'max:4000', 'required_if:resultado,no_viable'],
            'resultado' => ['required', Rule::enum(ResultadoEtapaCandidato::class)],
            'visitador_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'evidencias' => ['nullable', 'array', 'max:12'],
            // Fotografías, video corto o PDF: evidencia privada en el NAS.
            'evidencias.*' => ['file', 'mimes:jpg,jpeg,png,pdf,mp4,mov', 'max:'.((int) config('contratos.max_upload_mb', 20) * 1024 * 3)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['observaciones.required_if' => 'Indica el motivo cuando el estudio no es viable.'];
    }
}
