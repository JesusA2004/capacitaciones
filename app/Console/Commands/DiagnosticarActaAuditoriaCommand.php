<?php

namespace App\Console\Commands;

use App\Services\Integraciones\Auditoria\DiagnosticoActaAuditoriaService;
use Illuminate\Console\Command;

/**
 * php artisan auditoria:diagnosticar-acta --sucursal=ATL01 --colaborador=EMP-0201 [--mostrar]
 *
 * SÓLO LECTURA. Por cada dato que imprime el acta de Auditoría (contrato
 * «people-auditoria/2»): tabla/columna de origen, si existe en la base
 * (sí/no + longitud), y si la respuesta v2 que recibe Auditoría lo trae.
 * Los valores no se imprimen salvo con --mostrar (nunca la clave de
 * elector). No escribe nada ni registra datos personales en logs.
 */
class DiagnosticarActaAuditoriaCommand extends Command
{
    protected $signature = 'auditoria:diagnosticar-acta
        {--colaborador=* : Id o número de empleado del colaborador (repetible)}
        {--sucursal=* : Id o clave de la sucursal (repetible)}
        {--mostrar : Muestra los valores (datos personales: sólo en una terminal privada)}';

    protected $description = 'Diagnostica (sólo lectura) de dónde salen y si llegan a Auditoría los datos del acta';

    public function handle(DiagnosticoActaAuditoriaService $diagnostico): int
    {
        $sucursales = array_map('strval', (array) $this->option('sucursal'));
        $colaboradores = array_map('strval', (array) $this->option('colaborador'));
        if ($sucursales === [] && $colaboradores === []) {
            $this->error('Indica al menos una --sucursal o un --colaborador (id, clave o número de empleado).');

            return self::INVALID;
        }
        $mostrar = (bool) $this->option('mostrar');
        $cliente = $diagnostico->cliente();
        $this->line('Cliente de integración «auditoria»: '.($cliente === null ? 'NO existe (la API v2 respondería 401)' : 'existe'));

        foreach ($sucursales as $valor) {
            $ids = $diagnostico->buscarSucursales($valor);
            $this->avisarBusqueda('Sucursal', $valor, $ids);
            foreach ($ids as $id) {
                $this->imprimir("Sucursal {$id}", $diagnostico->sucursal($id, $mostrar), $mostrar);
            }
        }
        foreach ($colaboradores as $valor) {
            $ids = $diagnostico->buscarColaboradores($valor);
            $this->avisarBusqueda('Colaborador', $valor, $ids);
            foreach ($ids as $id) {
                $this->imprimir("Colaborador {$id}", $diagnostico->colaborador($id, $mostrar), $mostrar);
            }
        }

        return self::SUCCESS;
    }

    /** @param  list<int>  $ids */
    private function avisarBusqueda(string $tipo, string $valor, array $ids): void
    {
        if ($ids === []) {
            $this->warn("{$tipo} «{$valor}»: no existe en People.");
        } elseif (count($ids) > 1) {
            $this->warn("{$tipo} «{$valor}»: coincide con varios registros (".implode(', ', $ids).').');
        }
    }

    /** @param  array{id: int, v2: string, filas: list<array<string, mixed>>, otras: list<string>}  $r */
    private function imprimir(string $titulo, array $r, bool $mostrar): void
    {
        $this->newLine();
        $this->info("{$titulo} — respuesta v2: {$r['v2']}");
        $encabezado = ['Dato', 'Tabla.columna', 'En BD', 'Longitud', 'API v2 (ruta)', 'En API v2'];
        if ($mostrar) {
            $encabezado[] = 'Valor';
        }
        $this->table($encabezado, array_map(function (array $f) use ($mostrar): array {
            $fila = [
                $f['dato'], $f['origen'], $f['en_bd'] ? 'sí' : 'no', (string) $f['longitud'], $f['v2'],
                ($f['en_v2'] === null ? '—' : ($f['en_v2'] ? 'sí' : 'no')).($f['nota'] !== null ? ' ('.$f['nota'].')' : ''),
            ];
            if ($mostrar) {
                $fila[] = (string) ($f['valor'] ?? '');
            }

            return $fila;
        }, $r['filas']));
        foreach ($r['otras'] as $nota) {
            $this->line("  · {$nota}");
        }
    }
}
