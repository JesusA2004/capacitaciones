<?php

namespace App\Http\Requests\Reclutamiento;

use App\Enums\TipoAgendaEvento;
use App\Models\AgendaEvento;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Agendar una cita desde web o API. La sucursal se autoriza en AgendaEventoService. */
class AgendarEventoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', AgendaEvento::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'candidato_id' => ['nullable', 'required_without:candidato_nuevo', 'integer', 'exists:candidatos,id'],
            'candidato_nuevo' => ['nullable', 'array'],
            'candidato_nuevo.nombre' => ['nullable', 'string', 'max:150'],
            'candidato_nuevo.apellidos' => ['nullable', 'string', 'max:150'],
            'candidato_nuevo.telefono' => ['nullable', 'string', 'max:30'],
            'candidato_nuevo.correo' => ['nullable', 'email', 'max:255'],
            'candidato_nuevo.puesto_objetivo_id' => ['nullable', 'integer', 'exists:puestos,id'],
            'candidato_nuevo.vacante_id' => ['nullable', 'integer', 'exists:vacantes,id'],
            'sucursal_id' => ['required', 'integer', 'exists:sucursales,id'],
            'tipo' => ['required', Rule::enum(TipoAgendaEvento::class)],
            'fecha' => ['required', 'date_format:Y-m-d'],
            'hora' => ['required', 'date_format:H:i'],
            'notas' => ['nullable', 'string', 'max:2000'],
            'domicilio' => ['nullable', 'string', 'max:300'],
            'responsable_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'clave_idempotencia' => ['nullable', 'string', 'max:64'],
            'cv' => ['nullable', 'file', 'max:'.((int) config('reclutamiento.max_upload_mb') * 1024), 'mimes:'.implode(',', (array) config('reclutamiento.extensiones_permitidas'))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'candidato_id.required_without' => 'Elige un candidato o dalo de alta.',
            'sucursal_id.required' => 'Elige la sucursal.',
            'fecha.required' => 'Elige la fecha.',
            'hora.required' => 'Elige la hora.',
        ];
    }
}
