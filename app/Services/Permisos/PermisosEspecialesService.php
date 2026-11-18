<?php

namespace App\Services\Permisos;

use App\Enums\AccionAjustePermiso;
use App\Enums\TipoAjustePermiso;
use App\Models\AjustePermisoUsuario;
use App\Models\BitacoraPermisoUsuario;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Notificaciones\Aviso;
use App\Services\Notificaciones\NotificacionEventosService;
use App\Services\RolPermisoService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Permisos especiales POR PERSONA, sin tocar su rol: «un gerente cubre a
 * otro» → solo esa cuenta recibe (o pierde) ciertos permisos, con motivo,
 * quién, cuándo y vigencia opcional que vence sola. Retirar todos sus
 * ajustes la deja EXACTAMENTE con lo de su rol.
 *
 * Dónde se aplican: en un solo punto, App\Models\User (hasPermissionTo,
 * getAllPermissions y el scope permission()), así que valen igual en la web,
 * la API móvil (Sanctum), las Policies, el menú y los avisos «a quien tenga
 * el permiso X». Este servicio solo los administra.
 *
 * Reglas de seguridad (también en UserPolicy::gestionarPermisosEspeciales):
 *  - exige `usuarios.permisos_especiales` (por defecto lo tiene quien
 *    administra roles: config permisos_modulos → herencia);
 *  - nadie se ajusta a sí mismo; nunca a super_admin (ya tiene todo) ni a
 *    una cuenta de rol superior; solo personas de su alcance;
 *  - solo se CONCEDE lo que quien concede ya tiene (super_admin, todo):
 *    nadie reparte más de lo que puede hacer. Quitar, cualquier permiso.
 */
class PermisosEspecialesService
{
    public const PERMISO = 'usuarios.permisos_especiales';

    public function __construct(
        private readonly CatalogoPermisosService $catalogo,
        private readonly PermisoAccionService $acciones,
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly RolPermisoService $roles,
        private readonly NotificacionEventosService $avisos,
    ) {}

    public function puedeAdministrar(User $actor): bool
    {
        return $this->acciones->puede($actor, self::PERMISO);
    }

    /**
     * null si $actor puede ajustar los permisos de $objetivo; si no, el
     * motivo en español para mostrarlo tal cual.
     */
    public function motivoNoGestionable(User $actor, User $objetivo): ?string
    {
        return match (true) {
            ! $this->puedeAdministrar($actor) => 'No tienes permiso para dar permisos especiales.',
            $actor->is($objetivo) => 'Nadie se da ni se quita permisos especiales a sí mismo: pídelo a otro administrador.',
            $objetivo->hasRole('super_admin') => 'super_admin ya tiene todo el catálogo: no lleva permisos especiales.',
            ! $this->alcance->puedeVerUsuario($actor, $objetivo) => 'Esta persona está fuera de tu alcance.',
            $this->roles->esCuentaSuperior($actor, $objetivo) => 'Esa cuenta tiene un rol superior al tuyo: pide a un administrador de ese nivel que la ajuste.',
            default => null,
        };
    }

    /**
     * Lo que la persona tiene SIN ajustes: permisos directos + los de sus
     * roles activos.
     *
     * @return list<string>
     */
    public function permisosBase(User $usuario): array
    {
        return array_values(array_unique(array_map(
            fn ($nombre): string => (string) $nombre,
            $usuario->permissions->pluck('name')->merge($usuario->getPermissionsViaRoles()->pluck('name'))->all(),
        )));
    }

    /**
     * Todo lo que necesita la pantalla «Permisos especiales» de una persona.
     *
     * @return array<string, mixed>
     */
    public function vista(User $objetivo, User $actor): array
    {
        $objetivo->loadMissing(['roles', 'colaborador.puesto:id,nombre', 'colaborador.sucursalPrincipal:id,nombre']);
        $objetivo->unsetRelation('ajustesPermisosVigentes');
        $base = $this->permisosBase($objetivo);

        return [
            'usuario' => [
                'id' => $objetivo->id,
                'nombre' => $objetivo->nombreCompleto(),
                'username' => $objetivo->username,
                'puesto' => $objetivo->colaborador?->puesto?->nombre,
                'sucursal' => $objetivo->colaborador?->sucursalPrincipal?->nombre,
                'roles' => $objetivo->roles->map(fn (Model $rol): array => [
                    'nombre' => (string) $rol->getAttribute('name'),
                    'activo' => $rol->getAttribute('desactivado_en') === null,
                ])->values()->all(),
            ],
            'base' => $base,
            'efectivos' => array_values(array_map(fn ($p): string => (string) $p, $objetivo->getAllPermissions()->pluck('name')->all())),
            'ajustes' => $this->ajustesDe($objetivo),
            'bitacora' => $this->bitacora($objetivo),
            // null = puede conceder cualquiera (super_admin).
            'concedibles' => $actor->hasRole('super_admin')
                ? null
                : array_values(array_map(fn ($p): string => (string) $p, $actor->getAllPermissions()->pluck('name')->all())),
            'motivoNoGestionable' => $this->motivoNoGestionable($actor, $objetivo),
        ];
    }

    /**
     * @return list<array{permiso: string, tipo: string, motivo: string|null, vigente_hasta: string|null, vigente: bool, otorgado_por: string|null, fecha: string|null}>
     */
    public function ajustesDe(User $usuario): array
    {
        return array_values($usuario->ajustesPermisos()->with('otorgadoPor:id,name,apellidos,colaborador_id')->orderBy('permiso')->get()
            ->map(fn (AjustePermisoUsuario $a): array => [
                'permiso' => $a->permiso,
                'tipo' => $a->tipo->value,
                'motivo' => $a->motivo,
                'vigente_hasta' => $a->vigente_hasta?->toIso8601String(),
                'vigente' => $a->vigente(),
                'otorgado_por' => $a->otorgadoPor?->nombreCompleto(),
                'fecha' => $a->updated_at?->toIso8601String(),
            ])->all());
    }

    /**
     * @return list<array{id: int, permiso: string|null, accion: string, etiqueta: string, tipo: string|null, motivo: string|null, vigente_hasta: string|null, actor: string|null, fecha: string|null}>
     */
    public function bitacora(User $usuario, int $limite = 60): array
    {
        return array_values(BitacoraPermisoUsuario::query()
            ->with('actor:id,name,apellidos,colaborador_id')
            ->where('user_id', $usuario->id)
            ->orderByDesc('id')
            ->limit($limite)
            ->get()
            ->map(fn (BitacoraPermisoUsuario $b): array => [
                'id' => $b->id,
                'permiso' => $b->permiso,
                'accion' => $b->accion->value,
                'etiqueta' => $b->accion->etiqueta(),
                'tipo' => $b->tipo?->value,
                'motivo' => $b->motivo,
                'vigente_hasta' => $b->vigente_hasta?->toIso8601String(),
                'actor' => $b->actor?->nombreCompleto(),
                'fecha' => $b->created_at?->toIso8601String(),
            ])->all());
    }

    /**
     * Deja a la persona con EXACTAMENTE estos ajustes (los que no vienen se
     * retiran). Los redundantes se descartan: conceder lo que su rol ya da o
     * quitar lo que su rol no da no cambia nada.
     *
     * @param  array<string, TipoAjustePermiso>  $deseados  permiso => tipo
     * @return array{concedidos: int, quitados: int, retirados: int}
     *
     * @throws ValidationException
     */
    public function guardar(User $objetivo, array $deseados, ?string $motivo, ?CarbonInterface $hasta, User $actor): array
    {
        $base = array_flip($this->permisosBase($objetivo));
        $asignables = array_flip($this->catalogo->asignables());
        $concedibles = $actor->hasRole('super_admin') ? null : array_flip(array_map(fn ($p): string => (string) $p, $actor->getAllPermissions()->pluck('name')->all()));
        $errores = [];

        foreach ($deseados as $permiso => $tipo) {
            if (! isset($asignables[$permiso])) {
                $errores[] = sprintf('«%s» no es un permiso del catálogo.', $permiso);
            } elseif ($tipo === TipoAjustePermiso::Conceder && $concedibles !== null && ! isset($concedibles[$permiso])) {
                $errores[] = sprintf('No puedes conceder «%s»: tú no lo tienes.', $permiso);
            }

            // Redundantes: no cambian lo que la persona puede hacer.
            if (($tipo === TipoAjustePermiso::Conceder) === isset($base[$permiso])) {
                unset($deseados[$permiso]);
            }
        }

        if ($errores !== []) {
            throw ValidationException::withMessages(['ajustes' => $errores]);
        }

        /** @var Collection<string, AjustePermisoUsuario> $actuales */
        $actuales = $objetivo->ajustesPermisos()->get()->keyBy('permiso');
        $retirar = $actuales->reject(fn (AjustePermisoUsuario $a, string $permiso): bool => isset($deseados[$permiso]));
        $cambiar = array_filter(
            $deseados,
            fn (TipoAjustePermiso $tipo, string $permiso): bool => ! $actuales->has($permiso) || $actuales[$permiso]->tipo !== $tipo || ! $actuales[$permiso]->vigente(),
            ARRAY_FILTER_USE_BOTH,
        );

        if ($cambiar === [] && $retirar->isEmpty()) {
            return ['concedidos' => 0, 'quitados' => 0, 'retirados' => 0];
        }

        $motivo = $motivo !== null && trim($motivo) !== '' ? trim($motivo) : null;

        if ($cambiar !== [] && $motivo === null) {
            throw ValidationException::withMessages(['motivo' => 'Escribe el motivo (p. ej. «Cubre la gerencia de Cuautla del 1 al 15»): queda en la bitácora.']);
        }

        if ($hasta !== null && $hasta->isPast()) {
            throw ValidationException::withMessages(['hasta' => 'La vigencia debe ser una fecha futura.']);
        }

        DB::transaction(function () use ($objetivo, $retirar, $cambiar, $motivo, $hasta, $actor): void {
            foreach ($retirar as $ajuste) {
                $ajuste->delete();
                $this->anotar($objetivo, $ajuste->permiso, AccionAjustePermiso::Retirado, $ajuste->tipo, $motivo, null, $actor);
            }

            foreach ($cambiar as $permiso => $tipo) {
                AjustePermisoUsuario::query()->updateOrCreate(
                    ['user_id' => $objetivo->id, 'permiso' => $permiso],
                    ['tipo' => $tipo, 'motivo' => $motivo, 'vigente_hasta' => $hasta, 'otorgado_por' => $actor->id],
                );
                $accion = $tipo === TipoAjustePermiso::Conceder ? AccionAjustePermiso::Concedido : AccionAjustePermiso::Quitado;
                $this->anotar($objetivo, $permiso, $accion, $tipo, $motivo, $hasta, $actor);
            }
        });

        $objetivo->unsetRelation('ajustesPermisosVigentes');

        $resumen = [
            'concedidos' => count(array_filter($cambiar, fn (TipoAjustePermiso $t): bool => $t === TipoAjustePermiso::Conceder)),
            'quitados' => count(array_filter($cambiar, fn (TipoAjustePermiso $t): bool => $t === TipoAjustePermiso::Revocar)),
            'retirados' => $retirar->count(),
        ];

        $this->avisar($objetivo, $actor, $resumen, $hasta);

        return $resumen;
    }

    /**
     * «Quitar todos los ajustes especiales»: la persona queda exactamente con
     * lo de su rol. Devuelve cuántos se retiraron.
     */
    public function retirarTodos(User $objetivo, User $actor, ?string $motivo = null): int
    {
        $ajustes = $objetivo->ajustesPermisos()->get();

        if ($ajustes->isEmpty()) {
            return 0;
        }

        DB::transaction(function () use ($objetivo, $ajustes, $actor, $motivo): void {
            AjustePermisoUsuario::query()->whereIn('id', $ajustes->modelKeys())->delete();
            $this->anotar($objetivo, null, AccionAjustePermiso::RetiradosTodos, null, $motivo, null, $actor);
        });

        $objetivo->unsetRelation('ajustesPermisosVigentes');
        $this->avisar($objetivo, $actor, ['concedidos' => 0, 'quitados' => 0, 'retirados' => $ajustes->count()], null);

        return $ajustes->count();
    }

    /**
     * Retira los ajustes cuya vigencia ya pasó (ya no contaban: User los
     * ignora al vencer) y lo anota en la bitácora con aviso a la persona.
     * Lo corre `people:vencer-permisos-especiales` cada hora.
     */
    public function vencer(): int
    {
        $vencidos = AjustePermisoUsuario::query()->whereNotNull('vigente_hasta')->where('vigente_hasta', '<=', now())->get();

        foreach ($vencidos->groupBy('user_id') as $userId => $delUsuario) {
            DB::transaction(function () use ($delUsuario): void {
                foreach ($delUsuario as $ajuste) {
                    $ajuste->delete();
                    BitacoraPermisoUsuario::query()->create([
                        'user_id' => $ajuste->user_id,
                        'permiso' => $ajuste->permiso,
                        'accion' => AccionAjustePermiso::Vencido,
                        'tipo' => $ajuste->tipo,
                        'motivo' => $ajuste->motivo,
                        'vigente_hasta' => $ajuste->vigente_hasta,
                    ]);
                }
            });

            $usuario = User::query()->where('id', (int) $userId)->first();

            if ($usuario !== null) {
                $this->avisarVencimiento($usuario, $delUsuario->count());
            }
        }

        return $vencidos->count();
    }

    /**
     * Resumen por cuenta para el indicador «Permisos especiales» del listado
     * de usuarios.
     *
     * @param  list<int>  $ids
     * @return array<int, array{concedidos: int, quitados: int, vence: string|null}>
     */
    public function resumenPorUsuario(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $resumen = [];

        foreach (AjustePermisoUsuario::query()->vigentes()->whereIn('user_id', $ids)->get()->groupBy('user_id') as $userId => $ajustes) {
            $vence = $ajustes->pluck('vigente_hasta')->filter()->min();
            $resumen[(int) $userId] = [
                'concedidos' => $ajustes->where('tipo', TipoAjustePermiso::Conceder)->count(),
                'quitados' => $ajustes->where('tipo', TipoAjustePermiso::Revocar)->count(),
                'vence' => $vence instanceof CarbonInterface ? $vence->toIso8601String() : null,
            ];
        }

        return $resumen;
    }

    /**
     * Personas de su alcance con algún ajuste vigente (pantalla general y
     * acceso desde «Roles y permisos»).
     *
     * @return list<array{id: int, nombre: string, username: string, roles: list<string>, concedidos: int, quitados: int, vence: string|null}>
     */
    public function personasConAjustes(User $actor): array
    {
        $ids = array_values(array_map('intval', AjustePermisoUsuario::query()->vigentes()->distinct()->pluck('user_id')->all()));
        $resumen = $this->resumenPorUsuario($ids);

        return array_values($this->alcance->limitarUsuariosPorAlcance(User::query(), $actor)
            ->whereIn('id', $ids === [] ? [0] : $ids)
            ->with(['roles:id,name', 'colaborador:id,name,apellidos'])
            ->orderBy('name')
            ->get()
            ->map(fn (User $u): array => [
                'id' => $u->id,
                'nombre' => $u->nombreCompleto(),
                'username' => $u->username,
                'roles' => array_values(array_map(fn ($r): string => (string) $r, $u->roles->pluck('name')->all())),
                'concedidos' => $resumen[$u->id]['concedidos'] ?? 0,
                'quitados' => $resumen[$u->id]['quitados'] ?? 0,
                'vence' => $resumen[$u->id]['vence'] ?? null,
            ])->all());
    }

    private function anotar(User $objetivo, ?string $permiso, AccionAjustePermiso $accion, ?TipoAjustePermiso $tipo, ?string $motivo, ?CarbonInterface $hasta, User $actor): void
    {
        BitacoraPermisoUsuario::query()->create([
            'user_id' => $objetivo->id,
            'permiso' => $permiso,
            'accion' => $accion,
            'tipo' => $tipo,
            'motivo' => $motivo,
            'vigente_hasta' => $hasta,
            'actor_id' => $actor->id,
        ]);
    }

    /**
     * La persona se entera de que su acceso cambió (y quién lo hizo). Un
     * fallo al avisar nunca deshace el cambio.
     *
     * @param  array{concedidos: int, quitados: int, retirados: int}  $resumen
     */
    private function avisar(User $objetivo, User $actor, array $resumen, ?CarbonInterface $hasta): void
    {
        $partes = array_filter([
            $resumen['concedidos'] > 0 ? sprintf('%d permiso(s) concedido(s)', $resumen['concedidos']) : null,
            $resumen['quitados'] > 0 ? sprintf('%d permiso(s) quitado(s)', $resumen['quitados']) : null,
            $resumen['retirados'] > 0 ? sprintf('%d ajuste(s) retirado(s): vuelve a lo de su rol', $resumen['retirados']) : null,
        ]);

        $this->entregar($objetivo, sprintf(
            '%s ajustó tus permisos: %s%s. Si no lo esperabas, avisa a RH.',
            $actor->nombreCompleto(),
            implode(', ', $partes),
            $hasta !== null ? sprintf(' (vigente hasta el %s)', $hasta->format('d/m/Y H:i')) : '',
        ));
    }

    private function avisarVencimiento(User $objetivo, int $cuantos): void
    {
        $this->entregar($objetivo, sprintf('Venció la vigencia de %d permiso(s) especial(es): vuelves a tener exactamente lo de tu rol.', $cuantos));
    }

    private function entregar(User $objetivo, string $mensaje): void
    {
        try {
            $this->avisos->avisarResultado($objetivo, new Aviso(
                'cuenta_permisos_especiales',
                'cuenta',
                'Tus permisos cambiaron',
                $mensaje,
                $objetivo,
                'permisos_actualizados',
                ocurrencia: sprintf('permisos-%s', now()->format('YmdHisu')),
                directo: true,
            ));
        } catch (Throwable $e) {
            Log::warning('No se pudo avisar del cambio de permisos especiales.', ['usuario_id' => $objetivo->id, 'error' => $e->getMessage()]);
        }
    }
}
