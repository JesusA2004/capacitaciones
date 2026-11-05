<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\DescartaFormatosGeneradosDeApkAnterior;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/**
 * Vista previa de la carta compromiso antes de cerrar el registro: los
 * documentos que la persona marcaría «No tengo este documento». Que sean
 * de sus pendientes lo valida CartaCompromisoService.
 */
class VistaPreviaCartaCompromisoRequest extends FormRequest
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
            'documentos' => ['required', 'array', 'min:1', 'max:50'],
            'documentos.*' => ['integer', 'distinct', 'exists:document_types,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'documentos.required' => 'Elige al menos un documento pendiente.',
            'documentos.array' => 'Los documentos deben enviarse como una lista.',
            'documentos.min' => 'Elige al menos un documento pendiente.',
            'documentos.max' => 'Son demasiados documentos.',
            'documentos.*.integer' => 'Cada documento debe identificarse con un número.',
            'documentos.*.distinct' => 'Elegiste el mismo documento dos veces.',
            'documentos.*.exists' => 'Uno de los documentos elegidos no existe.',
        ];
    }

    /**
     * Sin los formatos que genera PEOPLE cuando los marca la APK anterior
     * (DescartaFormatosGeneradosDeApkAnterior).
     *
     * @return list<int>
     *
     * @throws ValidationException Si solo marcó formatos que genera PEOPLE.
     */
    public function documentos(): array
    {
        $ids = $this->validated('documentos');
        $ids = is_array($ids) ? array_values(array_map('intval', $ids)) : [];
        $filtrados = $this->sinFormatosGeneradosDeApkAnterior($ids);

        if ($ids !== [] && $filtrados === []) {
            throw ValidationException::withMessages([
                'documentos' => 'La Solicitud de empleo y el Estudio socioeconómico los genera PEOPLE con tus datos: no van en la carta compromiso.',
            ]);
        }

        return $filtrados;
    }
}
