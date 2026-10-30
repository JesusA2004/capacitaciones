<?php

namespace App\Services\Salarios;

use App\Models\Puesto;
use App\Models\TabuladorSalarial;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tabulador por puesto (mínimo, medio, máximo mensual). Cada cambio agrega una
 * versión y cierra la anterior. Nunca toca el sueldo de un colaborador: ese solo
 * cambia por CompensacionService y su historial.
 */
class TabuladorSalarialService
{
    public function __construct(private readonly AuditoriaService $auditoria) {}

    /** @return Collection<int, TabuladorSalarial> vigente por puesto_id */
    public function vigentes(): Collection
    {
        return TabuladorSalarial::query()->whereNull('vigente_hasta')->get()->keyBy('puesto_id');
    }

    /** @return Collection<int, TabuladorSalarial> */
    public function versiones(int $puestoId): Collection
    {
        return TabuladorSalarial::query()->with('usuario:id,name')->where('puesto_id', $puestoId)->orderByDesc('version')->get();
    }

    /**
     * @param  array{minimo: mixed, medio: mixed, maximo: mixed, fuente: string, fecha_actualizacion: string, motivo?: string|null}  $datos
     * @return TabuladorSalarial|null null si no hubo ningún cambio (no se crea versión)
     */
    public function guardar(Puesto $puesto, array $datos, User $usuario, string $origen = 'captura', ?int $importacionId = null): ?TabuladorSalarial
    {
        $minimo = round((float) $datos['minimo'], 2);
        $medio = round((float) $datos['medio'], 2);
        $maximo = round((float) $datos['maximo'], 2);
        $error = self::errorDeRango($minimo, $medio, $maximo);

        if ($error !== null) {
            throw ValidationException::withMessages(['minimo' => $error]);
        }

        return DB::transaction(function () use ($puesto, $datos, $usuario, $origen, $importacionId, $minimo, $medio, $maximo): ?TabuladorSalarial {
            // Bloquear el puesto serializa dos capturas simultáneas de la misma versión.
            Puesto::query()->whereKey($puesto->id)->lockForUpdate()->first();

            $actual = TabuladorSalarial::query()->where('puesto_id', $puesto->id)->whereNull('vigente_hasta')->first();

            if ($actual !== null
                && (float) $actual->minimo === $minimo && (float) $actual->medio === $medio && (float) $actual->maximo === $maximo
                && $actual->fuente === $datos['fuente'] && $actual->fecha_actualizacion->toDateString() === $datos['fecha_actualizacion']) {
                return null;
            }

            $version = (int) TabuladorSalarial::query()->where('puesto_id', $puesto->id)->max('version') + 1;
            $actual?->update(['vigente_hasta' => now()->toDateString()]);

            $nueva = TabuladorSalarial::query()->create([
                'puesto_id' => $puesto->id,
                'version' => $version,
                'minimo' => $minimo,
                'medio' => $medio,
                'maximo' => $maximo,
                'fuente' => $datos['fuente'],
                'fecha_actualizacion' => $datos['fecha_actualizacion'],
                'vigente_desde' => now()->toDateString(),
                'origen' => $origen,
                'motivo' => $datos['motivo'] ?? null,
                'importacion_id' => $importacionId,
                'usuario_id' => $usuario->id,
            ]);

            // Sin importes en la bitácora: los valores viven en la versión.
            $this->auditoria->registrar('salarios.tabulador_actualizado', $nueva, $usuario, [
                'puesto_id' => $puesto->id,
                'version' => $version,
                'origen' => $origen,
            ]);

            return $nueva;
        });
    }

    /** Mensaje de error si el rango es inválido; null si está bien. */
    public static function errorDeRango(float $minimo, float $medio, float $maximo): ?string
    {
        if ($minimo <= 0 || $medio <= 0 || $maximo <= 0) {
            return 'Los tres sueldos deben ser mayores a cero.';
        }

        return $minimo <= $medio && $medio <= $maximo ? null : 'El rango debe cumplir mínimo ≤ medio ≤ máximo.';
    }
}
