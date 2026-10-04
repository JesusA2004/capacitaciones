<?php

namespace App\Http\Requests\CicloLaboral;

use App\Services\CierreLaboral\ProcedimientoBajaService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Datos de las etapas del procedimiento integral de baja: negativa de
 * firma, testigos del acta, notificación electrónica, baja operativa,
 * consignación preventiva y evidencias.
 */
class ProcedimientoBajaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maximo = (int) config('contratos.max_upload_mb', 20) * 1024;
        $extensiones = implode(',', (array) config('contratos.extensiones_permitidas', ['pdf', 'jpg', 'jpeg', 'png']));

        return [
            'documentos' => ['nullable', 'array', 'max:10'],
            'documentos.*' => ['string', 'max:80'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
            'finiquito_a_disposicion' => ['sometimes', 'boolean'],
            'testigos' => ['nullable', 'array', 'max:2'],
            'testigos.*.nombre' => ['nullable', 'string', 'max:160'],
            'testigos.*.cargo' => ['nullable', 'string', 'max:120'],
            'participantes' => ['nullable', 'array'],
            'participantes.*' => ['nullable', 'string', 'max:191'],
            'etapa' => ['sometimes', 'required', 'string', 'in:'.implode(',', array_keys(ProcedimientoBajaService::ETAPAS))],
            'medios' => ['nullable', 'array'],
            'medios.*' => ['string', 'in:correo,whatsapp'],
            'evidencias' => ['nullable', 'array', 'max:10'],
            'evidencias.*' => ['file', 'max:'.$maximo, 'mimes:'.$extensiones],
        ];
    }
}
