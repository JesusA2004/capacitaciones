<?php

namespace App\Http\Requests\CartasCompromiso;

use Illuminate\Foundation\Http\FormRequest;

/**
 * «Subir carta firmada»: el escaneo (PDF o imagen) de la carta firmada y
 * con la huella de quien entra. Quién puede subirla lo decide
 * CartaCompromisoPolicy::subirFirmada; aquí solo se valida la forma.
 */
class SubirCartaCompromisoFirmadaRequest extends FormRequest
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
            'archivo' => [
                'required',
                'file',
                'max:'.((int) config('expedientes.max_upload_mb') * 1024),
                'mimes:'.implode(',', (array) config('expedientes.extensiones_permitidas')),
            ],
            'confirmo_firma' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'archivo.required' => 'Adjunta la carta firmada (PDF o imagen).',
            'archivo.file' => 'La carta firmada debe ser un archivo.',
            'archivo.max' => 'El archivo excede el tamaño máximo permitido.',
            'archivo.mimes' => 'Formato no permitido: sube un PDF o una imagen.',
            'confirmo_firma.accepted' => 'Confirma que la carta tiene la firma y la huella de la persona.',
        ];
    }
}
