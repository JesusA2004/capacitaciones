<?php

namespace App\Services\Cumpleanos;

use App\Enums\EstadoUsuario;
use App\Models\BirthdayGreeting;
use App\Models\BirthdayWallMessage;
use App\Models\MobileDevice;
use App\Models\User;
use App\Services\MobilePush\PushNotifier;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Muro de felicitaciones de cumpleaños (docs/CUMPLEANOS.md): RH abre el muro
 * de una felicitación y cualquier colaborador activo deja un mensaje y/o una
 * foto. Datos mínimos: del cumpleañero solo nombre, puesto y sucursal (nunca
 * año de nacimiento); de cada autor, nombre y puesto.
 */
class MuroCumpleanosService
{
    public function __construct(
        private readonly CumpleanosStorageService $storage,
        private readonly PushNotifier $push,
    ) {}

    /**
     * Muros visibles hoy: abiertos por RH, de una felicitación de los
     * últimos `cumpleanos.muro_dias_visible` días (incluye los ya cerrados
     * de ese rango, en modo lectura).
     *
     * @return Collection<int, BirthdayGreeting>
     */
    public function visibles(): Collection
    {
        $desde = now()->startOfDay()->subDays(max(0, (int) config('cumpleanos.muro_dias_visible', 3)));

        return BirthdayGreeting::query()
            ->whereNotNull('muro_abierto_at')
            ->whereDate('fecha', '>=', $desde->toDateString())
            ->with(['colaborador.puesto:id,nombre', 'colaborador.sucursalPrincipal:id,nombre'])
            ->withCount('mensajesMuro')
            ->orderByDesc('fecha')
            ->orderByDesc('muro_abierto_at')
            ->get();
    }

    public function abrir(BirthdayGreeting $greeting, User $rh): BirthdayGreeting
    {
        $primeraVez = $greeting->muro_abierto_at === null;

        $greeting->forceFill([
            'muro_abierto_at' => $greeting->muro_abierto_at ?? now(),
            'muro_abierto_por_id' => $greeting->muro_abierto_por_id ?? $rh->id,
            'muro_cerrado_at' => null,
        ])->save();

        if ($primeraVez) {
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

    public function publicar(BirthdayGreeting $greeting, User $autor, ?string $mensaje, ?UploadedFile $foto): BirthdayWallMessage
    {
        if (! $greeting->muroAbierto()) {
            throw ValidationException::withMessages(['mensaje' => 'El muro de felicitaciones ya está cerrado.']);
        }

        $mensaje = $mensaje !== null ? trim($mensaje) : null;
        if (($mensaje === null || $mensaje === '') && $foto === null) {
            throw ValidationException::withMessages(['mensaje' => 'Escribe un mensaje o agrega una foto.']);
        }

        $ruta = null;
        if ($foto !== null) {
            $extension = strtolower($foto->getClientOriginalExtension() ?: $foto->extension() ?: 'jpg');
            $ruta = "cumpleanos/muro/{$greeting->id}/".Str::uuid()->toString().".{$extension}";
            $this->storage->disco()->put($ruta, (string) file_get_contents($foto->getRealPath()));
        }

        try {
            $nuevo = DB::transaction(fn () => BirthdayWallMessage::create([
                'birthday_greeting_id' => $greeting->id,
                'user_id' => $autor->id,
                'mensaje' => $mensaje !== '' ? $mensaje : null,
                'foto_path' => $ruta,
                'foto_mime' => $foto?->getMimeType(),
            ]));
        } catch (Throwable $e) {
            if ($ruta !== null) {
                $this->storage->eliminar($ruta);
            }
            throw $e;
        }

        $this->avisarAlCumpleanero($greeting, $autor);

        return $nuevo;
    }

    public function eliminar(BirthdayWallMessage $mensaje): void
    {
        if ($mensaje->foto_path !== null) {
            $this->storage->eliminar($mensaje->foto_path);
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
        return $mensaje->user_id === $usuario->id || $usuario->can('rh.cumpleanos.muro.gestionar');
    }

    /** Usuario de acceso del cumpleañero (puede no existir). */
    public function cumpleanero(BirthdayGreeting $greeting): ?User
    {
        return $greeting->colaborador->user;
    }

    /**
     * @return array<string, mixed>
     */
    public function aArray(BirthdayGreeting $greeting, User $viewer): array
    {
        $greeting->loadMissing(['colaborador.puesto:id,nombre', 'colaborador.sucursalPrincipal:id,nombre']);
        $colaborador = $greeting->colaborador;
        $cumpleanero = $this->cumpleanero($greeting);

        return [
            'id' => $greeting->id,
            'fecha' => $greeting->fecha->toDateString(),
            'es_hoy' => $greeting->fecha->isToday(),
            'abierto' => $greeting->muroAbierto(),
            'abierto_at' => $greeting->muro_abierto_at?->toIso8601String(),
            'cerrado_at' => $greeting->muro_cerrado_at?->toIso8601String(),
            'mensajes_count' => (int) ($greeting->mensajes_muro_count ?? $greeting->mensajesMuro()->count()),
            'es_mi_muro' => $cumpleanero !== null && $cumpleanero->id === $viewer->id,
            'puede_gestionar' => $viewer->can('rh.cumpleanos.muro.gestionar'),
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
    public function mensajeArray(BirthdayWallMessage $mensaje, User $viewer): array
    {
        $mensaje->loadMissing(['autor.colaborador.puesto:id,nombre']);

        return [
            'id' => $mensaje->id,
            'mensaje' => $mensaje->mensaje,
            'foto_url' => $mensaje->foto_path !== null
                ? route('api.v1.cumpleanos.muros.mensajes.foto', [$mensaje->birthday_greeting_id, $mensaje->id])
                : null,
            'autor' => [
                'id' => $mensaje->autor->id,
                'nombre' => $mensaje->autor->nombreCompleto(),
                'puesto' => $mensaje->autor->colaborador?->puesto?->nombre,
            ],
            'es_mio' => $mensaje->user_id === $viewer->id,
            'puede_eliminar' => $this->puedeEliminar($viewer, $mensaje),
            'creado_en' => $mensaje->created_at->toIso8601String(),
        ];
    }

    /**
     * Push a todos los colaboradores activos con app (menos el cumpleañero,
     * que recibe su propio aviso). Un fallo de push nunca revierte la
     * apertura del muro.
     */
    private function avisarApertura(BirthdayGreeting $greeting): void
    {
        try {
            $cumpleanero = $this->cumpleanero($greeting);
            $nombre = $greeting->colaborador->nombreCompleto();
            $data = ['type' => 'cumpleanos_muro', 'resource_id' => $greeting->id];

            User::query()
                ->whereIn('id', MobileDevice::query()->activos()->select('user_id'))
                ->whereHas('colaborador', fn ($q) => $q->where('estatus', EstadoUsuario::Activo->value))
                ->when($cumpleanero !== null, fn ($q) => $q->whereKeyNot($cumpleanero->id))
                ->chunkById(200, function ($usuarios) use ($nombre, $data) {
                    foreach ($usuarios as $usuario) {
                        $this->push->aUsuarioConDatos($usuario, "Hoy cumple {$nombre}", 'Déjale una felicitación en su muro.', $data);
                    }
                });

            if ($cumpleanero !== null) {
                $this->push->aUsuarioConDatos($cumpleanero, '¡Tu muro de cumpleaños está abierto!', 'Tus compañeros ya pueden dejarte felicitaciones.', $data);
            }
        } catch (Throwable $e) {
            Log::error('cumpleanos: no se pudo avisar la apertura del muro', ['greeting_id' => $greeting->id, 'error' => $e->getMessage()]);
        }
    }

    private function avisarAlCumpleanero(BirthdayGreeting $greeting, User $autor): void
    {
        $cumpleanero = $this->cumpleanero($greeting);
        if ($cumpleanero === null || $cumpleanero->id === $autor->id) {
            return;
        }

        try {
            $this->push->aUsuarioConDatos(
                $cumpleanero,
                'Nueva felicitación',
                "{$autor->nombreCompleto()} te dejó un mensaje en tu muro.",
                ['type' => 'cumpleanos_muro', 'resource_id' => $greeting->id],
            );
        } catch (Throwable $e) {
            Log::error('cumpleanos: no se pudo avisar al cumpleañero', ['greeting_id' => $greeting->id, 'error' => $e->getMessage()]);
        }
    }
}
