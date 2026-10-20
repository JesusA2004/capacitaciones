<?php

namespace App\Services\Contratos;

use App\Enums\EstadoLoteContratos;
use App\Enums\ResultadoGeneracionContratos as Resultado;
use App\Jobs\GenerarContratosLoteJob;
use App\Models\Colaborador;
use App\Models\LoteGeneracionContratos;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * «Generar todos» del módulo Contratos: corre en la cola
 * (GenerarContratosLoteJob) la regularización idempotente de cada
 * colaborador activo del alcance de quien la lanzó
 * (RegularizacionContratosService::generarColaborador) y deja el progreso y
 * el resumen auditables en lotes_generacion_contratos.
 */
class LoteContratosService
{
    public function __construct(
        private readonly RegularizacionContratosService $regularizacion,
        private readonly AuditoriaService $auditoria,
    ) {}

    public function iniciar(User $actor): LoteGeneracionContratos
    {
        if (! $this->regularizacion->puedeGenerar($actor)) {
            throw new AuthorizationException('No tienes permiso para generar contratos.');
        }

        $lote = DB::transaction(function () use ($actor): LoteGeneracionContratos {
            $enCurso = LoteGeneracionContratos::query()
                ->whereIn('estado', [EstadoLoteContratos::EnCola->value, EstadoLoteContratos::Procesando->value])
                ->lockForUpdate()
                ->exists();

            if ($enCurso) {
                throw ValidationException::withMessages(['lote' => 'Ya hay una generación de contratos en proceso. Espera a que termine.']);
            }

            return LoteGeneracionContratos::query()->create([
                'iniciado_por' => $actor->id,
                'estado' => EstadoLoteContratos::EnCola,
                'total' => $this->regularizacion->queryActivos($actor)->count(),
                'resultados' => [],
            ]);
        });

        $this->auditoria->registrar('contratos_lote_iniciado', $lote, $actor, ['total' => $lote->total]);
        GenerarContratosLoteJob::dispatch($lote->id, $actor->id);

        return $lote;
    }

    /**
     * Procesa el lote (lo llama el Job). Cada colaborador es independiente:
     * un error en uno se registra y el lote sigue.
     */
    public function procesar(LoteGeneracionContratos $lote, User $actor): LoteGeneracionContratos
    {
        if (! $lote->estado->enCurso()) {
            return $lote;
        }

        $ids = $this->regularizacion->queryActivos($actor)->pluck('id');
        $lote->update(['estado' => EstadoLoteContratos::Procesando, 'iniciado_en' => now(), 'total' => $ids->count()]);
        $resultados = [];
        $conteo = ['generados' => 0, 'sin_cambios' => 0, 'omitidos' => 0, 'errores' => 0];

        foreach ($ids as $indice => $id) {
            $colaborador = Colaborador::query()->with(['puesto', 'sucursalPrincipal'])->where('id', $id)->first();

            if ($colaborador !== null) {
                try {
                    $resultado = $this->regularizacion->generarColaborador($colaborador, $actor);
                } catch (Throwable $e) {
                    Log::warning('LoteContratosService: fallo con un colaborador.', ['lote_id' => $lote->id, 'colaborador_id' => $id, 'error' => $e->getMessage()]);
                    $resultado = [
                        'colaborador_id' => $colaborador->id,
                        'nombre' => $colaborador->nombreCompleto(),
                        'resultado' => Resultado::Error->value,
                        'resultado_etiqueta' => Resultado::Error->etiqueta(),
                        'motivo' => $e->getMessage(),
                        'documentos' => [],
                    ];
                }

                $clave = match ($resultado['resultado']) {
                    Resultado::Generado->value => 'generados',
                    Resultado::SinCambios->value => 'sin_cambios',
                    Resultado::Omitido->value => 'omitidos',
                    default => 'errores',
                };
                $conteo[$clave]++;

                // «Ya tenía sus contratos» solo cuenta: el detalle auditable
                // es lo generado, lo omitido (con motivo) y los errores.
                if ($clave !== 'sin_cambios') {
                    $resultados[] = $resultado;
                }
            }

            $lote->update([...$conteo, 'procesados' => $indice + 1, 'resultados' => $resultados]);
        }

        $lote->update(['estado' => EstadoLoteContratos::Completado, 'terminado_en' => now()]);
        $this->auditoria->registrar('contratos_lote_completado', $lote, $actor, [...$conteo, 'total' => $lote->total]);

        return $lote->refresh();
    }

    public function marcarFallido(int $loteId, string $error): void
    {
        LoteGeneracionContratos::query()->whereKey($loteId)
            ->whereIn('estado', [EstadoLoteContratos::EnCola->value, EstadoLoteContratos::Procesando->value])
            ->update(['estado' => EstadoLoteContratos::Fallido->value, 'error' => $error, 'terminado_en' => now()]);
    }

    public function ultimo(): ?LoteGeneracionContratos
    {
        return LoteGeneracionContratos::query()->with('usuario:id,name,apellidos')->latest('id')->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function aArray(LoteGeneracionContratos $lote): array
    {
        return [
            'id' => $lote->id,
            'estado' => $lote->estado->value,
            'estado_etiqueta' => $lote->estado->etiqueta(),
            'en_curso' => $lote->estado->enCurso(),
            'total' => $lote->total,
            'procesados' => $lote->procesados,
            'generados' => $lote->generados,
            'sin_cambios' => $lote->sin_cambios,
            'omitidos' => $lote->omitidos,
            'errores' => $lote->errores,
            'resultados' => $lote->resultados ?? [],
            'error' => $lote->error,
            'iniciado_por' => $lote->usuario !== null ? trim(sprintf('%s %s', $lote->usuario->name, $lote->usuario->apellidos)) : null,
            'creado_en' => $lote->created_at?->toIso8601String(),
            'terminado_en' => $lote->terminado_en?->toIso8601String(),
        ];
    }
}
