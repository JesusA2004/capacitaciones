<?php

namespace App\Http\Requests\Api\V1;

use App\Services\Navigation\NavigationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class SubirDocumentoIncorporacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $usuario = $this->user();

        // Su propio expediente: mismo criterio que la web (`modo-colaborador`).
        return $usuario !== null
            && ($usuario->can('colaborador.incorporacion.documentos.subir') || Gate::forUser($usuario)->allows(NavigationService::GATE_MODO_COLABORADOR));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'archivo' => [
                'required',
                'file',
                'max:'.(config('expedientes.max_upload_mb') * 1024),
                'mimes:'.implode(',', config('expedientes.extensiones_permitidas')),
            ],
        ];
    }
}
