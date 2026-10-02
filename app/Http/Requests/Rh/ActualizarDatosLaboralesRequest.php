<?php

namespace App\Http\Requests\Rh;

use App\Models\Colaborador;
use App\Services\CicloLaboral\OrganizacionJerarquiaService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * Cambia empresa (vía sucursal), sucursal, departamento, puesto, jefe o
 * sueldo mensual de un colaborador SIN pasar por una vacante — a diferencia
 * de VacanteController::cubrir(), que sí crea/cierra una vacante. Siempre
 * exclusivo de RH (nunca autoservicio, ver ExpedienteController): un cambio
 * de puesto/sucursal es una decisión organizacional, no un dato personal.
 */
class ActualizarDatosLaboralesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $colaborador = $this->route('colaborador');

        // Nunca sobre el propio expediente (ver ActualizarDatosPersonalesRequest).
        if ($colaborador instanceof Colaborador && $this->user()?->colaborador_id === $colaborador->id) {
            return false;
        }

        return $this->user()?->can('expedientes.editar') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'sucursal_principal_id' => ['nullable', 'integer', 'exists:sucursales,id'],
            'departamento_id' => ['nullable', 'integer', 'exists:departamentos,id'],
            'puesto_id' => ['nullable', 'integer', 'exists:puestos,id'],
            'jefe_id' => ['nullable', 'integer', 'exists:colaboradores,id'],
            'sueldo_mensual' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'motivo' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Un jefe nunca puede ser la propia persona ni cerrar un ciclo
     * (A → B → A): misma regla que Configuración → Jerarquía.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $colaborador = $this->route('colaborador');
                $jefeId = $this->input('jefe_id');

                if (! $colaborador instanceof Colaborador || $validator->errors()->has('jefe_id') || ! is_numeric($jefeId)) {
                    return;
                }

                try {
                    app(OrganizacionJerarquiaService::class)->validarSuperior($colaborador, (int) $jefeId);
                } catch (ValidationException $e) {
                    foreach ($e->errors() as $campo => $mensajes) {
                        foreach ($mensajes as $mensaje) {
                            $validator->errors()->add($campo, $mensaje);
                        }
                    }
                }
            },
        ];
    }
}
