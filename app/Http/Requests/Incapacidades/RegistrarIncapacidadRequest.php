<?php

namespace App\Http\Requests\Incapacidades;

use App\Enums\SecuenciaIncapacidad;
use App\Enums\SubtipoRiesgoTrabajo;
use App\Enums\TipoIncapacidad;
use App\Models\Colaborador;
use App\Models\Incapacidad;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegistrarIncapacidadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('incapacidades.registrar') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $archivo = self::reglasArchivo();

        return [
            'colaborador_id' => ['required', 'integer', Rule::exists(Colaborador::class, 'id')],
            'tipo' => ['required', Rule::enum(TipoIncapacidad::class)],
            // La subsecuente hereda el subtipo de su inicial.
            'subtipo_riesgo' => ['nullable', Rule::enum(SubtipoRiesgoTrabajo::class), Rule::requiredIf(fn (): bool => $this->input('tipo') === TipoIncapacidad::RiesgoTrabajo->value && $this->input('secuencia') === SecuenciaIncapacidad::Inicial->value)],
            'secuencia' => ['required', Rule::enum(SecuenciaIncapacidad::class)],
            'incapacidad_inicial_id' => ['nullable', 'integer', Rule::exists(Incapacidad::class, 'id'), 'required_if:secuencia,'.SecuenciaIncapacidad::Subsecuente->value],
            'serie_folio_imss' => ['required', 'string', 'max:40'],
            'fecha_inicio' => ['required', 'date'],
            'dias' => ['required', 'integer', 'min:1', 'max:'.(int) config('incapacidades.dias_maximos', 365)],
            'fecha_evento' => ['nullable', 'date'],
            'descripcion_evento' => ['nullable', 'string', 'max:2000'],
            'fecha_st7' => ['nullable', 'date', 'before_or_equal:today'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            'certificado' => ['required', ...$archivo],
            'st7' => ['nullable', ...$archivo],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'colaborador_id.required' => 'Elige al colaborador.',
            'subtipo_riesgo.required' => 'Elige el tipo de riesgo de trabajo.',
            'incapacidad_inicial_id.required_if' => 'Elige la incapacidad inicial a la que se liga.',
            'serie_folio_imss.required' => 'La serie y el folio del certificado del IMSS son obligatorios.',
            'dias.min' => 'El número de días debe ser mayor a cero.',
            'certificado.required' => 'Adjunta el certificado de incapacidad del IMSS.',
            'fecha_st7.before_or_equal' => 'La fecha del ST-7 no puede ser futura.',
            ...self::mensajesArchivo('certificado', 'El certificado'),
            ...self::mensajesArchivo('st7', 'El ST-7'),
        ];
    }

    /**
     * Mismas reglas para cualquier documento IMSS de una incapacidad (registro
     * y carga posterior desde el expediente): config/incapacidades.php.
     *
     * @return list<string>
     */
    public static function reglasArchivo(): array
    {
        return [
            'file',
            sprintf('mimes:%s', implode(',', (array) config('incapacidades.documentos.mimes', []))),
            sprintf('max:%d', (int) config('incapacidades.documentos.max_kb', 15360)),
        ];
    }

    /**
     * Mensajes claros para quien sube el archivo (formato, tamaño y una carga
     * que el servidor no recibió completa).
     *
     * @return array<string, string>
     */
    public static function mensajesArchivo(string $campo, string $sujeto): array
    {
        $formatos = mb_strtoupper(implode(', ', (array) config('incapacidades.documentos.mimes', [])));
        $maximoMb = sprintf('%d MB', intdiv((int) config('incapacidades.documentos.max_kb', 15360), 1024));

        return [
            $campo.'.file' => sprintf('%s debe ser un archivo.', $sujeto),
            $campo.'.mimes' => sprintf('%s debe ser PDF o imagen (%s).', $sujeto, $formatos),
            $campo.'.max' => sprintf('%s supera el tamaño máximo de %s.', $sujeto, $maximoMb),
            $campo.'.uploaded' => sprintf('%s no se pudo subir: revisa que no supere %s e intenta de nuevo.', $sujeto, $maximoMb),
        ];
    }
}
