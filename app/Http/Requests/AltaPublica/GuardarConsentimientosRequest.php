<?php

namespace App\Http\Requests\AltaPublica;

use App\Models\AltaDigital;
use Illuminate\Foundation\Http\FormRequest;

class GuardarConsentimientosRequest extends FormRequest
{
    public function authorize(): bool
    {
        $alta = $this->route('alta');

        return $alta instanceof AltaDigital && $alta->tokenVigente() && $alta->estado->permiteCaptura();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'aviso_privacidad_aceptado' => ['required', 'accepted'],
            'consentimiento_datos_aceptado' => ['required', 'accepted'],
            // Data URL del lienzo de firma: unos KB; 2 MB de texto es de sobra.
            'firma' => ['required', 'string', 'max:2000000'],
        ];
    }
}
