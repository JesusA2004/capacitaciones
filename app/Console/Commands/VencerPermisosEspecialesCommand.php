<?php

namespace App\Console\Commands;

use App\Services\Permisos\PermisosEspecialesService;
use Illuminate\Console\Command;

/**
 * Retira los permisos especiales por persona cuya vigencia («hasta») ya
 * pasó: desde ese momento ya no contaban (App\Models\User los ignora al
 * vencer); aquí se limpian, se anotan en la bitácora y se avisa a la persona.
 */
class VencerPermisosEspecialesCommand extends Command
{
    protected $signature = 'people:vencer-permisos-especiales';

    protected $description = 'Retira los permisos especiales por persona ya vencidos (bitácora y aviso a la persona)';

    public function handle(PermisosEspecialesService $servicio): int
    {
        $this->info(sprintf('Permisos especiales vencidos retirados: %d', $servicio->vencer()));

        return self::SUCCESS;
    }
}
