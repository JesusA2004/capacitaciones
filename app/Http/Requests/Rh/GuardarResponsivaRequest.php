<?php

namespace App\Http\Requests\Rh;

use App\Enums\AccesorioEquipoElectronico;
use App\Enums\AccesorioVehiculo;
use App\Enums\EstadoConservacion;
use App\Enums\EstadoFisicoResponsiva;
use App\Enums\NivelCombustible;
use App\Enums\TipoBienMueble;
use App\Enums\TipoEquipoElectronico;
use App\Enums\TipoResponsiva;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Captura de una responsiva (vista previa y generación). La validación es
 * condicional según la familia (bien mueble, equipo electrónico, vehículo):
 * cada formato oficial pide sus propios campos legales. La autorización
 * (gerente con alcance o RH) la aplica ResponsivaPolicy en el controlador.
 */
class GuardarResponsivaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tipo' => ['required', Rule::enum(TipoResponsiva::class)],
            'lugar' => ['required', 'string', 'max:160'],
            'fecha_entrega' => ['required', 'date', 'before_or_equal:today'],
            // Responsable de entrega (quien realmente entregó). Si no se envía,
            // se asume al capturador. El alcance se valida en ResponsivaService.
            'responsable_entrega_id' => ['nullable', 'integer', 'exists:users,id'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            'datos' => ['required', 'array'],
            ...$this->reglasFamilia(),
        ];
    }

    /**
     * Reglas del bloque `datos` según la familia seleccionada en `tipo`.
     *
     * @return array<string, mixed>
     */
    private function reglasFamilia(): array
    {
        $tipo = TipoResponsiva::tryFrom((string) $this->input('tipo'));

        return match ($tipo) {
            TipoResponsiva::BienMueble => $this->reglasBienMueble(),
            TipoResponsiva::EquipoElectronico => $this->reglasEquipo(),
            TipoResponsiva::Vehiculo => $this->reglasVehiculo(),
            null => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function reglasBienMueble(): array
    {
        return [
            'datos.tipo_mueble' => ['required', Rule::enum(TipoBienMueble::class)],
            'datos.tipo_mueble_otro' => ['nullable', 'required_if:datos.tipo_mueble,otro', 'string', 'max:160'],
            'datos.marca' => ['nullable', 'string', 'max:120'],
            'datos.modelo' => ['nullable', 'string', 'max:120'],
            'datos.numero_inventario' => ['required', 'string', 'max:120'],
            'datos.numero_serie' => ['nullable', 'string', 'max:120'],
            'datos.color' => ['nullable', 'string', 'max:60'],
            'datos.material' => ['nullable', 'string', 'max:120'],
            'datos.tamano' => ['nullable', 'string', 'max:255'],
            'datos.estado' => ['required', Rule::enum(EstadoFisicoResponsiva::class)],
            'datos.danos' => ['nullable', 'string', 'max:2000'],
            'datos.ubicacion_lugar' => ['required', 'string', 'max:255'],
            'datos.ubicacion_area' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reglasEquipo(): array
    {
        return [
            'datos.tipo_equipo' => ['required', Rule::enum(TipoEquipoElectronico::class)],
            'datos.tipo_equipo_otro' => ['nullable', 'required_if:datos.tipo_equipo,otro', 'string', 'max:160'],
            'datos.marca' => ['required', 'string', 'max:120'],
            'datos.modelo' => ['nullable', 'string', 'max:120'],
            'datos.numero_serie' => ['nullable', 'string', 'max:120'],
            'datos.numero_inventario' => ['nullable', 'string', 'max:120'],
            'datos.imei1' => ['nullable', 'string', 'max:60'],
            'datos.imei2' => ['nullable', 'string', 'max:60'],
            'datos.numero_telefonico' => ['nullable', 'string', 'max:40'],
            'datos.estado' => ['required', Rule::enum(EstadoFisicoResponsiva::class)],
            'datos.danos' => ['nullable', 'string', 'max:2000'],
            'datos.accesorios' => ['nullable', 'array'],
            'datos.accesorios.*' => [Rule::enum(AccesorioEquipoElectronico::class)],
            'datos.accesorios_otro' => ['nullable', 'string', 'max:160'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reglasVehiculo(): array
    {
        return [
            'datos.marca' => ['required', 'string', 'max:120'],
            'datos.submarca' => ['nullable', 'string', 'max:120'],
            'datos.modelo_anio' => ['nullable', 'string', 'max:60'],
            'datos.color' => ['nullable', 'string', 'max:60'],
            'datos.niv' => ['nullable', 'string', 'max:40'],
            'datos.numero_motor' => ['nullable', 'string', 'max:60'],
            'datos.placas' => ['required', 'string', 'max:20'],
            'datos.kilometraje' => ['nullable', 'integer', 'min:0', 'max:9999999'],
            'datos.combustible' => ['required', Rule::enum(NivelCombustible::class)],
            'datos.estado_exterior' => ['required', Rule::enum(EstadoConservacion::class)],
            'datos.estado_interior' => ['required', Rule::enum(EstadoConservacion::class)],
            'datos.danos' => ['nullable', 'string', 'max:2000'],
            'datos.accesorios' => ['nullable', 'array'],
            'datos.accesorios.*' => [Rule::enum(AccesorioVehiculo::class)],
            'datos.accesorios_otro' => ['nullable', 'string', 'max:160'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'tipo' => 'tipo de responsiva',
            'lugar' => 'lugar',
            'fecha_entrega' => 'fecha de entrega',
            'responsable_entrega_id' => 'responsable de entrega',
            'datos.tipo_mueble' => 'tipo de mueble',
            'datos.tipo_mueble_otro' => 'otro tipo de mueble',
            'datos.tipo_equipo' => 'tipo de equipo',
            'datos.tipo_equipo_otro' => 'otro tipo de equipo',
            'datos.marca' => 'marca',
            'datos.modelo' => 'modelo',
            'datos.modelo_anio' => 'modelo / año',
            'datos.submarca' => 'submarca / tipo',
            'datos.numero_inventario' => 'número de inventario',
            'datos.numero_serie' => 'número de serie',
            'datos.numero_motor' => 'número de motor',
            'datos.placas' => 'placas',
            'datos.niv' => 'NIV / VIN',
            'datos.kilometraje' => 'kilometraje',
            'datos.combustible' => 'nivel de combustible',
            'datos.estado' => 'estado',
            'datos.estado_exterior' => 'estado exterior',
            'datos.estado_interior' => 'estado interior',
            'datos.ubicacion_lugar' => 'lugar donde quedará',
            'datos.ubicacion_area' => 'sucursal / oficina / área',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'datos.required' => 'Captura los datos del formato de la responsiva.',
            'fecha_entrega.before_or_equal' => 'La fecha de entrega no puede ser futura.',
            'datos.tipo_mueble_otro.required_if' => 'Describe qué mueble es cuando eliges «Otro».',
            'datos.tipo_equipo_otro.required_if' => 'Describe qué equipo es cuando eliges «Otro».',
            'datos.numero_inventario.required' => 'Captura el número de inventario del mueble.',
            'datos.ubicacion_lugar.required' => 'Indica el lugar donde quedará el mueble.',
            'datos.placas.required' => 'Captura las placas del vehículo.',
        ];
    }
}
