<?php

namespace App\Http\Requests\Rh;

use App\Enums\CanalReclutamiento;
use App\Enums\TipoCostoReclutamiento;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Una campaña parte de una VACANTE REAL: empresa, sucursal, departamento y
 * puesto se derivan de ella (CampanaReclutamientoService::guardar); el
 * periodo (mes/año) sale de la fecha de inicio.
 */
class UpdateCampanaReclutamientoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reclutamiento.campanas.administrar') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'vacante_id' => ['nullable', 'integer', 'exists:vacantes,id'],
            'nombre' => ['nullable', 'string', 'max:150'],
            'canal' => ['required', new Enum(CanalReclutamiento::class)],
            'tipo_costo' => ['nullable', new Enum(TipoCostoReclutamiento::class)],
            'fecha_inicio' => ['required', 'date'],
            'fecha_fin' => ['nullable', 'date', 'after_or_equal:fecha_inicio'],
            'mes' => ['nullable', 'integer', 'min:1', 'max:12'],
            'anio' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'presupuesto' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'monto' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
            'sueldo_publicado' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'copy' => ['nullable', 'string', 'max:5000'],
            'url' => ['nullable', 'url', 'max:500'],
            'impresiones' => ['nullable', 'integer', 'min:0', 'max:4000000000'],
            'clics' => ['nullable', 'integer', 'min:0', 'max:4000000000'],
            'responsable_id' => ['nullable', 'integer', 'exists:users,id'],
            'candidatos_generados' => ['nullable', 'integer', 'min:0'],
            'observaciones' => ['nullable', 'string', 'max:4000'],
            // Arte / PDF de la campaña → NAS privado.
            'adjuntos' => ['nullable', 'array', 'max:10'],
            'adjuntos.*' => ['file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:20480'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'vacante_id.required' => 'Elige la vacante real a la que va dirigida la campaña.',
            'fecha_inicio.required' => 'Indica cuándo inicia la campaña.',
            'fecha_fin.after_or_equal' => 'La fecha de fin no puede ser anterior al inicio.',
            'monto.required' => 'Indica el gasto real (puede ser 0 si aún no se gasta).',
            'url.url' => 'Escribe la liga completa (https://…).',
            'adjuntos.*.mimes' => 'Solo PDF o imágenes (JPG, PNG, WEBP).',
            'adjuntos.*.max' => 'Cada archivo puede pesar hasta 20 MB.',
        ];
    }
}
