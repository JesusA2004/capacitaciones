<?php

namespace App\Services\CicloLaboral;

use App\Enums\EstadoUsuario;
use App\Models\Aprobacion;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Colaboradores\JerarquiaColaboradorService;
use Illuminate\Support\Collection;

/**
 * Fuente única de "a quién reporta quién" para las aprobaciones del ciclo
 * laboral. ROL = qué permisos tiene una cuenta; ORGANIGRAMA = a quién
 * reporta una persona. Este service solo usa el organigrama real de
 * personas (colaboradores.jefe_id / gerente_id) y los responsables
 * capturados en catálogos (sucursales.responsable_id,
 * vacantes.gerente_solicitante_id, candidatos.gerente_involucrado_id) —
 * nunca el nombre de un rol ni el texto del nombre de un puesto.
 *
 * Si cambia el jefe de una persona, las aprobaciones NUEVAS se dirigen al
 * nuevo jefe automáticamente (se resuelven al abrirse); las ya abiertas o
 * decididas conservan su snapshot en `aprobaciones`.
 */
class OrganizacionJerarquiaService
{
    /** Protege contra ciclos accidentales en datos capturados a mano. */
    private const PROFUNDIDAD_MAXIMA = 15;

    public const PERMISO_PREAUTORIZAR = 'ciclo.preautorizar';

    public const PERMISO_AUTORIZAR_RH = 'ciclo.autorizar_rh';

    public function __construct(
        private readonly JerarquiaColaboradorService $jerarquia,
        private readonly AlcanceOrganizacionalService $alcance,
    ) {}

    /**
     * Superior operativo inmediato: jefe inmediato o, si no está capturado,
     * el gerente asignado.
     */
    public function supervisorDirectoDe(Colaborador $colaborador): ?Colaborador
    {
        $id = $colaborador->jefe_id ?? $colaborador->gerente_id;

        if ($id === null || $id === $colaborador->id) {
            return null;
        }

        return Colaborador::query()->where('id', $id)->first();
    }

    /**
     * Cadena de mando ascendente (jefe, jefe del jefe...), sin repetidos y a
     * prueba de ciclos.
     *
     * @return Collection<int, Colaborador>
     */
    public function cadenaDeMandoDe(Colaborador $colaborador): Collection
    {
        $cadena = collect();
        $vistos = [$colaborador->id => true];
        $actual = $colaborador;

        for ($i = 0; $i < self::PROFUNDIDAD_MAXIMA; $i++) {
            $superior = $this->supervisorDirectoDe($actual);

            if ($superior === null || isset($vistos[$superior->id])) {
                break;
            }

            $vistos[$superior->id] = true;
            $cadena->push($superior);
            $actual = $superior;
        }

        return $cadena->values();
    }

    /**
     * @return Collection<int, Colaborador>
     */
    public function subordinadosDe(Colaborador $colaborador, bool $recursivo = false): Collection
    {
        $directos = $this->jerarquia->subordinadosDirectos($colaborador);

        if (! $recursivo) {
            return $directos->toBase();
        }

        $todos = collect();
        $pendientes = $directos->toBase();
        $vistos = [$colaborador->id => true];
        $nivel = 0;

        while ($pendientes->isNotEmpty() && $nivel < self::PROFUNDIDAD_MAXIMA) {
            $siguientes = collect();

            foreach ($pendientes as $sub) {
                if (isset($vistos[$sub->id])) {
                    continue;
                }

                $vistos[$sub->id] = true;
                $todos->push($sub);
                $siguientes = $siguientes->merge($this->jerarquia->subordinadosDirectos($sub));
            }

            $pendientes = $siguientes;
            $nivel++;
        }

        return $todos->values();
    }

    public function estaEnCadenaDeMando(Colaborador $superior, Colaborador $colaborador): bool
    {
        return $this->cadenaDeMandoDe($colaborador)->contains(fn (Colaborador $c) => $c->id === $superior->id);
    }

    /**
     * Quién preautoriza un proceso sobre un colaborador:
     *  - si quien lo solicita está en la cadena de mando de la persona, su
     *    propia solicitud es la preautorización operativa (implícita);
     *  - si no, el superior directo de la persona;
     *  - si la persona no tiene superior en el organigrama, la etapa queda
     *    registrada como "no aplica" con su motivo (RH sigue siendo la
     *    autorización final).
     *
     * @return array{aprobador: Colaborador|null, implicita: bool, motivo_omision: string|null}
     */
    public function preautorizadorDeColaborador(Colaborador $persona, ?User $solicitante = null): array
    {
        $solicitanteColaborador = $solicitante?->colaborador;

        if ($solicitanteColaborador !== null && $this->estaEnCadenaDeMando($solicitanteColaborador, $persona)) {
            return ['aprobador' => $solicitanteColaborador, 'implicita' => true, 'motivo_omision' => null];
        }

        $supervisor = $this->supervisorDirectoDe($persona);

        if ($supervisor === null) {
            return [
                'aprobador' => null,
                'implicita' => false,
                'motivo_omision' => 'La persona no tiene superior operativo capturado en el organigrama.',
            ];
        }

        return ['aprobador' => $supervisor, 'implicita' => false, 'motivo_omision' => null];
    }

    /**
     * Gerente que preautoriza la selección de un candidato: el gerente
     * asignado al candidato, o el que solicitó la vacante, o el responsable
     * capturado de la sucursal (en ese orden).
     *
     * @return array{usuario: User|null, colaborador: Colaborador|null}
     */
    public function preautorizadorDeCandidato(Candidato $candidato): array
    {
        $candidato->loadMissing(['gerenteInvolucrado.colaborador', 'vacante.gerenteSolicitante.colaborador', 'sucursal.responsable.colaborador']);

        $usuario = $candidato->gerenteInvolucrado
            ?? $candidato->vacante->gerenteSolicitante
            ?? $candidato->sucursal?->responsable;

        return ['usuario' => $usuario, 'colaborador' => $usuario?->colaborador];
    }

    /**
     * true si $usuario puede decidir la preautorización pendiente: es el
     * aprobador resuelto, o está por encima de él en la cadena de mando
     * (el superior puede preautorizar en lugar de su subordinado). Siempre
     * con el permiso operativo y alcance sobre la persona afectada.
     */
    public function puedePreautorizar(User $usuario, Aprobacion $aprobacion): bool
    {
        if (! $usuario->can(self::PERMISO_PREAUTORIZAR) || ! $this->alcanzaPersona($usuario, $aprobacion)) {
            return false;
        }

        if ($aprobacion->aprobador_user_id !== null && $aprobacion->aprobador_user_id === $usuario->id) {
            return true;
        }

        // Candidato sin gerente asignado ni responsable de sucursal capturado:
        // preautoriza cualquier superior operativo con alcance sobre la sucursal.
        if ($aprobacion->aprobador_user_id === null && $aprobacion->aprobador_colaborador_id === null && $aprobacion->candidato_id !== null) {
            return true;
        }

        $colaboradorUsuario = $usuario->colaborador;

        if ($colaboradorUsuario === null) {
            return false;
        }

        if ($aprobacion->aprobador_colaborador_id === $colaboradorUsuario->id) {
            return true;
        }

        $aprobador = $aprobacion->aprobador_colaborador_id !== null
            ? Colaborador::query()->where('id', $aprobacion->aprobador_colaborador_id)->first()
            : null;

        if ($aprobador !== null) {
            return $this->estaEnCadenaDeMando($colaboradorUsuario, $aprobador);
        }

        // Sin aprobador resuelto (p. ej. candidato sin gerente asignado): el
        // superior de la persona afectada, si la hay.
        $persona = $aprobacion->colaborador_id !== null
            ? Colaborador::withTrashed()->where('id', $aprobacion->colaborador_id)->first()
            : null;

        return $persona !== null && $this->estaEnCadenaDeMando($colaboradorUsuario, $persona);
    }

    /**
     * RH es la autoridad final: permiso explícito + alcance sobre la persona.
     */
    public function puedeAutorizarRh(User $usuario, Aprobacion $aprobacion): bool
    {
        return $usuario->can(self::PERMISO_AUTORIZAR_RH) && $this->alcanzaPersona($usuario, $aprobacion);
    }

    private function alcanzaPersona(User $usuario, Aprobacion $aprobacion): bool
    {
        if ($this->alcance->tieneAlcanceGlobal($usuario)) {
            return true;
        }

        if ($aprobacion->colaborador_id !== null) {
            $persona = Colaborador::withTrashed()->where('id', $aprobacion->colaborador_id)->first();

            return $persona !== null && ($this->alcance->alcanzaColaborador($usuario, $persona) || $this->esSuperiorPorCadena($usuario, $persona));
        }

        if ($aprobacion->candidato_id !== null) {
            $candidato = Candidato::query()->where('id', $aprobacion->candidato_id)->first();

            return $candidato !== null && $this->alcanzaCandidato($usuario, $candidato);
        }

        return false;
    }

    public function alcanzaCandidato(User $usuario, Candidato $candidato): bool
    {
        if ($this->alcance->tieneAlcanceGlobal($usuario)) {
            return true;
        }

        if ($candidato->gerente_involucrado_id !== null && $candidato->gerente_involucrado_id === $usuario->id) {
            return true;
        }

        return $candidato->sucursal_id !== null && $this->alcance->sucursalesVisiblesIds($usuario)->contains($candidato->sucursal_id);
    }

    private function esSuperiorPorCadena(User $usuario, Colaborador $persona): bool
    {
        return $usuario->colaborador !== null && $this->estaEnCadenaDeMando($usuario->colaborador, $persona);
    }

    /**
     * Usuarios activos con cuenta de la persona (para notificar).
     */
    public function usuarioActivoDe(?Colaborador $colaborador): ?User
    {
        $usuario = $colaborador?->user;

        if ($usuario === null || $usuario->acceso_bloqueado_en !== null) {
            return null;
        }

        return $colaborador->estatus === EstadoUsuario::Inactivo ? null : $usuario;
    }
}
