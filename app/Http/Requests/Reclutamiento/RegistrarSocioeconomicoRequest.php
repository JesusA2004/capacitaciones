<?php

namespace App\Http\Requests\Reclutamiento;

use Illuminate\Foundation\Http\FormRequest;

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
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'checklist.arraigo_meses.max' => 'Los meses en la vivienda van de 0 a 11; 12 meses o más súmalos a los años.',
            'checklist.arraigo_meses.integer' => 'Los meses en la vivienda deben ser un número entero.',
        ];
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
            // Tiempo en la vivienda (arraigo): años + meses (0-11).
            'checklist.arraigo_anios' => ['nullable', 'numeric', 'min:0', 'max:80'],
            'checklist.arraigo_meses' => ['nullable', 'integer', 'min:0', 'max:11'],
            'checklist.resguardo_motocicleta' => ['nullable', 'boolean'],
            'riesgos' => ['nullable', 'string', 'max:4000'],
            'observaciones' => ['nullable', 'string', 'max:4000'],
            // Cargar NO es decidir: el resultado lo da la revisión del
            // responsable (socioeconomico/revision). Un cliente viejo que lo
            // mande no decide nada: se descarta.
            'resultado' => ['exclude'],
            'motivo_rechazo_id' => ['exclude'],
            'recontratable' => ['exclude'],
            'visitador_user_id' => ['nullable', 'integer', 'exists:users,id'],
            // Varios videos, fotografías o PDF: carpeta socioeconomico/ del NAS.
            ...ReglasArchivosCandidato::para('evidencias', ['video', 'foto', 'documento']),
        ];
    }
}
