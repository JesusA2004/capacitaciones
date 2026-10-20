<?php

namespace App\Http\Requests\Rh;

use App\Models\Colaborador;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Colaboradores\DomicilioService;
use Illuminate\Foundation\Http\FormRequest;

class ActualizarDatosPersonalesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $colaborador = $this->route('colaborador');
        $usuario = $this->user();

        // Nadie edita su propio expediente, aunque sea RH o administrador:
        // la persona pide una "Actualización de datos" (solicitud) y otra
        // persona de RH la revisa y aplica.
        if (! $usuario instanceof User || ! $colaborador instanceof Colaborador || $usuario->colaborador_id === $colaborador->id) {
            return false;
        }

        // Permiso de acción + alcance del módulo «Expedientes» sobre ESTE colaborador.
        return $usuario->can('expedientes.editar')
            && app(AlcanceOrganizacionalService::class)->puedeVerExpediente($usuario, $colaborador);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'fecha_nacimiento' => ['nullable', 'date', 'before:today'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'telefono_corporativo' => ['nullable', 'string', 'max:30'],
            'curp' => ['nullable', 'string', 'max:18'],
            'rfc' => ['nullable', 'string', 'max:13'],
            'nss' => ['nullable', 'string', 'max:11'],
            // Texto único legado: si vienen los campos estructurados, el
            // modelo lo recompone a partir de ellos (DomicilioService).
            'domicilio' => ['nullable', 'string', 'max:255'],
            ...app(DomicilioService::class)->reglas(),
            'correo_personal' => ['nullable', 'email', 'max:255'],
            'correo_corporativo' => ['nullable', 'email', 'max:255'],
            'contacto_emergencia_nombre' => ['nullable', 'string', 'max:150'],
            'contacto_emergencia_telefono' => ['nullable', 'string', 'max:30'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return app(DomicilioService::class)->mensajes();
    }
}
