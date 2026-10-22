<?php

namespace App\Services\WebPush;

use App\Jobs\SendWebPushJob;
use App\Models\User;
use App\Models\WebPushSuscripcion;
use Illuminate\Support\Str;

/**
 * Equivalente web de App\Services\MobilePush\PushNotifier: encola un
 * App\Jobs\SendWebPushJob por cada navegador donde el usuario activó las
 * notificaciones de escritorio. PushNotifier lo llama en CADA envío, así que
 * todo lo que llega a la app llega también al navegador — sin que ningún
 * servicio de negocio tenga que acordarse de los dos canales.
 */
class WebPushNotifier
{
    public function __construct(private readonly ClavesVapidService $claves) {}

    public function habilitado(): bool
    {
        return (bool) config('webpush.enabled') && $this->claves->clavePublica() !== null;
    }

    /**
     * @param  array<string, mixed>  $data  Mismo `data` del push móvil (type, resource_id, notification_id…).
     * @return int Navegadores a los que se encoló el aviso.
     */
    public function aUsuario(User $usuario, string $titulo, string $cuerpo, array $data): int
    {
        if (! $this->habilitado()) {
            return 0;
        }

        $ids = WebPushSuscripcion::query()->where('user_id', $usuario->id)->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        $payload = $this->payload($titulo, $cuerpo, $data);

        foreach ($ids as $id) {
            SendWebPushJob::dispatch((int) $id, $payload);
        }

        return $ids->count();
    }

    /**
     * Lo que recibe public/sw.js. Nunca PII extra: solo lo que ya trae el
     * push móvil (título, cuerpo, tipo e ids internos).
     *
     * @param  array<string, mixed>  $data
     * @return array{titulo: string, cuerpo: string, url: string, tag: string, color: string|null, tipo: string|null, icono: string, insignia: string|null}
     */
    public function payload(string $titulo, string $cuerpo, array $data): array
    {
        $tipo = is_string($data['type'] ?? null) ? $data['type'] : null;
        $idFeed = $this->idFeed($data);

        return [
            'titulo' => $titulo,
            'cuerpo' => Str::limit($cuerpo, 240),
            // Al hacer clic se abre la misma ruta que la campana (marca
            // leída y redirige a su destino real).
            'url' => $idFeed !== null
                ? route('notificaciones.ir', ['notificacion' => $idFeed], false)
                : route('notificaciones.centro', [], false),
            // Mismo aviso repetido (reintento, reconciliador) reemplaza la
            // notificación del sistema en vez de apilarse.
            'tag' => 'people-'.($idFeed ?? Str::uuid()->toString()),
            'color' => is_string($data['color'] ?? null) ? $data['color'] : null,
            'tipo' => $tipo,
            'icono' => (string) config('webpush.icono'),
            'insignia' => is_string(config('webpush.insignia')) ? config('webpush.insignia') : null,
        ];
    }

    /**
     * Id del feed unificado (NotificationFeedService::abrir) a partir del
     * `data` del push: notificación de Laravel, aviso de RH o celebración.
     *
     * @param  array<string, mixed>  $data
     */
    private function idFeed(array $data): ?string
    {
        $notificacion = $data['notification_id'] ?? null;

        if (is_string($notificacion) && $notificacion !== '') {
            return $notificacion;
        }

        $recurso = $data['resource_id'] ?? null;

        if (! is_int($recurso) && ! (is_string($recurso) && ctype_digit($recurso))) {
            return null;
        }

        if (($data['type'] ?? null) === 'aviso_rh') {
            return sprintf('aviso:%s', $recurso);
        }

        if (($data['related_type'] ?? null) === 'Celebracion') {
            return sprintf('celebracion:%s', $recurso);
        }

        return null;
    }
}
