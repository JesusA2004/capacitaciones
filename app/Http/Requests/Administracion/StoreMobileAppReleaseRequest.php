<?php

namespace App\Http\Requests\Administracion;

use App\Support\LimitesSubida;
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

    /**
     * Mensajes en MB (no KB, que es lo que usa el mensaje por defecto de la
     * regla `max` para archivos) y en español, para que quien sube el APK
     * entienda de inmediato por qué falló sin tener que consultar `.env`.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $maxMb = (int) config('mobile_releases.max_upload_mb');

        return [
            'apk.required' => 'Selecciona el archivo APK que quieres subir.',
            'apk.file' => 'El archivo APK no es válido.',
            'apk.max' => "El APK supera el máximo permitido de {$maxMb} MB.",
            // PHP descartó el archivo (UPLOAD_ERR_INI_SIZE/PARTIAL): se dice
            // el límite REAL de este PHP-FPM, no se adivina.
            'apk.uploaded' => sprintf(
                'El servidor no recibió el APK completo: PHP solo acepta archivos de hasta %s (upload_max_filesize). Pide a Sistemas que revise PHP-FPM.',
                LimitesSubida::resumen()['upload_max_filesize'],
            ),
            'version.required' => 'Indica el número de versión (por ejemplo, 1.2.0).',
        ];
    }
}
