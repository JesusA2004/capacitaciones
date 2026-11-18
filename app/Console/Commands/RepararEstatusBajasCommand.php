<?php

namespace App\Console\Commands;

use App\Services\Colaboradores\EstatusLaboralService;
use Illuminate\Console\Command;

/**
 * Deja coherentes a las personas dadas de baja (EstatusLaboralService):
 * estatus Inactivo, estado del alta «Baja», fecha de baja, acceso bloqueado
 * (tokens y dispositivos) y procesos de origen cerrados (onboarding, QR,
 * candidato en contratación, pendiente de alta en sistemas). Las bajas
 * programadas las aplica a diario cierres:revisar-fechas; este comando
 * repara lo que ya quedó inconsistente.
 *
 * Por defecto SOLO LISTA (auditable: registro, campo, valor actual → nuevo).
 * Nunca borra nada.
 */
class RepararEstatusBajasCommand extends Command
{
    protected $signature = 'bajas:reparar-estatus
        {--aplicar : Corrige lo listado (sin esta opción solo muestra qué cambiaría)}
        {--colaborador= : Solo este colaborador (id)}';

    protected $description = 'Lista (y con --aplicar corrige) personas dadas de baja que siguen apareciendo activas o con procesos/acceso abiertos';

    public function handle(EstatusLaboralService $estatus): int
    {
        $solo = is_numeric($this->option('colaborador')) ? (int) $this->option('colaborador') : null;
        $hallazgos = $estatus->diagnosticar($solo);

        if ($hallazgos === []) {
            $this->info('Sin inconsistencias: toda baja aplicada ya está reflejada en estatus, acceso y procesos.');

            return self::SUCCESS;
        }

        $this->table(
            ['Colaborador', 'Nombre', 'Registro', 'Campo', 'Valor actual', 'Valor nuevo'],
            array_map(fn (array $h): array => [$h['colaborador_id'], $h['nombre'], $h['registro'], $h['campo'], $h['actual'], $h['nuevo']], $hallazgos),
        );

        $personas = count(array_unique(array_column($hallazgos, 'colaborador_id')));

        if (! $this->option('aplicar')) {
            $this->warn(sprintf('%d cambio(s) en %d persona(s). Solo se listó: corre con --aplicar para corregir.', count($hallazgos), $personas));

            return self::SUCCESS;
        }

        $corregidos = $estatus->reparar($solo);
        $this->info(sprintf('Corregidas %d persona(s). Las filas «NO CUADRA» requieren captura manual.', $corregidos));

        return self::SUCCESS;
    }
}
