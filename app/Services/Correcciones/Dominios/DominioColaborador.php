<?php

namespace App\Services\Correcciones\Dominios;

use App\Models\Colaborador;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use Illuminate\Database\Eloquent\Model;

/**
 * Sujeto = colaborador, con el mismo permiso y alcance del expediente.
 */
abstract class DominioColaborador extends DominioBase
{
    public function __construct(protected readonly AlcanceOrganizacionalService $alcance) {}

    public function sujeto(int $id, bool $bloquear = false): ?Model
    {
        return Colaborador::query()->whereKey($id)->when($bloquear, fn ($q) => $q->lockForUpdate())->first();
    }

    public function etiquetaSujeto(Model $sujeto): string
    {
        return $sujeto instanceof Colaborador ? $sujeto->nombreCompleto() : '';
    }

    public function colaboradorDe(Model $sujeto): ?Colaborador
    {
        return $sujeto instanceof Colaborador ? $sujeto : null;
    }

    public function puedeLeer(User $actor, Model $sujeto): bool
    {
        return $sujeto instanceof Colaborador && $this->alcance->puedeVerExpediente($actor, $sujeto);
    }

    public function puedeEscribir(User $actor, Model $sujeto): bool
    {
        return $sujeto instanceof Colaborador && ! $this->esPropio($actor, $sujeto)
            && $actor->can('expedientes.editar') && $this->alcance->puedeVerExpediente($actor, $sujeto);
    }
}
