<?php

namespace App\Http\Requests\CicloLaboral;

use App\Enums\Genero;
use App\Enums\TipoContratacion;
use App\Models\Colaborador;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta manual de colaborador por RH (también es la base de la contratación
 * de un candidato, ver ContratarCandidatoRequest). Solo campos permitidos:
 * el servicio nunca recibe el request completo (mass assignment).
 */
class AltaColaboradorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('darDeAlta', Colaborador::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->reglasIdentidad(),
            ...$this->reglasLaborales(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function reglasIdentidad(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'apellidos' => ['nullable', 'string', 'max:160'],
            'genero' => ['nullable', Rule::enum(Genero::class)],
            'telefono' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:190', 'unique:users,email'],
            'correo_personal' => ['nullable', 'email', 'max:190'],
            'fecha_nacimiento' => ['nullable', 'date', 'before:today'],
            'curp' => ['nullable', 'string', 'size:18', 'regex:/^[A-Za-z0-9]{18}$/'],
            'rfc' => ['nullable', 'string', 'min:12', 'max:13', 'regex:/^[A-Za-z&Ññ0-9]{12,13}$/u'],
            'nss' => ['nullable', 'digits:11'],
            'domicilio' => ['nullable', 'string', 'max:255'],
            'contacto_emergencia_nombre' => ['nullable', 'string', 'max:160'],
            'contacto_emergencia_telefono' => ['nullable', 'string', 'max:20'],
            'numero_empleado' => ['nullable', 'string', 'max:30', 'unique:colaboradores,numero_empleado'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function reglasLaborales(): array
    {
        return [
            'sucursal_principal_id' => ['required', 'integer', 'exists:sucursales,id'],
            'departamento_id' => ['nullable', 'integer', 'exists:departamentos,id'],
            'puesto_id' => ['required', 'integer', 'exists:puestos,id'],
            'jefe_id' => ['nullable', 'integer', 'exists:colaboradores,id'],
            'gerente_id' => ['nullable', 'integer', 'exists:colaboradores,id'],
            'sueldo_mensual' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'fecha_ingreso' => ['required', 'date'],
            'tipo_contratacion' => ['required', Rule::enum(TipoContratacion::class)],
            'fecha_fin_contrato' => ['nullable', 'required_unless:tipo_contratacion,'.TipoContratacion::Indeterminado->value, 'date', 'after_or_equal:fecha_ingreso'],
            'vacante_id' => ['nullable', 'integer', 'exists:vacantes,id'],
            'crear_acceso' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fecha_fin_contrato.required_unless' => 'Captura la fecha de vencimiento del contrato (periodo de prueba/capacitación/determinado).',
            'email.unique' => 'Ya existe una cuenta con ese correo.',
            'numero_empleado.unique' => 'Ese número de empleado ya está asignado.',
        ];
    }
}
