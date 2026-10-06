<?php

namespace App\Http\Requests\Avisos;

use App\Enums\AlcanceAviso;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Autorización en el controlador (permiso `avisos.enviar`): aquí solo se
 * valida la forma del aviso.
 */
class CrearAvisoRequest extends FormRequest
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
            'titulo' => ['required', 'string', 'max:150'],
            'mensaje' => ['required', 'string', 'max:2000'],
            'alcance' => ['required', Rule::in(array_map(fn (AlcanceAviso $a): string => $a->value, AlcanceAviso::cases()))],
            'colaborador_objetivo_id' => ['required_if:alcance,colaborador', 'nullable', 'integer', 'exists:colaboradores,id'],
            'imagen' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'max:8192'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'titulo.required' => 'Escribe un título para el aviso.',
            'mensaje.required' => 'Escribe el mensaje del aviso.',
            'alcance.required' => 'Indica a quién le llega: toda la empresa o un colaborador.',
            'colaborador_objetivo_id.required_if' => 'Elige a qué colaborador le llega este aviso.',
            'imagen.image' => 'El archivo debe ser una imagen.',
            'imagen.mimes' => 'La imagen debe ser JPG, PNG o WEBP.',
            'imagen.max' => 'La imagen no debe pesar más de 8 MB.',
        ];
    }
}
