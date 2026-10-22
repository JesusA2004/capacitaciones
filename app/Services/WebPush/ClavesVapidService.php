<?php

namespace App\Services\WebPush;

use Illuminate\Support\Facades\Storage;
use Minishlink\WebPush\VAPID;
use Throwable;

/**
 * Llaves VAPID con que el servidor firma cada Web Push. Prioridad:
 *   1. VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY del .env (config/webpush.php).
 *   2. storage/app/private/webpush/vapid.json — generadas UNA vez la
 *      primera vez que se necesitan, para que producción funcione sin pasos
 *      manuales. Nunca se regeneran: cambiar las llaves invalida todas las
 *      suscripciones de los navegadores.
 */
class ClavesVapidService
{
    private const ARCHIVO = 'webpush/vapid.json';

    /** @var array{publicKey: string, privateKey: string}|null */
    private ?array $cache = null;

    public function clavePublica(): ?string
    {
        return $this->claves()['publicKey'] ?? null;
    }

    /**
     * @return array{subject: string, publicKey: string, privateKey: string}|null
     */
    public function vapid(): ?array
    {
        $claves = $this->claves();

        if ($claves === null) {
            return null;
        }

        return [
            'subject' => (string) config('webpush.vapid.subject'),
            'publicKey' => $claves['publicKey'],
            'privateKey' => $claves['privateKey'],
        ];
    }

    /**
     * @return array{publicKey: string, privateKey: string}|null
     */
    private function claves(): ?array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $publica = config('webpush.vapid.public_key');
        $privada = config('webpush.vapid.private_key');

        if (is_string($publica) && $publica !== '' && is_string($privada) && $privada !== '') {
            return $this->cache = ['publicKey' => $publica, 'privateKey' => $privada];
        }

        return $this->cache = $this->desdeArchivo();
    }

    /**
     * @return array{publicKey: string, privateKey: string}|null
     */
    private function desdeArchivo(): ?array
    {
        $disco = Storage::disk('local');

        try {
            if ($disco->exists(self::ARCHIVO)) {
                $datos = json_decode((string) $disco->get(self::ARCHIVO), true);

                if (is_array($datos) && is_string($datos['publicKey'] ?? null) && is_string($datos['privateKey'] ?? null)) {
                    return ['publicKey' => $datos['publicKey'], 'privateKey' => $datos['privateKey']];
                }
            }

            $nuevas = VAPID::createVapidKeys();
            $disco->put(self::ARCHIVO, (string) json_encode($nuevas));

            return ['publicKey' => $nuevas['publicKey'], 'privateKey' => $nuevas['privateKey']];
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
