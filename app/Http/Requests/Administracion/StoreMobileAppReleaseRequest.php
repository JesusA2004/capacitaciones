<?php

namespace App\Http\Requests\Administracion;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * Solo Android por ahora (ver seccion 9 del encargo movil): la extension se
 * valida explicitamente porque "apk" no es un mime reconocido por las
 * reglas `mimes:` estandar de Laravel.
 */
class StoreMobileAppReleaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('app_releases.crear') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxKb = (int) config('mobile_releases.max_upload_mb') * 1024;

        return [
            'apk' => ['required', 'file', 'max:'.$maxKb, function ($attribute, $value, $fail) {
                if (! $value instanceof UploadedFile) {
                    return;
                }

                if (strtolower((string) $value->getClientOriginalExtension()) !== 'apk') {
                    $fail('El archivo debe tener extensión .apk.');
                }
            }],
            'version' => ['required', 'string', 'max:50'],
            'build_number' => ['nullable', 'string', 'max:50'],
            'changelog' => ['nullable', 'string', 'max:5000'],
            'minimum_required' => ['sometimes', 'boolean'],
        ];
    }
}
