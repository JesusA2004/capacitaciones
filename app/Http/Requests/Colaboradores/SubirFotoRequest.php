<?php

namespace App\Http\Requests\Colaboradores;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Foto de perfil del colaborador (web y app). La autorización sobre QUÉ
 * colaborador se hace en el controlador (alcance organizacional / cuenta
 * propia); aquí solo se valida el archivo.
 */
class SubirFotoRequest extends FormRequest
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
            'foto' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'foto.required' => 'Selecciona o toma una foto.',
            'foto.image' => 'El archivo debe ser una imagen.',
            'foto.mimes' => 'La foto debe ser JPG, PNG o WEBP.',
            'foto.max' => 'La foto no debe pesar más de 10 MB.',
        ];
    }
}
