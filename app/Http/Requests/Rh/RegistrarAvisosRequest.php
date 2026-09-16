<?php

namespace App\Http\Requests\Rh;

use App\Models\Colaborador;
use Illuminate\Foundation\Http\FormRequest;

class RegistrarAvisosRequest extends FormRequest
{
    public function authorize(): bool
    {
        $colaborador = $this->route('colaborador');
        $colaboradorId = $colaborador instanceof Colaborador ? $colaborador->id : null;

        if ($this->user()?->colaborador_id === $colaboradorId) {
            return $this->user()->can('expedientes.ver');
        }

        return $this->user()?->can('expedientes.editar') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'aviso_privacidad_aceptado' => ['required', 'boolean'],
            'consentimiento_datos_aceptado' => ['required', 'boolean'],
        ];
    }
}
