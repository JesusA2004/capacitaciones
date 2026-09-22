<?php

namespace App\Services\Auditoria;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Trazabilidad explícita de acciones sensibles del ciclo laboral (alta,
 * baja, cambios de estructura, generación/firma/envío/recepción de
 * documentos, renovación, finiquito, préstamo, recibos, actas...) sobre
 * Spatie Activitylog (tabla activity_log, log "rh"). Complementa el
 * LogsActivity de los modelos (que solo registra cambios de columnas) con
 * el "qué acción de negocio" y el contexto IP/user agent.
 *
 * Nunca guarda contraseñas, tokens ni rutas físicas del NAS: las claves
 * sensibles se eliminan de las propiedades antes de escribir.
 */
class AuditoriaService
{
    public const LOG = 'rh';

    /**
     * Claves que nunca deben terminar en la bitácora aunque un llamador las
     * pase por descuido.
     *
     * @var list<string>
     */
    private const CLAVES_SENSIBLES = ['password', 'password_confirmation', 'token', 'access_token', 'remember_token', 'two_factor_secret', 'path', 'disk', 'storage_path'];

    public function __construct(private readonly ?Request $request = null) {}

    /**
     * @param  array<string, mixed>  $propiedades
     */
    public function registrar(string $accion, ?Model $sujeto, ?User $actor, array $propiedades = []): void
    {
        try {
            $propiedades = $this->limpiar($propiedades);

            if ($this->request !== null && $this->request->ip() !== null) {
                $propiedades['ip'] = $this->request->ip();
                $propiedades['user_agent'] = mb_substr((string) $this->request->userAgent(), 0, 500);
            }

            $registro = activity(self::LOG)->event($accion)->withProperties($propiedades);

            if ($sujeto !== null) {
                $registro->performedOn($sujeto);
            }

            if ($actor !== null) {
                $registro->causedBy($actor);
            }

            $registro->log($accion);
        } catch (Throwable $e) {
            // La auditoría nunca debe tumbar la operación de negocio que ya
            // se ejecutó; el fallo queda en el log de la aplicación.
            Log::warning('AuditoriaService: no se pudo registrar la acción.', ['accion' => $accion, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @param  array<string, mixed>  $propiedades
     * @return array<string, mixed>
     */
    private function limpiar(array $propiedades): array
    {
        foreach ($propiedades as $clave => $valor) {
            if (in_array(strtolower((string) $clave), self::CLAVES_SENSIBLES, true)) {
                unset($propiedades[$clave]);

                continue;
            }

            if (is_array($valor)) {
                $propiedades[$clave] = $this->limpiar($valor);
            }
        }

        return $propiedades;
    }
}
