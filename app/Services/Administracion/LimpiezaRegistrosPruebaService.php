<?php

namespace App\Services\Administracion;

use App\Enums\EstadoInvitacionIncorporacion;
use App\Enums\EstadoVacante;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Vacantes\VacanteAutoGenerationService;
use App\Support\LlavesForaneasEsquema;
use App\Support\ReferenciasColaborador;
use App\Support\ReferenciasUsuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * LIMPIEZA DEFINITIVA de registros de PRUEBA (uso administrativo puntual por
 * consola, nunca un botón para RH): borra físicamente candidatos,
 * colaboradores, su cuenta de acceso, sus invitaciones de incorporación y todo
 * lo que cuelga de ellos, más sus archivos en el NAS (Synology).
 *
 * Cómo encuentra las dependencias, sin adivinar:
 *  1. Llaves foráneas: las REALES de MariaDB (information_schema, InnoDB) unidas
 *     al catálogo de las migraciones (LlavesForaneasEsquema, para motores que no
 *     las exponen) y a las referencias sin llave conocidas (LLAVES_ADICIONALES).
 *  2. Relaciones polimórficas (`*_type` + `*_id`) contra el morph class de cada
 *     modelo de app/Models.
 *  3. Bitácora y notificaciones que guardan colaborador_id/candidato_id dentro
 *     de su JSON (AuditoriaService, notificaciones de RH).
 *  4. Red de seguridad: cualquier otra columna que por su nombre apunte a una
 *     persona y siga referenciando a los objetivos aborta la limpieza.
 *
 * Reglas para NO tocar a nadie más: una fila que se iba a borrar pero
 * pertenece a otra persona (colaborador_id/candidato_id ajeno), una llave
 * RESTRICT de terceros hacia la cuenta, contenido compartido creado por la
 * cuenta o un archivo/carpeta que otra fila también referencia ABORTAN todo.
 * La autoría sobre registros de terceros (jefe, registrado por…) se pone en
 * NULL, igual que haría la base con ON DELETE SET NULL.
 */
class LimpiezaRegistrosPruebaService
{
    /**
     * Los dos candidatos ficticios verificados en producción. Cada dato se
     * revalida antes de borrar; si uno no coincide, la limpieza no corre.
     *
     * @var list<array{etiqueta: string, nombre: string, candidato_id: int, colaborador_id: int, numero_empleado: string, user_id: int|null, invitacion_id: int}>
     */
    public const PERSONAS = [
        ['etiqueta' => 'Tito Iván López', 'nombre' => 'Tito Iván López', 'candidato_id' => 5, 'colaborador_id' => 133, 'numero_empleado' => 'EMP-0133', 'user_id' => 133, 'invitacion_id' => 4],
        ['etiqueta' => 'Víctor Ricardo Arriola', 'nombre' => 'Víctor Ricardo Arriola', 'candidato_id' => 4, 'colaborador_id' => 135, 'numero_empleado' => 'EMP-0135', 'user_id' => null, 'invitacion_id' => 6],
    ];

    /** Lo que se identificó en producción: si no coincide, se advierte (no aborta). */
    public const CONTEOS_ESPERADOS = [
        'employee_documents' => 16,
        'candidato_evidencias' => 6,
        'cartas_compromiso' => 1,
    ];

    public const FRASE_CONFIRMACION = 'ELIMINAR CANDIDATOS DE PRUEBA 5 Y 4';

    /** Horas máximas de antigüedad del respaldo exigido. */
    public const HORAS_RESPALDO = 24;

    /** Referencias a personas que no tienen llave foránea en las migraciones. */
    private const LLAVES_ADICIONALES = [
        ['birthday_greetings', 'user_id', 'users', 'id', 'cascade'],
        ['colaboradores', 'avisos_registrado_por_id', 'users', 'id', 'set_null'],
        ['colaboradores', 'incorporacion_decidida_por', 'users', 'id', 'set_null'],
        ['employee_documents', 'user_id', 'users', 'id', 'set_null'],
        ['movimientos_laborales', 'user_id', 'users', 'id', 'set_null'],
        ['sessions', 'user_id', 'users', 'id', 'cascade'],
        ['user_nodo_comercial', 'user_id', 'users', 'id', 'cascade'],
        ['notificacion_entregas', 'notification_id', 'notifications', 'id', 'cascade'],
    ];

    /**
     * Columnas SET NULL/RESTRICT que señalan al DUEÑO de la fila (su expediente,
     * su invitación, su tarea…): la fila se borra, no se desvincula.
     */
    private const PROPIAS_EXTRA = [
        ['aprobaciones', 'candidato_id'],
        ['aprobaciones', 'colaborador_id'],
        ['tareas_rh', 'candidato_id'],
        ['tareas_rh', 'colaborador_id'],
        ['altas_historicas', 'colaborador_id'],
        ['agenda_eventos', 'candidato_id'],
        ['altas_digitales', 'candidato_id'],
        ['altas_digitales', 'user_id'],
        ['generated_documents', 'candidato_id'],
        ['generated_documents', 'user_id'],
        ['official_format_generations', 'candidato_id'],
        ['official_format_generations', 'user_id'],
        ['movimientos_laborales', 'candidato_id'],
        ['movimientos_laborales', 'user_id'],
        ['incorporacion_invitaciones', 'candidato_id'],
        ['incorporacion_invitaciones', 'user_id'],
        ['employee_documents', 'user_id'],
        ['birthday_greetings', 'user_id'],
    ];

    /** Contenido COMPARTIDO creado por la cuenta (FK cascade): borrarlo destruiría datos ajenos. */
    private const COMPARTIDAS = [
        ['bancos_preguntas', 'creado_por'],
        ['recursos_multimedia', 'subido_por'],
        ['asignaciones', 'responsable_id'],
        ['sesiones_en_vivo', 'creado_por'],
        ['importaciones_excel', 'subido_por'],
        ['official_format_generations', 'generated_by_id'],
    ];

    /** Enlace persona↔persona: solo puede apuntar a los mismos objetivos. */
    private const EXCLUSIVAS = [
        ['users', 'colaborador_id'],
        ['candidatos', 'colaborador_id'],
        ['colaboradores', 'candidato_id'],
    ];

    /** Vacantes: se liberan (no se borran). */
    private const VACANTES = [
        ['vacantes', 'candidato_contratado_id'],
        ['vacantes', 'colaborador_contratado_id'],
    ];

    /** Columnas con «ruta»/«path» que no son archivos o no son de personas. */
    private const RUTAS_NO_ARCHIVO = [
        'intervenciones_candidato.ruta',
        'puestos.requiere_ruta',
        'cargas_multimedia.ruta_temporal',
        'colaboradores.expediente_storage_path',
        'candidatos.carpeta_nas',
    ];

    /** Columnas que parecen referencias a personas pero no lo son. */
    private const NO_PERSONA = [
        'causales_rotacion.reemplazada_por_id',
    ];

    /** @var array<string, list<string>> */
    private array $columnas = [];

    /** @var list<string>|null */
    private ?array $tablas = null;

    /** @var list<array{0: string, 1: string, 2: string, 3: string, 4: string}>|null */
    private ?array $llaves = null;

    /** @var array<string, list<string>>|null tabla → nombres con que se guarda su tipo polimórfico */
    private ?array $morfos = null;

    public function __construct(
        private readonly VacanteAutoGenerationService $vacantes,
        private readonly AuditoriaService $auditoria,
    ) {}

    // ------------------------------------------------------------------
    // Inventario (no modifica nada)
    // ------------------------------------------------------------------

    public function inventariar(): PlanLimpiezaPrueba
    {
        $plan = new PlanLimpiezaPrueba;
        $this->validarIdentidades($plan);

        if (! $plan->puedeEjecutarse()) {
            return $plan;
        }

        $this->cerrarDependencias($plan);
        $this->evaluarBloqueos($plan);
        $this->revisarTerceros($plan);
        $this->revisarReferenciasNoDeclaradas($plan);
        $this->inventariarArchivos($plan);
        $this->revisarConteos($plan);
        $this->revisarMotor($plan);
        $this->paresPlantilla($plan);

        return $plan;
    }

    /** @return array{candidatos: list<int>, colaboradores: list<int>, users: list<int>, incorporacion_invitaciones: list<int>} */
    public function objetivos(): array
    {
        return [
            'candidatos' => array_column(self::PERSONAS, 'candidato_id'),
            'colaboradores' => array_column(self::PERSONAS, 'colaborador_id'),
            'users' => $this->usuariosObjetivo(),
            'incorporacion_invitaciones' => array_column(self::PERSONAS, 'invitacion_id'),
        ];
    }

    /** @return list<int> */
    private function usuariosObjetivo(): array
    {
        $usuarios = [];

        foreach (self::PERSONAS as $persona) {
            if ($persona['user_id'] !== null) {
                $usuarios[] = $persona['user_id'];
            }
        }

        return $usuarios;
    }

    private function validarIdentidades(PlanLimpiezaPrueba $plan): void
    {
        foreach (self::PERSONAS as $p) {
            $persona = $p['etiqueta'];
            $esperado = $this->normalizarNombre($p['nombre']);

            $candidato = DB::table('candidatos')->where('id', $p['candidato_id'])->first();
            $this->validar($plan, $persona, sprintf('Candidato #%d existe', $p['candidato_id']), 'sí', $candidato !== null ? 'sí' : 'no');

            if ($candidato !== null) {
                $nombre = $this->normalizarNombre(trim(sprintf('%s %s', $candidato->nombre ?? '', $candidato->apellidos ?? '')));
                $this->validar($plan, $persona, sprintf('Nombre del candidato #%d', $p['candidato_id']), $esperado, $nombre);
                $this->validar($plan, $persona, 'Candidato → colaborador', sprintf('vacío o #%d', $p['colaborador_id']), $this->texto($candidato->colaborador_id ?? null), in_array($candidato->colaborador_id ?? null, [null, $p['colaborador_id']], false));
            }

            $colaborador = DB::table('colaboradores')->where('id', $p['colaborador_id'])->first();
            $this->validar($plan, $persona, sprintf('Colaborador #%d existe', $p['colaborador_id']), 'sí', $colaborador !== null ? 'sí' : 'no');

            if ($colaborador !== null) {
                $nombre = $this->normalizarNombre(trim(sprintf('%s %s', $colaborador->name ?? '', $colaborador->apellidos ?? '')));
                $this->validar($plan, $persona, sprintf('Nombre del colaborador #%d', $p['colaborador_id']), $esperado, $nombre);
                $this->validar($plan, $persona, 'Expediente', $p['numero_empleado'], $this->texto($colaborador->numero_empleado ?? null));
                $this->validar($plan, $persona, 'Colaborador → candidato', sprintf('vacío o #%d', $p['candidato_id']), $this->texto($colaborador->candidato_id ?? null), in_array($colaborador->candidato_id ?? null, [null, $p['candidato_id']], false));
            }

            if ($candidato !== null && $colaborador !== null) {
                $ligados = (int) ($candidato->colaborador_id ?? 0) === $p['colaborador_id'] || (int) ($colaborador->candidato_id ?? 0) === $p['candidato_id'];
                $this->validar($plan, $persona, 'Candidato y colaborador ligados entre sí', 'sí', $ligados ? 'sí' : 'no');
            }

            $cuentas = DB::table('users')->where('colaborador_id', $p['colaborador_id'])->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $esperadas = $p['user_id'] !== null ? [$p['user_id']] : [];
            $this->validar($plan, $persona, sprintf('Cuentas de acceso del colaborador #%d', $p['colaborador_id']), $esperadas === [] ? 'ninguna' : '#'.$p['user_id'], $cuentas === [] ? 'ninguna' : '#'.implode(', #', $cuentas), $cuentas == $esperadas);

            if ($p['user_id'] !== null) {
                $cuenta = DB::table('users')->where('id', $p['user_id'])->first();
                $this->validar($plan, $persona, sprintf('Usuario #%d → colaborador', $p['user_id']), '#'.$p['colaborador_id'], $cuenta !== null ? $this->texto($cuenta->colaborador_id ?? null) : 'no existe', $cuenta !== null && (int) ($cuenta->colaborador_id ?? 0) === $p['colaborador_id']);
            }

            $invitacion = DB::table('incorporacion_invitaciones')->where('id', $p['invitacion_id'])->first();
            $this->validar($plan, $persona, sprintf('Invitación #%d existe', $p['invitacion_id']), 'sí', $invitacion !== null ? 'sí' : 'no');

            if ($invitacion !== null) {
                $propia = in_array($invitacion->candidato_id ?? null, [null, $p['candidato_id']], false)
                    && in_array($invitacion->colaborador_id ?? null, [null, $p['colaborador_id']], false)
                    && in_array($invitacion->user_id ?? null, [null, $p['user_id']], false)
                    && ((int) ($invitacion->candidato_id ?? 0) === $p['candidato_id'] || (int) ($invitacion->colaborador_id ?? 0) === $p['colaborador_id']);
                $this->validar($plan, $persona, sprintf('Invitación #%d ligada solo a esta persona', $p['invitacion_id']), sprintf('candidato #%d / colaborador #%d', $p['candidato_id'], $p['colaborador_id']), sprintf('candidato %s / colaborador %s / usuario %s', $this->texto($invitacion->candidato_id ?? null), $this->texto($invitacion->colaborador_id ?? null), $this->texto($invitacion->user_id ?? null)), $propia);
            }
        }
    }

    private function validar(PlanLimpiezaPrueba $plan, string $persona, string $dato, string $esperado, string $encontrado, ?bool $ok = null): void
    {
        $ok ??= $esperado === $encontrado;
        $plan->validaciones[] = compact('persona', 'dato', 'esperado', 'encontrado', 'ok');

        if (! $ok) {
            $plan->error(sprintf('%s: «%s» no coincide (esperado %s, encontrado %s).', $persona, $dato, $esperado, $encontrado));
        }
    }

    private function cerrarDependencias(PlanLimpiezaPrueba $plan): void
    {
        $cola = [];

        foreach ($this->objetivos() as $tabla => $ids) {
            if ($ids !== [] && $this->existeTabla($tabla)) {
                $cola[] = [$tabla, $plan->agregarIds($tabla, $ids)];
            }
        }

        $this->agregarTecnicos($plan);
        $jsonRevisado = false;

        while ($cola !== []) {
            while ($cola !== []) {
                [$tabla, $ids] = array_shift($cola);

                foreach ($this->expandirLlaves($plan, $tabla, $ids) as $nuevo) {
                    $cola[] = $nuevo;
                }
            }

            foreach ($this->expandirPolimorficas($plan) as $nuevo) {
                $cola[] = $nuevo;
            }

            if (! $jsonRevisado) {
                $jsonRevisado = true;

                foreach ($this->expandirJson($plan) as $nuevo) {
                    $cola[] = $nuevo;
                }
            }
        }
    }

    /**
     * @param  list<int|string>  $ids
     * @return list<array{0: string, 1: list<int|string>}>
     */
    private function expandirLlaves(PlanLimpiezaPrueba $plan, string $padre, array $ids): array
    {
        $nuevos = [];

        if ($ids === []) {
            return $nuevos;
        }

        foreach ($this->llaves() as [$hija, $columna, $tablaPadre, $columnaPadre, $regla]) {
            if ($tablaPadre !== $padre || $columnaPadre !== 'id' || ! $this->existeColumna($hija, $columna)) {
                continue;
            }

            $accion = $this->accion($hija, $columna, $padre, $regla);

            foreach (array_chunk($ids, 500) as $lote) {
                $consulta = DB::table($hija)->whereIn($columna, $lote);

                switch ($accion) {
                    case 'vacante':
                        foreach ($consulta->pluck('id') as $id) {
                            $plan->vacantes[] = (int) $id;
                        }

                        $plan->vacantes = array_values(array_unique($plan->vacantes));
                        break;
                    case 'exclusiva':
                        $this->revisarExclusiva($plan, $hija, $columna, $consulta);
                        break;
                    case 'anular':
                        $plan->anular[] = ['tabla' => $hija, 'columnas' => [$columna], 'donde' => [$columna => $lote]];
                        break;
                    case 'bloquear':
                        $plan->bloqueos[] = ['tabla' => $hija, 'columna' => $columna, 'valores' => $lote];
                        break;
                    default:
                        $this->agregarFilas($plan, $hija, $consulta, [$columna => $lote], $nuevos);
                }
            }
        }

        return $nuevos;
    }

    /**
     * @param  array<string, list<int|string>>  $criterio
     * @param  list<array{0: string, 1: list<int|string>}>  $nuevos
     */
    private function agregarFilas(PlanLimpiezaPrueba $plan, string $tabla, Builder $consulta, array $criterio, array &$nuevos): void
    {
        if (! $this->existeColumna($tabla, 'id')) {
            if ($consulta->exists()) {
                $plan->agregarCriterio($tabla, $criterio);
            }

            return;
        }

        $agregados = $plan->agregarIds($tabla, $this->idsDeConsulta($consulta));

        if ($agregados !== []) {
            $nuevos[] = [$tabla, $agregados];
        }
    }

    /** @return list<int|string> */
    private function idsDeConsulta(Builder $consulta): array
    {
        $ids = [];

        foreach ($consulta->pluck('id') as $id) {
            $ids[] = is_int($id) || is_string($id) ? $id : sprintf('%s', $id);
        }

        return $ids;
    }

    private function accion(string $hija, string $columna, string $padre, string $regla): string
    {
        $par = [$hija, $columna];

        return match (true) {
            in_array($par, self::VACANTES, true) => 'vacante',
            in_array($par, self::EXCLUSIVAS, true) => 'exclusiva',
            $this->esPropia($hija, $columna) => 'borrar',
            $padre === 'users' && $regla === 'cascade' => in_array($par, self::COMPARTIDAS, true) ? 'bloquear' : 'borrar',
            $padre === 'users' && $regla === 'restrict' => 'bloquear',
            $regla === 'set_null' => 'anular',
            default => 'borrar',
        };
    }

    private function esPropia(string $tabla, string $columna): bool
    {
        if (in_array([$tabla, $columna], self::PROPIAS_EXTRA, true)) {
            return true;
        }

        foreach (ReferenciasColaborador::COLUMNAS as [$t, $c, , $trato]) {
            if ($t === $tabla && $c === $columna) {
                return $trato === 'eliminar';
            }
        }

        foreach (ReferenciasUsuario::COLUMNAS as [$t, $c, $regla]) {
            if ($t === $tabla && $c === $columna) {
                return $regla === 'cascade' && ! $this->esCompartida($tabla, $columna);
            }
        }

        return false;
    }

    private function esCompartida(string $tabla, string $columna): bool
    {
        foreach (self::COMPARTIDAS as [$t, $c]) {
            if ($t === $tabla && $c === $columna) {
                return true;
            }
        }

        return false;
    }

    private function revisarExclusiva(PlanLimpiezaPrueba $plan, string $tabla, string $columna, Builder $consulta): void
    {
        $objetivos = $this->objetivos()[$tabla] ?? [];

        foreach ($consulta->pluck('id') as $id) {
            if (! in_array((int) $id, $objetivos, true)) {
                $plan->error(sprintf('%s #%s también está ligado (%s) a una de las personas de prueba: no es exclusivo, revisa a mano.', $tabla, $id, $columna));
            }
        }
    }

    /** Tokens, roles, permisos y restablecimientos de contraseña de la cuenta (sin llave foránea). */
    private function agregarTecnicos(PlanLimpiezaPrueba $plan): void
    {
        $usuarios = $this->objetivos()['users'];

        if ($usuarios === []) {
            return;
        }

        $correos = DB::table('users')->whereIn('id', $usuarios)->whereNotNull('email')->pluck('email')->map(fn ($c): string => sprintf('%s', $c))->values()->all();

        if ($correos !== [] && $this->existeTabla('password_reset_tokens')) {
            $ajenos = DB::table('users')->whereIn('email', $correos)->whereNotIn('id', $usuarios)->count();

            if ($ajenos > 0) {
                $plan->error('El correo de la cuenta de prueba lo comparte otra cuenta: revisa a mano antes de limpiar.');
            } elseif (DB::table('password_reset_tokens')->whereIn('email', $correos)->exists()) {
                $plan->agregarCriterio('password_reset_tokens', ['email' => array_values($correos)]);
            }
        }
    }

    /** @return list<array{0: string, 1: list<int|string>}> */
    private function expandirPolimorficas(PlanLimpiezaPrueba $plan): array
    {
        $nuevos = [];

        foreach ($this->paresPolimorficos() as [$tabla, $prefijo]) {
            foreach ($plan->ids as $tablaBorrada => $ids) {
                $nombres = $this->morfos()[$tablaBorrada] ?? [];

                if ($nombres === [] || $ids === []) {
                    continue;
                }

                foreach (array_chunk(array_values($ids), 500) as $lote) {
                    $donde = [$prefijo.'_type' => $nombres, $prefijo.'_id' => $lote];
                    $consulta = DB::table($tabla)->whereIn($prefijo.'_type', $nombres)->whereIn($prefijo.'_id', $lote);

                    if (! $consulta->exists()) {
                        continue;
                    }

                    if ($prefijo === 'causer') {
                        $plan->anular[] = ['tabla' => $tabla, 'columnas' => [$prefijo.'_type', $prefijo.'_id'], 'donde' => $donde];

                        continue;
                    }

                    $this->agregarFilas($plan, $tabla, $consulta, $donde, $nuevos);
                }
            }
        }

        return $nuevos;
    }

    /**
     * Bitácora (activity_log.properties) y notificaciones (notifications.data)
     * que guardan a la persona dentro del JSON: son exclusivas de la prueba.
     *
     * @return list<array{0: string, 1: list<int|string>}>
     */
    private function expandirJson(PlanLimpiezaPrueba $plan): array
    {
        $nuevos = [];
        $objetivos = $this->objetivos();
        $claves = [
            'candidato_id' => $objetivos['candidatos'],
            'colaborador_id' => $objetivos['colaboradores'],
            'user_id' => $objetivos['users'],
            'invitacion_id' => $objetivos['incorporacion_invitaciones'],
        ];

        foreach ([['activity_log', 'properties'], ['notifications', 'data']] as [$tabla, $columna]) {
            if (! $this->existeColumna($tabla, $columna)) {
                continue;
            }

            $ids = [];

            DB::table($tabla)->select(['id', $columna])
                ->where(function (Builder $q) use ($columna): void {
                    foreach (['candidato', 'colaborador', 'invitacion', 'user_id', 'related_type'] as $pista) {
                        $q->orWhere($columna, 'like', '%'.$pista.'%');
                    }
                })
                ->orderBy('id')
                ->chunk(1000, function ($filas) use (&$ids, $columna, $claves, $plan): void {
                    foreach ($filas as $fila) {
                        $datos = json_decode((string) ($fila->{$columna} ?? ''), true);

                        if (is_array($datos) && $this->jsonApuntaAObjetivo($datos, $claves, $plan)) {
                            $ids[] = $fila->id;
                        }
                    }
                });

            if ($ids !== []) {
                $agregados = $plan->agregarIds($tabla, $ids);

                if ($agregados !== []) {
                    $nuevos[] = [$tabla, $agregados];
                }
            }
        }

        return $nuevos;
    }

    /**
     * @param  array<mixed>  $datos
     * @param  array<string, list<int>>  $claves
     */
    private function jsonApuntaAObjetivo(array $datos, array $claves, PlanLimpiezaPrueba $plan): bool
    {
        foreach ($claves as $clave => $valores) {
            if (isset($datos[$clave]) && is_numeric($datos[$clave]) && in_array((int) $datos[$clave], $valores, true)) {
                return true;
            }
        }

        foreach ([['related_type', 'related_id'], ['subject_type', 'subject_id']] as [$tipo, $id]) {
            if (! isset($datos[$tipo], $datos[$id]) || ! is_string($datos[$tipo]) || ! (is_int($datos[$id]) || is_string($datos[$id]))) {
                continue;
            }

            foreach ($this->morfos() as $tabla => $nombres) {
                if (in_array($datos[$tipo], $nombres, true) && $plan->seBorra($tabla, $datos[$id])) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Llaves RESTRICT o contenido compartido de terceros hacia la cuenta: lo que la base impediría borrar. */
    private function evaluarBloqueos(PlanLimpiezaPrueba $plan): void
    {
        foreach ($plan->bloqueos as $bloqueo) {
            $filas = DB::table($bloqueo['tabla'])->whereIn($bloqueo['columna'], $bloqueo['valores']);
            $ids = $this->existeColumna($bloqueo['tabla'], 'id') ? $filas->pluck('id')->all() : [];
            $ajenas = $this->existeColumna($bloqueo['tabla'], 'id')
                ? array_filter($ids, fn ($id): bool => ! $plan->seBorra($bloqueo['tabla'], $id))
                : ($filas->exists() ? ['?'] : []);

            if ($ajenas !== []) {
                $plan->error(sprintf('%d fila(s) de %s.%s de terceros dependen de la cuenta de prueba (llave RESTRICT o contenido compartido): no se puede borrar sin afectar a otros.', count($ajenas), $bloqueo['tabla'], $bloqueo['columna']));
            }
        }
    }

    /** Ninguna fila a borrar puede pertenecer a otra persona. */
    private function revisarTerceros(PlanLimpiezaPrueba $plan): void
    {
        $objetivos = $this->objetivos();
        $sujetos = ['colaborador_id' => $objetivos['colaboradores'], 'candidato_id' => $objetivos['candidatos']];

        foreach ($plan->tablasABorrar() as $tabla) {
            foreach ($sujetos as $columna => $permitidos) {
                if (! $this->existeColumna($tabla, $columna)) {
                    continue;
                }

                $ajenas = $this->consultaBorrado($plan, $tabla)
                    ->whereNotNull($columna)
                    ->whereNotIn($columna, $permitidos)
                    ->count();

                if ($ajenas > 0) {
                    $plan->error(sprintf('%d fila(s) de %s que se borrarían pertenecen a otra persona (%s ajeno): se aborta para no afectarla.', $ajenas, $tabla, $columna));
                }
            }
        }
    }

    /** Columnas que por su nombre apuntan a una persona pero no están declaradas: si referencian a los objetivos, se aborta. */
    private function revisarReferenciasNoDeclaradas(PlanLimpiezaPrueba $plan): void
    {
        $declaradas = [];

        foreach ($this->llaves() as [$hija, $columna]) {
            $declaradas[$hija.'.'.$columna] = true;
        }

        foreach ($this->paresPolimorficos() as [$tabla, $prefijo]) {
            $declaradas[$tabla.'.'.$prefijo.'_id'] = true;
        }

        $objetivos = $this->objetivos();

        foreach ($this->tablas() as $tabla) {
            foreach ($this->columnasDe($tabla) as $columna) {
                $clave = $tabla.'.'.$columna;

                if (isset($declaradas[$clave]) || in_array($clave, self::NO_PERSONA, true)) {
                    continue;
                }

                $objetivo = match (true) {
                    (bool) preg_match('/(^|_)candidato_id$/', $columna) => $objetivos['candidatos'],
                    (bool) preg_match('/(^|_)colaborador_id$/', $columna) => $objetivos['colaboradores'],
                    (bool) preg_match('/(^|_)(incorporacion_)?invitacion_id$/', $columna) => $objetivos['incorporacion_invitaciones'],
                    (bool) preg_match('/(^|_)(user|usuario)_id$|_por(_id)?$/', $columna) => $objetivos['users'],
                    default => null,
                };

                if ($objetivo === null || $objetivo === [] || $tabla === 'users' && $columna === 'id') {
                    continue;
                }

                $filas = DB::table($tabla)->whereIn($columna, $objetivo);

                if ($this->existeColumna($tabla, 'id')) {
                    $ajenas = array_filter($filas->pluck('id')->all(), fn ($id): bool => ! $plan->seBorra($tabla, $id));
                } else {
                    $ajenas = $filas->exists() ? ['?'] : [];
                }

                if ($ajenas !== []) {
                    $plan->error(sprintf('Dependencia no declarada: %s.%s referencia a una persona de prueba en %d fila(s). Revísala y decláralá antes de limpiar.', $tabla, $columna, count($ajenas)));
                }
            }
        }
    }

    private function inventariarArchivos(PlanLimpiezaPrueba $plan): void
    {
        $vistos = [];

        foreach ($plan->tablasABorrar() as $tabla) {
            $rutas = $this->columnasRuta($tabla);

            if ($rutas === []) {
                continue;
            }

            foreach ($this->consultaBorrado($plan, $tabla)->get() as $fila) {
                $datos = (array) $fila;

                foreach ($rutas as [$columnaRuta, $columnaDisco]) {
                    $ruta = trim((string) ($datos[$columnaRuta] ?? ''));

                    if ($ruta === '') {
                        continue;
                    }

                    $disco = $columnaDisco !== null && filled($datos[$columnaDisco] ?? null) ? (string) $datos[$columnaDisco] : $this->discoPorDefecto($tabla, $columnaRuta);
                    $this->agregarArchivo($plan, $vistos, $disco, $ruta, sprintf('%s #%s (%s)', $tabla, $datos['id'] ?? '?', $columnaRuta));
                }
            }
        }

        foreach ($this->carpetasDeObjetivos($plan) as [$disco, $carpeta, $origen]) {
            $existe = $this->existeCarpeta($disco, $carpeta);
            $plan->carpetas[] = ['disco' => $disco, 'carpeta' => $carpeta, 'origen' => $origen, 'existe' => $existe];

            if (! $existe) {
                $plan->advertencia(sprintf('La carpeta %s:%s no existe en el almacenamiento.', $disco, $carpeta));

                continue;
            }

            foreach (Storage::disk($disco)->allFiles($carpeta) as $ruta) {
                $this->agregarArchivo($plan, $vistos, $disco, $ruta, 'contenido de '.$origen);
            }

            $this->revisarRutaExclusiva($plan, $carpeta, true);
        }

        foreach ($plan->archivos as $archivo) {
            $this->revisarRutaExclusiva($plan, $archivo['ruta'], false);

            if (! $archivo['existe']) {
                $plan->advertencia(sprintf('Registrado en la base pero no está en el almacenamiento: %s:%s (%s).', $archivo['disco'], $archivo['ruta'], $archivo['origen']));
            }
        }
    }

    /** @param  array<string, true>  $vistos */
    private function agregarArchivo(PlanLimpiezaPrueba $plan, array &$vistos, string $disco, string $ruta, string $origen): void
    {
        $ruta = ltrim(str_replace('\\', '/', $ruta), '/');

        if (! $this->rutaSegura($ruta)) {
            $plan->error(sprintf('Ruta insegura o demasiado general, no se toca: %s:%s (%s).', $disco, $ruta, $origen));

            return;
        }

        if (config("filesystems.disks.{$disco}") === null) {
            $plan->error(sprintf('El disco «%s» de %s no está configurado.', $disco, $origen));

            return;
        }

        $clave = $disco.'|'.$ruta;

        if (isset($vistos[$clave])) {
            return;
        }

        $vistos[$clave] = true;
        $existe = Storage::disk($disco)->exists($ruta);
        $plan->archivos[] = ['disco' => $disco, 'ruta' => $ruta, 'origen' => $origen, 'existe' => $existe, 'tamano' => $existe ? Storage::disk($disco)->size($ruta) : null];
    }

    private function rutaSegura(string $ruta): bool
    {
        $segmentos = explode('/', $ruta);

        return $ruta !== ''
            && count($segmentos) >= 2
            && ! in_array('..', $segmentos, true)
            && ! in_array('', $segmentos, true)
            && ! str_contains($ruta, ':');
    }

    /**
     * Carpeta propia del candidato (`candidatos/{Nombre} - {id}`) y del
     * expediente (`expedientes/{empresa}/{sucursal}/{EMP-xxxx - Nombre}`);
     * nunca las carpetas generales.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function carpetasDeObjetivos(PlanLimpiezaPrueba $plan): array
    {
        $carpetas = [];

        foreach (self::PERSONAS as $p) {
            $candidato = DB::table('candidatos')->where('id', $p['candidato_id'])->first();
            $carpeta = trim((string) ($candidato->carpeta_nas ?? ''), '/ ');

            if ($candidato !== null && $carpeta !== '') {
                $ultimo = basename($carpeta);
                $valida = (bool) preg_match('#^candidatos/[^/]+$#', $carpeta)
                    && ($ultimo === (string) $p['candidato_id'] || str_ends_with($ultimo, ' - '.$p['candidato_id']));

                if (! $valida) {
                    $plan->error(sprintf('La carpeta del candidato #%d («%s») no tiene la forma candidatos/{Nombre} - %d: no se borra en bloque.', $p['candidato_id'], $carpeta, $p['candidato_id']));
                } else {
                    $disco = filled($candidato->cv_disk ?? null) ? (string) $candidato->cv_disk : (string) config('reclutamiento.disk');
                    $carpetas[] = [$disco, $carpeta, sprintf('carpeta del candidato #%d', $p['candidato_id'])];
                }
            }

            $colaborador = DB::table('colaboradores')->where('id', $p['colaborador_id'])->first();
            $carpeta = trim((string) ($colaborador->expediente_storage_path ?? ''), '/ ');

            if ($colaborador !== null && $carpeta !== '') {
                $segmentos = explode('/', $carpeta);
                $valida = $segmentos[0] === 'expedientes'
                    && count($segmentos) >= 4
                    && str_starts_with((string) end($segmentos), $p['numero_empleado'].' - ')
                    && $this->rutaSegura($carpeta);

                if (! $valida) {
                    $plan->error(sprintf('La carpeta del expediente %s («%s») no tiene la forma expedientes/…/%s - Nombre: no se borra en bloque.', $p['numero_empleado'], $carpeta, $p['numero_empleado']));
                } else {
                    $carpetas[] = [(string) config('expedientes.disk'), $carpeta, sprintf('expediente %s', $p['numero_empleado'])];
                }
            }
        }

        return $carpetas;
    }

    /** Ninguna fila AJENA puede referenciar el archivo (o algo dentro de la carpeta). */
    private function revisarRutaExclusiva(PlanLimpiezaPrueba $plan, string $ruta, bool $esCarpeta): void
    {
        foreach ($this->tablas() as $tabla) {
            $columnas = array_map(fn (array $c): string => $c[0], $this->columnasRuta($tabla, true));

            foreach ($columnas as $columna) {
                $consulta = DB::table($tabla);

                if ($esCarpeta) {
                    // Sin escapar comodines a propósito: un «_» de más solo
                    // ensancha la búsqueda (aborta de más, nunca de menos).
                    $patron = $ruta.'/%';
                    $consulta->where(fn (Builder $q) => $q->where($columna, $ruta)->orWhere($columna, 'like', $patron));
                } else {
                    $consulta->where($columna, $ruta);
                }

                $ajenas = $this->existeColumna($tabla, 'id')
                    ? array_filter($consulta->pluck('id')->all(), fn ($id): bool => ! $plan->seBorra($tabla, $id))
                    : ($consulta->exists() ? ['?'] : []);

                if ($ajenas !== []) {
                    $plan->error(sprintf('%s «%s» también lo referencia %s.%s de otro registro: no es exclusivo, no se borra nada.', $esCarpeta ? 'La carpeta' : 'El archivo', $ruta, $tabla, $columna));
                }
            }
        }
    }

    private function revisarConteos(PlanLimpiezaPrueba $plan): void
    {
        foreach (self::CONTEOS_ESPERADOS as $tabla => $esperado) {
            $encontrado = count($plan->idsDe($tabla));

            if ($encontrado !== $esperado) {
                $plan->advertencia(sprintf('%s: se esperaban %d y hay %d. Revisa la lista antes de confirmar.', $tabla, $esperado, $encontrado));
            }
        }
    }

    /** Sin InnoDB la transacción no deshace nada: la ejecución real se bloquea. */
    private function revisarMotor(PlanLimpiezaPrueba $plan): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $tablas = array_values(array_unique([...$plan->tablasABorrar(), ...array_column($plan->anular, 'tabla'), 'vacantes']));
        $sinTransaccion = DB::table('information_schema.TABLES')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->whereIn('TABLE_NAME', $tablas)
            ->where('ENGINE', '<>', 'InnoDB')
            ->pluck('TABLE_NAME')
            ->all();

        if ($sinTransaccion !== []) {
            $plan->error(sprintf('Tablas sin transacciones (no InnoDB): %s. Un error a medias no se podría deshacer; conviértelas antes.', implode(', ', $sinTransaccion)));
        }
    }

    private function paresPlantilla(PlanLimpiezaPrueba $plan): void
    {
        $pares = [];

        foreach (DB::table('colaboradores')->whereIn('id', $this->objetivos()['colaboradores'])->get(['sucursal_principal_id', 'puesto_id']) as $c) {
            if ($c->sucursal_principal_id !== null && $c->puesto_id !== null) {
                $pares[] = [(int) $c->sucursal_principal_id, (int) $c->puesto_id];
            }
        }

        foreach (DB::table('vacantes')->whereIn('id', $plan->vacantes)->get(['sucursal_id', 'puesto_id']) as $v) {
            if ($v->sucursal_id !== null && $v->puesto_id !== null) {
                $pares[] = [(int) $v->sucursal_id, (int) $v->puesto_id];
            }
        }

        $plan->paresPlantilla = array_values(array_unique($pares, SORT_REGULAR));
    }

    // ------------------------------------------------------------------
    // Respaldo y conteos para el reporte
    // ------------------------------------------------------------------

    /**
     * Respaldo verificado: dump SQL (o .sql.gz) reciente, completo y de ESTA
     * base, con las tablas que se van a tocar.
     *
     * @return list<string> Problemas (vacío = válido).
     */
    public function verificarRespaldo(string $ruta): array
    {
        if (! is_file($ruta) || ! is_readable($ruta)) {
            return [sprintf('No existe o no se puede leer el respaldo %s.', $ruta)];
        }

        $problemas = [];
        $horas = (time() - (int) filemtime($ruta)) / 3600;

        if ($horas > self::HORAS_RESPALDO) {
            $problemas[] = sprintf('El respaldo tiene %.1f horas; debe ser de las últimas %d horas.', $horas, self::HORAS_RESPALDO);
        }

        $buscar = [
            'CREATE TABLE `candidatos`' => false,
            'CREATE TABLE `colaboradores`' => false,
            'CREATE TABLE `users`' => false,
            'CREATE TABLE `incorporacion_invitaciones`' => false,
            'INSERT INTO `colaboradores`' => false,
            'Dump completed' => false,
        ];
        $base = DB::getDatabaseName();
        $deEstaBase = false;
        $gz = str_ends_with(strtolower($ruta), '.gz');
        $manejador = $gz ? gzopen($ruta, 'rb') : fopen($ruta, 'rb');

        if ($manejador === false) {
            return [sprintf('No se pudo abrir el respaldo %s.', $ruta)];
        }

        $previo = '';

        while (! ($gz ? gzeof($manejador) : feof($manejador))) {
            $bloque = $gz ? gzread($manejador, 1 << 20) : fread($manejador, 1 << 20);

            if ($bloque === false) {
                break;
            }

            $texto = $previo.$bloque;

            foreach ($buscar as $aguja => $encontrada) {
                if (! $encontrada && str_contains($texto, $aguja)) {
                    $buscar[$aguja] = true;
                }
            }

            if (! $deEstaBase && (str_contains($texto, 'Database: '.$base) || str_contains($texto, 'USE `'.$base.'`'))) {
                $deEstaBase = true;
            }

            $previo = substr($texto, -200);
        }

        $gz ? gzclose($manejador) : fclose($manejador);

        foreach ($buscar as $aguja => $encontrada) {
            if (! $encontrada) {
                $problemas[] = sprintf('El respaldo no contiene «%s» (¿incompleto o de otra base?).', $aguja);
            }
        }

        if (! $deEstaBase) {
            $problemas[] = sprintf('El respaldo no es de la base «%s».', $base);
        }

        return $problemas;
    }

    /** @return array<string, int> tabla → filas a borrar */
    public function conteoBorrado(PlanLimpiezaPrueba $plan): array
    {
        $conteo = [];

        foreach ($plan->tablasABorrar() as $tabla) {
            $conteo[$tabla] = $this->consultaBorrado($plan, $tabla)->count();
        }

        ksort($conteo);

        return $conteo;
    }

    /** @return array<string, int> «tabla.columna» → filas de terceros que quedan en NULL */
    public function conteoAnulado(PlanLimpiezaPrueba $plan): array
    {
        $conteo = [];

        foreach ($plan->anular as $anulacion) {
            $filas = $this->consultaAnulacion($plan, $anulacion)->count();

            if ($filas > 0) {
                $clave = $anulacion['tabla'].'.'.implode('+', $anulacion['columnas']);
                $conteo[$clave] = ($conteo[$clave] ?? 0) + $filas;
            }
        }

        ksort($conteo);

        return $conteo;
    }

    // ------------------------------------------------------------------
    // Ejecución
    // ------------------------------------------------------------------

    /**
     * Ejecuta el plan: (1) copia verificada de archivos + respaldo JSON de las
     * filas en `storage/app/private/limpiezas/{folio}`; (2) revoca la invitación
     * y corta el acceso; (3) una transacción para la base; (4) borra los
     * archivos y carpetas propias; (5) verifica que no quedó nada.
     *
     * @return array{folio: string, cuarentena: string, borradas: array<string, int>, anuladas: array<string, int>, archivos: int, carpetas: int, pendientes: list<string>}
     */
    public function ejecutar(PlanLimpiezaPrueba $plan): array
    {
        if (! $plan->puedeEjecutarse()) {
            throw new RuntimeException('El plan tiene errores: no se ejecuta.');
        }

        $folio = now()->format('Ymd-His').'-'.Str::lower(Str::random(6));
        $raiz = storage_path('app/private/limpiezas/'.$folio);
        $borradas = $this->conteoBorrado($plan);
        $anuladas = $this->conteoAnulado($plan);

        $this->ponerEnCuarentena($plan, $raiz);
        $this->revocarAcceso();

        DB::transaction(function () use ($plan): void {
            $this->conLlavesDiferidas(function () use ($plan): void {
                $this->liberarVacantes($plan);

                foreach ($plan->anular as $anulacion) {
                    $this->consultaAnulacion($plan, $anulacion)->update(array_fill_keys($anulacion['columnas'], null));
                }

                foreach ($plan->tablasABorrar() as $tabla) {
                    foreach ($plan->criterios[$tabla] ?? [] as $criterio) {
                        $this->aplicarCriterio(DB::table($tabla), $criterio)->delete();
                    }

                    foreach (array_chunk($plan->idsDe($tabla), 500) as $lote) {
                        DB::table($tabla)->whereIn('id', $lote)->delete();
                    }
                }
            });
        });

        $pendientes = $this->borrarArchivos($plan);
        $pendientes = [...$pendientes, ...$this->verificarFinal($plan)];

        foreach ($plan->paresPlantilla as [$sucursal, $puesto]) {
            $this->vacantes->sincronizarSinFallar($sucursal, $puesto);
        }

        $this->auditoria->registrar('limpieza_registros_prueba', null, null, [
            'folio' => $folio,
            'candidatos' => $this->objetivos()['candidatos'],
            'colaboradores_eliminados' => $this->objetivos()['colaboradores'],
            'usuarios' => $this->objetivos()['users'],
            'invitaciones' => $this->objetivos()['incorporacion_invitaciones'],
            'filas' => array_sum($borradas),
            'archivos' => count(array_filter($plan->archivos, fn (array $a): bool => $a['existe'])),
            'pendientes' => count($pendientes),
        ]);
        Log::warning('Limpieza definitiva de registros de prueba ejecutada.', ['folio' => $folio, 'pendientes' => $pendientes]);

        return [
            'folio' => $folio,
            'cuarentena' => $raiz,
            'borradas' => $borradas,
            'anuladas' => $anuladas,
            'archivos' => count(array_filter($plan->archivos, fn (array $a): bool => $a['existe'])),
            'carpetas' => count(array_filter($plan->carpetas, fn (array $c): bool => $c['existe'])),
            'pendientes' => $pendientes,
        ];
    }

    /** Copia verificada de cada archivo y respaldo JSON de las filas, ANTES de tocar nada. */
    private function ponerEnCuarentena(PlanLimpiezaPrueba $plan, string $raiz): void
    {
        $destino = Storage::build(['driver' => 'local', 'root' => $raiz, 'throw' => true]);
        $archivos = [];

        foreach ($plan->archivos as $archivo) {
            if (! $archivo['existe']) {
                continue;
            }

            $origen = Storage::disk($archivo['disco']);
            $flujo = $origen->readStream($archivo['ruta']);

            if (! is_resource($flujo)) {
                throw new RuntimeException(sprintf('No se pudo leer %s:%s para respaldarlo; no se borró nada.', $archivo['disco'], $archivo['ruta']));
            }

            $copia = 'archivos/'.$archivo['disco'].'/'.$archivo['ruta'];
            $destino->writeStream($copia, $flujo);
            fclose($flujo);

            if ($destino->size($copia) !== $archivo['tamano']) {
                throw new RuntimeException(sprintf('La copia de %s:%s no coincide en tamaño; no se borró nada.', $archivo['disco'], $archivo['ruta']));
            }

            $archivos[] = [...$archivo, 'copia' => $copia, 'sha256' => hash_file('sha256', $raiz.'/'.$copia)];
        }

        $filas = [];

        foreach ($plan->tablasABorrar() as $tabla) {
            $filas[$tabla] = $this->consultaBorrado($plan, $tabla)->get()->map(fn ($f): array => (array) $f)->all();
        }

        $anuladas = [];

        foreach ($plan->anular as $anulacion) {
            $anuladas[] = [...$anulacion, 'filas' => $this->consultaAnulacion($plan, $anulacion)->get()->map(fn ($f): array => (array) $f)->all()];
        }

        $destino->put('manifiesto.json', (string) json_encode([
            'creado_en' => now()->toIso8601String(),
            'base' => DB::getDatabaseName(),
            'personas' => self::PERSONAS,
            'archivos' => $archivos,
            'carpetas' => $plan->carpetas,
            'vacantes' => DB::table('vacantes')->whereIn('id', $plan->vacantes)->get()->map(fn ($f): array => (array) $f)->all(),
            'filas_borradas' => $filas,
            'filas_desvinculadas' => $anuladas,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /** Revoca la invitación activa y corta el acceso de la cuenta antes del borrado. */
    private function revocarAcceso(): void
    {
        $objetivos = $this->objetivos();

        DB::transaction(function () use ($objetivos): void {
            DB::table('incorporacion_invitaciones')
                ->whereIn('id', $objetivos['incorporacion_invitaciones'])
                ->whereNotIn('estado', [EstadoInvitacionIncorporacion::Usado->value, EstadoInvitacionIncorporacion::Revocado->value])
                ->update(['estado' => EstadoInvitacionIncorporacion::Revocado->value, 'revoked_at' => now()]);

            if ($objetivos['users'] === []) {
                return;
            }

            DB::table('personal_access_tokens')
                ->whereIn('tokenable_type', $this->morfos()['users'] ?? [])
                ->whereIn('tokenable_id', $objetivos['users'])
                ->delete();

            if ($this->existeTabla('sessions')) {
                DB::table('sessions')->whereIn('user_id', $objetivos['users'])->delete();
            }
        });
    }

    private function liberarVacantes(PlanLimpiezaPrueba $plan): void
    {
        $objetivos = $this->objetivos();

        foreach (DB::table('vacantes')->whereIn('id', $plan->vacantes)->lockForUpdate()->get() as $vacante) {
            $contratadoPrueba = in_array((int) ($vacante->colaborador_contratado_id ?? 0), $objetivos['colaboradores'], true);
            $cambios = [];

            if (in_array((int) ($vacante->candidato_contratado_id ?? 0), $objetivos['candidatos'], true)) {
                $cambios['candidato_contratado_id'] = null;
            }

            if ($contratadoPrueba) {
                $cubiertas = max(0, (int) $vacante->plazas_cubiertas - 1);
                $disponibles = max(max((int) $vacante->plazas_requeridas, 1) - $cubiertas, 0);
                $cambios['colaborador_contratado_id'] = null;
                $cambios['plazas_cubiertas'] = $cubiertas;
                $cambios['plazas_disponibles'] = $disponibles;

                if ($vacante->estado === EstadoVacante::Cubierta->value && $disponibles > 0) {
                    $cambios['estado'] = EstadoVacante::Abierta->value;
                    $cambios['fecha_cierre'] = null;
                }
            }

            if ($cambios !== []) {
                DB::table('vacantes')->where('id', $vacante->id)->update([...$cambios, 'updated_at' => now()]);
            }
        }
    }

    /** @param  callable(): void  $accion */
    private function conLlavesDiferidas(callable $accion): void
    {
        $driver = DB::getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');

            try {
                $accion();
            } finally {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }

            return;
        }

        if ($driver === 'sqlite') {
            DB::statement('PRAGMA defer_foreign_keys = ON');
        }

        $accion();
    }

    /** @return list<string> Lo que no se pudo borrar del almacenamiento (la copia está en la cuarentena). */
    private function borrarArchivos(PlanLimpiezaPrueba $plan): array
    {
        $pendientes = [];

        foreach ($plan->archivos as $archivo) {
            if (! $archivo['existe']) {
                continue;
            }

            try {
                Storage::disk($archivo['disco'])->delete($archivo['ruta']);
            } catch (Throwable $e) {
                Log::warning('Limpieza de prueba: no se pudo borrar un archivo.', ['disco' => $archivo['disco'], 'ruta' => $archivo['ruta'], 'error' => $e->getMessage()]);
            }
        }

        foreach ($plan->carpetas as $carpeta) {
            if (! $carpeta['existe']) {
                continue;
            }

            if (Storage::disk($carpeta['disco'])->allFiles($carpeta['carpeta']) !== []) {
                $pendientes[] = sprintf('La carpeta %s:%s todavía tiene archivos; no se borró.', $carpeta['disco'], $carpeta['carpeta']);

                continue;
            }

            try {
                Storage::disk($carpeta['disco'])->deleteDirectory($carpeta['carpeta']);
            } catch (Throwable $e) {
                Log::warning('Limpieza de prueba: no se pudo borrar una carpeta.', ['disco' => $carpeta['disco'], 'carpeta' => $carpeta['carpeta'], 'error' => $e->getMessage()]);
            }
        }

        return $pendientes;
    }

    /**
     * Que no quede nada: personas, filas planeadas, hijos huérfanos,
     * referencias polimórficas ni archivos.
     *
     * @return list<string>
     */
    public function verificarFinal(PlanLimpiezaPrueba $plan): array
    {
        $pendientes = [];

        foreach ($plan->tablasABorrar() as $tabla) {
            $quedan = $this->consultaBorrado($plan, $tabla)->count();

            if ($quedan > 0) {
                $pendientes[] = sprintf('Quedan %d fila(s) en %s.', $quedan, $tabla);
            }
        }

        foreach ($plan->ids as $padre => $ids) {
            $ids = array_values($ids);

            foreach ($this->llaves() as [$hija, $columna, $tablaPadre, $columnaPadre]) {
                if ($tablaPadre !== $padre || $columnaPadre !== 'id' || ! $this->existeColumna($hija, $columna) || in_array([$hija, $columna], self::VACANTES, true)) {
                    continue;
                }

                foreach (array_chunk($ids, 500) as $lote) {
                    $huerfanas = DB::table($hija)->whereIn($columna, $lote)->count();

                    if ($huerfanas > 0) {
                        $pendientes[] = sprintf('%d fila(s) de %s.%s apuntan a %s que ya no existe.', $huerfanas, $hija, $columna, $padre);
                    }
                }
            }
        }

        foreach ($this->paresPolimorficos() as [$tabla, $prefijo]) {
            foreach ($plan->ids as $padre => $ids) {
                $nombres = $this->morfos()[$padre] ?? [];

                if ($nombres === []) {
                    continue;
                }

                $quedan = DB::table($tabla)->whereIn($prefijo.'_type', $nombres)->whereIn($prefijo.'_id', array_values($ids))->count();

                if ($quedan > 0) {
                    $pendientes[] = sprintf('%d fila(s) de %s (%s) apuntan a %s que ya no existe.', $quedan, $tabla, $prefijo, $padre);
                }
            }
        }

        $objetivos = $this->objetivos();
        $vinculadas = DB::table('vacantes')
            ->where(fn (Builder $q) => $q->whereIn('candidato_contratado_id', $objetivos['candidatos'])->orWhereIn('colaborador_contratado_id', $objetivos['colaboradores']))
            ->count();

        if ($vinculadas > 0) {
            $pendientes[] = sprintf('%d vacante(s) siguen ocupadas por las personas borradas.', $vinculadas);
        }

        foreach ($plan->archivos as $archivo) {
            if ($archivo['existe'] && Storage::disk($archivo['disco'])->exists($archivo['ruta'])) {
                $pendientes[] = sprintf('El archivo %s:%s sigue en el almacenamiento (copia en la cuarentena).', $archivo['disco'], $archivo['ruta']);
            }
        }

        foreach ($plan->carpetas as $carpeta) {
            if ($carpeta['existe'] && $this->existeCarpeta($carpeta['disco'], $carpeta['carpeta'])) {
                $pendientes[] = sprintf('La carpeta %s:%s sigue en el almacenamiento.', $carpeta['disco'], $carpeta['carpeta']);
            }
        }

        return $pendientes;
    }

    // ------------------------------------------------------------------
    // Utilidades de esquema
    // ------------------------------------------------------------------

    private function consultaBorrado(PlanLimpiezaPrueba $plan, string $tabla): Builder
    {
        $ids = $plan->idsDe($tabla);
        $criterios = $plan->criterios[$tabla] ?? [];

        return DB::table($tabla)->where(function (Builder $q) use ($ids, $criterios): void {
            if ($ids !== []) {
                $q->orWhereIn('id', $ids);
            }

            foreach ($criterios as $criterio) {
                $q->orWhere(fn (Builder $c) => $this->aplicarCriterio($c, $criterio));
            }

            if ($ids === [] && $criterios === []) {
                $q->whereRaw('1 = 0');
            }
        });
    }

    /** @param  array{tabla: string, columnas: list<string>, donde: array<string, list<int|string>>}  $anulacion */
    private function consultaAnulacion(PlanLimpiezaPrueba $plan, array $anulacion): Builder
    {
        $consulta = $this->aplicarCriterio(DB::table($anulacion['tabla']), $anulacion['donde']);
        $propias = $plan->idsDe($anulacion['tabla']);

        if ($propias !== [] && $this->existeColumna($anulacion['tabla'], 'id')) {
            $consulta->whereNotIn('id', $propias);
        }

        return $consulta;
    }

    /** @param  array<string, list<int|string>>  $criterio */
    private function aplicarCriterio(Builder $consulta, array $criterio): Builder
    {
        foreach ($criterio as $columna => $valores) {
            $consulta->whereIn($columna, $valores);
        }

        return $consulta;
    }

    /**
     * Llaves foráneas: catálogo de migraciones + referencias sin llave +
     * restricciones REALES de MariaDB cuando el motor las expone.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    public function llaves(): array
    {
        if ($this->llaves !== null) {
            return $this->llaves;
        }

        $llaves = [];

        foreach ([...LlavesForaneasEsquema::LLAVES, ...self::LLAVES_ADICIONALES] as $llave) {
            $llaves[$llave[0].'.'.$llave[1].'>'.$llave[2]] = [$llave[0], $llave[1], $llave[2], $llave[3], $llave[4]];
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $reales = DB::select(
                'SELECT k.TABLE_NAME AS hija, k.COLUMN_NAME AS columna, k.REFERENCED_TABLE_NAME AS padre, k.REFERENCED_COLUMN_NAME AS columna_padre, r.DELETE_RULE AS regla
                 FROM information_schema.KEY_COLUMN_USAGE k
                 JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
                 WHERE k.CONSTRAINT_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL'
            );

            foreach ($reales as $r) {
                $regla = match (strtoupper((string) $r->regla)) {
                    'CASCADE' => 'cascade',
                    'SET NULL' => 'set_null',
                    default => 'restrict',
                };
                $llaves[$r->hija.'.'.$r->columna.'>'.$r->padre] = [(string) $r->hija, (string) $r->columna, (string) $r->padre, (string) $r->columna_padre, $regla];
            }
        }

        return $this->llaves = array_values($llaves);
    }

    /** @return list<array{0: string, 1: string}> [tabla, prefijo] de cada relación polimórfica */
    private function paresPolimorficos(): array
    {
        $pares = [];

        foreach ($this->tablas() as $tabla) {
            $columnas = $this->columnasDe($tabla);

            foreach ($columnas as $columna) {
                if (str_ends_with($columna, '_type')) {
                    $prefijo = substr($columna, 0, -5);

                    if (in_array($prefijo.'_id', $columnas, true)) {
                        $pares[] = [$tabla, $prefijo];
                    }
                }
            }
        }

        return $pares;
    }

    /** @return array<string, list<string>> */
    private function morfos(): array
    {
        if ($this->morfos !== null) {
            return $this->morfos;
        }

        $morfos = ['notifications' => ['notification', 'notifications', DatabaseNotification::class, 'DatabaseNotification']];

        foreach (glob(app_path('Models/*.php')) ?: [] as $archivo) {
            $clase = 'App\\Models\\'.basename($archivo, '.php');

            if (! class_exists($clase) || ! is_subclass_of($clase, Model::class)) {
                continue;
            }

            try {
                /** @var Model $modelo */
                $modelo = new $clase;
                $nombres = [$modelo->getMorphClass(), $clase, class_basename($clase)];
                $morfos[$modelo->getTable()] = array_values(array_unique([...($morfos[$modelo->getTable()] ?? []), ...$nombres]));
            } catch (Throwable) {
                continue;
            }
        }

        return $this->morfos = $morfos;
    }

    /**
     * Columnas de archivo de una tabla con su columna de disco (si tiene).
     *
     * @return list<array{0: string, 1: string|null}>
     */
    private function columnasRuta(string $tabla, bool $incluirCarpetas = false): array
    {
        $columnas = $this->columnasDe($tabla);
        $rutas = [];

        foreach ($columnas as $columna) {
            if (! preg_match('/(^|_)(path|ruta)$/', $columna)) {
                continue;
            }

            $clave = $tabla.'.'.$columna;

            if (in_array($clave, ['intervenciones_candidato.ruta', 'puestos.requiere_ruta', 'cargas_multimedia.ruta_temporal'], true)) {
                continue;
            }

            if (! $incluirCarpetas && in_array($clave, self::RUTAS_NO_ARCHIVO, true)) {
                continue;
            }

            $prefijo = (string) preg_replace('/(path|ruta)$/', '', $columna);
            $disco = null;

            foreach ([$prefijo.'disk', $prefijo.'disco', 'disk', 'disco'] as $candidata) {
                if (in_array($candidata, $columnas, true)) {
                    $disco = $candidata;

                    break;
                }
            }

            $rutas[] = [$columna, $disco];
        }

        return $rutas;
    }

    private function discoPorDefecto(string $tabla, string $columna): string
    {
        return match ($tabla.'.'.$columna) {
            'birthday_greetings.card_path', 'birthday_wall_messages.foto_path' => (string) config('cumpleanos.disk'),
            'candidatos.cv_path' => (string) config('reclutamiento.disk'),
            default => (string) config('expedientes.disk'),
        };
    }

    private function existeCarpeta(string $disco, string $carpeta): bool
    {
        $almacen = Storage::disk($disco);

        return $almacen->directoryExists($carpeta) || $almacen->allFiles($carpeta) !== [];
    }

    /** @return list<string> */
    private function tablas(): array
    {
        return $this->tablas ??= array_values(array_map(fn (array $t): string => (string) $t['name'], Schema::getTables()));
    }

    private function existeTabla(string $tabla): bool
    {
        return in_array($tabla, $this->tablas(), true);
    }

    /** @return list<string> */
    private function columnasDe(string $tabla): array
    {
        if (! $this->existeTabla($tabla)) {
            return [];
        }

        return $this->columnas[$tabla] ??= array_values(Schema::getColumnListing($tabla));
    }

    private function existeColumna(string $tabla, string $columna): bool
    {
        return in_array($columna, $this->columnasDe($tabla), true);
    }

    private function normalizarNombre(string $nombre): string
    {
        return (string) preg_replace('/\s+/', ' ', Str::lower(Str::ascii(trim($nombre))));
    }

    private function texto(mixed $valor): string
    {
        return $valor === null || $valor === '' ? 'vacío' : (is_numeric($valor) ? '#'.$valor : (string) $valor);
    }
}
