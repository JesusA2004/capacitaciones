<?php

namespace App\Http\Requests\MiCapacitacion;

use App\Enums\TipoEntregaActividad;
use App\Models\Leccion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEntregaActividadRequest extends FormRequest
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
        $leccion = $this->route('leccion');
        $tipoEntrega = $leccion instanceof Leccion ? $leccion->actividad?->tipo_entrega : null;

        return [
            'contenido_texto' => [
                Rule::requiredIf($tipoEntrega === TipoEntregaActividad::Texto),
                'nullable', 'string', 'max:5000',
            ],
            'url' => [
                Rule::requiredIf($tipoEntrega === TipoEntregaActividad::Enlace),
                'nullable', 'url', 'max:2048',
            ],
            'archivo' => [
                Rule::requiredIf($tipoEntrega === TipoEntregaActividad::Archivo),
                'nullable', 'file', 'max:51200',
            ],
        ];
    }
}
