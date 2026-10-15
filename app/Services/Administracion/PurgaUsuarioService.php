<?php

namespace App\Services\Administracion;

use App\Models\User;
use App\Support\ReferenciasUsuario;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * «Eliminar definitivamente» una cuenta (solo super_admin). No es
 * desactivar acceso ni baja laboral: borra la fila de `users` y lo que es
 * técnicamente suyo (sesiones, tokens, dispositivos, roles, lecturas de
 * avisos). Las referencias de autoría se desvinculan como lo declara cada
 * llave foránea (ReferenciasUsuario). Si la cuenta tiene historial de
 * negocio (solicitudes, asistencias, mensajes…) o una referencia
 * restrictiva con filas, NO se purga: se explica qué la detiene.
 *
 * El expediente del colaborador (tabla colaboradores) nunca se toca.
 * Por decisión del negocio no se registra en la bitácora quién purgó a
 * quién; los respaldos y logs del servidor quedan fuera del alcance de la
 * aplicación.
 */
class PurgaUsuarioService
{
    /** Tablas que solo guardan datos técnicos de la cuenta: se borran con ella. */
    public const TECNICAS = ['mobile_devices', 'notificacion_entregas', 'notification_reads', 'sucursal_user', 'aviso_lecturas'];

    /** Nombres legibles de las tablas que más suelen aparecer. */
    private const ETIQUETAS = [
        'solicitudes_internas' => 'solicitudes',
        'solicitudes_vacaciones' => 'solicitudes de vacaciones',
        'birthday_wall_messages' => 'mensajes del muro de cumpleaños',
        'asistencias' => 'asistencias',
        'certificados' => 'certificados',
        'inscripciones_curso' => 'inscripciones a cursos',
        'intentos_cuestionario' => 'intentos de cuestionario',
        'progreso_lecciones' => 'avance de lecciones',
        'official_format_generations' => 'formatos oficiales generados',
        'importaciones_excel' => 'importaciones de Excel',
        'document_extractions' => 'lecturas automáticas de documentos',
        'sesiones_en_vivo' => 'sesiones en vivo',
        'asignaciones' => 'asignaciones de capacitación',
        'asignaciones_usuario' => 'asignaciones de capacitación',
        'bancos_preguntas' => 'bancos de preguntas',
        'cargas_multimedia' => 'cargas multimedia',
        'recursos_multimedia' => 'recursos multimedia',
        'entregas_actividad' => 'entregas de actividades',
        'intervalos_video_vistos' => 'avance de videos',
        'sesiones_reproduccion' => 'reproducciones de video',
        'solicitudes_excepcion_periodo_prueba' => 'solicitudes de excepción de periodo de prueba',
        'finiquito_calculos' => 'cálculos de finiquito',
        'incorporacion_invitaciones' => 'invitaciones de incorporación',
        'intervenciones_candidato' => 'intervenciones en candidatos',
        'mobile_devices' => 'dispositivos móviles',
        'notificacion_entregas' => 'entregas de notificaciones',
        'notification_reads' => 'lecturas de notificaciones',
        'sucursal_user' => 'sucursales asignadas',
        'aviso_lecturas' => 'lecturas de avisos',
        'personal_access_tokens' => 'sesiones de la app',
        'notifications' => 'notificaciones',
        'sessions' => 'sesiones web',
        'model_has_roles' => 'roles',
        'model_has_permissions' => 'permisos directos',
    ];

    /**
     * Qué pasaría al purgar: qué se elimina, qué se desvincula y qué lo
     * impide. No modifica nada.
     *
     * @return array{usuario: string, confirmacion: string, puede: bool, bloqueos: list<string>, eliminar: list<array{que: string, filas: int}>, desvincular: list<array{que: string, filas: int}>}
     */
    public function revisar(User $usuario, User $actor): array
    {
        $this->autorizar($usuario, $actor);

        $bloqueos = [];
        $eliminar = [];
        $desvincular = [];

        foreach ($this->conteos($usuario) as [$tabla, $columna, $accion, $filas]) {

            if ($accion === 'set_null') {
                $desvincular[] = ['que' => sprintf('%s (%s)', $this->etiqueta($tabla), Str::of($columna)->replace('_', ' ')), 'filas' => $filas];
            } elseif ($accion === 'cascade' && in_array($tabla, self::TECNICAS, true)) {
                $eliminar[] = ['que' => $this->etiqueta($tabla), 'filas' => $filas];
            } else {
                $bloqueos[] = sprintf('%s: %d %s a su nombre.', Str::ucfirst($this->etiqueta($tabla)), $filas, $filas === 1 ? 'registro' : 'registros');
            }
        }

        foreach ($this->tecnicasPolimorficas($usuario) as $tabla => $filas) {
            if ($filas > 0) {
                $eliminar[] = ['que' => $this->etiqueta($tabla), 'filas' => $filas];
            }
        }

        return [
            'usuario' => (string) $usuario->username,
            'confirmacion' => (string) $usuario->username,
            'puede' => $bloqueos === [],
            'bloqueos' => $bloqueos,
            'eliminar' => $eliminar,
            'desvincular' => $desvincular,
        ];
    }

    /**
     * Purga transaccional. Exige escribir «ELIMINAR» o el usuario exacto.
     *
     * @throws ValidationException|AuthorizationException
     */
    public function purgar(User $usuario, User $actor, string $confirmacion): void
    {
        $revision = $this->revisar($usuario, $actor);
        $confirmacion = trim($confirmacion);

        if ($confirmacion !== 'ELIMINAR' && $confirmacion !== $revision['confirmacion']) {
            throw ValidationException::withMessages(['confirmacion' => sprintf('Escribe ELIMINAR o el usuario «%s» exactamente.', $revision['confirmacion'])]);
        }

        if (! $revision['puede']) {
            throw ValidationException::withMessages(['confirmacion' => 'No se puede eliminar definitivamente: '.implode(' ', $revision['bloqueos']).' Usa «Desactivar acceso» para conservar su historial.']);
        }

        DB::transaction(function () use ($usuario): void {
            foreach ($this->conteos($usuario) as [$tabla, $columna, $accion]) {
                match (true) {
                    $accion === 'set_null' => DB::table($tabla)->where($columna, $usuario->id)->update([$columna => null]),
                    $accion === 'cascade' && in_array($tabla, self::TECNICAS, true) => DB::table($tabla)->where($columna, $usuario->id)->delete(),
                    default => null,
                };
            }

            $tipo = $usuario->getMorphClass();
            DB::table('personal_access_tokens')->where('tokenable_type', $tipo)->where('tokenable_id', $usuario->id)->delete();
            DB::table('notifications')->where('notifiable_type', $tipo)->where('notifiable_id', $usuario->id)->delete();
            DB::table('model_has_roles')->where('model_type', $tipo)->where('model_id', $usuario->id)->delete();
            DB::table('model_has_permissions')->where('model_type', $tipo)->where('model_id', $usuario->id)->delete();

            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $usuario->id)->delete();
            }

            if ($usuario->email !== null && Schema::hasTable('password_reset_tokens')) {
                DB::table('password_reset_tokens')->where('email', $usuario->email)->delete();
            }

            // Borrado directo (sin eventos de modelo): no deja bitácora de la purga.
            DB::table('users')->where('id', $usuario->id)->delete();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @throws AuthorizationException */
    private function autorizar(User $usuario, User $actor): void
    {
        if (! $actor->hasRole('super_admin')) {
            throw new AuthorizationException('Solo super_admin puede eliminar definitivamente una cuenta.');
        }

        if ($usuario->id === $actor->id) {
            throw new AuthorizationException('No puedes eliminar definitivamente tu propia cuenta.');
        }

        if ($usuario->hasRole('super_admin') && User::role('super_admin')->count() <= 1) {
            throw new AuthorizationException('Es la única cuenta super_admin: no se puede eliminar.');
        }
    }

    /**
     * Las referencias declaradas cuyas tablas existen en esta base (una
     * sola lectura del catálogo).
     *
     * @return list<array{0: string, 1: string, 2: 'cascade'|'set_null'|'restrict'}>
     */
    private function referencias(): array
    {
        $tablas = array_flip(Schema::getTableListing(schemaQualified: false));

        return array_values(array_filter(ReferenciasUsuario::COLUMNAS, fn (array $r): bool => isset($tablas[$r[0]])));
    }

    /**
     * Filas que referencian a la cuenta, por tabla y columna, en una sola
     * consulta (UNION ALL). Solo devuelve las que tienen filas.
     *
     * @return list<array{0: string, 1: string, 2: 'cascade'|'set_null'|'restrict', 3: int}>
     */
    private function conteos(User $usuario): array
    {
        $referencias = $this->referencias();
        $resultado = [];

        foreach (array_chunk($referencias, 60) as $bloque) {
            $partes = array_map(
                fn (array $r, int $i) => DB::table($r[0])->selectRaw('? as i, count(*) as n', [$i])->where($r[1], $usuario->id),
                $bloque,
                array_keys($bloque),
            );
            $consulta = array_shift($partes);

            foreach ($partes as $parte) {
                $consulta->unionAll($parte);
            }

            foreach ($consulta->get() as $fila) {
                $n = (int) $fila->n;

                if ($n > 0) {
                    [$tabla, $columna, $accion] = $bloque[(int) $fila->i];
                    $resultado[] = [$tabla, $columna, $accion, $n];
                }
            }
        }

        return $resultado;
    }

    /** @return array<string, int> */
    private function tecnicasPolimorficas(User $usuario): array
    {
        $tipo = $usuario->getMorphClass();

        return [
            'personal_access_tokens' => DB::table('personal_access_tokens')->where('tokenable_type', $tipo)->where('tokenable_id', $usuario->id)->count(),
            'notifications' => DB::table('notifications')->where('notifiable_type', $tipo)->where('notifiable_id', $usuario->id)->count(),
            'model_has_roles' => DB::table('model_has_roles')->where('model_type', $tipo)->where('model_id', $usuario->id)->count(),
            'model_has_permissions' => DB::table('model_has_permissions')->where('model_type', $tipo)->where('model_id', $usuario->id)->count(),
            'sessions' => Schema::hasTable('sessions') ? DB::table('sessions')->where('user_id', $usuario->id)->count() : 0,
        ];
    }

    private function etiqueta(string $tabla): string
    {
        return self::ETIQUETAS[$tabla] ?? Str::of($tabla)->replace('_', ' ')->toString();
    }
}
