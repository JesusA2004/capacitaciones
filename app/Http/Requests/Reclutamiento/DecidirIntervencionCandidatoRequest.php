<?php

namespace App\Http\Requests\Reclutamiento;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Regional o Dirección Comercial deciden una intervención (CLAUDE.md §11).
 * Quién puede decidir cuál vive en
 * App\Services\Reclutamiento\IntervencionCandidatoService.
 */
class DecidirIntervencionCandidatoRequest extends FormRequest
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
            'aprueba' => ['required', 'boolean'],
            'comentario' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
