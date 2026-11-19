<?php

namespace App\Http\Requests\Bajas;

use App\Enums\EntregaAvisoRescision;
use App\Enums\FormaSalida;
use App\Enums\ModalidadBaja;
use App\Enums\Recontratable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Registro directo de la baja (gerente o RH) y su vista previa del
 * finiquito. Solo valida formato: la completitud (RN-01…RN-10) la exige
 * BajaReglasService al registrar, y el permiso + alcance BajaService (mismo
 * flujo web y API). `enviar` se acepta por compatibilidad con clientes
 * anteriores y ya no cambia nada: toda baja registrada queda aplicada.
 */
class GuardarBajaRequest extends FormRequest
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
            'colaborador_id' => [$this->route('baja') === null ? 'required' : 'prohibited', 'integer'],
            'forma_salida' => ['nullable', Rule::enum(FormaSalida::class)],
            'causal_rotacion_id' => ['nullable', 'integer'],
            'fecha_baja' => ['nullable', 'date'],
            'recontratable' => ['nullable', Rule::enum(Recontratable::class)],
            'comentarios' => ['nullable', 'string', 'max:'.(int) config('bajas.reglas.comentario_maximo', 500)],
            'fecha_conocimiento_falta' => ['nullable', 'date'],
            'entrega_aviso_rescision' => ['nullable', Rule::enum(EntregaAvisoRescision::class)],
            'fecha_entrega_aviso' => ['nullable', 'date'],
            // Inmediata (por defecto, compatible con la API anterior) o
            // programada (fecha > hoy); «Avisar al colaborador» (por defecto no).
            'modalidad_baja' => ['nullable', Rule::enum(ModalidadBaja::class)],
            'avisar_colaborador' => ['nullable', 'boolean'],
            'enviar' => ['nullable', 'boolean'],
            'volver' => ['nullable', 'string', 'in:ciclo'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'colaborador_id.required' => 'Elige al colaborador (busca por nombre o número de empleado).',
            'forma_salida.enum' => 'Elige cómo se va: renuncia voluntaria, no renovación o rescisión.',
            'recontratable.enum' => 'Indica si es recontratable: Sí, No o Con reservas.',
            'entrega_aviso_rescision.enum' => 'Indica cómo se entregó el aviso: personalmente o si se negó a recibirlo.',
            'comentarios.max' => 'El comentario admite hasta :max caracteres.',
            'modalidad_baja.enum' => 'Elige «Baja inmediata» o «Baja programada».',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function datos(): array
    {
        return $this->safe()->except(['enviar', 'volver']);
    }
}
