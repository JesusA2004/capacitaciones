<?php

namespace App\Console\Commands;

use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\User;
use App\Services\Contratos\ContratoLaboralService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Corrección puntual: un NUEVO contratado cuya fecha de ingreso se capturó con
 * una fecha histórica queda marcado como «tiempo indeterminado» porque su
 * periodo de prueba aparece vencido y la regularización lo auto-renueva. Este
 * comando restaura su capacitación inicial y corrige la fecha, sin borrar
 * historial (el indeterminado auto-creado queda cancelado). La lógica vive en
 * ContratoLaboralService::corregirIngresoNuevoContratado().
 */
class CorregirIngresoContratoCommand extends Command
{
    protected $signature = 'contratos:corregir-ingreso
        {colaborador : ID o número de empleado (p. ej. EMP-0131)}
        {fecha : Fecha de ingreso real, formato AAAA-MM-DD}
        {--actor= : ID del usuario que registra la corrección (por omisión, el primer Super administrador)}
        {--force : No pedir confirmación}';

    protected $description = 'Restaura la capacitación inicial de un nuevo contratado marcado como indeterminado por una fecha de ingreso mal capturada.';

    public function handle(ContratoLaboralService $contratos): int
    {
        $clave = (string) $this->argument('colaborador');
        $colaborador = Colaborador::query()
            ->where('numero_empleado', $clave)
            ->when(ctype_digit($clave), fn ($q) => $q->orWhere('id', (int) $clave))
            ->with('puesto')
            ->first();

        if ($colaborador === null) {
            $this->error("No se encontró al colaborador «{$clave}» (por número de empleado ni por ID).");

            return self::FAILURE;
        }

        try {
            $fecha = CarbonImmutable::parse((string) $this->argument('fecha'));
        } catch (Throwable) {
            $this->error('La fecha no es válida. Usa el formato AAAA-MM-DD (p. ej. 2026-10-21).');

            return self::FAILURE;
        }

        $actor = $this->option('actor') !== null
            ? User::query()->find((int) $this->option('actor'))
            : (User::query()->whereHas('roles', fn ($q) => $q->where('name', 'super_admin'))->first()
                ?? User::query()->permission(ContratoLaboralService::PERMISO_GENERAR)->first());

        if ($actor === null) {
            $this->error('No se pudo determinar el usuario que registra la corrección (--actor).');

            return self::FAILURE;
        }

        $this->line("Colaborador: <info>{$colaborador->nombreCompleto()}</info> ({$colaborador->numero_empleado}) · puesto {$colaborador->puesto?->nombre}");
        $this->line('Fecha de ingreso actual: '.($colaborador->fecha_ingreso?->toDateString() ?? '—')." → nueva: {$fecha->toDateString()}");
        $this->mostrarContratos($colaborador);

        if (! $this->option('force') && ! $this->confirm('¿Restaurar su capacitación inicial con esta fecha?', true)) {
            $this->warn('Cancelado. No se modificó nada.');

            return self::SUCCESS;
        }

        try {
            $resultado = $contratos->corregirIngresoNuevoContratado($colaborador, $fecha, $actor);
        } catch (ValidationException $e) {
            $this->error(collect($e->errors())->flatten()->implode(' '));

            return self::FAILURE;
        }

        $this->info('Listo. Capacitación inicial restaurada.');
        $this->line("  Periodo de prueba: {$resultado['periodo_prueba_inicio']} → {$resultado['periodo_prueba_fin']}");

        if ($resultado['indeterminado_cancelado'] !== null) {
            $this->line("  Indeterminado cancelado: contrato #{$resultado['indeterminado_cancelado']}");
        }

        if ($resultado['documentos_cancelados'] !== []) {
            $this->line('  Documentos cancelados: '.implode(', ', $resultado['documentos_cancelados']));
        }

        $this->mostrarContratos($colaborador->refresh());

        return self::SUCCESS;
    }

    private function mostrarContratos(Colaborador $colaborador): void
    {
        $filas = ContratoLaboral::query()
            ->where('colaborador_id', $colaborador->id)
            ->orderBy('id')
            ->get()
            ->map(fn (ContratoLaboral $c): array => [
                $c->id,
                $c->tipo->value,
                $c->estado->value,
                $c->contrato_anterior_id ?? '—',
                $c->fecha_inicio->toDateString(),
                $c->fecha_fin?->toDateString() ?? '—',
            ])
            ->all();

        $this->table(['#', 'Tipo', 'Estado', 'Anterior', 'Inicio', 'Fin'], $filas);
    }
}
