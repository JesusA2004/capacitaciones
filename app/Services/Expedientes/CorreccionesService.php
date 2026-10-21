<?php

namespace App\Services\Expedientes;

use App\Enums\EstadoCambioFoto;
use App\Enums\EstadoDocumento;
use App\Enums\EstadoSolicitudInterna;
use App\Enums\TipoSolicitudInterna;
use App\Models\CambioFotoPerfil;
use App\Models\EmployeeDocument;
use App\Models\SolicitudInterna;
use App\Models\SolicitudInternaHistorial;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Configuracion\WorkflowRoutingService;
use App\Services\Notificaciones\Aviso;
use App\Services\Notificaciones\NotificacionEventosService;
use App\Services\Solicitudes\SolicitudesService;
use App\Services\Solicitudes\TareasSolicitudService;
use App\Support\OrigenPeticion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Correcciones del expediente pedidas por el colaborador, con seguimiento
 * (docs/SOLICITUDES_UNIFICADAS.md, «Correcciones solicitadas por el
 * colaborador»). Una corrección es:
 *
 *  - una solicitud interna de actualización de datos (personales,
 *    domicilio, contacto de emergencia, beneficiario, datos médicos
 *    cifrados), de cuenta bancaria o de reposición documental;
 *  - un documento del expediente que RH rechazó o pidió corregir;
 *  - un cambio de foto de perfil (pendiente o rechazado).
 *
 * El colaborador ve un estado simple (enviada, en revisión, aprobada,
 * rechazada, requiere información) con su línea de tiempo y el comentario
 * de Recursos Humanos, y puede RESPONDER cuando RH pide más información:
 * la solicitud vuelve a la bandeja de RH con un aviso nuevo.
 *
 * Web (Mis solicitudes / Mi expediente) y API móvil leen de aquí: nunca dos
 * reglas distintas por canal.
 */
class CorreccionesService
{
    /** Tipos de solicitud que son corrección del expediente. */
    public const TIPOS = [
        TipoSolicitudInterna::ActualizacionDatos,
        TipoSolicitudInterna::ActualizacionBancaria,
        TipoSolicitudInterna::ReposicionDocumental,
    ];

    /** Estados que ve el colaborador (contrato de la API). */
    public const ESTADOS = [
        'enviada' => 'Enviada',
        'en_revision' => 'En revisión',
        'aprobada' => 'Aprobada',
        'rechazada' => 'Rechazada',
        'requiere_informacion' => 'Requiere información',
        'cancelada' => 'Cancelada',
    ];

    /** Cuántas correcciones resueltas se siguen mostrando (las abiertas siempre). */
    private const LIMITE_RESUELTAS = 20;

    public function __construct(
        private readonly ActualizacionDatosService $actualizacionDatos,
        private readonly NotificacionEventosService $eventos,
        private readonly WorkflowRoutingService $routing,
        private readonly TareasSolicitudService $tareas,
        private readonly ExpedienteService $expediente,
        private readonly AuditoriaService $auditoria,
    ) {}

    public function esCorreccion(SolicitudInterna $solicitud): bool
    {
        return in_array($solicitud->tipo, self::TIPOS, true);
    }

    /**
     * Estado del ciclo de la corrección en palabras del colaborador.
     *
     * @return array{clave: string, etiqueta: string, descripcion: string}
     */
    public function estado(SolicitudInterna $solicitud): array
    {
        $clave = match ($solicitud->estado) {
            EstadoSolicitudInterna::Creada, EstadoSolicitudInterna::Enviada => 'enviada',
            EstadoSolicitudInterna::EnRevision => 'en_revision',
            EstadoSolicitudInterna::Aprobada => 'aprobada',
            EstadoSolicitudInterna::Rechazada => 'rechazada',
            EstadoSolicitudInterna::RequiereCorreccion => 'requiere_informacion',
            EstadoSolicitudInterna::Cancelada => 'cancelada',
        };

        $descripcion = match ($clave) {
            'enviada' => 'Recursos Humanos la recibió y la revisará pronto.',
            'en_revision' => 'Recursos Humanos la está revisando.',
            'aprobada' => $solicitud->tipo === TipoSolicitudInterna::ActualizacionDatos
                ? 'Recursos Humanos la aprobó y tu expediente ya está actualizado.'
                : 'Recursos Humanos la aprobó.',
            'rechazada' => 'Recursos Humanos no la aprobó; revisa el motivo.',
            'requiere_informacion' => 'Recursos Humanos necesita más información: respóndele para que siga su revisión.',
            default => 'La cancelaste; no se aplicó ningún cambio.',
        };

        return ['clave' => $clave, 'etiqueta' => self::ESTADOS[$clave], 'descripcion' => $descripcion];
    }

    /**
     * Correcciones del colaborador de la cuenta (las abiertas primero) y
     * su contador. Solo las propias: nunca recibe un id ajeno.
     *
     * @return array{items: list<array<string, mixed>>, conteo: array{requieren_accion: int, en_proceso: int, abiertas: int}}
     */
    public function deColaborador(User $usuario): array
    {
        $items = [
            ...$this->solicitudesDe($usuario)->map(fn (SolicitudInterna $s): array => $this->aArray($s, $usuario))->all(),
            ...$this->documentosPorCorregir($usuario),
            ...$this->fotos($usuario),
        ];

        usort($items, function (array $a, array $b): int {
            $peso = fn (array $i): int => $i['requiere_accion'] ? 0 : (in_array($i['estado'], ['enviada', 'en_revision'], true) ? 1 : 2);

            return [$peso($a), $b['actualizada_en'] ?? ''] <=> [$peso($b), $a['actualizada_en'] ?? ''];
        });

        return ['items' => $items, 'conteo' => $this->contar($items)];
    }

    /**
     * Solo el contador (badge de la app y de «Mis solicitudes»).
     *
     * @return array{requieren_accion: int, en_proceso: int, abiertas: int}
     */
    public function conteo(User $usuario): array
    {
        try {
            return $this->deColaborador($usuario)['conteo'];
        } catch (Throwable $e) {
            Log::warning('CorreccionesService: no se pudo contar correcciones.', ['user_id' => $usuario->id, 'error' => $e->getMessage()]);

            return ['requieren_accion' => 0, 'en_proceso' => 0, 'abiertas' => 0];
        }
    }

    /**
     * Una corrección (solicitud) con su seguimiento completo.
     *
     * @return array<string, mixed>
     */
    public function aArray(SolicitudInterna $solicitud, ?User $lector = null): array
    {
        $solicitud->loadMissing(['historial.usuario:id,name,apellidos', 'revisadoPor:id,name,apellidos']);
        $estado = $this->estado($solicitud);
        $requiereAccion = $estado['clave'] === 'requiere_informacion';

        return [
            'id' => sprintf('solicitud-%d', $solicitud->id),
            'origen' => 'solicitud',
            'recurso_id' => $solicitud->id,
            'folio' => $solicitud->folio,
            'tipo' => $solicitud->tipo->value,
            'titulo' => $this->actualizacionDatos->etiqueta($solicitud) ?? $solicitud->tipo->etiqueta(),
            'estado' => $estado['clave'],
            'estado_etiqueta' => $estado['etiqueta'],
            'estado_descripcion' => $estado['descripcion'],
            'comentario_rh' => $this->comentarioRh($solicitud),
            'requiere_accion' => $requiereAccion,
            'puede_responder' => $requiereAccion && ($lector === null || $solicitud->esDe($lector)),
            'campos' => $this->camposVisibles($solicitud),
            'resuelta_por' => $solicitud->revisadoPor !== null ? trim(sprintf('%s %s', $solicitud->revisadoPor->name, $solicitud->revisadoPor->apellidos ?? '')) : null,
            'resuelta_en' => $solicitud->revisado_en?->toIso8601String(),
            'creada_en' => $solicitud->created_at?->toIso8601String(),
            'actualizada_en' => $solicitud->updated_at?->toIso8601String(),
            'url' => route('solicitudes.show', $solicitud->id, false),
            'api' => [
                'detalle' => route('api.v1.solicitudes.show', $solicitud->id, false),
                'responder' => $requiereAccion ? route('api.v1.solicitudes.responder', $solicitud->id, false) : null,
            ],
            'historial' => $this->historial($solicitud),
        ];
    }

    /**
     * Línea de tiempo simple: qué pasó, quién, cuándo, desde dónde y con
     * qué comentario (nunca IP ni user agent: esos quedan en auditoría).
     *
     * @return list<array{accion: string, etiqueta: string, por: string|null, es_rh: bool, comentario: string|null, origen: string|null, origen_etiqueta: string|null, en: string|null}>
     */
    public function historial(SolicitudInterna $solicitud): array
    {
        $solicitud->loadMissing('historial.usuario:id,name,apellidos');

        return array_values($solicitud->historial
            ->sortBy('id')
            ->reject(fn (SolicitudInternaHistorial $h): bool => $h->accion === 'creada')
            ->map(fn (SolicitudInternaHistorial $h): array => [
                'accion' => $h->accion,
                'etiqueta' => $this->etiquetaPaso($h->accion),
                'por' => $h->usuario !== null ? trim(sprintf('%s %s', $h->usuario->name, $h->usuario->apellidos ?? '')) : null,
                'es_rh' => $h->user_id !== null && $h->user_id !== $solicitud->user_id,
                'comentario' => $h->comentario,
                'origen' => $h->origen,
                'origen_etiqueta' => OrigenPeticion::etiqueta($h->origen),
                'en' => $h->created_at->toIso8601String(),
            ])
            ->all());
    }

    /**
     * El colaborador responde lo que RH pidió (y, si quiere, corrige los
     * datos propuestos). La solicitud vuelve a «Enviada», RH recibe un aviso
     * nuevo con enlace directo y su pendiente se reabre.
     *
     * @param  array<string, mixed>  $datos  Datos propuestos corregidos (solo actualización de datos).
     *
     * @throws ValidationException
     */
    public function responder(SolicitudInterna $solicitud, User $usuario, string $mensaje, array $datos = []): SolicitudInterna
    {
        if (! $solicitud->esDe($usuario)) {
            throw ValidationException::withMessages(['mensaje' => 'Solo quien hizo la solicitud puede responderla.']);
        }

        if ($solicitud->estado !== EstadoSolicitudInterna::RequiereCorreccion) {
            throw ValidationException::withMessages(['mensaje' => sprintf('Esta solicitud está «%s»: Recursos Humanos no espera una respuesta tuya.', $this->estado($solicitud)['etiqueta'])]);
        }

        $mensaje = trim($mensaje);

        if (mb_strlen($mensaje) < 3) {
            throw ValidationException::withMessages(['mensaje' => 'Escribe tu respuesta para Recursos Humanos.']);
        }

        $preparados = null;

        if ($datos !== []) {
            if ($solicitud->tipo !== TipoSolicitudInterna::ActualizacionDatos) {
                throw ValidationException::withMessages(['datos' => 'Solo una actualización de datos lleva datos propuestos.']);
            }

            $preparados = $this->actualizacionDatos->preparar($datos, $usuario->colaborador);
        }

        $historial = app(SolicitudesService::class);
        $rhQuePidio = $solicitud->historial()->where('accion', EstadoSolicitudInterna::RequiereCorreccion->value)->latest('id')->first()?->usuario;

        $solicitud = DB::transaction(function () use ($solicitud, $usuario, $mensaje, $preparados, $historial): SolicitudInterna {
            $cambios = ['estado' => EstadoSolicitudInterna::Enviada];

            if ($preparados !== null) {
                $cambios['datos_propuestos'] = $preparados['propuestos'];
                $cambios['datos_sensibles_propuestos'] = $preparados['sensibles'];
                $cambios['datos_base'] = $preparados['base'];
            }

            $solicitud->update($cambios);
            $historial->registrarHistorial($solicitud, $usuario, 'respuesta_colaborador', $mensaje);

            if ($preparados !== null) {
                $historial->registrarHistorial($solicitud, $usuario, 'datos_corregidos', implode(', ', $this->camposVisibles($solicitud)));
            }

            $historial->registrarHistorial($solicitud, $usuario, EstadoSolicitudInterna::Enviada->value);

            return $solicitud->refresh();
        });

        $this->auditoria->registrar('correccion_respondida', $solicitud, $usuario, [
            'modulo' => 'solicitudes',
            'folio' => $solicitud->folio,
            'datos_corregidos' => $preparados !== null ? $this->camposVisibles($solicitud) : [],
        ]);

        // Un fallo al avisar nunca deshace la respuesta.
        try {
            $persona = $solicitud->personaSolicitante();
            $responsables = $this->responsables($solicitud, $usuario);

            if ($rhQuePidio instanceof User && $rhQuePidio->id !== $usuario->id) {
                $responsables->push($rhQuePidio);
            }

            $ocurrencia = sprintf('respuesta%d', (int) $solicitud->historial()->max('id'));

            $this->eventos->registrarConPendiente(
                $usuario,
                new Aviso('correccion_respondida', 'solicitud', 'Respuesta enviada', sprintf('Tu respuesta sobre %s llegó a Recursos Humanos; te avisaremos cuando la revisen.', $solicitud->folio), $solicitud, 'respondida', 'media', [], $ocurrencia),
                $responsables,
                new Aviso('correccion_respondida', 'rh_solicitud', 'Corrección respondida por revisar', sprintf('%s respondió a lo que pidió Recursos Humanos en %s.', $persona?->nombreCompleto() ?? 'El colaborador', $solicitud->folio), $solicitud, 'revisar_solicitud', 'media', [], $ocurrencia),
            );

            $this->tareas->alResponderCorreccion($solicitud, $usuario);
        } catch (Throwable $e) {
            Log::warning('CorreccionesService: fallo al avisar la respuesta.', ['solicitud_id' => $solicitud->id, 'error' => $e->getMessage()]);
        }

        return $solicitud;
    }

    /**
     * PUNTO ÚNICO de integración para saber quién atiende una corrección.
     * Hoy: la regla «solicitud_creada» de Configuración → Notificaciones
     * (quien tiene rh.solicitudes.aprobar con alcance sobre la persona; sin
     * nadie, RH). Las correcciones no llevan visto bueno de jefe
     * (config/solicitudes.php → visto_bueno_jefe), así que no hay pasos
     * previos; si el motor central de aprobaciones define un responsable
     * para correcciones, solo cambia este método.
     *
     * @return Collection<int, User>
     */
    public function responsables(SolicitudInterna $solicitud, ?User $solicitante = null): Collection
    {
        $solicitante ??= $solicitud->usuario;

        return $this->routing->resolver('solicitud_creada', $solicitud->personaSolicitante(), [
            'solicitante' => $solicitante,
            'excluir' => $solicitante !== null ? [$solicitante->id] : [],
        ])->pluck('usuario')->filter(fn (mixed $u): bool => $u instanceof User)->values();
    }

    /**
     * Aviso para quien revisa, al crearse la corrección: qué datos (nunca
     * sus valores; un dato médico ni siquiera por nombre) y de quién.
     */
    public function avisoPendiente(SolicitudInterna $solicitud): Aviso
    {
        $persona = $solicitud->personaSolicitante()?->nombreCompleto() ?? 'Un colaborador';
        $campos = $this->camposVisibles($solicitud);

        $mensaje = $campos !== []
            ? sprintf('%s pidió %s: %s.', $persona, $this->verbo($solicitud), implode(', ', $campos))
            : sprintf('%s envió una solicitud de %s.', $persona, mb_strtolower($solicitud->tipo->etiqueta()));

        return new Aviso('solicitud_creada', 'rh_solicitud', 'Corrección de datos por revisar', $mensaje, $solicitud, 'revisar_solicitud');
    }

    /**
     * Aviso al colaborador cuando RH resuelve, pide información o la pasa a
     * revisión. $comentario es el de RH (motivo de rechazo o lo que falta).
     */
    public function avisoResultado(SolicitudInterna $solicitud, ?string $comentario): Aviso
    {
        $comentario = $comentario !== null && trim($comentario) !== '' ? mb_strimwidth(trim($comentario), 0, 180, '…') : null;
        $folio = $solicitud->folio;

        [$titulo, $mensaje] = match ($solicitud->estado) {
            EstadoSolicitudInterna::Aprobada => ['Corrección aprobada', $solicitud->tipo === TipoSolicitudInterna::ActualizacionDatos
                ? sprintf('Recursos Humanos aprobó tu solicitud %s; tu expediente ya está actualizado.', $folio)
                : sprintf('Recursos Humanos aprobó tu solicitud %s.', $folio)],
            EstadoSolicitudInterna::Rechazada => ['Corrección rechazada', $comentario !== null
                ? sprintf('Recursos Humanos rechazó tu solicitud %s. Motivo: %s', $folio, $comentario)
                : sprintf('Recursos Humanos rechazó tu solicitud %s.', $folio)],
            EstadoSolicitudInterna::RequiereCorreccion => ['Recursos Humanos necesita más información', $comentario !== null
                ? sprintf('Sobre tu solicitud %s: %s Respóndele desde Mis solicitudes.', $folio, $comentario)
                : sprintf('Recursos Humanos necesita más información sobre tu solicitud %s. Respóndele desde Mis solicitudes.', $folio)],
            default => ['Tu corrección está en revisión', sprintf('Recursos Humanos está revisando tu solicitud %s.', $folio)],
        };

        return new Aviso('solicitud_actualizada', 'solicitud', $titulo, $mensaje, $solicitud, $solicitud->estado->value, $solicitud->estado === EstadoSolicitudInterna::RequiereCorreccion ? 'alta' : 'media', ['estado' => $solicitud->estado->value]);
    }

    /**
     * @return Collection<int, SolicitudInterna>
     */
    private function solicitudesDe(User $usuario): Collection
    {
        $base = fn (): Builder => SolicitudInterna::query()
            ->whereIn('tipo', array_map(fn (TipoSolicitudInterna $t): string => $t->value, self::TIPOS))
            ->where(function (Builder $q) use ($usuario): void {
                $q->where('user_id', $usuario->id);

                if ($usuario->colaborador_id !== null) {
                    $q->orWhere('colaborador_id', $usuario->colaborador_id);
                }
            })
            ->with(['historial.usuario:id,name,apellidos', 'revisadoPor:id,name,apellidos']);

        $finales = [EstadoSolicitudInterna::Aprobada->value, EstadoSolicitudInterna::Rechazada->value, EstadoSolicitudInterna::Cancelada->value];

        return $base()->whereNotIn('estado', $finales)->orderByDesc('updated_at')->get()
            ->concat($base()->whereIn('estado', $finales)->orderByDesc('updated_at')->limit(self::LIMITE_RESUELTAS)->get());
    }

    /**
     * Documentos requeridos del expediente que RH rechazó o pidió corregir:
     * el colaborador debe volver a subirlos.
     *
     * @return list<array<string, mixed>>
     */
    private function documentosPorCorregir(User $usuario): array
    {
        $colaborador = $usuario->colaborador;

        if ($colaborador === null) {
            return [];
        }

        $requeridos = $this->expediente->tiposRequeridosPara($colaborador)->pluck('id')->all();

        return array_values($this->expediente->documentosVigentes($colaborador)
            ->filter(fn (EmployeeDocument $d): bool => in_array($d->document_type_id, $requeridos, true)
                && in_array($d->status, [EstadoDocumento::Rechazado, EstadoDocumento::RequiereCorreccion], true))
            ->map(function (EmployeeDocument $d): array {
                $rechazado = $d->status === EstadoDocumento::Rechazado;
                $estado = $rechazado ? 'rechazada' : 'requiere_informacion';
                $revisor = $d->revisadoPor !== null ? trim(sprintf('%s %s', $d->revisadoPor->name, $d->revisadoPor->apellidos ?? '')) : null;

                return [
                    'id' => sprintf('documento-%d', $d->id),
                    'origen' => 'documento',
                    'recurso_id' => $d->id,
                    'folio' => null,
                    'tipo' => 'documento',
                    'titulo' => sprintf('Documento: %s', $d->tipo->nombre ?? 'del expediente'),
                    'estado' => $estado,
                    'estado_etiqueta' => self::ESTADOS[$estado],
                    'estado_descripcion' => $rechazado
                        ? 'Recursos Humanos rechazó este documento: vuelve a subirlo corregido.'
                        : 'Recursos Humanos pidió corregir este documento: vuelve a subirlo.',
                    'comentario_rh' => $d->rejection_reason ?? $d->comments,
                    'requiere_accion' => true,
                    'puede_responder' => false,
                    'campos' => [$d->tipo->nombre ?? 'Documento'],
                    'resuelta_por' => $revisor,
                    'resuelta_en' => $d->reviewed_at?->toIso8601String(),
                    'creada_en' => $d->created_at?->toIso8601String(),
                    'actualizada_en' => ($d->reviewed_at ?? $d->updated_at)?->toIso8601String(),
                    'url' => route('mi-expediente', ['tab' => 'documentos'], false),
                    'api' => ['detalle' => null, 'responder' => null],
                    'historial' => $this->pasosSimples('Documento enviado', $d->created_at?->toIso8601String(), $estado, $rechazado ? 'Rechazado' : 'Requiere corrección', $revisor, $d->rejection_reason ?? $d->comments, $d->reviewed_at?->toIso8601String()),
                ];
            })
            ->all());
    }

    /**
     * El último cambio de foto propuesto, si sigue pendiente o se resolvió
     * en los últimos 30 días.
     *
     * @return list<array<string, mixed>>
     */
    private function fotos(User $usuario): array
    {
        if ($usuario->colaborador_id === null) {
            return [];
        }

        $cambio = CambioFotoPerfil::query()
            ->where('colaborador_id', $usuario->colaborador_id)
            ->where(fn (Builder $q) => $q->where('estado', EstadoCambioFoto::Pendiente->value)->orWhere('updated_at', '>=', now()->subDays(30)))
            ->with('revisadoPor:id,name,apellidos')
            ->latest('id')
            ->first();

        if ($cambio === null) {
            return [];
        }

        $estado = match ($cambio->estado) {
            EstadoCambioFoto::Pendiente => 'enviada',
            EstadoCambioFoto::Aprobado => 'aprobada',
            EstadoCambioFoto::Rechazado => 'rechazada',
        };
        $revisor = $cambio->revisadoPor !== null ? trim(sprintf('%s %s', $cambio->revisadoPor->name, $cambio->revisadoPor->apellidos ?? '')) : null;

        return [[
            'id' => sprintf('foto-%d', $cambio->id),
            'origen' => 'foto',
            'recurso_id' => $cambio->id,
            'folio' => null,
            'tipo' => 'foto_perfil',
            'titulo' => 'Cambio de foto de perfil',
            'estado' => $estado,
            'estado_etiqueta' => self::ESTADOS[$estado],
            'estado_descripcion' => match ($estado) {
                'enviada' => 'Recursos Humanos revisará tu nueva foto.',
                'aprobada' => 'Tu nueva foto ya está en tu perfil.',
                default => 'Recursos Humanos no aprobó la foto: sube otra.',
            },
            'comentario_rh' => $cambio->motivo_rechazo,
            'requiere_accion' => $estado === 'rechazada',
            'puede_responder' => false,
            'campos' => ['Foto de perfil'],
            'resuelta_por' => $revisor,
            'resuelta_en' => $cambio->revisado_en?->toIso8601String(),
            'creada_en' => $cambio->created_at->toIso8601String(),
            'actualizada_en' => $cambio->updated_at->toIso8601String(),
            'url' => route('mi-expediente', [], false),
            'api' => ['detalle' => null, 'responder' => null],
            'historial' => $this->pasosSimples('Foto enviada', $cambio->created_at->toIso8601String(), $estado, $estado === 'aprobada' ? 'Aprobada' : 'Rechazada', $revisor, $cambio->motivo_rechazo, $cambio->revisado_en?->toIso8601String()),
        ]];
    }

    /**
     * Línea de tiempo de dos pasos (envío y, si ya hubo, la revisión de RH)
     * para documentos y fotos, que no tienen historial propio.
     *
     * @return list<array{accion: string, etiqueta: string, por: string|null, es_rh: bool, comentario: string|null, origen: null, origen_etiqueta: null, en: string|null}>
     */
    private function pasosSimples(string $etiquetaEnvio, ?string $enviadoEn, string $estado, string $etiquetaRevision, ?string $revisor, ?string $comentario, ?string $revisadoEn): array
    {
        $pasos = [['accion' => 'enviada', 'etiqueta' => $etiquetaEnvio, 'por' => null, 'es_rh' => false, 'comentario' => null, 'origen' => null, 'origen_etiqueta' => null, 'en' => $enviadoEn]];

        if ($revisadoEn !== null) {
            $pasos[] = ['accion' => $estado, 'etiqueta' => $etiquetaRevision, 'por' => $revisor, 'es_rh' => true, 'comentario' => $comentario, 'origen' => null, 'origen_etiqueta' => null, 'en' => $revisadoEn];
        }

        return $pasos;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{requieren_accion: int, en_proceso: int, abiertas: int}
     */
    private function contar(array $items): array
    {
        $requieren = count(array_filter($items, fn (array $i): bool => (bool) $i['requiere_accion']));
        $enProceso = count(array_filter($items, fn (array $i): bool => in_array($i['estado'], ['enviada', 'en_revision'], true)));

        return ['requieren_accion' => $requieren, 'en_proceso' => $enProceso, 'abiertas' => $requieren + $enProceso];
    }

    /**
     * Comentario más reciente de Recursos Humanos (quien no es el
     * solicitante): lo que falta, el motivo del rechazo o la nota al aprobar.
     */
    private function comentarioRh(SolicitudInterna $solicitud): ?string
    {
        $ultimo = $solicitud->historial
            ->sortByDesc('id')
            ->first(fn (SolicitudInternaHistorial $h): bool => $h->user_id !== $solicitud->user_id && $h->comentario !== null && trim($h->comentario) !== '');

        return $ultimo->comentario ?? $solicitud->motivo_rechazo;
    }

    /**
     * Datos que pide corregir, por etiqueta. Los médicos se nombran solo
     * como «Datos médicos» (sin decir cuál).
     *
     * @return list<string>
     */
    private function camposVisibles(SolicitudInterna $solicitud): array
    {
        $etiquetas = [];
        $medicos = false;

        foreach (array_keys($this->actualizacionDatos->propuestosDe($solicitud)) as $campo) {
            if ($this->actualizacionDatos->esSensible($campo)) {
                $medicos = true;

                continue;
            }

            $etiquetas[] = $this->actualizacionDatos->capturables()[$campo] ?? $campo;
        }

        if ($medicos) {
            $etiquetas[] = 'Datos médicos (confidencial)';
        }

        return $etiquetas;
    }

    private function verbo(SolicitudInterna $solicitud): string
    {
        return match ($this->actualizacionDatos->clase($solicitud)) {
            'completar' => 'completar',
            'cambio' => 'cambiar',
            default => 'completar o cambiar',
        };
    }

    private function etiquetaPaso(string $accion): string
    {
        return match ($accion) {
            EstadoSolicitudInterna::Enviada->value => 'Enviada a Recursos Humanos',
            EstadoSolicitudInterna::EnRevision->value => 'En revisión',
            EstadoSolicitudInterna::Aprobada->value => 'Aprobada',
            EstadoSolicitudInterna::Rechazada->value => 'Rechazada',
            EstadoSolicitudInterna::RequiereCorreccion->value => 'Recursos Humanos pidió más información',
            EstadoSolicitudInterna::Cancelada->value => 'Cancelada',
            default => SolicitudInternaHistorial::etiquetaDe($accion),
        };
    }
}
