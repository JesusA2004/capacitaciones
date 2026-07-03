<?php

namespace App\Http\Requests\Multimedia;

use App\Enums\TipoRecursoMultimedia;
use App\Models\RecursoMultimedia;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class IniciarCargaResumibleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', RecursoMultimedia::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nombre_original' => ['required', 'string', 'max:255'],
            'tipo' => ['required', new Enum(TipoRecursoMultimedia::class)],
            'tamano_total_bytes' => ['required', 'integer', 'min:1', 'max:'.(config('media.max_upload_mb') * 1024 * 1024)],
            'hash_esperado' => ['nullable', 'string', 'size:64', 'regex:/^[a-f0-9]+$/i'],
        ];
    }
}
