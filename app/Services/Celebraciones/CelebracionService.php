<?php

namespace App\Services\Celebraciones;

use App\Enums\EstadoUsuario;
use App\Enums\TipoCelebracion;
use App\Models\BirthdayGreeting;
use App\Models\CelebracionConfiguracion;
use App\Models\Colaborador;
use App\Models\User;
use App\Notifications\Mobile\CelebracionNotification;
use App\Services\Colaboradores\FotoColaboradorService;
use App\Services\Cumpleanos\BirthdayCardService;
use App\Services\Cumpleanos\CumpleanosService;
use App\Services\Cumpleanos\MuroCumpleanosService;
use App\Services\MobilePush\PushNotifier;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Orquesta las CELEBRACIONES — cumpleaños y aniversario laboral — sobre el
 * mismo registro (App\Models\BirthdayGreeting), sin sistemas paralelos
 * (docs/CELEBRACIONES.md):
 *
 *  - prepararDia(): el scheduler crea el evento del día y su tarjeta
 *    (idempotente: índice único colaborador+fecha+tipo);
 *  - enviarAlColaborador(): tarjeta + notificación + push al homenajeado;
 *  - avisarATodos(): una sola vez por evento (`avisada_todos_at` se
 *    reclama con un UPDATE condicional: doble clic o dos RH a la vez no
 *    mandan dos avisos generales);
 *  - activas(): lo que un colaborador ve en su inicio / app.
 */
class CelebracionService
{
    public function __construct(
        private readonly AniversariosService $aniversarios,
        private readonly CumpleanosService $cumpleanos,
        private readonly BirthdayCardService $tarjetaCumpleanos,
        private readonly TarjetaAniversarioService $tarjetaAniversario,
        private readonly MuroCumpleanosService $muro,
        private readonly FotoColaboradorService $fotos,
        private readonly PushNotifier $push,
    ) {}

    /**
     * Crea (si faltan) los eventos de hoy y sus tarjetas. No avisa a nadie
     * salvo que la configuración del tipo tenga envío automático al
     * homenajeado (cumpleaños conserva su propio command de envío).
     *
     * @return array{cumpleanos: int, aniversarios: int, enviados: int}
     */
    public function prepararDia(?CarbonInterface $fecha = null): array
    {
        $fecha ??= FechasCelebracion::hoy();
        $resultado = ['cumpleanos' => 0, 'aniversarios' => 0, 'enviados' => 0];

        if ((bool) config('cumpleanos.enabled', true)) {
            foreach ($this->cumpleanos->cumpleanosDeHoy() as $colaborador) {
                try {
                    $this->tarjetaCumpleanos->generar($colaborador, $fecha);
                    $resultado['cumpleanos']++;
                } catch (Throwable $e) {
                    Log::error('celebraciones: no se pudo preparar el cumpleaños', ['colaborador_id' => $colaborador->id, 'error' => $e->getMessage()]);
                }
            }
        }

        $configuracion = CelebracionConfiguracion::de(TipoCelebracion::AniversarioLaboral);

        if ((bool) config('celebraciones.aniversario.enabled', true) && $configuracion->activo) {
            foreach ($this->aniversarios->enRango($fecha, $fecha) as $aniversario) {
                try {
                    $celebracion = $this->asegurarAniversario($aniversario['colaborador'], $fecha, $aniversario['anios']);
                    $this->tarjetaAniversario->generar($celebracion);
                    $resultado['aniversarios']++;

                    if ($configuracion->auto_enviar_colaborador && $celebracion->enviada_at === null) {
                        $this->enviarAlColaborador($celebracion, null);
                        $resultado['enviados']++;
                    }
                } catch (Throwable $e) {
                    Log::error('celebraciones: no se pudo preparar el aniversario', ['colaborador_id' => $aniversario['colaborador']->id, 'error' => $e->getMessage()]);
                }
            }
        }

        return $resultado;
    }

    /**
     * Evento de aniversario del día (idempotente, a prueba de carreras).
     */
    public function asegurarAniversario(Colaborador $colaborador, CarbonInterface $fecha, int $anios): BirthdayGreeting
    {
        $buscar = fn (): ?BirthdayGreeting => BirthdayGreeting::query()
            ->where('colaborador_id', $colaborador->id)
            ->where('tipo', TipoCelebracion::AniversarioLaboral->value)
            ->whereDate('fecha', $fecha->toDateString())
            ->first();

        $existente = $buscar();

        if ($existente !== null) {
            return $existente;
        }

        try {
            return BirthdayGreeting::query()->create([
                'colaborador_id' => $colaborador->id,
                'user_id' => $colaborador->user?->id,
                'tipo' => TipoCelebracion::AniversarioLaboral,
                'fecha' => $fecha->toDateString(),
                'anios' => $anios,
                'nombre_mostrado' => $colaborador->nombreCompleto(),
                'frase' => $this->tarjetaAniversario->mensaje($anios, $colaborador->nombreCompleto(), $colaborador->sucursalPrincipal?->nombre),
                'auto_generada' => true,
            ]);
        } catch (UniqueConstraintViolationException) {
            return $buscar() ?? throw ValidationException::withMessages(['celebracion' => 'No se pudo registrar el aniversario.']);
        }
    }

    /**
     * Evento de hoy (o de la fecha dada) para un colaborador y tipo; lo crea
     * si corresponde. null si ese día no le toca.
     */
    public function delDia(Colaborador $colaborador, TipoCelebracion $tipo, ?CarbonInterface $fecha = null): ?BirthdayGreeting
    {
        $fecha ??= FechasCelebracion::hoy();

        if ($tipo === TipoCelebracion::Cumpleanos) {
            return $colaborador->fecha_nacimiento !== null && FechasCelebracion::esHoy($colaborador->fecha_nacimiento, $fecha)
                ? $this->tarjetaCumpleanos->generar($colaborador, $fecha)
                : null;
        }

        $anios = $this->aniversarios->aniosEn($colaborador, $fecha);

        return $anios !== null ? $this->asegurarAniversario($colaborador, $fecha, $anios) : null;
    }

    /**
     * Garantiza la tarjeta vigente (o la regenera con los datos actuales).
     */
    public function tarjeta(BirthdayGreeting $celebracion, bool $regenerar = false): BirthdayGreeting
    {
        if ($celebracion->esAniversario()) {
            return $this->tarjetaAniversario->generar($celebracion, $regenerar);
        }

        return $regenerar
            ? $this->tarjetaCumpleanos->regenerar($celebracion->colaborador, $celebracion->fecha)
            : $this->tarjetaCumpleanos->generar($celebracion->colaborador, $celebracion->fecha);
    }

    /**
     * Felicitación directa al homenajeado: notificación in-app + push que
     * abre la pantalla del evento. Requiere que tenga cuenta.
     */
    public function enviarAlColaborador(BirthdayGreeting $celebracion, ?User $actor): BirthdayGreeting
    {
        $celebracion = $this->tarjeta($celebracion);
        $homenajeado = $celebracion->colaborador->user;

        if ($homenajeado === null) {
            throw ValidationException::withMessages(['celebracion' => sprintf('%s no tiene cuenta en la app: descarga la tarjeta y compártela por otro medio.', $celebracion->colaborador->nombreCompleto())]);
        }

        [$tipo, $titulo, $mensaje] = $celebracion->esAniversario()
            ? ['aniversario_laboral', sprintf('¡Felicidades por tus %s con MR. LANA!', FechasCelebracion::textoAnios((int) $celebracion->anios)), 'Gracias por tu entrega y constancia. Abre tu tarjeta y los mensajes de tus compañeros.']
            : ['cumpleanos', sprintf('¡Feliz cumpleaños, %s!', $celebracion->colaborador->name), 'Hoy celebramos tu vida y todo lo que aportas a MR. LANA. Abre tu tarjeta.'];

        try {
            $homenajeado->notify(new CelebracionNotification($celebracion, $tipo, $titulo, $mensaje));
            $this->push->aUsuarioConDatos($homenajeado, $titulo, $mensaje, [
                'type' => $tipo,
                'resource_id' => $celebracion->id,
                'related_type' => 'Celebracion',
                'accion' => 'abrir_celebracion',
            ]);
        } catch (Throwable $e) {
            Log::warning('celebraciones: fallo al notificar al homenajeado', ['celebracion_id' => $celebracion->id, 'error' => $e->getMessage()]);
        }

        $celebracion->update(['enviada_at' => now(), 'enviada_por_id' => $actor?->id]);

        return $celebracion->refresh();
    }

    /**
     * "Avisar a todos": abre la recepción de felicitaciones y avisa a los
     * colaboradores activos (in-app + push). UNA vez por evento.
     *
     * @return array{avisado: bool, celebracion: BirthdayGreeting, destinatarios: int}
     */
    public function avisarATodos(BirthdayGreeting $celebracion, User $actor): array
    {
        $reclamado = BirthdayGreeting::query()
            ->whereKey($celebracion->id)
            ->whereNull('avisada_todos_at')
            ->update(['avisada_todos_at' => now(), 'avisada_todos_por_id' => $actor->id]);

        $celebracion->refresh();

        if ($reclamado === 0) {
            return ['avisado' => false, 'celebracion' => $celebracion, 'destinatarios' => 0];
        }

        $this->tarjeta($celebracion);
        $this->muro->abrir($celebracion, $actor, avisar: false);

        $nombre = $celebracion->colaborador->name;
        [$tipo, $titulo, $mensaje] = $celebracion->esAniversario()
            ? ['aniversario_general', sprintf('%s cumple %s con MR. LANA', $celebracion->colaborador->nombreCompleto(), FechasCelebracion::textoAnios((int) $celebracion->anios)), 'Puedes dejarle un mensaje de felicitación.']
            : ['cumpleanos_general', sprintf('Hoy es el cumpleaños de %s', $celebracion->colaborador->nombreCompleto()), sprintf('Déjale a %s un mensaje de felicitación.', $nombre)];

        $destinatarios = 0;

        User::query()
            ->whereHas('colaborador', fn (Builder $q) => $q->where('estatus', EstadoUsuario::Activo->value))
            ->where(fn (Builder $q) => $q->whereNull('colaborador_id')->orWhere('colaborador_id', '!=', $celebracion->colaborador_id))
            ->chunkById(200, function ($usuarios) use ($celebracion, $tipo, $titulo, $mensaje, &$destinatarios): void {
                foreach ($usuarios as $usuario) {
                    try {
                        $usuario->notify(new CelebracionNotification($celebracion, $tipo, $titulo, $mensaje));
                        $this->push->aUsuarioConDatos($usuario, $titulo, $mensaje, [
                            'type' => $tipo,
                            'resource_id' => $celebracion->id,
                            'related_type' => 'Celebracion',
                            'accion' => 'abrir_celebracion',
                        ]);
                        $destinatarios++;
                    } catch (Throwable $e) {
                        Log::warning('celebraciones: fallo un aviso general', ['celebracion_id' => $celebracion->id, 'user_id' => $usuario->id, 'error' => $e->getMessage()]);
                    }
                }
            });

        activity('celebraciones')
            ->performedOn($celebracion)
            ->causedBy($actor)
            ->withProperties(['destinatarios' => $destinatarios, 'tipo' => $celebracion->tipo->value])
            ->log('celebracion_avisada_a_todos');

        return ['avisado' => true, 'celebracion' => $celebracion, 'destinatarios' => $destinatarios];
    }

    /**
     * Eventos vigentes que el usuario puede ver: publicados de los últimos
     * días y siempre los propios.
     *
     * @return Collection<int, BirthdayGreeting>
     */
    public function activas(User $usuario): Collection
    {
        $desde = FechasCelebracion::hoy()->subDays(max(0, (int) config('celebraciones.dias_visible', 3)));

        return BirthdayGreeting::query()
            ->whereDate('fecha', '>=', $desde->toDateString())
            ->whereDate('fecha', '<=', FechasCelebracion::hoy()->toDateString())
            ->whereHas('colaborador', fn (Builder $q) => $q->where('estatus', EstadoUsuario::Activo->value))
            ->where(fn (Builder $q) => $q->whereNotNull('muro_abierto_at')
                ->when($usuario->colaborador_id !== null, fn (Builder $s) => $s->orWhere('colaborador_id', $usuario->colaborador_id)))
            ->with(['colaborador.puesto:id,nombre', 'colaborador.sucursalPrincipal:id,nombre', 'colaborador.user:id,colaborador_id'])
            ->orderByDesc('fecha')
            ->orderBy('id')
            ->get();
    }

    /**
     * Forma pública de un evento para web y API (sin rutas físicas, sin año
     * de nacimiento).
     *
     * @return array<string, mixed>
     */
    public function aArray(BirthdayGreeting $celebracion, User $viewer, bool $api = true): array
    {
        $celebracion->loadMissing(['colaborador.puesto:id,nombre', 'colaborador.sucursalPrincipal:id,nombre']);
        $colaborador = $celebracion->colaborador;
        $gate = Gate::forUser($viewer);
        $verTodos = $gate->allows('verTodosLosMensajes', $celebracion);
        $mio = $this->muro->miMensaje($celebracion, $viewer);
        $anios = $celebracion->anios !== null ? FechasCelebracion::textoAnios($celebracion->anios) : null;
        $ruta = fn (string $web, string $movil): string => $api ? route($movil, $celebracion->id) : route($web, $celebracion->id);

        return [
            'id' => $celebracion->id,
            'tipo' => $celebracion->tipo->value,
            'tipo_etiqueta' => $celebracion->tipo->etiqueta(),
            'fecha' => $celebracion->fecha->toDateString(),
            'es_hoy' => $celebracion->fecha->isSameDay(FechasCelebracion::hoy()),
            'anios' => $celebracion->anios,
            'titulo' => $celebracion->esAniversario()
                ? sprintf('%s cumple %s con MR. LANA', $colaborador->name, $anios)
                : sprintf('Hoy es el cumpleaños de %s', $colaborador->name),
            'mensaje_tarjeta' => $celebracion->frase,
            'homenajeado' => [
                'colaborador_id' => $colaborador->id,
                'nombre' => $colaborador->nombreCompleto(),
                'puesto' => $colaborador->puesto?->nombre,
                'sucursal' => $colaborador->sucursalPrincipal?->nombre,
                'foto_url' => $colaborador->foto_path !== null ? $ruta('celebraciones.foto', 'api.v1.celebraciones.foto') : null,
            ],
            'tarjeta_url' => $ruta('celebraciones.tarjeta', 'api.v1.celebraciones.tarjeta'),
            'recibe_mensajes' => $celebracion->muroAbierto(),
            'es_mia' => $gate->allows('esHomenajeado', $celebracion),
            'puede_escribir' => $gate->allows('escribir', $celebracion) && $mio === null,
            'puede_ver_todos' => $verTodos,
            'mi_mensaje' => $mio !== null ? $this->muro->mensajeArray($mio, $viewer, $api) : null,
            'mensajes_count' => $verTodos ? $celebracion->mensajesMuro()->count() : null,
            'enviada_at' => $celebracion->enviada_at?->toIso8601String(),
            'avisada_todos_at' => $celebracion->avisada_todos_at?->toIso8601String(),
            'puede_enviar' => $gate->allows('enviar', $celebracion),
            'puede_moderar' => $gate->allows('moderar', $celebracion),
        ];
    }

    /**
     * Aniversarios para el panel RH (web y API): cada fila con el estado
     * del evento del día cuando ya existe (enviado / avisado).
     *
     * @param  array{empresa_id?: int|null, sucursal_id?: int|null, departamento_id?: int|null, busqueda?: string|null}  $filtros
     * @return list<array<string, mixed>>
     */
    public function filasAniversarios(User $usuario, CarbonInterface $desde, CarbonInterface $hasta, array $filtros = []): array
    {
        $lista = $this->aniversarios->enRango($desde, $hasta, $usuario, $filtros);
        $eventos = $this->eventosPorColaboradorYFecha(TipoCelebracion::AniversarioLaboral, $lista->map(fn (array $a) => $a['colaborador']->id)->all(), $desde, $hasta);

        return array_values($lista->map(fn (array $a): array => [
            ...$this->fila($a['colaborador'], $a['fecha'], $a['anios'], sprintf('%s en MR. LANA', FechasCelebracion::textoAnios($a['anios'])), $eventos),
            'anios_texto' => FechasCelebracion::textoAnios($a['anios']),
        ])->all());
    }

    /**
     * Cumpleaños para el panel RH con la MISMA forma que
     * filasAniversarios() (calendario, próximos y "hoy" comparten
     * componentes en el frontend). Nunca expone la fecha de nacimiento: solo
     * la fecha de la celebración en el periodo y, si la configuración lo
     * permite (cumpleanos.show_age), la edad que cumple.
     *
     * @param  array<string, mixed>  $filtros
     * @return list<array<string, mixed>>
     */
    public function filasCumpleanos(User $usuario, CarbonInterface $desde, CarbonInterface $hasta, array $filtros = []): array
    {
        $lista = $this->cumpleanos->cumpleanosEnRango($usuario, $desde, $hasta, $filtros);
        $eventos = $this->eventosPorColaboradorYFecha(TipoCelebracion::Cumpleanos, $lista->map(fn (Colaborador $c) => $c->id)->all(), $desde, $hasta);
        $mostrarEdad = (bool) config('cumpleanos.show_age');

        return array_values($lista->map(function (Colaborador $colaborador) use ($eventos, $mostrarEdad): array {
            $fecha = Carbon::parse($colaborador->getAttribute('_proxima_fecha'));
            $edad = $mostrarEdad && $colaborador->fecha_nacimiento !== null
                ? FechasCelebracion::aniosCumplidos($colaborador->fecha_nacimiento, $fecha)
                : null;

            return $this->fila($colaborador, $fecha, $edad, $edad !== null ? sprintf('Cumple %s', FechasCelebracion::textoAnios($edad)) : null, $eventos);
        })->all());
    }

    /**
     * Eventos ya registrados (enviado / avisado) indexados por
     * "colaborador:fecha" — una sola consulta para todo el periodo, nunca
     * una por fila.
     *
     * @param  array<int, int>  $colaboradorIds
     * @return SupportCollection<string, BirthdayGreeting>
     */
    private function eventosPorColaboradorYFecha(TipoCelebracion $tipo, array $colaboradorIds, CarbonInterface $desde, CarbonInterface $hasta): SupportCollection
    {
        return BirthdayGreeting::query()
            ->where('tipo', $tipo->value)
            ->whereIn('colaborador_id', $colaboradorIds)
            ->whereDate('fecha', '>=', $desde->toDateString())
            ->whereDate('fecha', '<=', $hasta->toDateString())
            ->get()
            ->keyBy(fn (BirthdayGreeting $g) => sprintf('%d:%s', $g->colaborador_id, $g->fecha->toDateString()))
            ->toBase();
    }

    /**
     * Fila común de celebración (ver resources/js/types/celebraciones.ts).
     *
     * @param  SupportCollection<string, BirthdayGreeting>  $eventos
     * @return array<string, mixed>
     */
    private function fila(Colaborador $colaborador, CarbonInterface $fecha, ?int $anios, ?string $detalle, SupportCollection $eventos): array
    {
        $evento = $eventos->get(sprintf('%d:%s', $colaborador->id, $fecha->toDateString()));

        return [
            'colaborador_id' => $colaborador->id,
            'nombre' => $colaborador->nombreCompleto(),
            'puesto' => $colaborador->puesto?->nombre,
            'sucursal' => $colaborador->sucursalPrincipal?->nombre,
            'departamento' => $colaborador->departamento?->nombre,
            'foto_url' => $this->fotos->url($colaborador),
            'fecha' => $fecha->toDateString(),
            'es_hoy' => $fecha->isSameDay(FechasCelebracion::hoy()),
            'anios' => $anios,
            'detalle' => $detalle,
            'celebracion_id' => $evento?->id,
            'enviada_at' => $evento?->enviada_at?->toIso8601String(),
            'avisada_todos_at' => $evento?->avisada_todos_at?->toIso8601String(),
        ];
    }

    /**
     * Resumen de estado de la celebración de hoy para RH (botones).
     *
     * @return array{celebracion_id: int|null, enviada_at: string|null, avisada_todos_at: string|null}
     */
    public function estadoHoy(Colaborador $colaborador, TipoCelebracion $tipo): array
    {
        $evento = BirthdayGreeting::query()
            ->where('colaborador_id', $colaborador->id)
            ->where('tipo', $tipo->value)
            ->whereDate('fecha', FechasCelebracion::hoy()->toDateString())
            ->first();

        return [
            'celebracion_id' => $evento?->id,
            'enviada_at' => $evento?->enviada_at?->toIso8601String(),
            'avisada_todos_at' => $evento?->avisada_todos_at?->toIso8601String(),
        ];
    }

    public function fotoUrlColaborador(Colaborador $colaborador): ?string
    {
        return $this->fotos->url($colaborador);
    }
}
