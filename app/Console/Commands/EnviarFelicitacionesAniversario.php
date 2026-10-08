<?php

namespace App\Console\Commands;

use App\Services\Celebraciones\CelebracionService;
use App\Services\Celebraciones\FechasCelebracion;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Felicita a quienes cumplen años en la empresa HOY (fecha_ingreso, hora de
 * CDMX): notificación in-app + push con su tarjeta, igual que los
 * cumpleaños. Idempotente y a prueba de carreras (ver
 * CelebracionService::enviarAniversariosDelDia()).
 */
class EnviarFelicitacionesAniversario extends Command
{
    protected $signature = 'aniversarios:enviar-felicitaciones {--fecha= : Fecha a procesar (AAAA-MM-DD), por defecto hoy en CDMX}';

    protected $description = 'Envía la felicitación de aniversario laboral (in-app + push) a quienes cumplen años en la empresa hoy';

    public function handle(CelebracionService $celebraciones): int
    {
        $fecha = $this->option('fecha') ? Carbon::parse((string) $this->option('fecha')) : FechasCelebracion::hoy();
        $resultado = $celebraciones->enviarAniversariosDelDia($fecha);

        $this->info(sprintf(
            'Aniversarios del %s: %d. Enviados: %d. Ya enviados: %d. Sin cuenta en la app: %d.',
            $fecha->format('d/m/Y'),
            $resultado['aniversarios'],
            $resultado['enviados'],
            $resultado['ya_enviados'],
            $resultado['sin_cuenta'],
        ));

        return self::SUCCESS;
    }
}
