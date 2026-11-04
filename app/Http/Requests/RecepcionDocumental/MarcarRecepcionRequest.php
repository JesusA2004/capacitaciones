<?php

namespace App\Http\Requests\RecepcionDocumental;

use App\Enums\EstadoRecepcionDocumento;
use App\Models\RecepcionDocumentalRenglon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Marcar la recepción del original de un renglón (web y API móvil, misma
 * regla): el permiso depende del estado pedido (RecepcionDocumentalRenglonPolicy::marcar).
 */
class MarcarRecepcionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $renglon = $this->route('renglon');
        $estado = EstadoRecepcionDocumento::tryFrom((string) $this->input('estado'));

        // Sin estado válido: que responda la validación (422), no un 403.
        if ($estado === null) {
            return $renglon instanceof RecepcionDocumentalRenglon;
        }

        return $renglon instanceof RecepcionDocumentalRenglon && $this->user()?->can('marcar', [$renglon, $estado]) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'estado' => ['required', Rule::enum(EstadoRecepcionDocumento::class)],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            'justificacion' => ['nullable', 'string', 'max:2000'],
            'cantidad_recibida' => ['nullable', 'integer', 'min:0', 'max:999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'estado.required' => 'Elige el estado de recepción.',
            'estado.enum' => 'El estado de recepción no es válido.',
        ];
    }

    public function estado(): EstadoRecepcionDocumento
    {
        return EstadoRecepcionDocumento::from((string) $this->validated('estado'));
    }

    /**
     * @return array{observaciones: string|null, justificacion: string|null, cantidad_recibida: int|null}
     */
    public function datos(): array
    {
        $cantidad = $this->validated('cantidad_recibida');

        return [
            'observaciones' => is_string($this->validated('observaciones')) ? $this->validated('observaciones') : null,
            'justificacion' => is_string($this->validated('justificacion')) ? $this->validated('justificacion') : null,
            'cantidad_recibida' => is_numeric($cantidad) ? (int) $cantidad : null,
        ];
    }
}
