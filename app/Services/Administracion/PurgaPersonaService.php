<?php

namespace App\Services\Administracion;

use App\Models\Colaborador;
use App\Models\User;
use App\Support\ReferenciasColaborador;
use App\Support\ReferenciasUsuario;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * PURGA INTEGRAL de una persona (solo super_admin): para registros erróneos
 * o de prueba. No es «Revocar acceso» (que conserva todo) ni la baja laboral.
 * Borra FÍSICAMENTE la cuenta (`users`) Y el expediente (`colaboradores`) Y
 * todo lo que es suyo —solicitudes, vacaciones, asistencias, onboarding,
 * evaluaciones, contratos, cierres, finiquitos, responsivas, actas,
 * préstamos, recibos, documentos lógicos, notificaciones, tokens, roles…—
 * respetando el orden de las llaves foráneas (hijos antes que padres).
 *
 * Qué NO toca:
 *  - Los ARCHIVOS del NAS (disco `nas`): PDF de responsivas, expediente,
 *    documentos generados. Solo se borran las FILAS que los referencian; los
 *    blobs quedan como respaldo histórico huérfano. Este servicio nunca llama
 *    a Storage::delete.
 *  - Catálogos compartidos (sucursales, puestos, departamentos, plantillas,
 *    formatos maestros) ni registros de TERCEROS: la autoría de la persona
 *    sobre ellos («registrado por», «jefe anterior», «evaluador»…) se pone en
 *    NULL; el registro ajeno se conserva (ReferenciasColaborador /
 *    ReferenciasUsuario).
 *
 * Como `Colaborador` usa SoftDeletes, se borra con el query builder
 * (`DB::table(...)->delete()`), nunca con `Model::delete()`, que solo marcaría
 * `deleted_at`. El borrado directo tampoco dispara eventos de modelo: no deja
 * bitácora de la purga ni intenta borrar archivos del NAS.
 */
class PurgaPersonaService
{
    /** La persona SOLO como actor restrictivo sobre registros de terceros (FK RESTRICT, no admite NULL): detiene la purga. */
    private const BLOQUEO_RESTRICT = [
        ['acuses_descriptivo_puesto', 'enviado_por', 'colaborador_id', 'acuses de descriptivo enviados a otras personas'],
        ['responsivas', 'entregado_por', 'colaborador_id', 'responsivas entregadas a otras personas'],
        ['finiquito_calculos', 'calculado_por_id', 'colaborador_id', 'finiquitos calculados de otras personas'],
        ['incorporacion_invitaciones', 'creado_por_id', 'colaborador_id', 'invitaciones de incorporación creadas para otras personas'],
        ['sesiones_induccion', 'presentada_por', null, 'sesiones de inducción presentadas'],
        ['induccion_materiales_explicacion', 'subido_por', null, 'materiales de explicación de la inducción subidos'],
        ['rotacion_cargas', 'aplicado_por', null, 'cargas de rotación aplicadas'],
        ['rotacion_cargas', 'subido_por', null, 'cargas de rotación subidas'],
        ['intervenciones_candidato', 'gerente_solicitante_id', null, 'intervenciones de candidatos solicitadas'],
        // Expediente de baja de OTRAS personas donde la cuenta actuó (FK
        // RESTRICT: la auditoría de la baja ajena no se altera).
        ['bajas', 'creado_por', 'colaborador_id', 'bajas de otras personas capturadas'],
        ['bajas', 'solicitado_por', 'colaborador_id', 'bajas de otras personas solicitadas'],
        ['bajas', 'validado_por', 'colaborador_id', 'bajas de otras personas validadas'],
        ['bajas', 'juridico_por', 'colaborador_id', 'bajas de otras personas revisadas por Jurídico'],
        ['bajas', 'rechazado_por', 'colaborador_id', 'bajas de otras personas rechazadas'],
        ['bajas', 'cerrado_por', 'colaborador_id', 'bajas de otras personas cerradas'],
        ['bajas', 'cancelado_por', 'colaborador_id', 'bajas de otras personas canceladas'],
    ];

    /**
     * Hijos del expediente de baja (FK RESTRICT a users, sin columna de
     * persona propia): solo detienen la purga cuando pertenecen a la baja de
     * OTRA persona; los de la baja propia se eliminan con ella.
     */
    private const BLOQUEO_RESTRICT_BAJA = [
        ['baja_documentos', 'cargado_por', 'documentos cargados en bajas de otras personas'],
        ['baja_documentos', 'quitado_por', 'documentos quitados en bajas de otras personas'],
        ['baja_bitacora', 'user_id', 'movimientos en la bitácora de bajas de otras personas'],
    ];

    /** Contenido COMPARTIDO que la persona creó y consumen terceros (FK cascade, pero borrarlo destruiría datos ajenos): detiene la purga. */
    private const BLOQUEO_COMPARTIDO = [
        ['bancos_preguntas', 'creado_por', null, 'bancos de preguntas'],
        ['recursos_multimedia', 'subido_por', null, 'recursos multimedia'],
        ['asignaciones', 'responsable_id', null, 'asignaciones de capacitación'],
        ['sesiones_en_vivo', 'creado_por', null, 'sesiones en vivo'],
        ['importaciones_excel', 'subido_por', null, 'importaciones de Excel'],
        ['official_format_generations', 'generated_by_id', 'colaborador_id', 'formatos oficiales generados para otras personas'],
    ];

    /** Nombres legibles para el preview. */
    private const ETIQUETAS = [
        'actas_administrativas' => 'actas administrativas',
        'acuses_descriptivo_puesto' => 'acuses de descriptivo de puesto',
        'ajustes_historicos_vacaciones' => 'ajustes históricos de vacaciones',
        'altas_digitales' => 'altas digitales',
        'asistencias' => 'asistencias',
        'avisos_datos_faltantes' => 'avisos de datos faltantes',
        'birthday_greetings' => 'felicitaciones de cumpleaños',
        'cambios_foto_perfil' => 'cambios de foto de perfil',
        'certificados' => 'certificados',
        'bajas' => 'expedientes de baja',
        'cierres_laborales' => 'cierres laborales',
        'clasificaciones_region' => 'clasificación de región en el organigrama',
        'lotes_generacion_contratos' => 'generaciones masivas de contratos',
        'revisiones_division_historico' => 'revisiones de la división de históricos',
        'coberturas_puesto' => 'coberturas de puesto',
        'colaborador_datos_medicos' => 'datos médicos',
        'cartas_compromiso' => 'cartas compromiso',
        'contratos_laborales' => 'contratos laborales',
        'divisiones_expediente_historico' => 'divisiones de expediente histórico',
        'document_extractions' => 'lecturas automáticas de documentos',
        'employee_documents' => 'documentos del expediente',
        'entregas_activo' => 'entregas de activos',
        'evaluaciones_induccion' => 'evaluaciones de inducción',
        'evaluaciones_periodo_prueba' => 'evaluaciones de periodo de prueba',
        'expedientes_historicos' => 'expedientes históricos',
        'finiquito_calculos' => 'cálculos de finiquito',
        'generated_documents' => 'documentos generados',
        'historial_salarios' => 'historial de salarios',
        'incorporacion_invitaciones' => 'invitaciones de incorporación',
        'induccion_asignaciones' => 'asignaciones de inducción',
        'induccion_video_intervalos' => 'avance del video de inducción',
        'inscripciones_curso' => 'inscripciones a cursos',
        'intentos_cuestionario' => 'intentos de cuestionario',
        'movimientos_laborales' => 'movimientos laborales',
        'official_format_generations' => 'formatos oficiales generados',
        'onboarding_procesos' => 'procesos de onboarding',
        'prestamos' => 'préstamos',
        'progreso_lecciones' => 'avance de lecciones',
        'recibos_nomina' => 'recibos de nómina',
        'reingresos' => 'reingresos',
        'responsivas' => 'responsivas',
        'sesion_induccion_colaboradores' => 'sesiones de inducción asignadas',
        'solicitud_vacaciones_dias' => 'días de vacaciones solicitados',
        'solicitudes_internas' => 'solicitudes',
        'solicitudes_vacaciones' => 'solicitudes de vacaciones',
        'sucursal_colaborador' => 'sucursales adicionales asignadas',
        'user_nodo_comercial' => 'asignaciones a la matriz comercial',
        'mobile_devices' => 'dispositivos móviles',
        'notificacion_entregas' => 'entregas de notificaciones',
        'notification_reads' => 'lecturas de notificaciones',
        'sucursal_user' => 'sucursales de acceso',
        'aviso_lecturas' => 'lecturas de avisos',
        'personal_access_tokens' => 'sesiones de la app',
        'notifications' => 'notificaciones',
        'sessions' => 'sesiones web',
        'model_has_roles' => 'roles',
        'model_has_permissions' => 'permisos directos',
    ];

    public const FRASE_CONFIRMACION = 'ELIMINAR DEFINITIVAMENTE';

    /**
     * Qué eliminaría la purga integral, qué desvincularía, qué archivos del
     * NAS se conservan y qué la impide. No modifica nada.
     *
     * @return array{persona: string, numero_empleado: string|null, frase: string, puede: bool, bloqueos: list<string>, eliminar: list<array{que: string, filas: int}>, desvincular: list<array{que: string, filas: int}>, nas: array{archivos: int, nota: string}}
     *
     * @throws AuthorizationException
     */
    public function revisar(User $cuenta, User $actor): array
    {
        $this->autorizar($cuenta, $actor);

        $colaborador = $this->colaboradorDe($cuenta);

        $eliminar = $this->conteosEliminar($cuenta, $colaborador);
        $desvincular = $this->conteosDesvincular($cuenta, $colaborador);
        $bloqueos = $this->bloqueos($cuenta, $colaborador);

        return [
            'persona' => $colaborador !== null ? $colaborador->nombreCompleto() : (string) $cuenta->username,
            'numero_empleado' => $colaborador?->numero_empleado,
            'frase' => self::FRASE_CONFIRMACION,
            'puede' => $bloqueos === [],
            'bloqueos' => $bloqueos,
            'eliminar' => $eliminar,
            'desvincular' => $desvincular,
            'nas' => [
                'archivos' => $this->archivosEnNas($colaborador),
                'nota' => 'Los archivos del expediente (PDF, escaneos, documentos generados) permanecen en el NAS como respaldo histórico; PEOPLE solo deja de referenciarlos.',
            ],
        ];
    }

    /**
     * Purga integral transaccional y física. La confirmación escrita
     * (frase + identidad de la persona) se valida en el Form Request del
     * endpoint; aquí se revalida la autorización y los bloqueos.
     *
     * @throws AuthorizationException|ValidationException
     */
    public function purgar(User $cuenta, User $actor): void
    {
        $this->autorizar($cuenta, $actor);

        $colaborador = $this->colaboradorDe($cuenta);
        $bloqueos = $this->bloqueos($cuenta, $colaborador);

        if ($bloqueos !== []) {
            throw ValidationException::withMessages([
                'frase' => 'No se puede purgar: '.implode(' ', $bloqueos).' Son datos de terceros que deben conservarse.',
            ]);
        }

        DB::transaction(function () use ($cuenta, $colaborador): void {
            // 1) Desvincular la autoría de la persona sobre registros de
            //    terceros (se conservan con el actor en NULL).
            $this->desvincularAutoria($cuenta, $colaborador);

            // 2) Borrar el expediente de la persona: primero las ramas con FK
            //    RESTRICT o SET NULL (que no caen por cascada) en orden
            //    hijos→padres; el resto de ramas en cascada caen al borrar la
            //    fila de `colaboradores` (paso 5).
            if ($colaborador !== null) {
                $this->eliminarRamasColaborador($colaborador->id);
            }

            // 3) Borrar la actividad propia de la cuenta (solicitudes,
            //    vacaciones, asistencias, inscripciones…) y lo
            //    técnico/polimórfico (tokens, roles, notificaciones, sesiones).
            $this->eliminarActividadCuenta($cuenta);
            $this->eliminarTecnicoCuenta($cuenta);

            // 4) Borrar la fila de la cuenta.
            DB::table('users')->where('id', $cuenta->id)->delete();

            // 5) Borrar FÍSICAMENTE la persona (bypassa SoftDeletes): arrastra
            //    en cascada el resto de su expediente.
            if ($colaborador !== null) {
                DB::table('colaboradores')->where('id', $colaborador->id)->delete();
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @throws AuthorizationException */
    private function autorizar(User $cuenta, User $actor): void
    {
        if (! $actor->hasRole('super_admin')) {
            throw new AuthorizationException('Solo super_admin puede purgar definitivamente a una persona.');
        }

        if ($cuenta->id === $actor->id) {
            throw new AuthorizationException('No puedes purgar tu propia cuenta.');
        }

        // Debe quedar al menos OTRO super_admin vigente (sin contar a la
        // cuenta purgada, que puede estar en papelera y no figurar en el
        // conteo por rol).
        if ($cuenta->hasRole('super_admin') && User::role('super_admin')->where('id', '<>', $cuenta->id)->count() === 0) {
            throw new AuthorizationException('Es el único super_admin del sistema: no se puede purgar.');
        }
    }

    private function colaboradorDe(User $cuenta): ?Colaborador
    {
        if ($cuenta->colaborador_id === null) {
            return null;
        }

        return Colaborador::withTrashed()->where('id', $cuenta->colaborador_id)->first();
    }

    /**
     * Mensajes de lo que impide la purga: autoría RESTRICT sobre terceros o
     * contenido compartido creado por la persona (ver constantes).
     *
     * @return list<string>
     */
    private function bloqueos(User $cuenta, ?Colaborador $colaborador): array
    {
        $mensajes = [];

        foreach ([...self::BLOQUEO_RESTRICT, ...self::BLOQUEO_COMPARTIDO] as [$tabla, $columna, $subject, $etiqueta]) {
            if (! Schema::hasTable($tabla)) {
                continue;
            }

            $consulta = DB::table($tabla)->where($columna, $cuenta->id);

            // Las filas que SON de la persona ya se eliminan: solo cuentan las
            // de terceros (misma tabla, otra persona como sujeto).
            if ($subject !== null && $colaborador !== null) {
                $consulta->where(function (Builder $q) use ($subject, $colaborador): void {
                    $q->whereNull($subject)->orWhere($subject, '<>', $colaborador->id);
                });
            }

            $filas = $consulta->count();

            if ($filas > 0) {
                $mensajes[] = sprintf('%d %s a su nombre.', $filas, $etiqueta);
            }
        }

        foreach (self::BLOQUEO_RESTRICT_BAJA as [$tabla, $columna, $etiqueta]) {
            if (! Schema::hasTable($tabla)) {
                continue;
            }

            $consulta = DB::table($tabla)->where($columna, $cuenta->id);

            if ($colaborador !== null) {
                $consulta->whereNotIn('baja_id', $this->bajasPropias($colaborador->id));
            }

            $filas = $consulta->count();

            if ($filas > 0) {
                $mensajes[] = sprintf('%d %s a su nombre.', $filas, $etiqueta);
            }
        }

        return $mensajes;
    }

    /**
     * Subconsulta de los expedientes de baja de la persona: los suyos
     * (`colaborador_id`) y los ligados a sus cierres laborales.
     *
     * @return Closure(Builder): void
     */
    private function bajasPropias(int $colaboradorId): Closure
    {
        return function (Builder $q) use ($colaboradorId): void {
            $q->select('id')->from('bajas')
                ->where('colaborador_id', $colaboradorId)
                ->orWhereIn('cierre_laboral_id', fn (Builder $c) => $c->select('id')->from('cierres_laborales')->where('colaborador_id', $colaboradorId));
        };
    }

    /**
     * Conteo de lo que se elimina: registros propios de la persona
     * (ReferenciasColaborador=eliminar) y actividad propia de la cuenta
     * (ReferenciasUsuario cascade) más lo técnico/polimórfico.
     *
     * @return list<array{que: string, filas: int}>
     */
    private function conteosEliminar(User $cuenta, ?Colaborador $colaborador): array
    {
        $totales = [];

        if ($colaborador !== null) {
            foreach (ReferenciasColaborador::COLUMNAS as [$tabla, $columna, , $trato]) {
                if ($trato !== 'eliminar' || ! Schema::hasTable($tabla)) {
                    continue;
                }

                $this->acumular($totales, $tabla, DB::table($tabla)->where($columna, $colaborador->id)->count());
            }
        }

        foreach (ReferenciasUsuario::COLUMNAS as [$tabla, $columna, $accion]) {
            if ($accion !== 'cascade' || ! Schema::hasTable($tabla) || $this->esCompartida($tabla, $columna)) {
                continue;
            }

            $this->acumular($totales, $tabla, DB::table($tabla)->where($columna, $cuenta->id)->count());
        }

        foreach ($this->tecnicoCuenta($cuenta) as $tabla => $filas) {
            $this->acumular($totales, $tabla, $filas);
        }

        return $this->aLista($totales);
    }

    /**
     * Conteo de lo que se desvincula (autoría sobre terceros, NULL).
     *
     * @return list<array{que: string, filas: int}>
     */
    private function conteosDesvincular(User $cuenta, ?Colaborador $colaborador): array
    {
        $totales = [];

        if ($colaborador !== null) {
            foreach (ReferenciasColaborador::COLUMNAS as [$tabla, $columna, , $trato]) {
                if ($trato !== 'desvincular' || ! Schema::hasTable($tabla)) {
                    continue;
                }

                $this->acumular($totales, sprintf('%s (%s)', $this->etiqueta($tabla), $this->legible($columna)), DB::table($tabla)->where($columna, $colaborador->id)->count());
            }
        }

        foreach (ReferenciasUsuario::COLUMNAS as [$tabla, $columna, $accion]) {
            if ($accion !== 'set_null' || ! Schema::hasTable($tabla)) {
                continue;
            }

            $this->acumular($totales, sprintf('%s (%s)', $this->etiqueta($tabla), $this->legible($columna)), DB::table($tabla)->where($columna, $cuenta->id)->count());
        }

        return $this->aLista($totales);
    }

    private function desvincularAutoria(User $cuenta, ?Colaborador $colaborador): void
    {
        foreach (ReferenciasUsuario::COLUMNAS as [$tabla, $columna, $accion]) {
            if ($accion === 'set_null' && Schema::hasTable($tabla)) {
                DB::table($tabla)->where($columna, $cuenta->id)->update([$columna => null]);
            }
        }

        if ($colaborador === null) {
            return;
        }

        foreach (ReferenciasColaborador::COLUMNAS as [$tabla, $columna, , $trato]) {
            if ($trato === 'desvincular' && Schema::hasTable($tabla)) {
                DB::table($tabla)->where($columna, $colaborador->id)->update([$columna => null]);
            }
        }
    }

    /**
     * Ramas del expediente con FK RESTRICT o SET NULL (no caen por cascada al
     * borrar la persona), en orden hijos→padres. El resto cae en cascada.
     */
    private function eliminarRamasColaborador(int $colaboradorId): void
    {
        // Expediente de baja (RESTRICT a colaboradores y a cierres laborales;
        // sus documentos y bitácora, RESTRICT a la baja). Las filas se borran;
        // los archivos del NAS se conservan.
        $bajas = DB::table('bajas')->whereIn('id', $this->bajasPropias($colaboradorId))->pluck('id')->all();

        DB::table('baja_documentos')->whereIn('baja_id', $bajas)->delete();
        DB::table('baja_bitacora')->whereIn('baja_id', $bajas)->delete();
        DB::table('bajas')->whereIn('id', $bajas)->delete();

        // Expediente histórico (RESTRICT en divisiones→expediente, en
        // partes→división y en revisiones manuales→división).
        $divisiones = fn (Builder $q) => $q->select('id')->from('divisiones_expediente_historico')->where('colaborador_id', $colaboradorId);
        DB::table('partes_division_historico')->whereIn('division_id', $divisiones)->delete();
        DB::table('revisiones_division_historico')->whereIn('division_id', $divisiones)->delete();
        DB::table('divisiones_expediente_historico')->where('colaborador_id', $colaboradorId)->delete();
        DB::table('document_extractions')->where('colaborador_id', $colaboradorId)->delete();
        DB::table('expedientes_historicos')->where('colaborador_id', $colaboradorId)->delete();

        // Responsivas (RESTRICT a colaboradores y a users por «entregado_por»).
        DB::table('responsivas')->where('colaborador_id', $colaboradorId)->delete();

        // Acuses e inducción (RESTRICT a colaboradores y a users).
        DB::table('acuses_descriptivo_puesto')->where('colaborador_id', $colaboradorId)->delete();
        DB::table('sesion_induccion_colaboradores')->where('colaborador_id', $colaboradorId)->delete();

        // Cartas compromiso (RESTRICT a colaboradores).
        DB::table('cartas_compromiso')->where('colaborador_id', $colaboradorId)->delete();

        // Finiquitos (RESTRICT a users por «calculado_por_id»).
        DB::table('finiquito_calculos')->where('colaborador_id', $colaboradorId)->delete();

        // Documentos generados / formatos / invitaciones / alta digital
        // (SET NULL en la FK: hay que borrarlos explícitamente para que no
        // queden huérfanos con `colaborador_id = NULL`).
        DB::table('generated_documents')->where('colaborador_id', $colaboradorId)->delete();
        DB::table('official_format_generations')->where('colaborador_id', $colaboradorId)->delete();
        DB::table('incorporacion_invitaciones')->where('colaborador_id', $colaboradorId)->delete();
        DB::table('altas_digitales')->where('colaborador_id', $colaboradorId)->delete();
    }

    /**
     * Actividad propia de la cuenta (FK user_id cascade, excluyendo el
     * contenido compartido que detiene la purga): borrado directo por
     * `user_id` para no depender de la cascada del motor. Las sub-ramas
     * (p. ej. el historial de una solicitud) caen por su FK cascade hija.
     */
    private function eliminarActividadCuenta(User $cuenta): void
    {
        foreach (ReferenciasUsuario::COLUMNAS as [$tabla, $columna, $accion]) {
            if ($accion === 'cascade' && ! $this->esCompartida($tabla, $columna) && Schema::hasTable($tabla)) {
                DB::table($tabla)->where($columna, $cuenta->id)->delete();
            }
        }
    }

    private function eliminarTecnicoCuenta(User $cuenta): void
    {
        $tipo = $cuenta->getMorphClass();

        DB::table('personal_access_tokens')->where('tokenable_type', $tipo)->where('tokenable_id', $cuenta->id)->delete();
        DB::table('notifications')->where('notifiable_type', $tipo)->where('notifiable_id', $cuenta->id)->delete();
        DB::table('model_has_roles')->where('model_type', $tipo)->where('model_id', $cuenta->id)->delete();
        DB::table('model_has_permissions')->where('model_type', $tipo)->where('model_id', $cuenta->id)->delete();

        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->where('user_id', $cuenta->id)->delete();
        }

        if ($cuenta->email !== null && Schema::hasTable('password_reset_tokens')) {
            DB::table('password_reset_tokens')->where('email', $cuenta->email)->delete();
        }
    }

    /** @return array<string, int> */
    private function tecnicoCuenta(User $cuenta): array
    {
        $tipo = $cuenta->getMorphClass();

        return [
            'personal_access_tokens' => DB::table('personal_access_tokens')->where('tokenable_type', $tipo)->where('tokenable_id', $cuenta->id)->count(),
            'notifications' => DB::table('notifications')->where('notifiable_type', $tipo)->where('notifiable_id', $cuenta->id)->count(),
            'model_has_roles' => DB::table('model_has_roles')->where('model_type', $tipo)->where('model_id', $cuenta->id)->count(),
            'model_has_permissions' => DB::table('model_has_permissions')->where('model_type', $tipo)->where('model_id', $cuenta->id)->count(),
            'sessions' => Schema::hasTable('sessions') ? DB::table('sessions')->where('user_id', $cuenta->id)->count() : 0,
        ];
    }

    private function esCompartida(string $tabla, string $columna): bool
    {
        foreach (self::BLOQUEO_COMPARTIDO as [$t, $c]) {
            if ($t === $tabla && $c === $columna) {
                return true;
            }
        }

        return false;
    }

    private function archivosEnNas(?Colaborador $colaborador): int
    {
        if ($colaborador === null) {
            return 0;
        }

        $total = 0;

        foreach (['employee_documents', 'responsivas', 'generated_documents', 'official_format_generations', 'expedientes_historicos'] as $tabla) {
            if (Schema::hasTable($tabla)) {
                $total += DB::table($tabla)->where('colaborador_id', $colaborador->id)->count();
            }
        }

        return $total;
    }

    /**
     * @param  array<string, int>  $totales
     */
    private function acumular(array &$totales, string $clave, int $filas): void
    {
        if ($filas > 0) {
            $totales[$clave] = ($totales[$clave] ?? 0) + $filas;
        }
    }

    /**
     * @param  array<string, int>  $totales
     * @return list<array{que: string, filas: int}>
     */
    private function aLista(array $totales): array
    {
        $lista = [];

        foreach ($totales as $clave => $filas) {
            $lista[] = ['que' => isset(self::ETIQUETAS[$clave]) ? self::ETIQUETAS[$clave] : $clave, 'filas' => $filas];
        }

        return $lista;
    }

    private function etiqueta(string $tabla): string
    {
        return self::ETIQUETAS[$tabla] ?? Str::of($tabla)->replace('_', ' ')->toString();
    }

    private function legible(string $columna): string
    {
        return Str::of($columna)->replace('_', ' ')->toString();
    }
}
