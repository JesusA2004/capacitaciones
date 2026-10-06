<?php

namespace App\Services\CicloLaboral;

use App\Enums\EstadoUsuario;
use App\Enums\TipoNodoComercial;
use App\Models\Aprobacion;
use App\Models\Candidato;
use App\Models\CoberturaPuesto;
use App\Models\Colaborador;
use App\Models\NodoComercial;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Colaboradores\JerarquiaColaboradorService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

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
     * Gerencia de una sucursal: ocupantes activos de los puestos
     * configurados (ciclo_laboral.organizacion.puestos_gerencia_sucursal) en
     * ESA sucursal, quien los cubre (CoberturaPuesto vigente), el
     * responsable capturado de la sucursal y, como respaldo, los superiores
     * de la cadena de mando de la persona con ese puesto. Por personas
     * reales, nunca por rol.
     *
     * @return Collection<int, User>
     */
    public function gerenciaSucursalDe(?int $sucursalId, ?Colaborador $persona = null): Collection
    {
        $puestos = $this->puestosConfigurados('puestos_gerencia_sucursal');
        $personas = collect();

        if ($sucursalId !== null && $puestos !== []) {
            $personas = $personas
                ->merge($this->activos()->where('sucursal_principal_id', $sucursalId)->whereIn('puesto_id', $puestos)->get())
                ->merge(CoberturaPuesto::query()->where('activa', true)->whereIn('puesto_id', $puestos)->where('sucursal_id', $sucursalId)->with('colaborador')->get()->pluck('colaborador'));
        }

        if ($persona !== null) {
            $personas = $personas->merge($this->cadenaDeMandoDe($persona)->filter(fn (Colaborador $c) => in_array($c->puesto_id, $puestos, true)));
        }

        $usuarios = $this->usuariosDe($personas, $persona);
        $responsable = $sucursalId !== null ? Sucursal::query()->whereKey($sucursalId)->with('responsable')->first()?->responsable : null;

        if ($responsable !== null && $responsable->acceso_bloqueado_en === null && ($persona === null || $responsable->colaborador_id !== $persona->id)) {
            $usuarios->push($responsable);
        }

        return $usuarios->unique('id')->values();
    }

    /**
     * Gerente regional de la región de una sucursal (matriz comercial:
     * zona → región → puesto regional ligado), ocupantes de un puesto
     * regional genérico cuya sucursal está en esa región, quien lo cubre y,
     * como respaldo, los regionales de la cadena de mando de la persona.
     *
     * @return Collection<int, User>
     */
    public function regionalesDe(?int $sucursalId, ?Colaborador $persona = null): Collection
    {
        /** @var list<string> $nombresRegion */
        $nombresRegion = config('organigrama.puestos_de_region', []);
        $genericos = Puesto::query()->whereIn('nombre', $nombresRegion)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $ligados = NodoComercial::query()->where('tipo', TipoNodoComercial::Region->value)->whereNotNull('puesto_id')->pluck('puesto_id')->map(fn ($id) => (int) $id)->all();
        $personas = $persona !== null
            ? $this->cadenaDeMandoDe($persona)->filter(fn (Colaborador $c) => in_array($c->puesto_id, [...$ligados, ...$genericos], true))
            : collect();

        $region = $sucursalId !== null
            ? NodoComercial::query()
                ->where('tipo', TipoNodoComercial::Zona->value)
                ->where('sucursal_id', $sucursalId)
                ->whereHas('padre', fn ($q) => $q->where('tipo', TipoNodoComercial::Region->value))
                ->with('padre:id,puesto_id,tipo')
                ->first()?->padre
            : null;

        if ($region !== null) {
            $sucursalesRegion = NodoComercial::query()->where('tipo', TipoNodoComercial::Zona->value)->where('parent_id', $region->id)->whereNotNull('sucursal_id')->pluck('sucursal_id')->all();

            if ($region->puesto_id !== null) {
                $personas = $personas
                    ->merge($this->activos()->where('puesto_id', $region->puesto_id)->get())
                    ->merge(CoberturaPuesto::query()->where('activa', true)->where('puesto_id', $region->puesto_id)
                        ->where(fn ($q) => $q->whereNull('region_id')->orWhere('region_id', $region->id))
                        ->with('colaborador')->get()->pluck('colaborador'));
            }

            if ($genericos !== []) {
                $personas = $personas->merge($this->activos()->whereIn('puesto_id', $genericos)->whereIn('sucursal_principal_id', $sucursalesRegion)->get());
            }
        }

        return $this->usuariosDe($personas, $persona);
    }

    /**
     * Gerencia de Recursos Humanos: ocupantes activos de los puestos
     * configurados (ciclo_laboral.organizacion.puestos_gerencia_rh).
     *
     * @return Collection<int, User>
     */
    public function gerenciaRh(?Colaborador $persona = null): Collection
    {
        $puestos = $this->puestosConfigurados('puestos_gerencia_rh');

        return $puestos === [] ? collect() : $this->usuariosDe($this->activos()->whereIn('puesto_id', $puestos)->get(), $persona);
    }

    /**
     * Dirección Comercial: ocupantes activos de los puestos configurados
     * (ciclo_laboral.organizacion.puestos_direccion_comercial). Visto bueno
     * extra para quien YA es de gerencia o superior (ver
     * AprobacionJerarquicaService::esGerenciaOSuperior).
     *
     * @return Collection<int, User>
     */
    public function direccionComercialDe(?Colaborador $persona = null): Collection
    {
        $puestos = $this->puestosConfigurados('puestos_direccion_comercial');

        return $puestos === [] ? collect() : $this->usuariosDe($this->activos()->whereIn('puesto_id', $puestos)->get(), $persona);
    }

    /**
     * @return list<int>
     */
    private function puestosConfigurados(string $clave): array
    {
        /** @var list<string> $nombres */
        $nombres = config("ciclo_laboral.organizacion.{$clave}", []);

        return $nombres === [] ? [] : array_values(Puesto::query()->whereIn('nombre', $nombres)->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    /**
     * @return Builder<Colaborador>
     */
    private function activos(): Builder
    {
        return Colaborador::query()->where('estatus', EstadoUsuario::Activo->value);
    }

    /**
     * @param  Collection<int, mixed>  $personas
     * @return Collection<int, User>
     */
    private function usuariosDe(Collection $personas, ?Colaborador $excluir): Collection
    {
        return $personas
            ->filter(fn ($c) => $c instanceof Colaborador && ($excluir === null || $c->id !== $excluir->id))
            ->map(fn (Colaborador $c) => $this->usuarioActivoDe($c))
            ->filter()
            ->unique('id')
            ->values();
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

    /**
     * Valida que $jefeId pueda ser jefe (o gerente) de la persona: existe,
     * está activo/en incorporación, no es ella misma y no crea un ciclo
     * (A → B → A, A → B → C → A). Lanza 422 con el campo indicado.
     */
    public function validarSuperior(Colaborador $persona, ?int $jefeId, string $campo = 'jefe_id'): void
    {
        if ($jefeId === null) {
            return;
        }

        if ($jefeId === $persona->id) {
            throw ValidationException::withMessages([$campo => 'Una persona no puede ser su propio jefe.']);
        }

        $jefe = Colaborador::query()->where('id', $jefeId)->first();

        if ($jefe === null || ! in_array($jefe->estatus, [EstadoUsuario::Activo, EstadoUsuario::EnIncorporacion], true)) {
            throw ValidationException::withMessages([$campo => 'El jefe debe ser una persona activa.']);
        }

        // Subir desde el jefe propuesto: si en su cadena aparece la persona,
        // asignarlo cerraría un ciclo.
        $actual = $jefe;
        $vistos = [$jefe->id => true];

        for ($i = 0; $i < self::PROFUNDIDAD_MAXIMA * 2; $i++) {
            $siguienteId = $actual->jefe_id ?? $actual->gerente_id;

            if ($siguienteId === null) {
                return;
            }

            if ($siguienteId === $persona->id) {
                throw ValidationException::withMessages([$campo => sprintf('%s ya depende de %s: asignarlo como jefe crearía un ciclo.', $jefe->nombreCompleto(), $persona->nombreCompleto())]);
            }

            if (isset($vistos[$siguienteId])) {
                return;
            }

            $vistos[$siguienteId] = true;
            $siguiente = Colaborador::query()->select(['id', 'jefe_id', 'gerente_id'])->where('id', $siguienteId)->first();

            if ($siguiente === null) {
                return;
            }

            $actual = $siguiente;
        }
    }
}
