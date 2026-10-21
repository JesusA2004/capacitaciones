<?php

namespace App\Console\Commands;

use App\Models\Colaborador;
use App\Services\Organigrama\OrganigramaPersonasService;
use Illuminate\Console\Command;

/**
 * Diagnóstico de solo lectura: por qué una persona tiene el jefe directo
 * que tiene. Muestra la cadena del organigrama nivel por nivel (titular,
 * cobertura o plaza VACANTE) y compara el jefe derivado con el guardado en
 * `colaboradores.jefe_id`. No escribe nada: para corregir una copia
 * desactualizada se usa `organigrama:sincronizar-jefes`.
 */
class ExplicarJefeDirectoCommand extends Command
{
    protected $signature = 'organigrama:explicar-jefe
        {colaborador : ID o número de empleado (p. ej. EMP-0131)}';

    protected $description = 'Explica, nivel por nivel, de dónde sale el jefe directo de una persona según el organigrama';

    public function handle(OrganigramaPersonasService $organigrama): int
    {
        $valor = (string) $this->argument('colaborador');

        $persona = Colaborador::query()
            ->with(['puesto:id,nombre', 'sucursalPrincipal:id,nombre', 'jefe:id,name,apellidos'])
            ->where('numero_empleado', $valor)
            ->when(ctype_digit($valor), fn ($q) => $q->orWhere('id', (int) $valor))
            ->first();

        if ($persona === null) {
            $this->error(sprintf('No existe un colaborador con ID o número de empleado «%s».', $valor));

            return self::FAILURE;
        }

        $this->info(sprintf(
            '%s · %s · %s · %s',
            $persona->nombreCompleto(),
            $persona->puesto->nombre ?? 'Sin puesto',
            $persona->sucursalPrincipal->nombre ?? 'Sin sucursal',
            $persona->estatus->etiqueta(),
        ));

        $explicacion = $organigrama->explicarJefe($persona->id);

        if (! $explicacion['vigente']) {
            $this->warn('No está en el organigrama: necesita puesto y estatus Activo o En incorporación.');

            return self::SUCCESS;
        }

        $filas = [];

        foreach ($explicacion['pasos'] as $i => $paso) {
            $filas[] = [
                $i + 1,
                $paso['puesto'],
                $paso['ambito'],
                match ($paso['tipo']) {
                    'persona' => $paso['persona'],
                    'cobertura' => sprintf('%s (cubre temporalmente)', $paso['persona']),
                    default => 'VACANTE',
                },
                $paso['no_vigentes'] === [] ? '' : sprintf('Registrado(s) sin contar: %s', implode(', ', $paso['no_vigentes'])),
            ];
        }

        $this->table(['Nivel', 'Puesto superior', 'Sucursal / región', 'Ocupa', 'Nota'], $filas);

        $derivado = $explicacion['jefe_id'] !== null ? Colaborador::query()->where('id', $explicacion['jefe_id'])->first() : null;

        $this->line(sprintf('Jefe según el organigrama: %s', $derivado?->nombreCompleto() ?? 'ninguno (encabeza la estructura)'));
        $this->line(sprintf('Jefe guardado (jefe_id): %s', $persona->jefe?->nombreCompleto() ?? 'ninguno'));

        if ($persona->jefe_id !== $explicacion['jefe_id']) {
            $this->warn('El jefe guardado está desactualizado: corre «php artisan organigrama:sincronizar-jefes».');
        }

        return self::SUCCESS;
    }
}
