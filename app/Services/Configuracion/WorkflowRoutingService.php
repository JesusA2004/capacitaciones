<?php

namespace App\Services\Configuracion;

use App\Enums\EstadoUsuario;
use App\Enums\TipoDestinatarioNotificacion as Tipo;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\ReglaNotificacion;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Auditoria\AuditoriaService;
use App\Services\CicloLaboral\OrganizacionJerarquiaService;
use App\Services\Colaboradores\JerarquiaColaboradorService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Throwable;

/**
 * Ruteo de notificaciones: resuelve A QUIÉN se avisa de cada evento según
 * su regla (destinatarios dinámicos sobre el organigrama real de personas:
 * jefe directo, gerencia de su sucursal, regional, RH...). Las reglas por
 * defecto están versionadas en config/configuracion_sistema.php ('eventos');
 * Administración → Configuración → Notificaciones las puede ajustar.
 *
 * NOTIFICAR ≠ AUTORIZAR: este service jamás da permiso de decidir nada. Las
 * decisiones siguen protegidas por Policy + permiso + alcance +
 * AprobacionService (RH siempre es la autorización final).
 *
 * Siempre: sin duplicados, sin cuentas bloqueadas/inactivas, respetando el
 * alcance cuando el destinatario sale de un permiso o de una lista, con log
 * cuando un receptor requerido no existe y fallback auditable.
 */
class WorkflowRoutingService
{
    /** Destinatarios cuando el evento no tiene regla: seguro y acotado. */
    private const FALLBACK_SEGURO = ['rh'];

    public function __construct(
        private readonly OrganizacionJerarquiaService $organizacion,
        private readonly JerarquiaColaboradorService $personas,
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly AuditoriaService $auditoria,
    ) {}

    /**
     * Todas las reglas (catálogo + personalizaciones) para la pantalla.
     *
     * @return list<array<string, mixed>>
     */
    public function reglas(): array
    {
        /** @var array<string, array<string, mixed>> $eventos */
        $eventos = config('configuracion_sistema.eventos', []);

        return array_map(fn (string $evento) => $this->regla($evento), array_keys($eventos));
    }

    /**
     * @return array{evento: string, etiqueta: string, descripcion: string|null, destinatarios: list<string>, permiso: string|null, usuario_ids: list<int>, fallback: list<string>, activa: bool, personalizada: bool, conocido: bool, actualizado_en: string|null}
     */
    public function regla(string $evento): array
    {
        /** @var array<string, mixed>|null $catalogo */
        $catalogo = config("configuracion_sistema.eventos.{$evento}");
        $guardada = $this->guardada($evento);

        $destinatarios = $guardada !== null ? $guardada->destinatarios : (array) ($catalogo['destinatarios'] ?? self::FALLBACK_SEGURO);
        $fallback = $guardada !== null ? ($guardada->fallback ?? []) : (array) ($catalogo['fallback'] ?? self::FALLBACK_SEGURO);

        return [
            'evento' => $evento,
            'etiqueta' => (string) ($catalogo['etiqueta'] ?? $evento),
            'descripcion' => isset($catalogo['descripcion']) ? (string) $catalogo['descripcion'] : null,
            'destinatarios' => array_values(array_map('strval', $destinatarios)),
            'permiso' => $guardada !== null ? $guardada->permiso : (isset($catalogo['permiso']) ? (string) $catalogo['permiso'] : null),
            'usuario_ids' => array_map('intval', $guardada->usuario_ids ?? []),
            'fallback' => array_values(array_map('strval', $fallback)),
            'activa' => $guardada === null || $guardada->activa,
            'personalizada' => $guardada !== null,
            'conocido' => $catalogo !== null,
            'actualizado_en' => $guardada?->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Destinatarios del evento, cada uno con el motivo por el que recibe el
     * aviso ("eres su jefe directo").
     *
     * @param  array{solicitante?: User|null, creador?: User|null, evaluador?: User|Colaborador|null, aprobador?: User|null, excluir?: list<int>}  $contexto
     * @return Collection<int, array{usuario: User, motivos: list<string>}>
     */
    public function resolver(string $evento, Colaborador|Candidato|null $sujeto, array $contexto = []): Collection
    {
        $regla = $this->regla($evento);

        if (! $regla['conocido'] && ! $regla['personalizada']) {
            Log::warning('WorkflowRoutingService: evento sin regla de notificación; se usa el destinatario seguro (RH con alcance).', ['evento' => $evento]);
        }

        if (! $regla['activa']) {
            return collect();
        }

        $encontrados = $this->porTipos($regla['destinatarios'], $evento, $regla, $sujeto, $contexto, true);

        if ($encontrados === [] && $regla['fallback'] !== []) {
            Log::warning('WorkflowRoutingService: la regla no encontró destinatarios; se usa su respaldo.', ['evento' => $evento, 'fallback' => $regla['fallback'], 'sujeto' => $sujeto?->getKey()]);
            $encontrados = $this->porTipos($regla['fallback'], $evento, $regla, $sujeto, $contexto, false);
        }

        $excluir = array_map('intval', $contexto['excluir'] ?? []);

        return collect($encontrados)
            ->reject(fn (array $d) => in_array($d['usuario']->id, $excluir, true))
            ->values();
    }

    /**
     * @param  array{destinatarios: list<string>, permiso?: string|null, usuario_ids?: list<int>|null, fallback?: list<string>|null, activa?: bool}  $datos
     */
    public function guardarRegla(string $evento, array $datos, User $actor): void
    {
        if (config("configuracion_sistema.eventos.{$evento}") === null) {
            throw ValidationException::withMessages(['evento' => 'Ese evento no existe.']);
        }

        $tipos = array_map(fn (Tipo $t) => $t->value, Tipo::cases());
        validator($datos, [
            'destinatarios' => ['present', 'array'],
            'destinatarios.*' => ['string', Rule::in($tipos)],
            'fallback' => ['nullable', 'array'],
            'fallback.*' => ['string', Rule::in($tipos)],
            'permiso' => ['nullable', 'string', Rule::exists('permissions', 'name'), Rule::requiredIf(in_array(Tipo::UsuariosConPermiso->value, $datos['destinatarios'], true) || in_array(Tipo::UsuariosConPermiso->value, (array) ($datos['fallback'] ?? []), true))],
            'usuario_ids' => ['nullable', 'array', Rule::requiredIf(in_array(Tipo::UsuarioEspecifico->value, $datos['destinatarios'], true))],
            'usuario_ids.*' => ['integer', Rule::exists('users', 'id')],
            'activa' => ['sometimes', 'boolean'],
        ], [
            'permiso.required' => 'Elige el permiso para «usuarios con permiso».',
            'usuario_ids.required' => 'Elige al menos un usuario para «usuarios específicos».',
        ])->validate();

        $antes = $this->regla($evento);

        ReglaNotificacion::query()->updateOrCreate(['evento' => $evento], [
            'destinatarios' => array_values(array_unique($datos['destinatarios'])),
            'permiso' => $datos['permiso'] ?? null,
            'usuario_ids' => array_values(array_unique(array_map('intval', $datos['usuario_ids'] ?? []))),
            'fallback' => array_values(array_unique($datos['fallback'] ?? [])),
            'activa' => $datos['activa'] ?? true,
            'actualizado_por' => $actor->id,
        ]);

        $despues = $this->regla($evento);
        $this->auditoria->registrar('regla_notificacion_actualizada', null, $actor, [
            'evento' => $evento,
            'antes' => array_intersect_key($antes, array_flip(['destinatarios', 'permiso', 'usuario_ids', 'fallback', 'activa'])),
            'despues' => array_intersect_key($despues, array_flip(['destinatarios', 'permiso', 'usuario_ids', 'fallback', 'activa'])),
        ]);
    }

    public function restaurarRegla(string $evento, User $actor): void
    {
        $antes = $this->regla($evento);
        ReglaNotificacion::query()->where('evento', $evento)->delete();

        $this->auditoria->registrar('regla_notificacion_restaurada', null, $actor, [
            'evento' => $evento,
            'antes' => array_intersect_key($antes, array_flip(['destinatarios', 'permiso', 'usuario_ids', 'fallback', 'activa'])),
        ]);
    }

    /**
     * @param  list<string>  $tipos
     * @param  array<string, mixed>  $regla
     * @param  array<string, mixed>  $contexto
     * @return array<int, array{usuario: User, motivos: list<string>}>
     */
    private function porTipos(array $tipos, string $evento, array $regla, Colaborador|Candidato|null $sujeto, array $contexto, bool $avisarFaltantes): array
    {
        $encontrados = [];

        foreach ($tipos as $valor) {
            $tipo = Tipo::tryFrom($valor);

            if ($tipo === null) {
                continue;
            }

            $usuarios = $this->usuariosDe($tipo, $regla, $sujeto, $contexto)
                ->filter(fn (User $u) => $this->puedeRecibir($u))
                ->unique('id');

            if ($usuarios->isEmpty() && $avisarFaltantes) {
                Log::warning('WorkflowRoutingService: receptor requerido inexistente.', ['evento' => $evento, 'destinatario' => $tipo->value, 'sujeto' => $sujeto?->getKey()]);
            }

            foreach ($usuarios as $usuario) {
                $encontrados[$usuario->id] ??= ['usuario' => $usuario, 'motivos' => []];
                $encontrados[$usuario->id]['motivos'][] = $tipo->motivo();
            }
        }

        return $encontrados;
    }

    /**
     * @param  array<string, mixed>  $regla
     * @param  array<string, mixed>  $contexto
     * @return Collection<int, User>
     */
    private function usuariosDe(Tipo $tipo, array $regla, Colaborador|Candidato|null $sujeto, array $contexto): Collection
    {
        $colaborador = $sujeto instanceof Colaborador ? $sujeto : null;
        $sucursalId = $sujeto instanceof Colaborador ? $sujeto->sucursal_principal_id : ($sujeto instanceof Candidato ? $sujeto->sucursal_id : null);

        try {
            return match ($tipo) {
                Tipo::Solicitante => $this->deContexto($contexto['solicitante'] ?? null),
                Tipo::Creador => $this->deContexto($contexto['creador'] ?? null),
                Tipo::Aprobador => $this->deContexto($contexto['aprobador'] ?? null),
                Tipo::Evaluador => $this->deContexto($contexto['evaluador'] ?? null),
                Tipo::ColaboradorAfectado => $this->deContexto($colaborador),
                Tipo::JefeDirecto => $this->deContexto($sujeto instanceof Candidato ? $this->organizacion->preautorizadorDeCandidato($sujeto)['usuario'] : $this->jefeDirecto($sujeto)),
                Tipo::Gerente => $this->deContexto($colaborador !== null ? $this->personas->gerenteDe($colaborador) : null),
                Tipo::SuperiorDelJefe => $this->deContexto(($jefe = $this->jefeDirecto($sujeto)) !== null ? $this->organizacion->supervisorDirectoDe($jefe) : null),
                Tipo::GerenciaSucursal => $this->organizacion->gerenciaSucursalDe($sucursalId, $colaborador),
                Tipo::Regional => $this->organizacion->regionalesDe($sucursalId, $colaborador),
                Tipo::GerenciaRh => $this->organizacion->gerenciaRh($colaborador),
                Tipo::Rh => $this->conPermiso(OrganizacionJerarquiaService::PERMISO_AUTORIZAR_RH, $sujeto),
                Tipo::UsuariosConPermiso => is_string($regla['permiso'] ?? null) ? $this->conPermiso($regla['permiso'], $sujeto) : collect(),
                Tipo::UsuarioEspecifico => User::query()->whereIn('id', (array) ($regla['usuario_ids'] ?? []))->get()->filter(fn (User $u) => $this->alcanza($u, $sujeto))->values(),
                Tipo::Sistemas => $this->sistemas(),
            };
        } catch (Throwable $e) {
            Log::warning('WorkflowRoutingService: no se pudo resolver un destinatario.', ['destinatario' => $tipo->value, 'error' => $e->getMessage()]);

            return collect();
        }
    }

    /**
     * Área de Sistemas: cuentas con el rol configurado (sistemas) o cuyo
     * colaborador ocupa un puesto de los departamentos configurados
     * (Sistemas). Nunca un usuario fijo: se resuelve por rol/departamento
     * (config/configuracion_sistema.php → sistemas).
     *
     * @return Collection<int, User>
     */
    private function sistemas(): Collection
    {
        $roles = (array) config('configuracion_sistema.sistemas.roles', ['sistemas']);
        $departamentos = array_map('mb_strtolower', (array) config('configuracion_sistema.sistemas.departamentos', ['Sistemas']));

        return User::query()
            ->where(fn ($q) => $q
                ->whereHas('roles', fn ($r) => $r->whereIn('name', $roles))
                ->orWhereHas('colaborador', fn ($c) => $c
                    ->whereHas('departamento', fn ($d) => $d->whereIn(DB::raw('LOWER(nombre)'), $departamentos))
                    ->orWhereHas('puesto.departamento', fn ($d) => $d->whereIn(DB::raw('LOWER(nombre)'), $departamentos))))
            ->get();
    }

    private function jefeDirecto(Colaborador|Candidato|null $sujeto): ?Colaborador
    {
        if ($sujeto instanceof Colaborador) {
            return $this->organizacion->supervisorDirectoDe($sujeto);
        }

        // Candidato: el gerente que lo preautoriza hace las veces de jefe.
        return $sujeto instanceof Candidato ? $this->organizacion->preautorizadorDeCandidato($sujeto)['colaborador'] : null;
    }

    /**
     * @return Collection<int, User>
     */
    private function deContexto(mixed $valor): Collection
    {
        if ($valor instanceof Colaborador) {
            $valor = $this->organizacion->usuarioActivoDe($valor);
        }

        return $valor instanceof User ? collect([$valor]) : collect();
    }

    /**
     * Cuentas con el permiso Y alcance sobre el sujeto (nunca "todos los que
     * tienen el permiso").
     *
     * @return Collection<int, User>
     */
    private function conPermiso(string $permiso, Colaborador|Candidato|null $sujeto): Collection
    {
        if (! Permission::query()->where('name', $permiso)->exists()) {
            Log::warning('WorkflowRoutingService: permiso inexistente en la regla.', ['permiso' => $permiso]);

            return collect();
        }

        return User::query()
            ->permission($permiso)
            ->whereNull('acceso_bloqueado_en')
            ->get()
            ->filter(fn (User $u) => $this->alcanza($u, $sujeto))
            ->values();
    }

    private function alcanza(User $usuario, Colaborador|Candidato|null $sujeto): bool
    {
        return match (true) {
            $sujeto instanceof Colaborador => $this->alcance->alcanzaColaborador($usuario, $sujeto),
            $sujeto instanceof Candidato => $this->organizacion->alcanzaCandidato($usuario, $sujeto),
            default => true,
        };
    }

    private function puedeRecibir(User $usuario): bool
    {
        if ($usuario->acceso_bloqueado_en !== null) {
            return false;
        }

        $persona = $usuario->colaborador;

        return $persona === null || $persona->estatus !== EstadoUsuario::Inactivo;
    }

    private function guardada(string $evento): ?ReglaNotificacion
    {
        try {
            return ReglaNotificacion::query()->where('evento', $evento)->first();
        } catch (Throwable) {
            return null;
        }
    }
}
