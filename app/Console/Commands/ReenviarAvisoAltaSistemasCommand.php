<?php

namespace App\Console\Commands;

use App\Models\Colaborador;
use App\Services\Notificaciones\AvisoAltaSistemasService;
use Illuminate\Console\Command;

/**
 * Reenvía el correo «Alta para asignar usuarios» a Sistemas de un
 * colaborador (por número de empleado o id). Para soporte: RH tiene el mismo
 * reenvío en la ficha del candidato.
 */
class ReenviarAvisoAltaSistemasCommand extends Command
{
    protected $signature = 'sistemas:reenviar-aviso-alta {colaborador : Número de empleado o ID del colaborador}';

    protected $description = 'Reenvía a Sistemas el correo de alta (datos para asignar usuarios) de un colaborador.';

    public function handle(AvisoAltaSistemasService $avisos): int
    {
        if (! $avisos->activo()) {
            $this->error('El aviso está desactivado: revisa SISTEMAS_ALTA_CORREO_ACTIVO y SISTEMAS_ALTA_CORREO_PARA en el .env (y php artisan config:cache).');

            return self::FAILURE;
        }

        $clave = trim((string) $this->argument('colaborador'));
        $colaborador = Colaborador::withTrashed()->where('numero_empleado', $clave)->first()
            ?? (ctype_digit($clave) ? Colaborador::withTrashed()->where('id', (int) $clave)->first() : null);

        if ($colaborador === null) {
            $this->error(sprintf('No existe un colaborador con número de empleado o ID «%s».', $clave));

            return self::FAILURE;
        }

        $aviso = $avisos->reencolar($colaborador);
        $cc = $avisos->cc();

        $this->info(sprintf(
            'Reenvío encolado para %s (aviso #%d). Para: %s; cc: %s.',
            $colaborador->nombreCompleto(),
            $aviso->id,
            implode(', ', $avisos->para()),
            $cc !== [] ? implode(', ', $cc) : '—',
        ));

        return self::SUCCESS;
    }
}
