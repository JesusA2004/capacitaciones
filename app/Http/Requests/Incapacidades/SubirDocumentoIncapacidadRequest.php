<?php

namespace App\Http\Requests\Incapacidades;

use App\Enums\TipoDocumentoIncapacidad;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Carga o reemplazo de un documento IMSS desde el expediente. La autorización
 * por persona (alcance) la hace IncapacidadPolicy::subirDocumento en el
 * controlador; las reglas de negocio (orden ST-7 → ST-2, motivo al
 * reemplazar) viven en IncapacidadService.
 */
class SubirDocumentoIncapacidadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('incapacidades.registrar') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tipo' => ['required', Rule::enum(TipoDocumentoIncapacidad::class)],
            'archivo' => ['required', ...RegistrarIncapacidadRequest::reglasArchivo()],
            'fecha_documento' => ['nullable', 'date', 'before_or_equal:today'],
            'motivo' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tipo.required' => 'Indica qué documento vas a cargar.',
            'tipo.enum' => 'Ese tipo de documento no existe para una incapacidad.',
            'archivo.required' => 'Elige el archivo (PDF o imagen).',
            'fecha_documento.date' => 'La fecha del documento no es válida.',
            'fecha_documento.before_or_equal' => 'La fecha del documento no puede ser futura.',
            'motivo.max' => 'El motivo no debe pasar de 500 caracteres.',
            ...RegistrarIncapacidadRequest::mensajesArchivo('archivo', 'El archivo'),
        ];
    }
}
