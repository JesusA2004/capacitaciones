<?php

namespace App\Services\Reclutamiento;

use App\Enums\AlcanceDatos;
use App\Enums\EstadoAgendaEvento;
use App\Enums\EstadoCandidato;
use App\Enums\EstadoUsuario;
use App\Enums\FuenteCandidato;
use App\Enums\MotivoNoViableAgenda;
use App\Enums\OrigenAgendaEvento;
use App\Enums\ResultadoAgendaEvento;
use App\Enums\ResultadoEtapaCandidato;
use App\Enums\TipoAgendaEvento;
use App\Models\AgendaEvento;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\Puesto;
use App\Models\User;
use App\Services\CicloLaboral\OrganizacionJerarquiaService;
use App\Services\Notificaciones\Aviso;
use App\Services\Notificaciones\NotificacionEventosService;
use App\Services\Permisos\AlcanceModuloService;
use App\Services\Permisos\PermisoAccionService;
use App\Support\FechaMexico;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Agenda de entrevistas y estudios socioeconómicos: la capa de citas sobre el
 * recorrido del candidato. Es la única regla para web y API.
 *
 * La agenda NO avanza al candidato por su cuenta: el resultado se delega en
 * CandidatoWorkflowService (registrarEntrevista, registrarSocioeconomico,
 * revisarSocioeconomico), que valida permiso del paso, alcance y estado.
 *
 * Reprogramar crea una cita nueva ligada a la anterior, que conserva su
 * historial; ver AgendaConsultaService para cómo se cuenta cada una.
 */
class AgendaEventoService
{
    public function __construct(
        private readonly CandidatoWorkflowService $workflow,
        private readonly ExpedienteCandidatoService $expediente,
        private readonly NotificacionEventosService $avisos,
        private readonly OrganizacionJerarquiaService $jerarquia,
        private readonly AlcanceModuloService $alcance,
        private readonly PermisoAccionService $permisos,
    ) {}

    /**
     * @param  array<string, mixed>  $datos  sucursal_id, tipo, fecha, hora, candidato_id | candidato_nuevo, notas?, domicilio?, responsable_user_id?, clave_idempotencia?
     * @return array{evento: AgendaEvento, repetido: bool, empalmes: list<string>}
     */
    public function agendar(array $datos, User $actor, ?UploadedFile $cv = null): array
    {
        $sucursalId = (int) $datos['sucursal_id'];
        Gate::forUser($actor)->authorize('agendarEn', [AgendaEvento::class, $sucursalId]);

        $clave = isset($datos['clave_idempotencia']) && is_string($datos['clave_idempotencia']) ? $datos['clave_idempotencia'] : null;
        $repetido = $this->porClave($clave);

        if ($repetido !== null) {
            return ['evento' => $repetido, 'repetido' => true, 'empalmes' => []];
        }

        $tipo = TipoAgendaEvento::from((string) $datos['tipo']);
        $fechaHora = $this->fechaHoraFutura((string) $datos['fecha'], (string) $datos['hora']);
        $responsableId = isset($datos['responsable_user_id']) ? (int) $datos['responsable_user_id'] : null;
        $candidato = isset($datos['candidato_id'])
            ? Candidato::query()->findOrFail((int) $datos['candidato_id'])
            : $this->altaRapida((array) ($datos['candidato_nuevo'] ?? []), $sucursalId, $actor);

        $this->validarSucursalDelCandidato($candidato, $sucursalId);
        $empalmes = $this->empalmes($sucursalId, $fechaHora, $responsableId);

        try {
            $evento = DB::transaction(function () use ($candidato, $tipo, $sucursalId, $fechaHora, $actor, $datos, $clave, $responsableId): AgendaEvento {
                $bloqueado = Candidato::query()->lockForUpdate()->findOrFail($candidato->id);
                $this->validarEstadoParaAgendar($bloqueado, $tipo);

                if ($bloqueado->sucursal_id === null) {
                    $bloqueado->update(['sucursal_id' => $sucursalId]);
                }

                $evento = AgendaEvento::query()->create([
                    'candidato_id' => $bloqueado->id,
                    'sucursal_id' => $sucursalId,
                    'tipo' => $tipo,
                    'fecha_hora' => $fechaHora,
                    'estado' => EstadoAgendaEvento::Programado,
                    'origen' => $this->origenDe($actor),
                    'agendado_por' => $actor->id,
                    'responsable_user_id' => $responsableId,
                    'notas' => $this->texto($datos['notas'] ?? null),
                    'domicilio' => $this->texto($datos['domicilio'] ?? null),
                    'clave_idempotencia' => $clave,
                    'clave_activa' => $this->claveActiva($bloqueado->id, $tipo),
                ]);

                $this->bitacora($evento, 'agendado', null, EstadoAgendaEvento::Programado, null, $actor);

                if ($tipo === TipoAgendaEvento::Entrevista && $bloqueado->estado === EstadoCandidato::Recibidos) {
                    $this->workflow->pasarAEntrevistaPorAgenda($bloqueado, $actor);
                }

                return $evento;
            });
        } catch (UniqueConstraintViolationException) {
            $repetido = $this->porClave($clave);

            if ($repetido !== null) {
                return ['evento' => $repetido, 'repetido' => true, 'empalmes' => []];
            }

            throw ValidationException::withMessages(['candidato_id' => sprintf('Este candidato ya tiene un evento de %s programado: reprográmalo en lugar de crear otro.', mb_strtolower($tipo->etiqueta()))]);
        }

        if ($cv instanceof UploadedFile) {
            $this->expediente->guardarCv($candidato->refresh(), $cv, $actor);
        }

        $this->avisar($evento, 'agenda_evento_agendado', sprintf('Nueva %s agendada', mb_strtolower($tipo->etiqueta())), 'agendado', $actor);

        return ['evento' => $evento, 'repetido' => false, 'empalmes' => $empalmes];
    }

    /**
     * @return array{evento: AgendaEvento, empalmes: list<string>}
     */
    public function reprogramar(AgendaEvento $evento, string $fecha, string $hora, ?string $motivo, User $actor): array
    {
        Gate::forUser($actor)->authorize('gestionar', $evento);
        $fechaHora = $this->fechaHoraFutura($fecha, $hora);
        $empalmes = $this->empalmes($evento->sucursal_id, $fechaHora, $evento->responsable_user_id, $evento->id);

        $resultado = DB::transaction(function () use ($evento, $fechaHora, $motivo, $actor): array {
            $actual = AgendaEvento::query()->lockForUpdate()->findOrFail($evento->id);

            // Doble clic: la misma reprogramación devuelve la cita ya creada.
            if ($actual->reprogramado_a_id !== null) {
                $nuevo = AgendaEvento::query()->findOrFail($actual->reprogramado_a_id);

                if ($nuevo->fecha_hora->eq($fechaHora)) {
                    return ['evento' => $nuevo, 'nuevo' => false];
                }

                throw ValidationException::withMessages(['estado' => 'Esta cita ya se reprogramó. Abre la cita vigente.']);
            }

            if (! in_array($actual->estado, [EstadoAgendaEvento::Programado, EstadoAgendaEvento::NoAsistio], true)) {
                throw ValidationException::withMessages(['estado' => sprintf('Una cita «%s» no se puede reprogramar.', $actual->estado->etiqueta())]);
            }

            if ($actual->estado === EstadoAgendaEvento::Programado && $actual->fecha_hora->eq($fechaHora)) {
                throw ValidationException::withMessages(['fecha' => 'Elige una fecha u hora distinta a la actual.']);
            }

            $anterior = $actual->estado;
            // Una inasistencia sigue contando como tal; solo una cita viva pasa a «Reprogramado».
            $estadoAnterior = $anterior === EstadoAgendaEvento::Programado ? EstadoAgendaEvento::Reprogramado : $anterior;
            $actual->update(['estado' => $estadoAnterior, 'clave_activa' => null]);

            $nuevo = AgendaEvento::query()->create([
                ...$actual->only(['candidato_id', 'sucursal_id', 'tipo', 'origen', 'agendado_por', 'responsable_user_id', 'notas', 'domicilio']),
                'fecha_hora' => $fechaHora,
                'estado' => EstadoAgendaEvento::Programado,
                'reprogramado_de_id' => $actual->id,
                'clave_activa' => $this->claveActiva($actual->candidato_id, $actual->tipo),
            ]);

            $actual->update(['reprogramado_a_id' => $nuevo->id]);
            $this->bitacora($actual, 'reprogramado', $anterior, $estadoAnterior, $this->texto($motivo), $actor);
            $this->bitacora($nuevo, 'agendado_por_reprogramacion', null, EstadoAgendaEvento::Programado, sprintf('Viene de la cita del %s.', $actual->fecha_hora->format('d/m/Y H:i')), $actor);

            return ['evento' => $nuevo, 'nuevo' => true];
        });

        if ($resultado['nuevo']) {
            $this->avisar($resultado['evento'], 'agenda_evento_reprogramado', sprintf('%s reprogramada', $resultado['evento']->tipo->etiqueta()), 'reprogramado', $actor);
        }

        return ['evento' => $resultado['evento'], 'empalmes' => $resultado['nuevo'] ? $empalmes : []];
    }

    public function cancelar(AgendaEvento $evento, string $motivo, User $actor): AgendaEvento
    {
        Gate::forUser($actor)->authorize('gestionar', $evento);

        $cambio = DB::transaction(function () use ($evento, $motivo, $actor): bool {
            $actual = AgendaEvento::query()->lockForUpdate()->findOrFail($evento->id);

            if ($actual->estado === EstadoAgendaEvento::Cancelado) {
                return false;
            }

            if ($actual->estado !== EstadoAgendaEvento::Programado) {
                throw ValidationException::withMessages(['estado' => sprintf('Una cita «%s» no se puede cancelar.', $actual->estado->etiqueta())]);
            }

            $actual->update(['estado' => EstadoAgendaEvento::Cancelado, 'motivo_cancelacion' => $motivo, 'clave_activa' => null]);
            $this->bitacora($actual, 'cancelado', EstadoAgendaEvento::Programado, EstadoAgendaEvento::Cancelado, $motivo, $actor);

            return true;
        });

        $evento->refresh();

        if ($cambio) {
            $this->avisar($evento, 'agenda_evento_cancelado', sprintf('%s cancelada', $evento->tipo->etiqueta()), 'cancelado', $actor);
        }

        return $evento;
    }

    public function registrarInasistencia(AgendaEvento $evento, ?string $comentario, User $actor): AgendaEvento
    {
        Gate::forUser($actor)->authorize('registrarResultado', $evento);

        return DB::transaction(function () use ($evento, $comentario, $actor): AgendaEvento {
            $actual = AgendaEvento::query()->lockForUpdate()->findOrFail($evento->id);

            if ($actual->estado === EstadoAgendaEvento::NoAsistio) {
                return $actual;
            }

            $this->exigirProgramadoYDia($actual);

            $actual->update([
                'estado' => EstadoAgendaEvento::NoAsistio,
                'comentario_resultado' => $this->texto($comentario),
                'resultado_por' => $actor->id,
                'resultado_en' => $this->ahora(),
                'clave_activa' => null,
            ]);
            $this->bitacora($actual, 'no_asistio', EstadoAgendaEvento::Programado, EstadoAgendaEvento::NoAsistio, $this->texto($comentario), $actor);

            return $actual;
        });
    }

    /**
     * @param  array<string, mixed>  $datos  resultado (viable|no_viable), motivo?, comentario?
     */
    public function registrarResultadoEntrevista(AgendaEvento $evento, array $datos, User $actor): AgendaEvento
    {
        Gate::forUser($actor)->authorize('registrarResultado', $evento);
        $viable = (string) $datos['resultado'] === ResultadoAgendaEvento::Viable->value;
        $resultado = $viable ? ResultadoAgendaEvento::Viable : ResultadoAgendaEvento::NoViable;
        $comentario = $this->texto($datos['comentario'] ?? null);
        $motivo = ! $viable && isset($datos['motivo']) ? MotivoNoViableAgenda::tryFrom((string) $datos['motivo']) : null;

        if (! $viable && ($motivo === null || $comentario === null)) {
            throw ValidationException::withMessages(['motivo' => 'Elige el motivo y escribe un comentario breve.']);
        }

        return DB::transaction(function () use ($evento, $resultado, $viable, $motivo, $comentario, $actor): AgendaEvento {
            $actual = AgendaEvento::query()->lockForUpdate()->with('candidato')->findOrFail($evento->id);

            if ($actual->tipo !== TipoAgendaEvento::Entrevista) {
                throw ValidationException::withMessages(['resultado' => 'Esta cita es un estudio socioeconómico.']);
            }

            if ($actual->estado === EstadoAgendaEvento::Realizado && $actual->resultado === $resultado) {
                return $actual;
            }

            $this->exigirProgramadoYDia($actual);

            // Una cita de hoy aún no alcanzada se registra con la hora real.
            $realizada = $actual->fecha_hora->lt($this->ahora()) ? $actual->fecha_hora : $this->ahora();
            $observaciones = $motivo !== null ? sprintf('%s: %s', $motivo->etiqueta(), $comentario) : $comentario;

            $this->workflow->registrarEntrevista($actual->candidato, $actor, [
                'resultado' => $viable ? ResultadoEtapaCandidato::Viable->value : ResultadoEtapaCandidato::NoViable->value,
                'observaciones' => $observaciones,
                'realizada_en' => $realizada->toDateTimeString(),
            ]);

            return $this->cerrarConResultado($actual, $resultado, $motivo, $comentario, $actor);
        });
    }

    /**
     * Cargar el estudio y decidirlo son dos pasos del recorrido: si quien captura
     * también puede revisarlo, se resuelve de una vez; si no, queda pendiente de
     * revisión de quien sí tiene el permiso, sin darle una atribución de más.
     *
     * @param  array<string, mixed>  $datos  resultado (favorable|no_favorable), comentario?, direccion?, fecha_visita?
     * @param  list<UploadedFile>  $archivos
     */
    public function registrarResultadoSocioeconomico(AgendaEvento $evento, array $datos, array $archivos, User $actor): AgendaEvento
    {
        Gate::forUser($actor)->authorize('registrarResultado', $evento);
        $favorable = (string) $datos['resultado'] === ResultadoAgendaEvento::Favorable->value;
        $resultado = $favorable ? ResultadoAgendaEvento::Favorable : ResultadoAgendaEvento::NoFavorable;
        $comentario = $this->texto($datos['comentario'] ?? null);

        if (! $favorable && $comentario === null) {
            throw ValidationException::withMessages(['comentario' => 'Escribe por qué el estudio no fue favorable.']);
        }

        return DB::transaction(function () use ($evento, $datos, $archivos, $resultado, $favorable, $comentario, $actor): AgendaEvento {
            $actual = AgendaEvento::query()->lockForUpdate()->with('candidato')->findOrFail($evento->id);

            if ($actual->tipo !== TipoAgendaEvento::Socioeconomico) {
                throw ValidationException::withMessages(['resultado' => 'Esta cita es una entrevista.']);
            }

            if ($actual->estado === EstadoAgendaEvento::Realizado && $actual->resultado === $resultado) {
                return $actual;
            }

            $this->exigirProgramadoYDia($actual);

            $direccion = $this->texto($datos['direccion'] ?? null) ?? $actual->domicilio;

            if ($direccion === null) {
                throw ValidationException::withMessages(['direccion' => 'Indica el domicilio visitado.']);
            }

            if ($archivos === []) {
                throw ValidationException::withMessages(['evidencias' => 'Adjunta el formato o las fotografías del estudio.']);
            }

            $hoy = Carbon::parse(FechaMexico::hoy());
            $this->workflow->registrarSocioeconomico($actual->candidato, $actor, [
                'fecha_visita' => (string) ($datos['fecha_visita'] ?? ($actual->fecha_hora->lt($hoy) ? $actual->fecha_hora->toDateString() : $hoy->toDateString())),
                'direccion' => $direccion,
                'observaciones' => $comentario,
            ], $archivos);

            if ($this->permisos->puede($actor, CandidatoWorkflowService::PERMISO_REVISAR_SOCIOECONOMICO)) {
                $this->workflow->revisarSocioeconomico($actual->candidato->refresh(), $actor, $favorable, $comentario);
            }

            return $this->cerrarConResultado($actual, $resultado, null, $comentario, $actor);
        });
    }

    /**
     * Citas programadas cerca de esa hora en la misma sucursal o con el mismo
     * responsable. Es solo una advertencia: no se conoce la capacidad real de atención.
     *
     * @return list<string>
     */
    public function empalmes(int $sucursalId, CarbonInterface $fechaHora, ?int $responsableId = null, ?int $excluirId = null): array
    {
        $ventana = (int) config('reclutamiento.agenda.ventana_empalme_minutos', 60);

        return array_values(AgendaEvento::query()
            ->where('estado', EstadoAgendaEvento::Programado->value)
            ->whereBetween('fecha_hora', [$fechaHora->copy()->subMinutes($ventana), $fechaHora->copy()->addMinutes($ventana)])
            ->where(fn ($q) => $q->where('sucursal_id', $sucursalId)
                ->when($responsableId !== null, fn ($r) => $r->orWhere('responsable_user_id', $responsableId)))
            ->when($excluirId !== null, fn ($q) => $q->whereKeyNot($excluirId))
            ->with('sucursal:id,nombre')
            ->orderBy('fecha_hora')
            ->get()
            ->map(fn (AgendaEvento $e): string => sprintf('%s ya tiene %s a las %s.', $e->sucursal->nombre, mb_strtolower($e->tipo->etiqueta()), $e->fecha_hora->format('H:i')))
            ->all());
    }

    /**
     * Gerencia de la sucursal (organigrama, incluye cobertura) y quienes ocupan
     * los puestos configurados. Personas reales con cuenta activa, nunca por rol.
     *
     * @return Collection<int, User>
     */
    public function destinatarios(int $sucursalId): Collection
    {
        $puestos = Puesto::query()->whereIn('nombre', (array) config('reclutamiento.agenda.puestos_a_notificar', []))->pluck('id');
        $colaboradores = Colaborador::query()
            ->where('sucursal_principal_id', $sucursalId)
            ->where('estatus', EstadoUsuario::Activo->value)
            ->whereIn('puesto_id', $puestos)
            ->pluck('id');

        return User::query()
            ->whereIn('colaborador_id', $colaboradores)
            ->whereNull('acceso_bloqueado_en')
            ->get()
            ->concat($this->jerarquia->gerenciaSucursalDe($sucursalId))
            ->unique('id')
            ->values();
    }

    public function etiquetaFecha(CarbonInterface $fecha): string
    {
        return $fecha->copy()->locale('es')->translatedFormat('D j M, H:i');
    }

    /** Hora de reloj de México, comparable con fecha_hora. */
    public function ahora(): Carbon
    {
        return Carbon::parse(Carbon::now(FechaMexico::ZONA)->toDateTimeString());
    }

    private function cerrarConResultado(AgendaEvento $evento, ResultadoAgendaEvento $resultado, ?MotivoNoViableAgenda $motivo, ?string $comentario, User $actor): AgendaEvento
    {
        $evento->update([
            'estado' => EstadoAgendaEvento::Realizado,
            'resultado' => $resultado,
            'motivo_no_viable' => $motivo,
            'comentario_resultado' => $comentario,
            'resultado_por' => $actor->id,
            'resultado_en' => $this->ahora(),
            'clave_activa' => null,
        ]);
        $this->bitacora($evento, 'resultado', EstadoAgendaEvento::Programado, EstadoAgendaEvento::Realizado, $resultado->etiqueta(), $actor);

        return $evento;
    }

    /**
     * @param  array<int|string, mixed>  $nuevo  nombre, apellidos?, telefono?, correo?, puesto_objetivo_id?, vacante_id?
     */
    private function altaRapida(array $nuevo, int $sucursalId, User $actor): Candidato
    {
        if (! $actor->can('agenda.alta_candidato')) {
            throw ValidationException::withMessages(['candidato_id' => 'Elige un candidato de la lista: tu cuenta no da de alta candidatos.']);
        }

        $nombre = trim((string) ($nuevo['nombre'] ?? ''));

        if ($nombre === '') {
            throw ValidationException::withMessages(['candidato_nuevo.nombre' => 'Escribe el nombre del candidato.']);
        }

        $apellidos = $this->texto($nuevo['apellidos'] ?? null);
        $telefono = $this->texto($nuevo['telefono'] ?? null);
        $existente = $this->buscarDuplicado($nombre, $apellidos, $telefono, $sucursalId);

        if ($existente !== null) {
            throw ValidationException::withMessages(['candidato_nuevo.nombre' => sprintf('%s ya está registrado: elígelo de la lista en lugar de darlo de alta otra vez.', $existente->nombreCompleto())]);
        }

        $vacanteId = isset($nuevo['vacante_id']) ? (int) $nuevo['vacante_id'] : null;

        return $this->workflow->registrar([
            'sucursal_id' => $sucursalId,
            'nombre' => $nombre,
            'apellidos' => $apellidos,
            'telefono' => $telefono,
            'correo' => $this->texto($nuevo['correo'] ?? null),
            'puesto_objetivo_id' => isset($nuevo['puesto_objetivo_id']) ? (int) $nuevo['puesto_objetivo_id'] : null,
            'vacante_id' => $vacanteId,
            'espontaneo' => $vacanteId === null,
            'fuente' => FuenteCandidato::Otro->value,
        ], $actor);
    }

    /** Mismo teléfono (últimos 10 dígitos) o mismo nombre en la sucursal, en proceso o ya contratado. */
    private function buscarDuplicado(string $nombre, ?string $apellidos, ?string $telefono, int $sucursalId): ?Candidato
    {
        $salidas = array_map(fn (EstadoCandidato $e): string => $e->value, EstadoCandidato::salidas());
        $vigentes = Candidato::query()->whereNotIn('estado', $salidas);
        $digitos = substr((string) preg_replace('/\D+/', '', (string) $telefono), -10);

        if ($digitos !== '') {
            $porTelefono = (clone $vigentes)
                ->whereNotNull('telefono')
                ->get()
                ->first(fn (Candidato $c): bool => substr((string) preg_replace('/\D+/', '', (string) $c->telefono), -10) === $digitos);

            if ($porTelefono !== null) {
                return $porTelefono;
            }
        }

        return (clone $vigentes)
            ->where('sucursal_id', $sucursalId)
            ->whereRaw('LOWER(TRIM(nombre)) = ?', [mb_strtolower($nombre)])
            ->whereRaw("LOWER(TRIM(COALESCE(apellidos, ''))) = ?", [mb_strtolower((string) $apellidos)])
            ->first();
    }

    private function validarSucursalDelCandidato(Candidato $candidato, int $sucursalId): void
    {
        if ($candidato->sucursal_id !== null && $candidato->sucursal_id !== $sucursalId) {
            throw ValidationException::withMessages(['sucursal_id' => 'Este candidato pertenece a otra sucursal.']);
        }
    }

    private function validarEstadoParaAgendar(Candidato $candidato, TipoAgendaEvento $tipo): void
    {
        $limite = $tipo === TipoAgendaEvento::Entrevista ? EstadoCandidato::EntrevistaPendiente : EstadoCandidato::SocioeconomicoPendiente;

        if ($candidato->estado->esSalida() || $candidato->estado->orden() > $limite->orden()) {
            throw ValidationException::withMessages(['candidato_id' => sprintf('El candidato está en «%s»: ya no corresponde agendarle %s.', $candidato->estado->etiqueta(), mb_strtolower($tipo->etiqueta()))]);
        }
    }

    private function exigirProgramadoYDia(AgendaEvento $evento): void
    {
        if ($evento->estado !== EstadoAgendaEvento::Programado) {
            throw ValidationException::withMessages(['estado' => sprintf('Esta cita ya está en «%s». Recarga la agenda.', $evento->estado->etiqueta())]);
        }

        if ($evento->fecha_hora->toDateString() > FechaMexico::hoy()) {
            throw ValidationException::withMessages(['estado' => 'Esta cita todavía no llega: el resultado se registra a partir de su fecha.']);
        }
    }

    private function fechaHoraFutura(string $fecha, string $hora): Carbon
    {
        $fechaHora = Carbon::parse(sprintf('%s %s', $fecha, $hora));

        if ($fechaHora->lt($this->ahora())) {
            throw ValidationException::withMessages(['fecha' => 'Elige una fecha y hora que aún no hayan pasado.']);
        }

        return $fechaHora;
    }

    /** Lo decide el alcance de la cuenta: quien opera todas las sucursales es Reclutamiento. */
    private function origenDe(User $actor): OrigenAgendaEvento
    {
        return $this->alcance->nivel($actor, 'agenda') === AlcanceDatos::Todos ? OrigenAgendaEvento::Reclutamiento : OrigenAgendaEvento::Gerente;
    }

    private function claveActiva(int $candidatoId, TipoAgendaEvento $tipo): string
    {
        return sprintf('%d:%s', $candidatoId, $tipo->value);
    }

    private function porClave(?string $clave): ?AgendaEvento
    {
        return $clave !== null ? AgendaEvento::query()->where('clave_idempotencia', $clave)->first() : null;
    }

    private function bitacora(AgendaEvento $evento, string $accion, ?EstadoAgendaEvento $anterior, ?EstadoAgendaEvento $nuevo, ?string $detalle, User $actor): void
    {
        $evento->historial()->create([
            'accion' => $accion,
            'estado_anterior' => $anterior?->value,
            'estado_nuevo' => $nuevo?->value,
            'detalle' => $detalle,
            'user_id' => $actor->id,
        ]);
    }

    /** Un fallo al avisar nunca deshace la cita (CLAUDE.md). */
    private function avisar(AgendaEvento $evento, string $codigo, string $titulo, string $ocurrencia, User $actor): void
    {
        try {
            $evento->loadMissing(['candidato', 'sucursal:id,nombre']);
            $destinatarios = $this->destinatarios($evento->sucursal_id)->reject(fn (User $u): bool => $u->id === $actor->id);

            $this->avisos->crearPendiente($destinatarios, $this->aviso($evento, $codigo, $titulo, sprintf('%s:%d', $ocurrencia, $evento->id)));
        } catch (Throwable $e) {
            Log::warning('AgendaEventoService: no se pudo avisar del evento.', ['evento_id' => $evento->id, 'error' => $e->getMessage()]);
        }
    }

    public function aviso(AgendaEvento $evento, string $codigo, string $titulo, string $ocurrencia): Aviso
    {
        return new Aviso(
            evento: $codigo,
            tipo: 'agenda_evento',
            titulo: $titulo,
            mensaje: sprintf('%s · %s · %s', $evento->candidato->nombreCompleto(), $evento->sucursal->nombre, $this->etiquetaFecha($evento->fecha_hora)),
            recurso: $evento,
            accion: 'ver_agenda',
            datos: ['evento_tipo' => $evento->tipo->value, 'sucursal_id' => $evento->sucursal_id],
            ocurrencia: $ocurrencia,
        );
    }

    private function texto(mixed $valor): ?string
    {
        $texto = is_string($valor) ? trim($valor) : '';

        return $texto !== '' ? $texto : null;
    }
}
