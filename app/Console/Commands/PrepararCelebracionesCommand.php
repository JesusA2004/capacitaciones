<?php

namespace App\Console\Commands;

use App\Services\Celebraciones\CelebracionService;
use Illuminate\Console\Command;

/**
 * Prepara las celebraciones del día (docs/CELEBRACIONES.md): crea el evento
 * de cada cumpleaños y aniversario laboral de hoy y genera su tarjeta, para
 * que RH solo tenga que decidir "Enviar al colaborador" / "Avisar a todos".
 * No avisa a nadie salvo configuración explícita de envío automático.
 * Idempotente: correrlo dos veces no crea eventos duplicados.
 */
class PrepararCelebracionesCommand extends Command
{
    protected $signature = 'celebraciones:preparar';

    protected $description = 'Crea los eventos y tarjetas de cumpleaños y aniversarios de hoy';

    public function handle(CelebracionService $celebraciones): int
    {
        $resultado = $celebraciones->prepararDia();

        $this->info(sprintf(
            'Celebraciones de hoy listas: %d cumpleaños, %d aniversarios (%d enviados automáticamente).',
            $resultado['cumpleanos'],
            $resultado['aniversarios'],
            $resultado['enviados'],
        ));

        return self::SUCCESS;
    }
}
