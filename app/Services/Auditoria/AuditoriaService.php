<?php

namespace App\Services\Auditoria;

use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\Models\Activity;
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
     * Historial de quién hizo qué sobre un registro (más reciente primero):
     * para mostrar quién autorizó/cambió/concedió algo.
     *
     * @param  list<string>|null  $acciones  Solo estos eventos (null = todos).
     * @return list<array{accion: string, por: string|null, en: string|null, propiedades: array<string, mixed>}>
     */
    public function historial(Model $sujeto, ?array $acciones = null, int $limite = 20): array
    {
        $actividades = Activity::query()
            ->where('log_name', self::LOG)
            ->where('subject_type', $sujeto->getMorphClass())
            ->where('subject_id', $sujeto->getKey())
            ->when($acciones !== null, fn ($q) => $q->whereIn('event', $acciones))
            ->with('causer')
            ->latest('id')
            ->limit($limite)
            ->get();

        $historial = [];

        foreach ($actividades as $actividad) {
            $propiedades = [];

            foreach ($actividad->properties->toArray() as $clave => $valor) {
                if (is_string($clave) && ! in_array($clave, ['ip', 'user_agent'], true)) {
                    $propiedades[$clave] = $valor;
                }
            }

            $historial[] = [
                'accion' => (string) $actividad->event,
                'por' => $actividad->causer instanceof User ? $actividad->causer->name : null,
                'en' => $actividad->created_at?->toIso8601String(),
                'propiedades' => $propiedades,
            ];
        }

        return $historial;
    }

    /**
     * @param  array<string, mixed>  $propiedades
     */
    public function registrar(string $accion, ?Model $sujeto, ?User $actor, array $propiedades = []): void
    {
        try {
            $propiedades = $this->limpiar($propiedades);

            // La timeline única de cada persona (App\Services\CicloLaboral\TimelineService)
            // lee esta bitácora por colaborador_id/candidato_id: se completan
            // a partir del sujeto cuando el llamador no los pasó.
            if ($sujeto instanceof Colaborador) {
                $propiedades['colaborador_id'] ??= $sujeto->id;
            } elseif ($sujeto instanceof Candidato) {
                $propiedades['candidato_id'] ??= $sujeto->id;
            } elseif ($sujeto !== null && ! isset($propiedades['colaborador_id']) && is_numeric($sujeto->getAttribute('colaborador_id'))) {
                $propiedades['colaborador_id'] = (int) $sujeto->getAttribute('colaborador_id');
            }

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
