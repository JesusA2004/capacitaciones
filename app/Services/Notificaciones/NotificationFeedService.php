<?php

namespace App\Services\Notificaciones;

use App\Enums\AlcanceAviso;
use App\Models\Aviso;
use App\Models\AvisoLectura;
use App\Models\BirthdayGreeting;
use App\Models\NotificationRead;
use App\Models\User;
use App\Services\Avisos\AvisoService;
use App\Services\Celebraciones\CelebracionService;
use App\Services\Colaboradores\NotificacionesService;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Proyección ÚNICA de todo lo que un colaborador ve como "notificación":
 * notificaciones transaccionales propias (Laravel `notifications`), avisos
 * de RH visibles (globales o dirigidos a él, tabla `avisos`) y celebraciones
 * globales visibles (`BirthdayGreeting`). Una sola fuente para la campana
 * web, el Centro de Notificaciones web y el centro de notificaciones de la
 * app — nunca tres pantallas ni tres conteos distintos.
 *
 * Nunca materializa una fila por persona para un evento global: avisos y
 * celebraciones se proyectan por consulta (igual que ya hacía
 * AvisoService::paraColaborador()); la lectura individual se registra solo
 * cuando cada quien realmente lo abre o lo marca (AvisoLectura para avisos,
 * NotificationRead para celebraciones, `read_at` nativo para notifications).
 *
 * Cada item usa un id con prefijo de fuente (`notification:{uuid}`,
 * `aviso:{id}`, `celebracion:{id}`) para que marcar-leída/abrir sepan a qué
 * fuente despachar sin ambigüedad. Un UUID sin prefijo se sigue aceptando
 * como notificación nativa (lo envía el broadcast en tiempo real).
 */
class NotificationFeedService
{
    public function __construct(
        private readonly AvisoService $avisos,
        private readonly CelebracionService $celebraciones,
        private readonly DestinoNotificacionService $destinos,
    ) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function feed(User $usuario, int $limite = 30): Collection
    {
        return $this->items($usuario)
            ->sortByDesc('creada_en_ts')
            ->values()
            ->take($limite)
            ->map(fn (array $item): array => collect($item)->except('creada_en_ts')->all());
    }

    public function contarNoLeidas(User $usuario): int
    {
        return $this->items($usuario)->reject(fn (array $item) => $item['leida'])->count();
    }

    /**
     * Para la campana (web): conteo + unos cuantos recientes, misma fuente
     * que el Centro de Notificaciones completo.
     *
     * @return array{no_leidas: int, recientes: Collection<int, array<string, mixed>>}
     */
    public function resumen(User $usuario, int $limite = 10): array
    {
        $items = $this->items($usuario)->sortByDesc('creada_en_ts')->values();

        return [
            'no_leidas' => $items->reject(fn (array $item) => $item['leida'])->count(),
            'recientes' => $items->take($limite)->map(fn (array $item): array => collect($item)->except('creada_en_ts')->all())->values(),
        ];
    }

    /**
     * Para el Centro de Notificaciones (web y app): paginado real sobre la
     * proyección unificada ya ordenada.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function feedPaginado(User $usuario, int $porPagina = 30, int $pagina = 1): LengthAwarePaginator
    {
        $items = $this->items($usuario)
            ->sortByDesc('creada_en_ts')
            ->values()
            ->map(fn (array $item): array => collect($item)->except('creada_en_ts')->all());

        $slice = $items->slice(($pagina - 1) * $porPagina, $porPagina)->values();

        return new LengthAwarePaginator($slice, $items->count(), $porPagina, $pagina);
    }

    /**
     * @return array{url: string|null, atendida: bool|null, estado_recurso: string|null, mensaje_estado: string|null, no_leidas: int}
     */
    public function abrir(User $usuario, string $id): array
    {
        [$source, $sourceId] = $this->descomponer($id);

        $destino = match ($source) {
            'notification' => $this->abrirNotification($usuario, $sourceId),
            'aviso' => $this->abrirAviso($usuario, (int) $sourceId),
            'celebracion' => $this->abrirCelebracion($usuario, (int) $sourceId),
            default => throw ValidationException::withMessages(['id' => 'Notificación no reconocida.']),
        };

        return [...$destino, 'no_leidas' => $this->contarNoLeidas($usuario)];
    }

    public function marcarLeida(User $usuario, string $id): void
    {
        [$source, $sourceId] = $this->descomponer($id);

        match ($source) {
            'notification' => $usuario->notifications()->whereKey($sourceId)->firstOrFail()->markAsRead(),
            'aviso' => $this->avisos->marcarLeido(Aviso::query()->findOrFail((int) $sourceId), $usuario),
            'celebracion' => $this->marcarCelebracionLeida($usuario, (int) $sourceId),
            default => throw ValidationException::withMessages(['id' => 'Notificación no reconocida.']),
        };
    }

    /**
     * Marca como leído SOLO lo que este usuario puede ver — nunca lo de los
     * demás (un aviso/celebración global es una fila compartida; leerla no
     * debe tocar el estado de nadie más).
     */
    public function marcarTodas(User $usuario): void
    {
        $usuario->unreadNotifications->markAsRead();

        $colaborador = $usuario->colaborador;

        if ($colaborador !== null) {
            Aviso::query()
                ->where(fn ($q) => $q->where('alcance', AlcanceAviso::Todos)->orWhere('colaborador_objetivo_id', $colaborador->id))
                ->whereDoesntHave('lecturas', fn ($q) => $q->where('user_id', $usuario->id))
                ->get()
                ->each(fn (Aviso $aviso) => $this->avisos->marcarLeido($aviso, $usuario));
        }

        $this->celebraciones->activas($usuario)
            ->reject(fn (BirthdayGreeting $c) => $c->colaborador_id === $usuario->colaborador_id)
            ->each(fn (BirthdayGreeting $c) => $this->marcarCelebracionLeida($usuario, $c->id));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function items(User $usuario): Collection
    {
        $colaborador = $usuario->colaborador;

        $notificaciones = $usuario->notifications()->latest()->limit(100)->get()
            ->map($this->itemDeNotification(...));

        $avisosQuery = $colaborador !== null
            ? Aviso::query()->where(fn ($q) => $q->where('alcance', AlcanceAviso::Todos)->orWhere('colaborador_objetivo_id', $colaborador->id))
            : Aviso::query()->where('alcance', AlcanceAviso::Todos);

        $avisosLeidos = $colaborador !== null
            ? AvisoLectura::query()->where('user_id', $usuario->id)->pluck('leido_en', 'aviso_id')
            : collect();

        $avisos = $avisosQuery->orderByDesc('enviado_en')->limit(100)->get()
            ->map(fn (Aviso $aviso): array => $this->itemDeAviso($aviso, $avisosLeidos));

        $celebracionLecturas = NotificationRead::query()
            ->where('source_type', 'Celebracion')
            ->where('user_id', $usuario->id)
            ->pluck('read_at', 'source_id');

        $celebraciones = $this->celebraciones->activas($usuario)
            ->map(fn (BirthdayGreeting $celebracion): array => $this->itemDeCelebracion($celebracion, $celebracionLecturas, $usuario));

        return $notificaciones->concat($avisos)->concat($celebraciones)->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function itemDeNotification(DatabaseNotification $notificacion): array
    {
        $data = $notificacion->data;
        $creada = $notificacion->created_at;
        $tipo = $data['type'] ?? $data['tipo'] ?? null;
        $estilo = self::estilo($tipo);

        return [
            'id' => "notification:{$notificacion->id}",
            'source' => 'notification',
            'source_id' => (string) $notificacion->id,
            'titulo' => $data['titulo'] ?? '',
            'mensaje' => $data['mensaje'] ?? '',
            'tipo' => $tipo,
            'emoji' => $estilo['emoji'],
            'color' => $estilo['color'],
            // Compatibilidad con el front web (NotificacionPortalItem.url):
            // la URL ya calculada que el evento guardó al crearse, igual que
            // antes de la unificación — nunca se recalcula aquí (sería una
            // consulta extra por item); `abrir()` sigue resolviendo la URL
            // ACTUAL en el momento del clic.
            'url' => $data['url'] ?? null,
            // La app navega nativamente con estos campos estructurados
            // (resolveResourceRoute), nunca con `url` — mismo contrato que ya
            // tenía antes de la unificación (NotificacionesService::aArray()).
            'data' => [
                'type' => $tipo,
                'resource_id' => $data['resource_id'] ?? null,
                'related_type' => $data['related_type'] ?? null,
                'accion' => $data['accion'] ?? null,
                'colaborador_id' => $data['colaborador_id'] ?? null,
                'periodo' => $data['periodo'] ?? null,
            ],
            'creada_en' => $creada?->toIso8601String(),
            'creada_en_ts' => $creada->timestamp,
            'leida' => $notificacion->read_at !== null,
        ];
    }

    /**
     * @param  Collection<int|string, mixed>  $avisosLeidos
     * @return array<string, mixed>
     */
    private function itemDeAviso(Aviso $aviso, Collection $avisosLeidos): array
    {
        $creada = $aviso->enviado_en ?? $aviso->created_at;
        $estilo = self::estilo('aviso_rh');

        return [
            'id' => "aviso:{$aviso->id}",
            'source' => 'aviso',
            'source_id' => (string) $aviso->id,
            'titulo' => $aviso->titulo,
            'mensaje' => $aviso->mensaje,
            'tipo' => 'aviso_rh',
            'emoji' => $estilo['emoji'],
            'color' => $estilo['color'],
            'url' => null,
            // `aviso_rh` ya lo resuelve la app a `/avisos/{id}` (pantalla de
            // detalle existente, reutilizada tal cual — sección 6: no es un
            // "módulo Avisos" separado, es el destino de la tarjeta).
            'data' => ['type' => 'aviso_rh', 'resource_id' => $aviso->id],
            'creada_en' => $creada?->toIso8601String(),
            'creada_en_ts' => $creada->timestamp,
            'leida' => $avisosLeidos->has($aviso->id),
        ];
    }

    /**
     * @param  Collection<int|string, mixed>  $celebracionLecturas
     * @return array<string, mixed>
     */
    private function itemDeCelebracion(BirthdayGreeting $celebracion, Collection $celebracionLecturas, User $usuario): array
    {
        $esHomenajeado = $celebracion->colaborador_id === $usuario->colaborador_id;
        $titulo = $celebracion->esAniversario()
            ? sprintf('%s cumple con MR. LANA', $celebracion->colaborador->nombreCompleto())
            : sprintf('Hoy es el cumpleaños de %s', $celebracion->colaborador->nombreCompleto());
        $tipo = $celebracion->esAniversario() ? 'aniversario_general' : 'cumpleanos_general';
        $estilo = self::estilo($tipo);

        return [
            'id' => "celebracion:{$celebracion->id}",
            'source' => 'celebracion',
            'source_id' => (string) $celebracion->id,
            'titulo' => $titulo,
            'mensaje' => (string) $celebracion->frase,
            'tipo' => $tipo,
            'emoji' => $estilo['emoji'],
            'color' => $estilo['color'],
            'url' => route('celebraciones.show', $celebracion->id, false),
            'data' => ['type' => $tipo, 'resource_id' => $celebracion->id],
            'creada_en' => $celebracion->created_at?->toIso8601String(),
            'creada_en_ts' => $celebracion->created_at->timestamp,
            // El propio homenajeado no "lee" su celebración: siempre abierta para él.
            'leida' => $esHomenajeado || $celebracionLecturas->has($celebracion->id),
        ];
    }

    /**
     * @return array{url: string|null, atendida: bool|null, estado_recurso: string|null, mensaje_estado: string|null}
     */
    private function abrirNotification(User $usuario, string $notificacionId): array
    {
        $notificacion = $usuario->notifications()->whereKey($notificacionId)->firstOrFail();
        $notificacion->markAsRead();

        return $this->destinos->resolver($notificacion, $usuario);
    }

    /**
     * @return array{url: string|null, atendida: bool|null, estado_recurso: string|null, mensaje_estado: string|null}
     */
    private function abrirAviso(User $usuario, int $avisoId): array
    {
        $aviso = Aviso::query()->findOrFail($avisoId);
        $this->avisos->marcarLeido($aviso, $usuario);

        // Un aviso no tiene "destino" propio: su contenido ya es la tarjeta
        // del feed; si trae adjunto, la UI lo abre con `action`, no con `url`.
        return ['url' => null, 'atendida' => null, 'estado_recurso' => null, 'mensaje_estado' => null];
    }

    /**
     * @return array{url: string|null, atendida: bool|null, estado_recurso: string|null, mensaje_estado: string|null}
     */
    private function abrirCelebracion(User $usuario, int $celebracionId): array
    {
        $celebracion = BirthdayGreeting::query()->findOrFail($celebracionId);

        if ($celebracion->colaborador_id !== $usuario->colaborador_id) {
            $this->marcarCelebracionLeida($usuario, $celebracionId);
        }

        return ['url' => route('celebraciones.show', $celebracion->id, false), 'atendida' => null, 'estado_recurso' => null, 'mensaje_estado' => null];
    }

    private function marcarCelebracionLeida(User $usuario, int $celebracionId): void
    {
        NotificationRead::query()->firstOrCreate(
            ['source_type' => 'Celebracion', 'source_id' => $celebracionId, 'user_id' => $usuario->id],
            ['read_at' => now()],
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function descomponer(string $id): array
    {
        // Un UUID sin prefijo es el id nativo de una notificación de Laravel:
        // así lo entrega el broadcast en tiempo real (botón "Ver" del toast,
        // useNotificacionesTiempoReal) y así lo mandaban la campana y la app
        // antes de la unificación del feed. Rechazarlo dejaría esos avisos
        // sin poder abrirse (422), así que se acepta como `notification:{uuid}`.
        if (Str::isUuid($id)) {
            return ['notification', $id];
        }

        $partes = explode(':', $id, 2);

        if (count($partes) !== 2 || $partes[0] === '' || $partes[1] === '') {
            throw ValidationException::withMessages(['id' => 'Notificación no reconocida.']);
        }

        return [$partes[0], $partes[1]];
    }

    /**
     * Mismo catálogo emoji/color que la campana web y la API ya usaban
     * (App\Services\Colaboradores\NotificacionesService::ESTILOS) — se
     * reutiliza tal cual para no mantener dos catálogos del mismo mapeo
     * tipo→emoji/color.
     *
     * @return array{emoji: string, color: string}
     */
    private static function estilo(?string $tipo): array
    {
        return NotificacionesService::ESTILOS[(string) $tipo] ?? NotificacionesService::ESTILO_DEFAULT;
    }
}
