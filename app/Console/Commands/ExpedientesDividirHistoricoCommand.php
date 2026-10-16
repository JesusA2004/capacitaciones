<?php

namespace App\Console\Commands;

use App\Enums\EstadoDatoDetectado;
use App\Enums\EstadoDivisionHistorico;
use App\Models\Colaborador;
use App\Models\ExpedienteHistorico;
use App\Models\User;
use App\Services\Autenticacion\NombreUsuarioService;
use App\Services\Expedientes\DivisionHistorico\ComparadorDatosHistorico;
use App\Services\Expedientes\DivisionHistorico\DivisionExpedienteHistoricoService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Divide el PDF histórico único (migración inicial) de cada colaborador por
 * checklist (docs/DIVISION_EXPEDIENTE_HISTORICO.md):
 *
 *   php artisan expedientes:dividir-historico --colaborador=15 --simular
 *   php artisan expedientes:dividir-historico --todos --simular
 *   php artisan expedientes:dividir-historico --todos [--forzar] [--usuario="Nombre Apellido"]
 *
 * --simular lee y clasifica (con OCR si está disponible) pero no escribe
 * nada en el NAS ni en la BD. Sin --simular genera las copias por parte y
 * carga al checklist EN REVISIÓN los tipos que faltaban. El PDF original
 * nunca se corta, mueve ni borra; los datos detectados nunca se aplican
 * solos (RH los confirma en la pantalla).
 *
 * @phpstan-import-type DatoDetectado from ComparadorDatosHistorico
 */
class ExpedientesDividirHistoricoCommand extends Command
{
    protected $signature = 'expedientes:dividir-historico
        {--colaborador= : Id del colaborador}
        {--todos : Todos los expedientes históricos vinculados}
        {--simular : Solo lee y clasifica; no escribe nada}
        {--forzar : Repite la división aunque ya exista una vigente}
        {--usuario= : Usuario (username) de quien ejecuta (por defecto, el primer super_admin)}';

    protected $description = 'Divide el PDF histórico de cada colaborador por documento del checklist y reporta datos faltantes';

    public function handle(DivisionExpedienteHistoricoService $division): int
    {
        $colaboradorId = $this->option('colaborador');
        $todos = (bool) $this->option('todos');
        $simular = (bool) $this->option('simular');

        if (($colaboradorId === null || $colaboradorId === '') === ! $todos) {
            $this->error('Indica --colaborador=ID o --todos (uno de los dos).');

            return self::FAILURE;
        }

        $actor = null;

        if (! $simular) {
            $actor = $this->option('usuario')
                ? app(NombreUsuarioService::class)->buscar((string) $this->option('usuario'))
                : User::role('super_admin')->orderBy('id')->first();

            if ($actor === null) {
                $this->error('No se encontró el usuario que ejecuta (usa --usuario="Nombre Apellido").');

                return self::FAILURE;
            }
        }

        $ocr = $division->estadoOcr();
        $this->line($ocr['disponible']
            ? sprintf('<fg=green>OCR disponible: %s</>', $ocr['motor'])
            : sprintf('<fg=yellow>OCR no disponible: %s Las páginas escaneadas se reportarán sin clasificar.</>', $ocr['motivo'] ?? ''));

        $consulta = ExpedienteHistorico::query()
            ->where('estado', ExpedienteHistorico::VINCULADO)
            ->whereNotNull('colaborador_id')
            ->when(! $todos, fn ($q) => $q->where('colaborador_id', (int) $colaboradorId))
            ->orderBy('id');

        $total = (clone $consulta)->count();

        if ($total === 0) {
            $this->info('No hay expedientes históricos vinculados con ese filtro.');

            return self::SUCCESS;
        }

        $this->line(sprintf('<fg=blue>%d expediente(s) histórico(s) %s.</>', $total, $simular ? 'a simular (no se escribe nada)' : 'a dividir'));
        $errores = 0;

        $consulta->chunkById(50, function ($historicos) use ($division, $actor, &$errores): void {
            foreach ($historicos as $historico) {
                try {
                    $actor instanceof User
                        ? $this->ejecutar($division, $historico, $actor, $errores)
                        : $this->simular($division, $historico);
                } catch (Throwable $e) {
                    $errores++;
                    $this->error(sprintf('  Histórico #%d: %s', $historico->id, $e->getMessage()));
                }
            }
        });

        return $errores > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function simular(DivisionExpedienteHistoricoService $division, ExpedienteHistorico $historico): void
    {
        $analisis = $division->analizar($historico);
        $this->encabezado($historico, $analisis['total_paginas']);

        $this->table(['Páginas', 'Documento', 'Confianza', 'Qué pasaría'], array_map(fn (array $p): array => [
            implode(', ', $p['paginas']),
            $p['tipo_nombre'] ?? 'Sin clasificar',
            $p['confianza'] ?? '—',
            $p['accion']->etiqueta(),
        ], $analisis['partes']));

        $this->reportarDatos($analisis['datos']);
        $this->reportarAdvertencias($analisis['advertencias']);
    }

    private function ejecutar(DivisionExpedienteHistoricoService $division, ExpedienteHistorico $historico, User $actor, int &$errores): void
    {
        $resultado = $division->dividir($historico, $actor, (bool) $this->option('forzar'));
        $this->encabezado($historico, $resultado->total_paginas);

        // dividir() deja la corrida Fallida sin relanzar (para no romper la
        // pantalla de RH). El comando SÍ debe contabilizarlo como error para
        // devolver un exit != 0 que CI/cron puedan detectar.
        if ($resultado->estado === EstadoDivisionHistorico::Fallida) {
            $errores++;
            $this->error(sprintf('  No se pudo dividir: %s', $resultado->error_message));

            return;
        }

        $this->table(['Páginas', 'Documento', 'Confianza', 'Resultado'], $resultado->partes()->with('tipo')->get()->map(fn ($p): array => [
            $p->rangoPaginas(),
            $p->tipo->nombre ?? 'Sin clasificar',
            $p->confianza ?? '—',
            $p->estado->etiqueta(),
        ])->all());

        $colaborador = $historico->colaborador;

        if ($colaborador instanceof Colaborador) {
            $checklist = $division->resumenChecklist($colaborador);
            $this->line(sprintf(
                '  Checklist: %d de %d con archivo, %d aprobados. %s',
                $checklist['entregados'],
                $checklist['requeridos'],
                $checklist['aprobados'],
                $checklist['completo'] ? 'Expediente COMPLETO.' : ($checklist['cubierto'] ? 'Completo en cuanto RH apruebe lo que está en revisión.' : 'Faltan: '.implode(', ', $checklist['faltantes']).'.'),
            ));
        }

        $this->reportarDatos($division->datosDe($resultado));
        $this->reportarAdvertencias($resultado->advertencias ?? []);
    }

    private function encabezado(ExpedienteHistorico $historico, int $paginas): void
    {
        $this->newLine();
        $this->line(sprintf('<options=bold>%s</> — histórico #%d, %d página(s)', $historico->colaborador?->nombreCompleto() ?? 'Sin colaborador', $historico->id, $paginas));
    }

    /**
     * @param  list<DatoDetectado>  $datos
     */
    private function reportarDatos(array $datos): void
    {
        $relevantes = array_filter($datos, fn (array $d): bool => in_array($d['estado'], [EstadoDatoDetectado::Faltante->value, EstadoDatoDetectado::Discrepancia->value, EstadoDatoDetectado::ConflictoDocumentos->value], true));

        if ($relevantes === []) {
            $this->line('  Datos: nada faltante ni distinto detectado.');

            return;
        }

        $this->table(['Dato', 'En el sistema', 'En el PDF', 'Estado'], array_map(fn (array $d): array => [
            $d['etiqueta'],
            $d['actual'] ?? '—',
            implode(' | ', array_column($d['valores'], 'valor')),
            EstadoDatoDetectado::from($d['estado'])->etiqueta(),
        ], array_values($relevantes)));
        $this->line('  RH confirma y aplica estos datos desde Expedientes → Dividir PDF histórico; nunca se aplican solos.');
    }

    /**
     * @param  list<string>  $advertencias
     */
    private function reportarAdvertencias(array $advertencias): void
    {
        foreach ($advertencias as $advertencia) {
            $this->warn('  ⚠ '.$advertencia);
        }
    }
}
