<?php

namespace App\Http\Requests\Rh;

use Illuminate\Foundation\Http\FormRequest;

class AplicarExtraccionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('rh.documentos.extraccion.aplicar') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // RH puede aceptar el valor detectado tal cual o corregirlo a
            // mano antes de enviarlo — aqui no se distingue, ambos casos
            // son una decision explicita de RH. Whitelist explícita: solo
            // los campos que DocumentExtractionService sabe aplicar a una
            // columna real de Colaborador (ver CAMPO_COLUMNA), nunca una
            // llave arbitraria: el whitelist real es la lista de reglas
            // 'valores.<campo>' de abajo — Laravel descarta de
            // $request->validated('valores') cualquier clave que no tenga
            // una regla explícita, así que un campo fuera de esta lista
            // nunca llega a aplicar().
            'valores' => ['required', 'array', 'min:1'],
            'valores.curp' => ['sometimes', 'string', 'max:18'],
            'valores.rfc' => ['sometimes', 'string', 'max:13'],
            'valores.nss' => ['sometimes', 'string', 'max:11'],
            'valores.fecha_nacimiento' => ['sometimes', 'date_format:d/m/Y'],
            'valores.codigo_postal' => ['sometimes', 'string', 'max:10'],
            // Texto libre ("H"/"M" o el texto detectado de estado civil): si
            // no tiene equivalencia segura al enum real, aplicar() lo deja
            // como sugerencia sin aplicar (nunca adivina).
            'valores.sexo' => ['sometimes', 'string', 'max:30'],
            'valores.clave_elector' => ['sometimes', 'string', 'max:18'],
            'valores.correo' => ['sometimes', 'string', 'email', 'max:191'],
            'valores.telefono' => ['sometimes', 'string', 'max:15'],
            'valores.nacionalidad' => ['sometimes', 'string', 'max:60'],
            'valores.estado_civil' => ['sometimes', 'string', 'max:30'],
            'valores.lugar_nacimiento' => ['sometimes', 'string', 'max:120'],
            'valores.profesion' => ['sometimes', 'string', 'max:120'],
            'valores.domicilio' => ['sometimes', 'string', 'max:255'],
            'valores.colonia' => ['sometimes', 'string', 'max:120'],
            'valores.municipio' => ['sometimes', 'string', 'max:120'],
            'valores.estado_domicilio' => ['sometimes', 'string', 'max:80'],
            'valores.beneficiario_nombre' => ['sometimes', 'string', 'max:191'],
            'valores.beneficiario_parentesco' => ['sometimes', 'string', 'max:60'],
            'valores.contacto_emergencia_nombre' => ['sometimes', 'string', 'max:255'],
            'valores.contacto_emergencia_telefono' => ['sometimes', 'string', 'max:15'],
            'valores.contacto_emergencia_parentesco' => ['sometimes', 'string', 'max:80'],
        ];
    }
}
