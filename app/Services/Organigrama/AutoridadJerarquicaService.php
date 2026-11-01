<?php

namespace App\Services\Organigrama;

use App\Models\CoberturaPuesto;
use App\Models\Colaborador;
use App\Models\Puesto;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

/**
 * Autoridad dentro de la línea comercial (config organigrama.linea_comercial):
 * Gerente Regional > Subgerente Regional > Gerente de Sucursal > Subgerente de
 * Sucursal > Gestores. Se modifica lo hecho por un puesto inferior o del mismo
 * nivel, nunca lo hecho por uno superior. Se resuelve por el puesto en la base
 * (titular o cobertura vigente), nunca por el nombre de una persona.
 *
 * La regla solo ordena esta línea: quien está fuera de ella (RH, Dirección,
 * sistemas…) conserva sus permisos. Una acción sin autor conocido se trata como
 * de nivel inferior; quien llama la anota en su historial.
 *
 * El rango del autor es el de su puesto actual: las tablas de decisiones guardan
 * quién decidió, no qué puesto tenía entonces.
 */
class AutoridadJerarquicaService
{
    public const MENSAJE_PUESTO_SUPERIOR = 'Lo registró un puesto superior; no puedes cambiarlo.';

    public const MENSAJE_SIN_AUTORIDAD = 'Esa persona ocupa un puesto superior al tuyo: no puedes hacer este movimiento.';

    public const NOTA_AUTOR_DESCONOCIDO = 'No se conoce quién registró lo anterior: se trató como de un puesto inferior.';

    public function puedeModificarLoHechoPor(User $actor, ?User $autor): bool
    {
        if ($autor === null || $actor->is($autor)) {
            return true;
        }

        $rangoActor = $this->rangoDeUsuario($actor);
        $rangoAutor = $this->rangoDeUsuario($autor);

        return $rangoActor === null || $rangoAutor === null || $rangoActor <= $rangoAutor;
    }

    /**
     * Mando sobre la persona: puesto estrictamente superior y dentro de su
     * alcance (los regionales, toda la empresa; los demás, sus sucursales). Fuera
     * de la línea no hay mando: RH y Dirección actúan con sus propios permisos.
     */
    public function tieneAutoridadSobre(User $actor, Colaborador $colaborador): bool
    {
        $rangoActor = $this->rangoDeUsuario($actor);
        $rangoPersona = $this->rangoDeColaborador($colaborador);

        if ($rangoActor === null || $rangoPersona === null || $rangoActor >= $rangoPersona || $actor->colaborador_id === $colaborador->id) {
            return false;
        }

        if ($this->rangoEsDeEmpresa($rangoActor)) {
            return true;
        }

        return in_array($colaborador->sucursal_principal_id, $this->sucursalesDe($this->colaboradorDe($actor)), true);
    }

    /**
     * La persona ocupa un puesto superior al del actor. Sirve para negar bajas y
     * movimientos aunque esté dentro del alcance por sucursal.
     */
    public function esDePuestoSuperior(Colaborador $colaborador, User $actor): bool
    {
        $rangoActor = $this->rangoDeUsuario($actor);
        $rangoPersona = $this->rangoDeColaborador($colaborador);

        return $rangoActor !== null && $rangoPersona !== null && $rangoPersona < $rangoActor;
    }

    /**
     * Rechaza si alguno de los autores es de un puesto superior.
     *
     * @param  iterable<int, User|null>  $autores
     * @return bool true si hay un autor desconocido que anotar en el historial
     *
     * @throws AuthorizationException
     */
    public function exigirPuedeModificar(User $actor, iterable $autores): bool
    {
        $desconocido = false;

        foreach ($autores as $autor) {
            if (! $this->puedeModificarLoHechoPor($actor, $autor)) {
                throw new AuthorizationException(self::MENSAJE_PUESTO_SUPERIOR);
            }

            $desconocido = $desconocido || $autor === null;
        }

        return $desconocido && $this->rangoDeUsuario($actor) !== null;
    }

    /**
     * Gerente o Subgerente Regional (titular o cobertura): plazas de empresa, su
     * alcance operativo no depende de la sucursal en que estén registrados.
     */
    public function tieneAlcanceDeEmpresa(User $usuario): bool
    {
        $rango = $this->rangoDeColaborador($this->colaboradorDe($usuario));

        return $rango !== null && $this->rangoEsDeEmpresa($rango);
    }

    public function autorPorId(?int $usuarioId): ?User
    {
        return $usuarioId === null ? null : User::withTrashed()->where('id', $usuarioId)->first();
    }

    /**
     * Rango de una cuenta: null si su rol o su puesto la dejan fuera de la línea.
     */
    private function rangoDeUsuario(User $usuario): ?int
    {
        if ($usuario->hasAnyRole((array) config('organigrama.roles_fuera_de_linea', []))) {
            return null;
        }

        return $this->rangoDeColaborador($this->colaboradorDe($usuario));
    }

    /**
     * El mejor rango entre su puesto titular y lo que cubre hoy (0 es el más alto).
     */
    private function rangoDeColaborador(?Colaborador $colaborador): ?int
    {
        if ($colaborador === null) {
            return null;
        }

        $puestos = CoberturaPuesto::query()->where('colaborador_id', $colaborador->id)->where('activa', true)->pluck('puesto_id')
            ->push($colaborador->puesto_id)
            ->filter()
            ->all();

        $rangos = [];

        foreach (Puesto::withTrashed()->whereIn('id', $puestos)->pluck('nombre') as $nombre) {
            $rango = $this->rangoDePuesto((string) $nombre);

            if ($rango !== null) {
                $rangos[] = $rango;
            }
        }

        return $rangos === [] ? null : min($rangos);
    }

    private function rangoDePuesto(string $nombre): ?int
    {
        foreach ($this->linea() as $rango => $nivel) {
            foreach ($nivel['puestos'] as $puesto) {
                if ($this->normalizar($puesto) === $this->normalizar($nombre)) {
                    return $rango;
                }
            }
        }

        return null;
    }

    private function rangoEsDeEmpresa(int $rango): bool
    {
        return $this->linea()[$rango]['de_empresa'] ?? false;
    }

    /**
     * @return list<array{puestos: list<string>, de_empresa: bool}>
     */
    private function linea(): array
    {
        /** @var list<array{puestos: list<string>, de_empresa: bool}> $linea */
        $linea = config('organigrama.linea_comercial', []);

        return $linea;
    }

    /**
     * @return list<int>
     */
    private function sucursalesDe(?Colaborador $colaborador): array
    {
        if ($colaborador === null) {
            return [];
        }

        return array_values(collect([$colaborador->sucursal_principal_id])
            ->merge($colaborador->sucursalesAdicionales()->pluck('sucursales.id'))
            ->merge(CoberturaPuesto::query()->where('colaborador_id', $colaborador->id)->where('activa', true)->pluck('sucursal_id'))
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->all());
    }

    /**
     * Incluye a quien ya está de baja: lo que hizo sigue teniendo autor.
     */
    private function colaboradorDe(User $usuario): ?Colaborador
    {
        return $usuario->colaborador_id === null ? null : Colaborador::withTrashed()->where('id', $usuario->colaborador_id)->first();
    }

    private function normalizar(string $nombre): string
    {
        return mb_strtolower(Str::ascii(trim($nombre)));
    }
}
