<?php

namespace App\Console\Commands;

use App\Services\Colaboradores\CorreccionNombresDuplicadosService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Colaboradores cuyo nombre trae también los apellidos al final
 * («NANCY NALLELY LOPEZ GARCIA» + «Lopez Garcia»): los lista y, sin
 * --simular, deja solo el nombre (y sincroniza la cuenta enlazada).
 * Cada corrección queda en la bitácora de auditoría. Idempotente.
 *
 * php artisan colaboradores:corregir-nombres-duplicados --simular
 * php artisan colaboradores:corregir-nombres-duplicados
 */
class CorregirNombresDuplicadosCommand extends Command
{
    protected $signature = 'colaboradores:corregir-nombres-duplicados {--simular : Solo lista lo que se corregiría, sin tocar la BD}';

    protected $description = 'Quita los apellidos repetidos dentro del nombre de los colaboradores (y de su cuenta)';

    public function handle(CorreccionNombresDuplicadosService $servicio): int
    {
        $simular = (bool) $this->option('simular');
        $filas = $servicio->detectar();

        if ($filas === []) {
            $this->info('No hay colaboradores con los apellidos repetidos dentro del nombre.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'No. empleado', 'Nombre actual', 'Nombre corregido', 'Apellidos', 'Cuenta (actual → nueva)'],
            array_map(fn (array $f): array => [
                $f['id'],
                $f['numero_empleado'] ?? '—',
                $f['nombre_actual'],
                $f['nombre_nuevo'],
                $f['apellidos'],
                $f['cuenta_nueva'] !== null ? sprintf('%s → %s', $f['cuenta_actual'], $f['cuenta_nueva']) : ($f['cuenta_actual'] ?? 'sin cuenta'),
            ], $filas),
        );

        if ($simular) {
            $this->info(sprintf('Simulación: se corregirían %d colaborador(es). Corre el comando sin --simular para aplicarlo.', count($filas)));

            return self::SUCCESS;
        }

        $corregidos = 0;
        $fallidos = 0;

        foreach ($filas as $fila) {
            try {
                $servicio->corregir($fila);
                $corregidos++;
            } catch (Throwable $e) {
                $fallidos++;
                $this->error(sprintf('#%d: no se pudo corregir: %s', $fila['id'], $e->getMessage()));
            }
        }

        $this->info(sprintf('Corregidos: %d. Fallidos: %d.', $corregidos, $fallidos));

        return $fallidos === 0 ? self::SUCCESS : self::FAILURE;
    }
}
