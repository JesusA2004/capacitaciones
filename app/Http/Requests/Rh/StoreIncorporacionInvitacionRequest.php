<?php

namespace App\Http\Requests\Rh;

use App\Enums\EstadoCandidato;
use App\Enums\EstadoInvitacionIncorporacion;
use App\Models\Candidato;
use App\Models\IncorporacionInvitacion;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Alta Digital QR simplificado (ver sección 5 del encargo): el formulario ya
 * no captura nombre/correo/teléfono/empresa/sucursal/departamento/puesto a
 * mano — todo eso se autocompleta desde el Candidato elegido
 * (IncorporacionInvitacionController::store()). Aquí solo se valida que el
 * candidato exista, esté "Listo para contratación" y no tenga ya una
 * incorporación en curso (idempotencia).
 */
class StoreIncorporacionInvitacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('rh.incorporacion.invitaciones.crear') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'candidato_id' => ['required', 'integer', 'exists:candidatos,id'],
            // QR de acceso temporal a un formulario de incorporacion: rango
            // deliberadamente corto, nunca dias ni "hasta 1 año" como antes.
            'duracion_horas' => ['required', 'integer', 'min:1', 'max:24'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $validator): void {
            $candidatoId = $this->input('candidato_id');

            if ($candidatoId === null) {
                return;
            }

            $candidato = Candidato::query()->where('id', $candidatoId)->first();

            if ($candidato === null) {
                return;
            }

            if ($candidato->estado !== EstadoCandidato::ListoParaContratacion) {
                $validator->errors()->add(
                    'candidato_id',
                    'El candidato debe estar "Listo para contratación" antes de generar su invitación QR.',
                );

                return;
            }

            if ($this->tieneColaboradorCreado($candidato)) {
                $validator->errors()->add(
                    'candidato_id',
                    'Este candidato ya tiene un colaborador dado de alta: no se puede generar otra invitación.',
                );

                return;
            }

            if ($this->tieneInvitacionActiva($candidato)) {
                $validator->errors()->add(
                    'candidato_id',
                    'Este candidato ya tiene una invitación QR activa y vigente. Revócala o espera a que venza antes de generar otra.',
                );
            }
        });
    }

    /**
     * Idempotencia: nunca dos invitaciones activas a la vez para el mismo
     * candidato, ni una nueva mientras la anterior siga sin vencer/usarse/
     * revocarse.
     */
    private function tieneInvitacionActiva(Candidato $candidato): bool
    {
        return IncorporacionInvitacion::query()
            ->where('candidato_id', $candidato->id)
            ->where('estado', EstadoInvitacionIncorporacion::Activo)
            ->where('expires_at', '>', now())
            ->exists();
    }

    /**
     * Idempotencia: si el candidato ya fue convertido en colaborador (por
     * Alta Digital aprobada o por una invitación QR ya usada), no tiene
     * caso generarle otra invitación.
     */
    private function tieneColaboradorCreado(Candidato $candidato): bool
    {
        $candidato->loadMissing('altaDigital');

        if ($candidato->altaDigital?->colaborador_id !== null) {
            return true;
        }

        return IncorporacionInvitacion::query()
            ->where('candidato_id', $candidato->id)
            ->whereNotNull('user_id')
            ->exists();
    }
}
