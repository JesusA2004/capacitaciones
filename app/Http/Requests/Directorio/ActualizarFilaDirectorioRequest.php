<?php

namespace App\Http\Requests\Directorio;

use App\Http\Requests\Rh\ActualizarDatosLaboralesRequest;
use App\Http\Requests\Rh\ActualizarDatosPersonalesRequest;
use App\Models\Colaborador;
use App\Models\User;
use App\Services\Directorio\DirectorioEdicionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;

/**
 * Edición rápida de una fila del Directorio. Las reglas de cada campo son
 * EXACTAMENTE las del expediente (se toman de sus Form Requests, no se
 * copian): un dato válido en el expediente es válido aquí y viceversa.
 */
class ActualizarFilaDirectorioRequest extends FormRequest
{
    public function authorize(): bool
    {
        $colaborador = $this->route('colaborador');
        $usuario = $this->user();

        return $usuario instanceof User
            && $colaborador instanceof Colaborador
            && app(DirectorioEdicionService::class)->puedeEditar($usuario, $colaborador);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...Arr::only((new ActualizarDatosPersonalesRequest)->rules(), DirectorioEdicionService::CAMPOS_CONTACTO),
            ...Arr::only((new ActualizarDatosLaboralesRequest)->rules(), DirectorioEdicionService::CAMPOS_LABORALES),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'correo_corporativo' => 'correo corporativo',
            'telefono_corporativo' => 'teléfono corporativo',
            'sucursal_principal_id' => 'sucursal',
            'departamento_id' => 'departamento',
            'puesto_id' => 'puesto',
        ];
    }
}
