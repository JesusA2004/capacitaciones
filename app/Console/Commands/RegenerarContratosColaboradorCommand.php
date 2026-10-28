<?php

namespace App\Console\Commands;

use App\Models\Colaborador;
use App\Models\User;
use App\Services\Contratos\ContratoLaboralService;
use App\Services\DocumentosMaestros\DocumentoProcesoService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Regenera (nueva versión) los documentos contractuales ya generados de un
 * colaborador — misma vía que el botón «Regenerar» de RH
 * (DocumentoProcesoService::generar con regenerar: true). Útil tras corregir un
 * dato que ya quedó impreso en un PDF viejo (p. ej. la jurisdicción de la
 * sucursal) y recompilar los masters con `documentos:normalizar-masters`.
 *
 * Seguro: respeta la política `regenerar` (un documento ya firmado físicamente
 * no se regenera sin el proceso de anulación auditado); cada documento sin
 * permiso o con error se reporta sin tumbar el resto.
 */
class RegenerarContratosColaboradorCommand extends Command
{
    protected $signature = 'contratos:regenerar
        {colaborador : ID o número de empleado (p. ej. EMP-0131)}
        {--clave=* : Claves a regenerar (por omisión, todos los documentos generados del contrato vigente)}
        {--motivo= : Motivo que queda en la auditoría (mínimo 15 caracteres)}
        {--actor= : ID del usuario que regenera (por omisión, el primer super_admin)}
        {--force : No pedir confirmación}';

    protected $description = 'Regenera los documentos contractuales de un colaborador (nueva versión) tras corregir datos o recompilar los masters.';

    public function handle(ContratoLaboralService $contratos, DocumentoProcesoService $documentos): int
    {
        $clave = (string) $this->argument('colaborador');
        $colaborador = Colaborador::query()
            ->where('numero_empleado', $clave)
            ->when(ctype_digit($clave), fn ($q) => $q->orWhere('id', (int) $clave))
            ->first();

        if ($colaborador === null) {
            $this->error("No se encontró al colaborador «{$clave}».");

            return self::FAILURE;
        }

        $contrato = $contratos->vigente($colaborador);

        if ($contrato === null) {
            $this->error("{$colaborador->nombreCompleto()} no tiene un contrato vigente.");

            return self::FAILURE;
        }

        $actor = $this->option('actor') !== null
            ? User::query()->find((int) $this->option('actor'))
            : (User::query()->whereHas('roles', fn ($q) => $q->where('name', 'super_admin'))->first()
                ?? User::query()->permission(ContratoLaboralService::PERMISO_GENERAR)->first());

        if ($actor === null) {
            $this->error('No se pudo determinar el usuario que regenera (--actor).');

            return self::FAILURE;
        }

        // El contrato inicial pertenece al proceso de alta; la renovación a
        // tiempo indeterminado, al de renovación.
        $proceso = $contrato->contrato_anterior_id === null ? 'alta' : 'renovacion';

        /** @var list<string> $claves */
        $claves = $this->option('clave') !== []
            ? array_values(array_unique(array_map('strval', (array) $this->option('clave'))))
            : $contratos->documentosDelContrato($contrato)
                ->pluck('clave_plantilla')->filter()->unique()->values()->all();

        if ($claves === []) {
            $this->warn('El contrato vigente no tiene documentos generados que regenerar.');

            return self::SUCCESS;
        }

        $motivo = (string) ($this->option('motivo')
            ?? 'Regeneración por corrección de datos del colaborador o recompilación de los formatos.');

        if (mb_strlen($motivo) < DocumentoProcesoService::MINIMO_MOTIVO_REGENERAR) {
            $this->error(sprintf('El motivo debe tener al menos %d caracteres.', DocumentoProcesoService::MINIMO_MOTIVO_REGENERAR));

            return self::FAILURE;
        }

        $this->line("Colaborador: <info>{$colaborador->nombreCompleto()}</info> ({$colaborador->numero_empleado})");
        $this->line("Contrato vigente #{$contrato->id} ({$contrato->tipo->value}) · proceso «{$proceso}»");
        $this->line('Documentos a regenerar: '.implode(', ', $claves));

        if (! $this->option('force') && ! $this->confirm('¿Generar una versión nueva de estos documentos?', true)) {
            $this->warn('Cancelado. No se regeneró nada.');

            return self::SUCCESS;
        }

        $ok = 0;
        $fallas = 0;

        foreach ($claves as $c) {
            try {
                $documento = $documentos->generar($proceso, $contrato, $c, $actor, regenerar: true, motivoRevision: $motivo);
                $this->info(sprintf('  ✓ %s → documento #%d', $c, $documento->id));
                $ok++;
            } catch (ValidationException $e) {
                $this->error(sprintf('  ✗ %s: %s', $c, collect($e->errors())->flatten()->implode(' ')));
                $fallas++;
            } catch (Throwable $e) {
                $this->error(sprintf('  ✗ %s: %s', $c, $e->getMessage()));
                $fallas++;
            }
        }

        $this->line(sprintf('Regenerados: %d · con problema: %d', $ok, $fallas));

        return $fallas > 0 && $ok === 0 ? self::FAILURE : self::SUCCESS;
    }
}
