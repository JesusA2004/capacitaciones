<?php

namespace App\Http\Requests\Administracion;

use App\Models\Colaborador;
use App\Services\Organigrama\TraspasoPuestoService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * «Cambiar de puesto (traspaso)» de una o varias personas (vista previa y
 * aplicar). Cada persona se autoriza con ColaboradorPolicy::moverEnOrganigrama
 * (organigrama.mover_personas, con alcance, nunca uno mismo).
 */
class TraspasoPuestoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $usuario = $this->user();

        return $usuario !== null && $usuario->can('organigrama.ver') && $usuario->can('organigrama.mover_personas');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'colaborador_ids' => ['required', 'array', 'min:1', 'max:'.TraspasoPuestoService::MAXIMO],
            'colaborador_ids.*' => ['integer', 'distinct', Rule::exists('colaboradores', 'id')],
            'puesto_id' => ['required', 'integer', Rule::exists('puestos', 'id')->where('activo', true)],
            'sucursal_id' => ['nullable', 'integer', Rule::exists('sucursales', 'id')->where('activo', true)],
            'comentario' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'colaborador_ids.required' => 'Elige al menos a una persona.',
            'colaborador_ids.min' => 'Elige al menos a una persona.',
            'colaborador_ids.max' => sprintf('Traspasa a lo más %d personas a la vez.', TraspasoPuestoService::MAXIMO),
            'colaborador_ids.*.exists' => 'Una de las personas elegidas no existe.',
            'colaborador_ids.*.distinct' => 'Una persona está repetida en la lista.',
            'puesto_id.required' => 'Elige a qué puesto pasan.',
            'puesto_id.exists' => 'Elige un puesto activo del catálogo.',
            'sucursal_id.exists' => 'Elige una sucursal activa.',
            'comentario.max' => 'El comentario no puede pasar de 500 caracteres.',
        ];
    }

    /**
     * Personas a traspasar, en el orden elegido; cada una pasa por la
     * Policy (403 si alguna queda fuera del alcance de quien mueve).
     *
     * @return Collection<int, Colaborador>
     */
    public function colaboradores(): Collection
    {
        $ids = array_values(array_map('intval', (array) $this->input('colaborador_ids', [])));
        $porId = Colaborador::query()->whereIn('id', $ids)->get()->keyBy('id');
        $usuario = $this->user();
        $lista = collect();

        foreach ($ids as $id) {
            $colaborador = $porId->get($id);

            if ($colaborador === null) {
                continue;
            }

            if ($usuario === null || ! $usuario->can('moverEnOrganigrama', $colaborador)) {
                throw new AuthorizationException(sprintf('No puedes cambiar de puesto a %s.', $colaborador->nombreCompleto()));
            }

            $lista->push($colaborador);
        }

        return $lista->values();
    }

    /**
     * @return array{puesto_id: int, sucursal_id: int|null}
     */
    public function destino(): array
    {
        return [
            'puesto_id' => $this->integer('puesto_id'),
            'sucursal_id' => $this->filled('sucursal_id') ? $this->integer('sucursal_id') : null,
        ];
    }

    public function comentario(): ?string
    {
        return $this->filled('comentario') ? $this->string('comentario')->toString() : null;
    }
}
