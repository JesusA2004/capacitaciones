<?php

namespace App\Http\Requests\Rh;

use App\Enums\FuenteCandidato;
use App\Models\Candidato;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCandidatoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Candidato::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'empresa_id' => ['nullable', 'integer', 'exists:empresas,id'],
            'sucursal_id' => ['nullable', 'integer', 'exists:sucursales,id'],
            'departamento_id' => ['nullable', 'integer', 'exists:departamentos,id'],
            'puesto_objetivo_id' => ['nullable', 'integer', 'exists:puestos,id'],
            'espontaneo' => ['sometimes', 'boolean'],
            // Sin vacante solo si el candidato es explícitamente espontáneo
            // (CLAUDE.md §3): ya no existe el "pipeline general" implícito.
            'vacante_id' => [Rule::requiredIf(fn () => ! $this->boolean('espontaneo')), 'nullable', 'integer', 'exists:vacantes,id'],
            // De qué campaña viene (cuenta sola en el embudo y el costo de
            // esa campaña). Si no llegó por una campaña, el medio por el que
            // llegó es obligatorio: ningún candidato queda sin origen.
            'campana_reclutamiento_id' => ['nullable', 'integer', 'exists:campanas_reclutamiento,id'],
            'nombre' => ['required', 'string', 'max:150'],
            'apellidos' => ['nullable', 'string', 'max:150'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'correo' => ['nullable', 'email', 'max:255'],
            // Sin campaña solo valen los medios que no son pauta: «llegó por
            // Meta» sin decir qué campaña sería información duplicada/suelta.
            'fuente' => ['nullable', 'required_without:campana_reclutamiento_id', 'string', Rule::in(
                $this->filled('campana_reclutamiento_id')
                    ? FuenteCandidato::valores()
                    : array_map(fn (FuenteCandidato $f): string => $f->value, FuenteCandidato::sinCampana()),
            )],
            // Preguntas de requisito indispensable del puesto (pregunta_id =>
            // sí/no). Cuáles son obligatorias lo decide
            // PreguntasFiltroCandidatoService según el puesto.
            'respuestas_filtro' => ['nullable', 'array'],
            'respuestas_filtro.*' => ['nullable', 'boolean'],
            'observaciones' => ['nullable', 'string', 'max:4000'],
            'responsable_rh_id' => ['nullable', 'integer', 'exists:users,id'],
            'gerente_involucrado_id' => ['nullable', 'integer', 'exists:users,id'],
            // CV desde la fase inicial (opcional): carpeta candidatos/{id}/cv/ del NAS.
            // O, en vez de subirlo, el nombre de un archivo de la bandeja de
            // CVs del NAS (descargas de WhatsApp Web, ver BandejaCvService).
            'cv_bandeja' => ['nullable', 'string', 'max:255'],
            'cv' => ['nullable', 'file', 'max:'.((int) config('reclutamiento.max_upload_mb') * 1024), 'mimes:'.implode(',', (array) config('reclutamiento.extensiones_permitidas'))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fuente.required_without' => 'Indica cómo llegó el candidato: la campaña o, si no fue por campaña, el medio.',
            'fuente.in' => 'Si llegó por una pauta (Meta, Indeed, Computrabajo, LinkedIn), elige la campaña.',
            'respuestas_filtro.*.boolean' => 'Responde Sí o No.',
        ];
    }
}
