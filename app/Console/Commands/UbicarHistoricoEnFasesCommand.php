<?php

namespace App\Console\Commands;

use App\Enums\EstadoCandidato;
use App\Services\Reclutamiento\HistoricoEnFasesService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Ubica a la plantilla histórica (colaboradores con fecha de ingreso que ya
 * laboraban antes de PEOPLE) en el «Recorrido del candidato». Simula por
 * omisión; `--aplicar` escribe. Idempotente. Toda la lógica vive en
 * HistoricoEnFasesService. No avisa a nadie ni genera QR.
 */
class UbicarHistoricoEnFasesCommand extends Command
{
    protected $signature = 'people:ubicar-historico-en-fases
        {--aplicar : Escribe de verdad (sin esta opción solo simula y lista)}
        {--detalle : Lista a TODOS los colaboradores, no solo el resumen y lo que no cuadra}
        {--colaborador= : Solo este colaborador (ID o número de empleado)}
        {--meses-sin-duracion=6 : Meses de periodo de prueba que se asumen SOLO para puestos sin duración configurada}';

    protected $description = 'Ubica en las fases del recorrido a todo el histórico de colaboradores con fecha de ingreso (candidato histórico + contrato indeterminado en papel).';

    public function handle(HistoricoEnFasesService $historico): int
    {
        $aplicar = (bool) $this->option('aplicar');
        $colaborador = $this->option('colaborador');
        $plan = $historico->plan(
            (int) $this->option('meses-sin-duracion'),
            is_string($colaborador) && $colaborador !== '' ? $colaborador : null,
        );

        $this->line($aplicar ? '<info>MODO APLICAR</info>: se crean los registros históricos.' : '<comment>SIMULACIÓN</comment>: no se modifica nada (agrega --aplicar para escribir).');

        $porCategoria = array_count_values(array_column($plan, 'categoria'));
        $ubicados = array_values(array_filter($plan, fn (array $f): bool => $f['categoria'] === HistoricoEnFasesService::UBICADO));
        $etiquetas = EstadoCandidato::fases();
        $porFase = [];

        foreach ($ubicados as $fila) {
            $fase = (string) $fila['fase_resultante'];
            $porFase[$fase] = ($porFase[$fase] ?? 0) + 1;
        }

        $candidatosCreados = 0;
        $contratosRegistrados = 0;
        $errores = [];

        if ($aplicar) {
            foreach ($ubicados as $fila) {
                try {
                    $resultado = $historico->aplicar($fila);
                    $candidatosCreados += $resultado['candidato_creado'] ? 1 : 0;
                    $contratosRegistrados += $resultado['contrato_registrado'] ? 1 : 0;
                } catch (Throwable $e) {
                    $errores[] = sprintf('%s (#%d): %s', $fila['nombre'], $fila['colaborador_id'], $e->getMessage());
                }
            }
        }

        $this->newLine();
        $this->line('<options=bold>Resumen</>');
        $this->table(['Concepto', 'Colaboradores'], [
            ['Revisados', count($plan)],
            ['Plantilla histórica que se ubica en fases', count($ubicados)],
            ['  · registro histórico por crear', count(array_filter($ubicados, fn (array $f): bool => $f['crear_candidato']))],
            ['  · contrato indeterminado en papel por registrar', count(array_filter($ubicados, fn (array $f): bool => $f['contrato_fisico_desde'] !== null))],
            ['Ya vienen de reclutamiento en PEOPLE (sin cambios)', $porCategoria[HistoricoEnFasesService::CON_RECLUTAMIENTO] ?? 0],
            ['Bajas (conservan historial, no se muestran en fases)', $porCategoria[HistoricoEnFasesService::BAJA] ?? 0],
            ['Alta en curso (se ubican al quedar activos)', $porCategoria[HistoricoEnFasesService::EN_INCORPORACION] ?? 0],
            ['NO CUADRAN: sin fecha de ingreso', $porCategoria[HistoricoEnFasesService::SIN_FECHA_INGRESO] ?? 0],
        ]);

        $this->line('<options=bold>Fase en la que queda la plantilla histórica</>');
        $filasFase = [];

        foreach ($porFase as $fase => $total) {
            $filasFase[] = [$etiquetas[$fase] ?? $fase, $total];
        }

        $this->table(['Fase', 'Colaboradores'], $filasFase === [] ? [['—', 0]] : $filasFase);

        // Lo que no cuadra o no queda en «Contrato indeterminado» SIEMPRE se
        // lista, persona por persona (auditable).
        $atencion = array_values(array_filter($plan, fn (array $f): bool => $f['categoria'] === HistoricoEnFasesService::SIN_FECHA_INGRESO
            || $f['categoria'] === HistoricoEnFasesService::EN_INCORPORACION
            || ($f['categoria'] === HistoricoEnFasesService::UBICADO && ($f['fase_resultante'] !== 'contrato_indeterminado' || str_contains($f['nota'], 'se asumen')))));

        if ($atencion !== []) {
            $this->line('<options=bold>Requieren atención (no cuadran, siguen en periodo o se asumió la duración)</>');
            $this->tabla($atencion, $etiquetas);
        }

        if ((bool) $this->option('detalle')) {
            $this->line('<options=bold>Detalle completo</>');
            $this->tabla($plan, $etiquetas);
        } else {
            $this->comment('Agrega --detalle para listar a todos, persona por persona.');
        }

        if ($aplicar) {
            $this->info(sprintf('Registros históricos creados: %d · contratos indeterminados en papel registrados: %d.', $candidatosCreados, $contratosRegistrados));

            foreach ($errores as $error) {
                $this->error($error);
            }
        } else {
            $this->comment('Nada se modificó. Ejecuta de nuevo con --aplicar para escribir.');
        }

        return $errores === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<array{colaborador_id: int, numero_empleado: string|null, nombre: string, sucursal: string|null, puesto: string|null, fecha_ingreso: string|null, fin_periodo: string|null, categoria: string, crear_candidato: bool, contrato_fisico_desde: string|null, fase_resultante: string|null, nota: string}>  $filas
     * @param  array<string, string>  $etiquetas
     */
    private function tabla(array $filas, array $etiquetas): void
    {
        $this->table(
            ['#', 'No. empleado', 'Colaborador', 'Puesto · Sucursal', 'Ingreso', 'Fin de periodo', 'Fase', 'Indeterminado en papel desde', 'Nota'],
            array_map(fn (array $f): array => [
                $f['colaborador_id'],
                $f['numero_empleado'] ?? '—',
                $f['nombre'],
                sprintf('%s · %s', $f['puesto'] ?? 'Sin puesto', $f['sucursal'] ?? 'Sin sucursal'),
                $f['fecha_ingreso'] ?? '—',
                $f['fin_periodo'] ?? '—',
                $f['fase_resultante'] !== null ? ($etiquetas[$f['fase_resultante']] ?? $f['fase_resultante']) : '—',
                $f['contrato_fisico_desde'] ?? '—',
                $f['nota'],
            ], $filas),
        );
    }
}
