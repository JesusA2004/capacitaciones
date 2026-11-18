<?php

namespace App\Http\Requests\Rh;

use App\Models\Puesto;
use App\Models\User;
use App\Services\MovimientosLaborales\CambioSucursalService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * «Cambio de sucursal» (vista previa y aplicar). Permiso propio
 * expedientes.cambiar_sucursal; si además cambia el puesto («Ambos»), el
 * mismo permiso que «Trasladar personas» a otro puesto
 * (PuestoPolicy::moverColaboradores). Alcance, plaza libre y fecha los
 * valida CambioSucursalService.
 */
class CambioSucursalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $usuario = $this->user();

        if (! $usuario instanceof User || ! $usuario->can('expedientes.cambiar_sucursal')) {
            return false;
        }

        $puestoId = $this->input('puesto_destino_id');

        if ($puestoId === null || $puestoId === '') {
            return true;
        }

        $puesto = Puesto::query()->whereKey((int) $puestoId)->first();

        return $puesto instanceof Puesto && $usuario->can('moverColaboradores', $puesto);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'colaborador_ids' => ['required', 'array', 'min:1', sprintf('max:%d', CambioSucursalService::MAXIMO)],
            'colaborador_ids.*' => ['integer', 'distinct'],
            // En la vista previa puede faltar: entonces solo se devuelven las opciones.
            'sucursal_id' => [$this->routeIs('*.vista-previa') ? 'nullable' : 'required', 'integer', Rule::exists('sucursales', 'id')->where('activo', true)->whereNull('deleted_at')],
            'puesto_destino_id' => ['nullable', 'integer', Rule::exists('puestos', 'id')->where('activo', true)->whereNull('deleted_at')],
            'fecha' => ['nullable', 'date'],
            'autorizar_plaza' => ['nullable', 'boolean'],
            'motivo' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'colaborador_ids.required' => 'Elige al menos a una persona.',
            'colaborador_ids.min' => 'Elige al menos a una persona.',
            'colaborador_ids.max' => sprintf('Máximo %d personas por cambio.', CambioSucursalService::MAXIMO),
            'sucursal_id.required' => 'Elige la sucursal destino.',
            'sucursal_id.exists' => 'La sucursal destino no existe o está inactiva.',
            'puesto_destino_id.exists' => 'El puesto destino no existe o está inactivo.',
            'fecha.date' => 'La fecha efectiva no es válida.',
        ];
    }

    /**
     * @return list<int>
     */
    public function colaboradorIds(): array
    {
        return array_values(array_map('intval', (array) $this->validated('colaborador_ids')));
    }

    /**
     * @return array{sucursal_id: int, puesto_destino_id: int|null, fecha: string|null, autorizar_plaza: bool, motivo: string|null}
     */
    public function datos(): array
    {
        $puesto = $this->validated('puesto_destino_id');
        $fecha = $this->validated('fecha');
        $motivo = $this->validated('motivo');

        return [
            'sucursal_id' => (int) $this->validated('sucursal_id'),
            'puesto_destino_id' => is_numeric($puesto) ? (int) $puesto : null,
            'fecha' => is_string($fecha) ? $fecha : null,
            'autorizar_plaza' => $this->boolean('autorizar_plaza'),
            'motivo' => is_string($motivo) ? $motivo : null,
        ];
    }
}
