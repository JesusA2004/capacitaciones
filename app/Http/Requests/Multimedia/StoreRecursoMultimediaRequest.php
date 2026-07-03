<?php

namespace App\Http\Requests\Multimedia;

use App\Enums\TipoRecursoMultimedia;
use App\Models\RecursoMultimedia;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreRecursoMultimediaRequest extends FormRequest
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
            'tipo' => ['required', new Enum(TipoRecursoMultimedia::class)],
            'archivo' => [
                'required',
                'file',
                'max:'.(config('media.max_upload_mb') * 1024),
                match ($this->input('tipo')) {
                    'video' => 'mimetypes:video/mp4,video/quicktime,video/webm,video/x-matroska',
                    'imagen' => 'mimes:jpg,jpeg,png,webp',
                    default => 'mimes:pdf,doc,docx,ppt,pptx,xls,xlsx',
                },
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'archivo' => 'archivo',
        ];
    }
}
