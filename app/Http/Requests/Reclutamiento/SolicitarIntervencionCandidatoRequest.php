<?php

namespace App\Http\Requests\Reclutamiento;

use Illuminate\Foundation\Http\FormRequest;

/**
 * El gerente que entrevistó pide revisar un rechazo de RH (CLAUDE.md §10):
 * motivo siempre obligatorio. Quién puede solicitarla y desde qué estado
 * vive en App\Services\Reclutamiento\IntervencionCandidatoService.
 */
class SolicitarIntervencionCandidatoRequest extends FormRequest
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
            'motivo' => ['required', 'string', 'max:2000'],
        ];
    }
}
