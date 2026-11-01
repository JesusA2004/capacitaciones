<?php

namespace App\Services\Notificaciones;

use App\Enums\CategoriaNotificacion;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\NotificacionEventoRol;
use App\Models\NotificacionEventoRolHistorial;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Auditoria\AuditoriaService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Throwable;

/**
 * Checklist de avisos por rol (Roles y permisos → Notificaciones): qué acción le
 * llega a qué rol, en campana, tiempo real, app y navegador.
 *
 * El checklist se aplica encima del destinatario, nunca lo reemplaza:
 *  - marcar un rol jamás amplía su alcance ni le da permiso de actuar;
 *  - desmarcarlo quita el aviso, no la tarea de «Mis pendientes»;
 *  - lo propio, lo asignado por persona y lo que el rol debe aprobar no se quita.
 *
 * Un fallo de configuración nunca silencia un aviso: permite() devuelve true.
 */
class PoliticaNotificacionRolService
{
    private const CACHE = 'notificaciones.matriz_rol.v1';

    public const MENSAJE_PUSH = 'Abre MR. LANA PEOPLE para ver el detalle.';

    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly AuditoriaService $auditoria,
    ) {}

    /**
     * ¿Se le entrega este aviso? $colaboradorDelRecurso es la persona de la que trata.
     */
    public function permite(User $usuario, Aviso $aviso, CategoriaNotificacion $categoria, ?int $colaboradorDelRecurso = null): bool
    {
        if ($aviso->directo || in_array($categoria, [CategoriaNotificacion::Confirmacion, CategoriaNotificacion::Prueba], true)) {
            return true;
        }

        if ($usuario->colaborador_id !== null && $usuario->colaborador_id === $colaboradorDelRecurso) {
            return true;
        }

        return $this->permiteClave($usuario, $aviso->evento);
    }

    /**
     * Para envíos directos, sin Aviso (recordatorio de cumpleaños, celebraciones).
     */
    public function permiteClave(User $usuario, string $evento): bool
    {
        try {
            $clave = $this->resolverClave($evento);

            if ($clave === null) {
                Log::warning('PoliticaNotificacionRol: evento fuera del catálogo; se entrega.', ['evento' => $evento]);

                return true;
            }

            if ($this->evento($clave)['audiencia'] !== 'operativa') {
                return true;
            }

            $base = (array) config('notificaciones_catalogo.roles_base', []);
            $roles = $usuario->getRoleNames()->map(fn ($rol): string => (string) $rol);
            $operativos = $roles->reject(fn (string $rol): bool => in_array($rol, $base, true));
            $efectivos = $operativos->isNotEmpty() ? $operativos : $roles;

            // Sin roles: lo decidió su permiso directo; no hay casilla que lo quite.
            return $efectivos->isEmpty() || $efectivos->contains(fn (string $rol): bool => $this->marcado($rol, $clave));
        } catch (Throwable $e) {
            Log::warning('PoliticaNotificacionRol: no se pudo evaluar; se entrega.', ['evento' => $evento, 'error' => $e->getMessage()]);

            return true;
        }
    }

    /**
     * Título y cuerpo para la pantalla bloqueada: sin nombre completo, causal,
     * calificación, texto libre ni montos. El detalle se ve ya autenticado.
     *
     * @return array{0: string, 1: string}
     */
    public function textoPush(Aviso $aviso, CategoriaNotificacion $categoria): array
    {
        if ($categoria === CategoriaNotificacion::Prueba) {
            return [$aviso->titulo, $aviso->mensaje];
        }

        // La confirmación es texto fijo sobre lo que la persona acaba de hacer.
        $titulo = $aviso->pushTitulo ?? ($categoria === CategoriaNotificacion::Confirmacion ? $aviso->titulo : $this->tituloPush($aviso->evento));

        return [$titulo, self::MENSAJE_PUSH];
    }

    public function tituloPush(string $evento): string
    {
        $clave = $this->resolverClave($evento);

        if ($clave === null) {
            return 'Tienes un aviso nuevo';
        }

        $definicion = $this->evento($clave);

        return (string) ($definicion['push_titulo'] ?? $definicion['etiqueta']);
    }

    /**
     * Cuentas de roles agregados a mano a este aviso, con alcance sobre su sujeto.
     * Sin sujeto no se puede comprobar el alcance, así que no se amplía.
     *
     * @param  list<int>  $excluir
     * @return Collection<int, User>
     */
    public function usuariosAgregados(Aviso $aviso, ?int $colaboradorDelRecurso, array $excluir = []): Collection
    {
        $clave = $this->resolverClave($aviso->evento);

        if ($clave === null || $this->evento($clave)['audiencia'] !== 'operativa') {
            return collect();
        }

        $agregados = array_keys(array_filter(
            $this->excepciones()[$clave] ?? [],
            fn (bool $activo, string $rol): bool => $activo && ! $this->porDefecto($rol, $clave),
            ARRAY_FILTER_USE_BOTH,
        ));

        if ($agregados === []) {
            return collect();
        }

        $candidato = $this->candidatoDe($aviso->recurso);
        $colaborador = $candidato === null && $colaboradorDelRecurso !== null
            ? Colaborador::query()->where('id', $colaboradorDelRecurso)->first()
            : null;

        if ($candidato === null && $colaborador === null) {
            return collect();
        }

        return User::query()
            ->role($agregados)
            ->whereNull('acceso_bloqueado_en')
            ->whereNotIn('id', $excluir)
            ->get()
            ->reject(fn (User $usuario): bool => $colaborador !== null && $usuario->colaborador_id === $colaborador->id)
            ->filter(fn (User $usuario): bool => $candidato !== null
                ? Gate::forUser($usuario)->allows('view', $candidato)
                : $this->alcance->alcanzaColaborador($usuario, $colaborador))
            ->values();
    }

    public function resolverClave(string $evento): ?string
    {
        $eventos = $this->eventos();
        $evento = str_starts_with($evento, 'pendiente_') ? substr($evento, 10) : $evento;

        if (isset($eventos[$evento])) {
            return $evento;
        }

        foreach ($eventos as $clave => $definicion) {
            if (in_array($evento, (array) ($definicion['alias'] ?? []), true)) {
                return $clave;
            }
        }

        return null;
    }

    public function marcado(string $rol, string $clave): bool
    {
        return $this->esObligatorio($rol, $clave)
            || ($this->excepciones()[$clave][$rol] ?? $this->porDefecto($rol, $clave));
    }

    public function esObligatorio(string $rol, string $clave): bool
    {
        return in_array($rol, (array) ($this->evento($clave)['obligatorios'] ?? []), true);
    }

    /**
     * Valor de fábrica. Un rol que no es explícito (hecho a mano o de colaborador)
     * nace marcado: su permiso y alcance siguen decidiendo.
     */
    public function porDefecto(string $rol, string $clave): bool
    {
        $defecto = (array) ($this->evento($clave)['defecto'] ?? []);

        return in_array('*', $defecto, true)
            || in_array($rol, $defecto, true)
            || ! in_array($rol, (array) config('notificaciones_catalogo.roles_explicitos', []), true);
    }

    /**
     * Módulos y casillas para la pantalla, con el estado de cada rol.
     *
     * @param  Collection<int, Role>  $roles
     * @return array{modulos: list<array{clave: string, etiqueta: string, eventos: list<array{clave: string, etiqueta: string, audiencia: string}>}>, marcados: array<int, list<string>>, obligatorios: array<int, list<string>>, por_defecto: array<int, list<string>>, mensaje_obligatoria: string}
     */
    public function matrizParaPantalla(Collection $roles): array
    {
        $eventos = $this->eventos();
        $modulos = [];

        foreach ((array) config('notificaciones_catalogo.modulos', []) as $modulo => $etiqueta) {
            $propios = [];

            foreach ($eventos as $clave => $definicion) {
                if ($definicion['modulo'] === $modulo) {
                    $propios[] = ['clave' => $clave, 'etiqueta' => (string) $definicion['etiqueta'], 'audiencia' => (string) $definicion['audiencia']];
                }
            }

            if ($propios !== []) {
                $modulos[] = ['clave' => (string) $modulo, 'etiqueta' => (string) $etiqueta, 'eventos' => $propios];
            }
        }

        $marcados = [];
        $obligatorios = [];
        $porDefecto = [];

        foreach ($roles as $rol) {
            $operativas = array_map('strval', array_keys(array_filter($eventos, fn (array $d): bool => $d['audiencia'] === 'operativa')));
            $id = (int) $rol->id;
            $marcados[$id] = array_values(array_filter($operativas, fn (string $c): bool => $this->marcado((string) $rol->name, $c)));
            $obligatorios[$id] = array_values(array_filter($operativas, fn (string $c): bool => $this->esObligatorio((string) $rol->name, $c)));
            $porDefecto[$id] = array_values(array_filter($operativas, fn (string $c): bool => $this->porDefecto((string) $rol->name, $c) || $this->esObligatorio((string) $rol->name, $c)));
        }

        return [
            'modulos' => $modulos,
            'marcados' => $marcados,
            'obligatorios' => $obligatorios,
            'por_defecto' => $porDefecto,
            'mensaje_obligatoria' => 'Este rol debe aprobar esta acción: el aviso no se puede quitar.',
        ];
    }

    /**
     * Guarda las casillas marcadas del rol. Sólo los avisos operativos se editan.
     *
     * @param  list<string>  $marcados
     *
     * @throws ValidationException
     */
    public function guardar(Role $rol, array $marcados, User $actor): void
    {
        $nombre = (string) $rol->name;
        $operativas = array_keys(array_filter($this->eventos(), fn (array $d): bool => $d['audiencia'] === 'operativa'));
        $invalidas = array_values(array_diff($marcados, $operativas));

        if ($invalidas !== []) {
            throw ValidationException::withMessages(['eventos' => sprintf('Estos avisos no se pueden marcar por rol: %s.', implode(', ', $invalidas))]);
        }

        $faltantes = array_values(array_filter($operativas, fn (string $c): bool => $this->esObligatorio($nombre, $c) && ! in_array($c, $marcados, true)));

        if ($faltantes !== []) {
            throw ValidationException::withMessages(['eventos' => sprintf('Este rol debe aprobar estas acciones y su aviso no se puede quitar: %s.', implode(', ', array_map(fn (string $c): string => (string) $this->evento($c)['etiqueta'], $faltantes)))]);
        }

        DB::transaction(function () use ($rol, $nombre, $operativas, $marcados, $actor): void {
            $cambios = [];

            foreach ($operativas as $clave) {
                $antes = $this->marcado($nombre, $clave);
                $despues = in_array($clave, $marcados, true);
                $this->escribir($rol, $clave, $despues, $actor);

                if ($antes !== $despues) {
                    $cambios[] = ['evento' => $clave, 'antes' => $antes, 'despues' => $despues];
                }
            }

            $this->registrarCambios($rol, $cambios, $actor, 'rol_notificaciones_actualizadas');
            DB::afterCommit(fn () => Cache::forget(self::CACHE));
        });
    }

    /**
     * Vuelve el rol a los valores de fábrica.
     */
    public function restablecer(Role $rol, User $actor): void
    {
        $nombre = (string) $rol->name;

        DB::transaction(function () use ($rol, $nombre, $actor): void {
            $cambios = [];

            foreach (NotificacionEventoRol::query()->where('role_id', $rol->id)->get() as $excepcion) {
                if (isset($this->eventos()[$excepcion->evento]) && $excepcion->activo !== ($this->porDefecto($nombre, $excepcion->evento) || $this->esObligatorio($nombre, $excepcion->evento))) {
                    $cambios[] = ['evento' => $excepcion->evento, 'antes' => $excepcion->activo, 'despues' => ! $excepcion->activo];
                }
            }

            NotificacionEventoRol::query()->where('role_id', $rol->id)->delete();
            $this->registrarCambios($rol, $cambios, $actor, 'rol_notificaciones_restablecidas');
            DB::afterCommit(fn () => Cache::forget(self::CACHE));
        });
    }

    /**
     * @return list<array{id: int, evento: string, etiqueta: string, antes: bool, despues: bool, actor: string|null, fecha: string|null}>
     */
    public function historial(Role $rol, int $limite = 50): array
    {
        return array_values(NotificacionEventoRolHistorial::query()
            ->with('actor:id,name,apellidos')
            ->where('role_id', $rol->id)
            ->latest('id')
            ->limit($limite)
            ->get()
            ->map(fn (NotificacionEventoRolHistorial $fila): array => [
                'id' => $fila->id,
                'evento' => $fila->evento,
                'etiqueta' => (string) ($this->eventos()[$fila->evento]['etiqueta'] ?? $fila->evento),
                'antes' => $fila->antes,
                'despues' => $fila->despues,
                'actor' => $fila->actor?->nombreCompleto(),
                'fecha' => $fila->created_at?->toIso8601String(),
            ])
            ->all());
    }

    private function escribir(Role $rol, string $clave, bool $activo, User $actor): void
    {
        if ($activo === ($this->porDefecto((string) $rol->name, $clave) || $this->esObligatorio((string) $rol->name, $clave))) {
            NotificacionEventoRol::query()->where('role_id', $rol->id)->where('evento', $clave)->delete();

            return;
        }

        NotificacionEventoRol::query()->updateOrCreate(
            ['role_id' => $rol->id, 'evento' => $clave],
            ['activo' => $activo, 'actualizado_por' => $actor->id],
        );
    }

    /**
     * @param  list<array{evento: string, antes: bool, despues: bool}>  $cambios
     */
    private function registrarCambios(Role $rol, array $cambios, User $actor, string $accion): void
    {
        foreach ($cambios as $cambio) {
            NotificacionEventoRolHistorial::query()->create([...$cambio, 'role_id' => $rol->id, 'actor_id' => $actor->id]);
        }

        $this->auditoria->registrar($accion, null, $actor, ['rol' => (string) $rol->name, 'cambios' => $cambios]);
    }

    private function candidatoDe(?Model $recurso): ?Candidato
    {
        if ($recurso instanceof Candidato) {
            return $recurso;
        }

        $candidato = $recurso?->getAttribute('candidato_id');

        return is_int($candidato) ? Candidato::query()->where('id', $candidato)->first() : null;
    }

    /**
     * @return array<string, array<string, bool>> evento → rol → activo
     */
    private function excepciones(): array
    {
        try {
            /** @var array<string, array<string, bool>> */
            return Cache::rememberForever(self::CACHE, function (): array {
                $matriz = [];
                $roles = Role::query()->pluck('name', 'id');

                foreach (NotificacionEventoRol::query()->get() as $fila) {
                    $rol = $roles[$fila->role_id] ?? null;

                    if (is_string($rol)) {
                        $matriz[$fila->evento][$rol] = $fila->activo;
                    }
                }

                return $matriz;
            });
        } catch (Throwable $e) {
            Log::warning('PoliticaNotificacionRol: no se pudieron leer las excepciones.', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function eventos(): array
    {
        /** @var array<string, array<string, mixed>> */
        return (array) config('notificaciones_catalogo.eventos', []);
    }

    /**
     * @return array<string, mixed>
     */
    private function evento(string $clave): array
    {
        return $this->eventos()[$clave];
    }
}
