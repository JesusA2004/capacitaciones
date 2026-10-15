<?php

namespace App\Services\Onboarding;

use App\Enums\EstadoEvaluacionInduccion;
use App\Enums\PrioridadTarea;
use App\Enums\TipoTarea;
use App\Models\AcuseDescriptivoPuesto;
use App\Models\Colaborador;
use App\Models\DescriptivoPuesto;
use App\Models\EvaluacionInduccion;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Puestos\Descriptivos\DescriptivoPuestoService;
use App\Services\Tareas\NotificadorRhService;
use App\Services\Tareas\TareaService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Enviar descriptivo de puesto» (después de la inducción):
 *
 *   1. El colaborador aprobó su evaluación de inducción (RH ya recibió y
 *      calificó sus respuestas) → RH presiona «Enviar descriptivo de
 *      puesto» (permiso onboarding.gestionar + alcance del módulo).
 *   2. Se envía la VERSIÓN vigente del descriptivo OFICIAL de su puesto
 *      (DescriptivoPuestoService; nunca contenido inventado) → pendiente en
 *      «Mis pendientes» + notificación/push a la app.
 *   3. El colaborador lo lee en la app (queda «leído»), marca «OK,
 *      enterado» y escribe su comentario → acuse; RH ve el comentario.
 *
 * Misma regla para la web (Rh\AcuseDescriptivoPuestoController) y la API
 * (Api\V1\Rh\AcuseDescriptivoPuestoController y
 * Api\V1\DescriptivoPuestoColaboradorController).
 */
class AcuseDescriptivoPuestoService
{
    public const PERMISO = OnboardingService::PERMISO_GESTIONAR;

    public const MODULO = SesionInduccionService::MODULO;

    public const COMENTARIO_MAX = 2000;

    public function __construct(
        private readonly DescriptivoPuestoService $descriptivos,
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly TareaService $tareas,
        private readonly NotificadorRhService $notificador,
        private readonly AuditoriaService $auditoria,
    ) {}

    /**
     * RH envía el descriptivo vigente de su puesto. Si ya hay uno de esa
     * misma versión esperando su «Enterado», no se duplica: se le vuelve a
     * avisar.
     *
     * @throws AuthorizationException sin permiso, fuera de alcance o a sí mismo
     * @throws ValidationException inducción sin aprobar, sin puesto/descriptivo/cuenta o ya enterado
     */
    public function enviar(Colaborador $colaborador, User $actor): AcuseDescriptivoPuesto
    {
        $this->autorizarGestion($actor, $colaborador);

        $motivo = $this->motivoNoDisponible($colaborador);

        if ($motivo !== null) {
            throw ValidationException::withMessages(['descriptivo' => $motivo]);
        }

        $colaborador->loadMissing('user');
        $usuario = $colaborador->user;
        $descriptivo = $colaborador->puesto_id !== null ? $this->descriptivos->vigenteDePuesto($colaborador->puesto_id) : null;

        if ($usuario === null || $descriptivo === null) {
            // motivoNoDisponible() ya lo descartó; defensa por carrera.
            throw ValidationException::withMessages(['descriptivo' => 'No se pudo enviar el descriptivo de puesto.']);
        }

        /** @var array{0: AcuseDescriptivoPuesto, 1: bool} $resultado */
        $resultado = DB::transaction(function () use ($colaborador, $actor, $descriptivo): array {
            $existente = AcuseDescriptivoPuesto::query()
                ->where('colaborador_id', $colaborador->id)
                ->where('descriptivo_puesto_id', $descriptivo->id)
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if ($existente !== null && $existente->acusado_en !== null) {
                throw ValidationException::withMessages([
                    'descriptivo' => sprintf('%s ya dio por enterado este descriptivo de puesto el %s.', $colaborador->nombreCompleto(), $existente->acusado_en->timezone('America/Mexico_City')->format('d/m/Y')),
                ]);
            }

            if ($existente !== null) {
                return [$existente, false];
            }

            $evaluacionId = EvaluacionInduccion::query()
                ->where('colaborador_id', $colaborador->id)
                ->where('estado', EstadoEvaluacionInduccion::Aprobada->value)
                ->latest('id')
                ->value('id');

            $acuse = AcuseDescriptivoPuesto::query()->create([
                'colaborador_id' => $colaborador->id,
                'descriptivo_puesto_id' => $descriptivo->id,
                'descriptivo_version' => $descriptivo->version,
                'puesto_id' => $colaborador->puesto_id,
                'evaluacion_induccion_id' => is_numeric($evaluacionId) ? (int) $evaluacionId : null,
                'enviado_por' => $actor->id,
                'enviado_en' => now('America/Mexico_City'),
            ]);

            return [$acuse, true];
        });

        [$acuse, $nuevo] = $resultado;

        // Ya con commit: pendiente + aviso (nunca deshacen el envío; ambas
        // capas capturan y registran sus propios fallos).
        $this->tareas->abrir(TipoTarea::DescriptivoPuestoPorAcusar, $acuse, [
            'titulo' => 'Lee tu descriptivo de puesto',
            'descripcion' => 'Recursos Humanos te envió las actividades y responsabilidades de tu puesto. Léelas y confirma que estás enterado.',
            'prioridad' => PrioridadTarea::Alta,
            'colaborador' => $colaborador,
            'usuario' => $usuario,
            'accion' => 'acusar_descriptivo_puesto',
        ]);

        $this->notificador->notificar(
            [$usuario],
            'descriptivo_puesto_enviado',
            $nuevo ? 'Lee tu descriptivo de puesto' : 'Recordatorio: lee tu descriptivo de puesto',
            'Recursos Humanos te envió el descriptivo de tu puesto: tus actividades y lo que te corresponde. Léelo en tu app y confirma que estás enterado.',
            $acuse,
            'acusar_descriptivo_puesto',
            'alta',
        );

        $this->auditoria->registrar($nuevo ? 'descriptivo_puesto_enviado' : 'descriptivo_puesto_reenviado', $colaborador, $actor, [
            'acuse_descriptivo_puesto_id' => $acuse->id,
            'descriptivo_puesto_id' => $acuse->descriptivo_puesto_id,
            'version' => $acuse->descriptivo_version,
        ]);

        return $acuse->refresh();
    }

    /**
     * El descriptivo más reciente que RH le envió a este usuario (o null).
     */
    public function vigenteDe(User $usuario): ?AcuseDescriptivoPuesto
    {
        if ($usuario->colaborador_id === null) {
            return null;
        }

        return AcuseDescriptivoPuesto::query()
            ->where('colaborador_id', $usuario->colaborador_id)
            ->latest('id')
            ->first();
    }

    /**
     * El colaborador lo abrió (idempotente: conserva la primera lectura).
     *
     * @throws AuthorizationException no es suyo
     */
    public function marcarLeido(AcuseDescriptivoPuesto $acuse, User $usuario): AcuseDescriptivoPuesto
    {
        $this->exigirTitular($acuse, $usuario);

        if ($acuse->leido_en === null) {
            $acuse->update(['leido_en' => now('America/Mexico_City')]);
        }

        return $acuse;
    }

    /**
     * «OK, enterado» + comentario del colaborador. Las dos cosas son
     * obligatorias; una vez acusado no se modifica.
     *
     * @throws AuthorizationException no es suyo
     * @throws ValidationException sin «Enterado», sin comentario o ya acusado
     */
    public function acusar(AcuseDescriptivoPuesto $acuse, User $usuario, bool $enterado, string $comentario): AcuseDescriptivoPuesto
    {
        $this->exigirTitular($acuse, $usuario);

        $comentario = trim($comentario);
        $errores = [];

        if (! $enterado) {
            $errores['enterado'] = 'Marca «OK, enterado» para confirmar que leíste tu descriptivo de puesto.';
        }

        if ($comentario === '') {
            $errores['comentario'] = 'Escribe tu comentario de enterado.';
        } elseif (mb_strlen($comentario) > self::COMENTARIO_MAX) {
            $errores['comentario'] = sprintf('Tu comentario no puede pasar de %d caracteres.', self::COMENTARIO_MAX);
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        $acuse = DB::transaction(function () use ($acuse, $comentario): AcuseDescriptivoPuesto {
            $acuse = AcuseDescriptivoPuesto::query()->lockForUpdate()->findOrFail($acuse->id);

            if ($acuse->acusado_en !== null) {
                throw ValidationException::withMessages(['descriptivo' => 'Ya habías confirmado que estás enterado de este descriptivo de puesto.']);
            }

            $ahora = now('America/Mexico_City');
            $acuse->update([
                'leido_en' => $acuse->leido_en ?? $ahora,
                'enterado' => true,
                'comentario' => $comentario,
                'acusado_en' => $ahora,
            ]);

            return $acuse;
        });

        $this->tareas->resolver(TipoTarea::DescriptivoPuestoPorAcusar, $acuse, $usuario);
        $acuse->loadMissing(['colaborador', 'remitente']);

        $this->notificador->notificar(
            [$acuse->remitente],
            'descriptivo_puesto_acusado',
            'Descriptivo de puesto: enterado',
            sprintf('%s dio por enterado su descriptivo de puesto: «%s»', $acuse->colaborador->nombreCompleto(), mb_strimwidth($comentario, 0, 160, '…')),
            $acuse,
            'ver_descriptivo_puesto',
        );

        $this->auditoria->registrar('descriptivo_puesto_acusado', $acuse->colaborador, $usuario, [
            'acuse_descriptivo_puesto_id' => $acuse->id,
            'version' => $acuse->descriptivo_version,
        ]);

        return $acuse->refresh();
    }

    /**
     * Por qué todavía no se le puede enviar (null = sí se puede). No revisa
     * permisos de quien envía: eso es autorizarGestion().
     */
    public function motivoNoDisponible(Colaborador $colaborador): ?string
    {
        $aprobada = EvaluacionInduccion::query()
            ->where('colaborador_id', $colaborador->id)
            ->where('estado', EstadoEvaluacionInduccion::Aprobada->value)
            ->exists();

        if (! $aprobada) {
            return 'Se habilita cuando el colaborador termina y aprueba su evaluación de inducción.';
        }

        $colaborador->loadMissing(['puesto:id,nombre', 'user']);

        if ($colaborador->puesto_id === null) {
            return 'El colaborador no tiene puesto asignado.';
        }

        if ($this->descriptivos->vigenteDePuesto($colaborador->puesto_id) === null) {
            return sprintf('El puesto «%s» todavía no tiene descriptivo oficial importado.', $colaborador->puesto->nombre ?? 'sin nombre');
        }

        if ($colaborador->user === null) {
            return 'El colaborador no tiene cuenta para recibirlo en la app.';
        }

        return null;
    }

    /**
     * Estado para RH (seguimiento de inducción y revisión de la
     * evaluación): el último envío, si puede enviarlo y por qué no.
     *
     * @return array{acuse: array<string, mixed>|null, puede_enviar: bool, motivo: string|null}
     */
    public function resumenRh(Colaborador $colaborador, User $actor): array
    {
        $ultimo = AcuseDescriptivoPuesto::query()
            ->where('colaborador_id', $colaborador->id)
            ->latest('id')
            ->first();

        return $this->armarResumenRh($colaborador, $actor, $ultimo);
    }

    /**
     * resumenRh() para varias personas con una sola consulta de acuses.
     *
     * @param  Collection<int, Colaborador>  $colaboradores
     * @return array<int, array{acuse: array<string, mixed>|null, puede_enviar: bool, motivo: string|null}>
     */
    public function resumenesRh(Collection $colaboradores, User $actor): array
    {
        $ultimos = AcuseDescriptivoPuesto::query()
            ->whereIn('colaborador_id', $colaboradores->pluck('id')->all())
            ->with(['remitente:id,name,apellidos,colaborador_id', 'descriptivo:id,nombre_puesto,version'])
            ->orderBy('id')
            ->get()
            ->keyBy('colaborador_id');

        $resumenes = [];

        foreach ($colaboradores as $colaborador) {
            $resumenes[$colaborador->id] = $this->armarResumenRh($colaborador, $actor, $ultimos->get($colaborador->id));
        }

        return $resumenes;
    }

    /**
     * DTO estable para web/app. $conContenido agrega las secciones del
     * descriptivo oficial (lo que el PDF trae, tal cual) para leerlo.
     *
     * @return array<string, mixed>
     */
    public function aArray(AcuseDescriptivoPuesto $acuse, bool $conContenido = false): array
    {
        $acuse->loadMissing(['remitente:id,name,apellidos,colaborador_id', 'descriptivo']);
        $estado = $acuse->estado();
        $descriptivo = $acuse->descriptivo;

        $datos = [
            'id' => $acuse->id,
            'colaborador_id' => $acuse->colaborador_id,
            'estado' => $estado->value,
            'estado_etiqueta' => $estado->etiqueta(),
            'enviado_por' => $acuse->remitente->nombreCompleto(),
            'enviado_en' => $acuse->enviado_en->toIso8601String(),
            'leido_en' => $acuse->leido_en?->toIso8601String(),
            'acusado_en' => $acuse->acusado_en?->toIso8601String(),
            'enterado' => $acuse->enterado,
            'comentario' => $acuse->comentario,
            'descriptivo' => [
                'id' => $descriptivo->id,
                'nombre_puesto' => $descriptivo->nombre_puesto,
                'version' => $acuse->descriptivo_version,
            ],
        ];

        if ($conContenido) {
            $datos['descriptivo'] += $this->contenido($descriptivo);
        }

        return $datos;
    }

    /**
     * Las secciones del descriptivo oficial que le dicen al colaborador qué
     * hace y qué le corresponde — solo lo que trae el PDF (las vacías no se
     * mandan). Edad, sexo, formación y experiencia son requisitos de
     * reclutamiento: no van aquí.
     *
     * @return array{area: string|null, objetivo: string|null, personal_a_cargo: string|null, fecha: string|null, secciones: list<array{clave: string, titulo: string, elementos: list<string>}>}
     */
    private function contenido(DescriptivoPuesto $descriptivo): array
    {
        $d = $descriptivo->datos;
        $texto = fn (mixed $v): ?string => is_string($v) && trim($v) !== '' ? trim($v) : null;
        $lista = fn (mixed $v): array => array_values(array_filter(
            array_map(fn (mixed $x): ?string => $texto($x), is_array($v) ? $v : []),
            fn (?string $x): bool => $x !== null,
        ));
        $pares = fn (mixed $filas, string $nombre, string $detalle) => array_values(array_filter(array_map(
            function (mixed $fila) use ($texto, $nombre, $detalle): ?string {
                if (! is_array($fila) || $texto($fila[$nombre] ?? null) === null) {
                    return null;
                }

                $principal = (string) $texto($fila[$nombre]);
                $extra = $texto($fila[$detalle] ?? null);

                return $extra !== null ? sprintf('%s: %s', $principal, $extra) : $principal;
            },
            is_array($filas) ? $filas : [],
        ), fn (?string $x): bool => $x !== null));

        $secciones = [
            ['clave' => 'funciones', 'titulo' => 'Tus funciones', 'elementos' => $lista($d['funciones'] ?? [])],
            ['clave' => 'actividades_criticas', 'titulo' => 'Actividades críticas en ausencia', 'elementos' => $lista($d['actividades_criticas'] ?? [])],
            ['clave' => 'responsables_ausencia', 'titulo' => 'Responsables en ausencia', 'elementos' => $lista($d['responsables_ausencia'] ?? [])],
            ['clave' => 'facultades', 'titulo' => 'Facultades', 'elementos' => $lista($d['facultades'] ?? [])],
            ['clave' => 'conocimientos', 'titulo' => 'Conocimientos', 'elementos' => $pares($d['conocimientos'] ?? [], 'conocimiento', 'funcion')],
            ['clave' => 'competencias', 'titulo' => 'Competencias', 'elementos' => $pares($d['competencias'] ?? [], 'competencia', 'funcion')],
            ['clave' => 'plan_formacion', 'titulo' => 'Plan de formación', 'elementos' => $lista($d['plan_formacion'] ?? [])],
            ['clave' => 'compromisos_culturales', 'titulo' => 'Compromisos culturales y de imagen', 'elementos' => $lista($d['compromisos_culturales'] ?? [])],
        ];

        $metadatos = is_array($d['metadatos'] ?? null) ? $d['metadatos'] : [];

        return [
            'area' => $texto($d['area'] ?? null),
            'objetivo' => $texto($d['objetivo'] ?? null),
            'personal_a_cargo' => $texto($d['personal_a_cargo'] ?? null),
            'fecha' => $texto($metadatos['fecha_revision'] ?? null) ?? $texto($metadatos['fecha_elaboracion'] ?? null),
            'secciones' => array_values(array_filter($secciones, fn (array $s): bool => $s['elementos'] !== [])),
        ];
    }

    /**
     * @return array{acuse: array<string, mixed>|null, puede_enviar: bool, motivo: string|null}
     */
    private function armarResumenRh(Colaborador $colaborador, User $actor, ?AcuseDescriptivoPuesto $ultimo): array
    {
        $puedeGestionar = $actor->can(self::PERMISO)
            && $actor->colaborador_id !== $colaborador->id
            && $this->alcance->alcanzaColaboradorEnModulo($actor, $colaborador, self::MODULO);
        $motivo = $puedeGestionar ? $this->motivoNoDisponible($colaborador) : null;

        // Ya enterado de la versión vigente: no hay nada que reenviar.
        $vigente = $colaborador->puesto_id !== null ? $this->descriptivos->vigenteDePuesto($colaborador->puesto_id) : null;
        $yaEnterado = $ultimo !== null && $ultimo->acusado_en !== null && $vigente !== null && $ultimo->descriptivo_puesto_id === $vigente->id;

        return [
            'acuse' => $ultimo !== null ? $this->aArray($ultimo) : null,
            'puede_enviar' => $puedeGestionar && $motivo === null && ! $yaEnterado,
            'motivo' => $motivo,
        ];
    }

    /**
     * RH con onboarding.gestionar que alcanza al colaborador (nunca a sí mismo).
     *
     * @throws AuthorizationException
     */
    public function autorizarGestion(User $actor, Colaborador $colaborador): void
    {
        if (! $actor->can(self::PERMISO)) {
            throw new AuthorizationException('No tienes permiso para enviar el descriptivo de puesto.');
        }

        if ($actor->colaborador_id === $colaborador->id || ! $this->alcance->alcanzaColaboradorEnModulo($actor, $colaborador, self::MODULO)) {
            throw new AuthorizationException('Este colaborador está fuera de tu alcance.');
        }
    }

    private function exigirTitular(AcuseDescriptivoPuesto $acuse, User $usuario): void
    {
        if ($usuario->colaborador_id === null || $usuario->colaborador_id !== $acuse->colaborador_id) {
            throw new AuthorizationException('Este descriptivo de puesto no es tuyo.');
        }
    }
}
