<?php

namespace App\Http\Requests\CicloLaboral;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Decisión de una etapa de aprobación del ciclo (preautorizar, autorizar,
 * rechazar, devolver). `motivo` es obligatorio para rechazar/devolver; la
 * regla de quién puede decidir vive en App\Services\CicloLaboral\AprobacionService.
 */
class DecisionAprobacionRequest extends FormRequest
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
        $exigeMotivo = in_array($this->route()?->getActionMethod(), ['rechazar', 'rechazarRh', 'devolver', 'devolverRh'], true);

        return [
            'comentario' => ['nullable', 'string', 'max:2000'],
            'motivo' => [$exigeMotivo ? 'required' : 'nullable', 'string', 'max:2000'],
        ];
    }

    public function motivo(): string
    {
        return trim((string) ($this->validated('motivo') ?? $this->validated('comentario') ?? ''));
    }

    public function comentario(): ?string
    {
        $valor = $this->validated('comentario') ?? $this->validated('motivo');

        return $valor !== null && trim((string) $valor) !== '' ? trim((string) $valor) : null;
    }
}
