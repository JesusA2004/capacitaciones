<?php

namespace App\Http\Requests\Bajas;

use App\Enums\EntregaAvisoRescision;
use App\Enums\Recontratable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * RH completa o corrige datos de una baja en revisión (la causal se cambia
 * con su propio formulario y motivo). El permiso lo valida BajaService.
 */
class ActualizarBajaRhRequest extends FormRequest
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
            'fecha_baja' => ['sometimes', 'nullable', 'date'],
            'recontratable' => ['sometimes', 'nullable', Rule::enum(Recontratable::class)],
            'comentarios' => ['sometimes', 'nullable', 'string', 'max:'.(int) config('bajas.reglas.comentario_maximo', 500)],
            'fecha_conocimiento_falta' => ['sometimes', 'nullable', 'date'],
            'entrega_aviso_rescision' => ['sometimes', 'nullable', Rule::enum(EntregaAvisoRescision::class)],
            'fecha_entrega_aviso' => ['sometimes', 'nullable', 'date'],
            'es_rotacion' => ['sometimes', 'nullable', 'boolean'],
            'motivo_cambio' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function datos(): array
    {
        return $this->validated();
    }
}
