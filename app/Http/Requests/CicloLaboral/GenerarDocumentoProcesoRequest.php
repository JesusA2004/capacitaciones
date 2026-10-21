<?php

namespace App\Http\Requests\CicloLaboral;

use App\Services\DocumentosMaestros\DocumentoProcesoService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Generar un documento del proceso (o el paquete completo). La clave se
 * valida contra el proceso en DocumentoProcesoService: el cliente nunca
 * decide qué documento le toca a la persona.
 *
 * completar: datos faltantes capturados en el modal "Faltan N datos"
 * (columna del colaborador => valor; "sucursal.columna" para la sucursal).
 * manuales: datos que solo existen en el acto (testigos, hora del acta).
 */
class GenerarDocumentoProcesoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'clave' => ['sometimes', 'required', 'string', 'max:80', 'regex:/^[a-z0-9_]+$/'],
            'proceso' => ['nullable', 'string', 'in:alta,renovacion,evaluacion,baja,negativa_firma,permiso,prestamo,activos,acta,expediente'],
            // Regenerar = nueva versión con los datos actuales: SIEMPRE con
            // motivo (queda en la auditoría). Sobre un documento ya firmado
            // exige además documentos_laborales.revisar_firmado.
            'regenerar' => ['sometimes', 'boolean'],
            'revision' => ['sometimes', 'boolean'],
            'motivo' => ['required_if:revision,true,1', 'required_if:regenerar,true,1', 'nullable', 'string', 'min:'.DocumentoProcesoService::MINIMO_MOTIVO_REGENERAR, 'max:500'],
            'completar' => ['nullable', 'array', 'max:30'],
            'completar.*' => ['nullable', 'string', 'max:191'],
            'manuales' => ['nullable', 'array', 'max:20'],
            'manuales.*' => ['nullable', 'string', 'max:191'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'motivo.required_if' => 'Escribe el motivo de la nueva versión: queda en la auditoría.',
            'motivo.min' => sprintf('El motivo debe tener al menos %d caracteres.', DocumentoProcesoService::MINIMO_MOTIVO_REGENERAR),
            'motivo.max' => 'El motivo no puede pasar de 500 caracteres.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function completar(): array
    {
        return array_filter((array) $this->validated('completar', []), fn (mixed $v): bool => $v !== null && $v !== '');
    }

    /**
     * @return array<string, string>
     */
    public function manuales(): array
    {
        return array_map('strval', array_filter((array) $this->validated('manuales', []), fn (mixed $v): bool => $v !== null && $v !== ''));
    }
}
