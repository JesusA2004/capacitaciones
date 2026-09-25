<?php

namespace App\Console\Commands;

use App\Services\Celebraciones\FechasCelebracion;
use App\Services\Cumpleanos\CumpleanosService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Felicita automaticamente a los colaboradores activos que cumplen anios
 * hoy: genera su BirthdayGreeting (idempotente por user_id+fecha, ver
 * App\Services\Cumpleanos\BirthdayCardService) y notifica in-app + push. Si
 * ya se notifico hoy (BirthdayGreeting.enviada_at) no se duplica, asi que
 * correrlo dos veces el mismo dia es seguro. Un fallo en la tarjeta/push de
 * un colaborador se registra en log y no detiene al resto del lote.
 */
class EnviarFelicitacionesCumpleanos extends Command
{
    protected $signature = 'cumpleanos:enviar-felicitaciones';

    protected $description = 'Genera y envía la felicitación de cumpleaños a los colaboradores activos que cumplen años hoy';

    public function handle(CumpleanosService $cumpleanos): int
    {
        if (! (bool) config('cumpleanos.enabled')) {
            $this->info('Módulo de cumpleaños deshabilitado (CUMPLEANOS_ENABLED=false). No se hizo nada.');

            return self::SUCCESS;
        }

        $hoy = FechasCelebracion::hoy();
        $colaboradores = $cumpleanos->cumpleanosDeHoy();

        $enviadas = 0;
        $fallidas = 0;

        foreach ($colaboradores as $colaborador) {
            try {
                $cumpleanos->felicitarColaborador($colaborador, $hoy);
                $enviadas++;
            } catch (\Throwable $e) {
                $fallidas++;
                Log::error('cumpleanos:enviar-felicitaciones: fallo con un colaborador', [
                    'user_id' => $colaborador->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Cumpleaños de hoy: {$colaboradores->count()}. Felicitaciones procesadas: {$enviadas}. Fallidas: {$fallidas}.");

        return self::SUCCESS;
    }
}
