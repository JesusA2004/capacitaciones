<?php

namespace App\Http\Requests\Administracion;

use App\Enums\TipoPuesto;
use App\Models\Puesto;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class ActualizarJerarquiaPuestoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('puesto')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $puesto = $this->route('puesto');
        $puestoId = $puesto instanceof Puesto ? $puesto->id : null;

        return [
            'nivel_jerarquico' => ['nullable', 'integer', 'min:1', 'max:20'],
            'puesto_superior_id' => [
                'nullable', 'integer', 'exists:puestos,id', Rule::notIn([$puestoId]),
                $this->reglaSinCiclo($puesto, 'puesto_superior_id'),
            ],
            'puesto_crecimiento_id' => [
                'nullable', 'integer', 'exists:puestos,id', Rule::notIn([$puestoId]),
                $this->reglaSinCiclo($puesto, 'puesto_crecimiento_id'),
            ],
            'tipo_puesto' => ['nullable', new Enum(TipoPuesto::class)],
            'esquema_comisiones' => ['nullable', 'string', 'max:255'],
            'requiere_ruta' => ['boolean'],
            'responsabilidades' => ['nullable', 'string', 'max:4000'],
            'requisitos' => ['nullable', 'string', 'max:4000'],
            'respaldos' => ['array'],
            'respaldos.*' => ['integer', 'exists:puestos,id', Rule::notIn([$puestoId])],
            'puestos_que_puede_cubrir' => ['array'],
            'puestos_que_puede_cubrir.*' => ['integer', 'exists:puestos,id', Rule::notIn([$puestoId])],
        ];
    }

    /**
     * Rechaza el valor si asignarlo a $columna en $puesto cerraría un ciclo
     * jerárquico (ver Puesto::creariaCiclo()). Sin efecto al crear un
     * puesto nuevo (todavía no existe instancia contra la cual detectar
     * ciclos).
     */
    private function reglaSinCiclo(?Puesto $puesto, string $columna): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($puesto, $columna) {
            if (! $puesto instanceof Puesto || $value === null || $value === '') {
                return;
            }

            if ($puesto->creariaCiclo($columna, (int) $value)) {
                $fail('Esta asignación crearía un ciclo en la jerarquía de puestos.');
            }
        };
    }
}
