<?php

namespace App\Services\Cumpleanos;

use App\Enums\EstadoUsuario;
use App\Models\BirthdayGreeting;
use App\Models\BirthdayWallMessage;
use App\Models\MobileDevice;
use App\Models\User;
use App\Notifications\Mobile\CelebracionNotification;
use App\Services\MobilePush\PushNotifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Felicitaciones PRIVADAS de una celebración (cumpleaños o aniversario,
 * docs/CELEBRACIONES.md). No es un foro público:
 *  - cada colaborador escribe (y puede editar/borrar) SU felicitación —una
 *    por persona— y solo ve la suya;
 *  - el homenajeado y RH con `celebraciones.moderar` ven todas;
 *  - la privacidad vive en mensajesVisibles() (consulta), no en la vista.
 *
 * Datos mínimos: del homenajeado nombre, puesto y sucursal (nunca año de
 * nacimiento); de cada autor, nombre, puesto y foto.
 */
class MuroCumpleanosService
{
    public function __construct(
        private readonly CumpleanosStorageService $storage,
        private readonly PushNotifier $push,
    ) {}

    /**
     * Celebraciones publicadas de los últimos `celebraciones.dias_visible`
     * días (incluye las ya cerradas de ese rango, en solo lectura).
     *
     * @return Collection<int, BirthdayGreeting>
     */
    public function visibles(): Collection
    {
        $desde = now('America/Mexico_City')->startOfDay()->subDays(max(0, (int) config('celebraciones.dias_visible', config('cumpleanos.muro_dias_visible', 3))));

        return BirthdayGreeting::query()
            ->whereNotNull('muro_abierto_at')
            ->whereDate('fecha', '>=', $desde->toDateString())
            ->whereHas('colaborador', fn (Builder $q) => $q->where('estatus', EstadoUsuario::Activo->value))
            ->with(['colaborador.puesto:id,nombre', 'colaborador.sucursalPrincipal:id,nombre'])
            ->orderByDesc('fecha')
            ->orderByDesc('muro_abierto_at')
            ->get();
    }

    public function abrir(BirthdayGreeting $greeting, User $rh, bool $avisar = true): BirthdayGreeting
    {
        $primeraVez = $greeting->muro_abierto_at === null;

        $greeting->forceFill([
            'muro_abierto_at' => $greeting->muro_abierto_at ?? now(),
            'muro_abierto_por_id' => $greeting->muro_abierto_por_id ?? $rh->id,
            'muro_cerrado_at' => null,
        ])->save();

        if ($primeraVez && $avisar) {
            $this->avisarApertura($greeting);
        }

        return $greeting;
    }

    public function cerrar(BirthdayGreeting $greeting): BirthdayGreeting
    {
        if ($greeting->muroPublicado() && $greeting->muro_cerrado_at === null) {
            $greeting->forceFill(['muro_cerrado_at' => now()])->save();
        }

        return $greeting;
    }

    public function puedeVerTodos(User $usuario, BirthdayGreeting $greeting): bool
    {
        return Gate::forUser($usuario)->allows('verTodosLosMensajes', $greeting);
    }

    /**
     * La ÚNICA consulta de mensajes: todos para homenajeado/RH moderador;
     * solo el propio para cualquier otro.
     *
     * @return HasMany<BirthdayWallMessage, BirthdayGreeting>
     */
    public function mensajesVisibles(BirthdayGreeting $greeting, User $usuario): HasMany
    {
        $consulta = $greeting->mensajesMuro()->with(['autorColaborador.puesto:id,nombre', 'autor.colaborador.puesto:id,nombre']);

        return $this->puedeVerTodos($usuario, $greeting) ? $consulta : $consulta->where('user_id', $usuario->id);
    }

    public function miMensaje(BirthdayGreeting $greeting, User $usuario): ?BirthdayWallMessage
    {
        return $greeting->mensajesMuro()->where('user_id', $usuario->id)->first();
    }

    public function publicar(BirthdayGreeting $greeting, User $autor, ?string $mensaje, ?UploadedFile $foto): BirthdayWallMessage
    {
        if (! $greeting->muroAbierto()) {
            throw ValidationException::withMessages(['mensaje' => 'La recepción de felicitaciones está cerrada.']);
        }

        if ($autor->colaborador_id !== null && $autor->colaborador_id === $greeting->colaborador_id) {
            throw ValidationException::withMessages(['mensaje' => 'No puedes dejarte una felicitación a ti mismo.']);
        }

        $mensaje = $mensaje !== null ? trim($mensaje) : null;

        if (($mensaje === null || $mensaje === '') && $foto === null) {
            throw ValidationException::withMessages(['mensaje' => 'Escribe un mensaje o agrega una foto.']);
        }

        $ruta = $foto !== null ? $this->guardarFoto($greeting, $foto) : null;

        try {
            $nuevo = DB::transaction(function () use ($greeting, $autor, $mensaje, $ruta, $foto): BirthdayWallMessage {
                // Una felicitación por persona (bloqueo contra doble clic).
                $existente = BirthdayWallMessage::query()
                    ->where('birthday_greeting_id', $greeting->id)
                    ->where('user_id', $autor->id)
                    ->lockForUpdate()
                    ->exists();

                if ($existente) {
                    throw ValidationException::withMessages(['mensaje' => 'Ya enviaste tu felicitación; puedes editarla.']);
                }

                return BirthdayWallMessage::query()->create([
                    'birthday_greeting_id' => $greeting->id,
                    'user_id' => $autor->id,
                    'autor_colaborador_id' => $autor->colaborador_id,
                    'mensaje' => $mensaje !== '' ? $mensaje : null,
                    'foto_path' => $ruta,
                    'foto_mime' => $foto?->getMimeType(),
                ]);
            });
        } catch (Throwable $e) {
            if ($ruta !== null) {
                $this->storage->eliminar($ruta);
            }

            throw $e;
        }

        $this->avisarAlHomenajeado($greeting, $autor);

        return $nuevo;
    }

    /**
     * Solo el autor edita su propio texto, mientras la recepción siga abierta.
     */
    public function actualizar(BirthdayWallMessage $mensaje, User $autor, string $texto): BirthdayWallMessage
    {
        if ($mensaje->user_id !== $autor->id) {
            throw ValidationException::withMessages(['mensaje' => 'Solo puedes editar tu propia felicitación.']);
        }

        if (! $mensaje->greeting->muroAbierto()) {
            throw ValidationException::withMessages(['mensaje' => 'La recepción de felicitaciones está cerrada.']);
        }

        $texto = trim($texto);

        if ($texto === '' && $mensaje->foto_path === null) {
            throw ValidationException::withMessages(['mensaje' => 'Escribe un mensaje.']);
        }

        $mensaje->update(['mensaje' => $texto !== '' ? $texto : null]);

        return $mensaje;
    }

    public function eliminar(BirthdayWallMessage $mensaje, ?User $actor = null): void
    {
        if ($mensaje->foto_path !== null) {
            $this->storage->eliminar($mensaje->foto_path);
        }

        if ($actor !== null && $actor->id !== $mensaje->user_id) {
            activity('celebraciones')
                ->performedOn($mensaje->greeting)
                ->causedBy($actor)
                ->withProperties(['mensaje_id' => $mensaje->id, 'autor_id' => $mensaje->user_id])
                ->log('celebracion_mensaje_moderado');
        }

        $mensaje->delete();
    }

    public function foto(BirthdayWallMessage $mensaje): StreamedResponse
    {
        abort_if($mensaje->foto_path === null || ! $this->storage->existe($mensaje->foto_path), 404);

        return $this->storage->respuesta($mensaje->foto_path, [
            'Content-Type' => $mensaje->foto_mime ?? 'image/jpeg',
            'Content-Disposition' => 'inline; filename="felicitacion"',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    public function puedeEliminar(User $usuario, BirthdayWallMessage $mensaje): bool
    {
        return $mensaje->user_id === $usuario->id || Gate::forUser($usuario)->allows('moderar', $mensaje->greeting);
    }

    /** Usuario de acceso del homenajeado (puede no existir). */
    public function cumpleanero(BirthdayGreeting $greeting): ?User
    {
        return $greeting->colaborador->user;
    }

    /**
     * Forma de la API legacy /api/v1/cumpleanos/muros (se conserva el
     * contrato; `mensajes_count` solo lo ve quien puede ver todos).
     *
     * @return array<string, mixed>
     */
    public function aArray(BirthdayGreeting $greeting, User $viewer): array
    {
        $greeting->loadMissing(['colaborador.puesto:id,nombre', 'colaborador.sucursalPrincipal:id,nombre']);
        $colaborador = $greeting->colaborador;
        $cumpleanero = $this->cumpleanero($greeting);
        $verTodos = $this->puedeVerTodos($viewer, $greeting);

        return [
            'id' => $greeting->id,
            'tipo' => $greeting->tipo->value,
            'fecha' => $greeting->fecha->toDateString(),
            'es_hoy' => $greeting->fecha->isSameDay(now('America/Mexico_City')),
            'abierto' => $greeting->muroAbierto(),
            'abierto_at' => $greeting->muro_abierto_at?->toIso8601String(),
            'cerrado_at' => $greeting->muro_cerrado_at?->toIso8601String(),
            'mensajes_count' => $verTodos ? $greeting->mensajesMuro()->count() : null,
            'puede_ver_todos' => $verTodos,
            'es_mi_muro' => $cumpleanero !== null && $cumpleanero->id === $viewer->id,
            'puede_gestionar' => Gate::forUser($viewer)->allows('moderar', $greeting),
            'cumpleanero' => [
                'id' => $colaborador->id,
                'nombre' => $colaborador->nombreCompleto(),
                'puesto' => $colaborador->puesto?->nombre,
                'sucursal' => $colaborador->sucursalPrincipal?->nombre,
                'foto_url' => $colaborador->foto_path !== null ? route('api.v1.cumpleanos.muros.foto-cumpleanero', $greeting) : null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function mensajeArray(BirthdayWallMessage $mensaje, User $viewer, bool $api = true): array
    {
        $mensaje->loadMissing(['autorColaborador.puesto:id,nombre', 'autor.colaborador.puesto:id,nombre']);
        $autor = $mensaje->autorColaborador ?? $mensaje->autor->colaborador;

        return [
            'id' => $mensaje->id,
            'mensaje' => $mensaje->mensaje,
            'foto_url' => $mensaje->foto_path !== null
                ? ($api
                    ? route('api.v1.cumpleanos.muros.mensajes.foto', [$mensaje->birthday_greeting_id, $mensaje->id])
                    : route('celebraciones.mensajes.foto', [$mensaje->birthday_greeting_id, $mensaje->id]))
                : null,
            'autor' => [
                'id' => $mensaje->autor->id,
                'colaborador_id' => $autor?->id,
                'nombre' => $autor?->nombreCompleto() ?? $mensaje->autor->nombreCompleto(),
                'puesto' => $autor?->puesto?->nombre,
                'foto_url' => $autor?->foto_path !== null
                    ? ($api ? route('api.v1.celebraciones.autor-foto', [$mensaje->birthday_greeting_id, $mensaje->id]) : route('celebraciones.mensajes.autor-foto', [$mensaje->birthday_greeting_id, $mensaje->id]))
                    : null,
            ],
            'es_mio' => $mensaje->user_id === $viewer->id,
            'puede_editar' => $mensaje->user_id === $viewer->id && $mensaje->greeting->muroAbierto(),
            'puede_eliminar' => $this->puedeEliminar($viewer, $mensaje),
            'creado_en' => $mensaje->created_at->toIso8601String(),
            'editado_en' => $mensaje->updated_at !== null && $mensaje->updated_at->gt($mensaje->created_at) ? $mensaje->updated_at->toIso8601String() : null,
        ];
    }

    private function guardarFoto(BirthdayGreeting $greeting, UploadedFile $foto): string
    {
        $extension = match ($foto->getMimeType()) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
        $ruta = "cumpleanos/muro/{$greeting->id}/".Str::uuid()->toString().".{$extension}";
        $this->storage->disco()->put($ruta, (string) file_get_contents($foto->getRealPath()));

        return $ruta;
    }

    /**
     * Push a todos los colaboradores activos con app (menos el homenajeado).
     * Un fallo de push nunca revierte la apertura.
     */
    private function avisarApertura(BirthdayGreeting $greeting): void
    {
        try {
            $homenajeado = $this->cumpleanero($greeting);
            $nombre = $greeting->colaborador->nombreCompleto();
            $data = ['type' => 'cumpleanos_muro', 'resource_id' => $greeting->id, 'related_type' => 'Celebracion', 'accion' => 'abrir_celebracion'];

            User::query()
                ->whereIn('id', MobileDevice::query()->activos()->select('user_id'))
                ->whereHas('colaborador', fn ($q) => $q->where('estatus', EstadoUsuario::Activo->value))
                ->when($homenajeado !== null, fn ($q) => $q->whereKeyNot($homenajeado->id))
                ->chunkById(200, function ($usuarios) use ($nombre, $data) {
                    foreach ($usuarios as $usuario) {
                        $this->push->aUsuarioConDatos($usuario, "Hoy celebramos a {$nombre}", 'Déjale una felicitación.', $data);
                    }
                });
        } catch (Throwable $e) {
            Log::warning('celebraciones: no se pudo avisar la apertura', ['greeting_id' => $greeting->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Al homenajeado, sin spam: como máximo un aviso cada
     * `celebraciones.minutos_entre_avisos_mensajes` minutos por evento; los
     * mensajes que lleguen mientras tanto los ve en su pantalla.
     */
    private function avisarAlHomenajeado(BirthdayGreeting $greeting, User $autor): void
    {
        $homenajeado = $this->cumpleanero($greeting);

        if ($homenajeado === null || $homenajeado->id === $autor->id) {
            return;
        }

        $minutos = max(1, (int) config('celebraciones.minutos_entre_avisos_mensajes', 60));

        if (! Cache::add(sprintf('celebracion:%d:aviso-mensajes', $greeting->id), true, now()->addMinutes($minutos))) {
            return;
        }

        $nombreAutor = $autor->colaborador?->nombreCompleto() ?? $autor->nombreCompleto();
        $titulo = 'Tienes una nueva felicitación';
        $cuerpo = sprintf('%s te dejó un mensaje.', $nombreAutor);

        try {
            $homenajeado->notify(new CelebracionNotification($greeting, 'celebracion_mensaje', $titulo, $cuerpo));
            $this->push->aUsuarioConDatos($homenajeado, $titulo, $cuerpo, [
                'type' => 'celebracion_mensaje',
                'resource_id' => $greeting->id,
                'related_type' => 'Celebracion',
                'accion' => 'abrir_celebracion',
            ]);
        } catch (Throwable $e) {
            Log::warning('celebraciones: no se pudo avisar al homenajeado', ['greeting_id' => $greeting->id, 'error' => $e->getMessage()]);
        }
    }
}
