<?php

namespace App\Http\Requests\Rh;

use App\Models\Colaborador;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use Illuminate\Foundation\Http\FormRequest;

class RegistrarAvisosRequest extends FormRequest
{
    public function authorize(): bool
    {
        $colaborador = $this->route('colaborador');
        $usuario = $this->user();

        if (! $colaborador instanceof Colaborador || ! $usuario instanceof User) {
            return false;
        }

        // La persona acepta sus propios avisos desde Mi expediente.
        if ($usuario->colaborador_id === $colaborador->id) {
            return $usuario->can('expedientes.ver');
        }

        // RH los registra por otra persona, solo dentro de su alcance.
        return $usuario->can('expedientes.editar')
            && app(AlcanceOrganizacionalService::class)->puedeVerExpediente($usuario, $colaborador);
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
