<?php

namespace App\Console\Commands;

use App\Enums\EstadoUsuario;
use App\Models\Baja;
use App\Models\BajaHistorica;
use App\Models\Colaborador;
use App\Models\Sucursal;
use App\Services\Rotacion\FiltrosRotacion;
use App\Services\Rotacion\RotacionAnalisisService;
use App\Services\Rotacion\RotacionMotorService;
use App\Support\FechaMexico;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Diagnóstico de SOLO LECTURA del histórico de rotación (no escribe nada):
 * qué meses tienen detalle individual, cuáles solo el total del Excel, dónde
 * no cuadran, y qué personas dadas de baja en su expediente no tienen ningún
 * registro de baja (posibles eventos faltantes). Pensado para correrlo en el
 * servidor antes y después de cargar el histórico.
 *
 *   php artisan rotacion:diagnostico --anio=2026 --hasta=2026-10-23
 */
class RotacionDiagnosticoCommand extends Command
{
    protected $signature = 'rotacion:diagnostico {--anio= : Año a revisar (por defecto el actual)} {--hasta= : Fecha de corte AAAA-MM-DD (por defecto hoy)}';

    protected $description = 'Revisa (sin modificar nada) qué bajas históricas existen, por mes y fuente, y dónde no cuadran.';

    public function handle(RotacionMotorService $motor, RotacionAnalisisService $analisis): int
    {
        $anio = is_numeric($this->option('anio')) ? (int) $this->option('anio') : FechaMexico::inicioDeHoyInmutable()->year;
        $hastaTexto = $this->option('hasta');
        $hasta = is_string($hastaTexto) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $hastaTexto) === 1
            ? CarbonImmutable::createFromFormat('!Y-m-d', $hastaTexto) ?: FechaMexico::inicioDeHoyInmutable()
            : FechaMexico::inicioDeHoyInmutable();
        $desde = CarbonImmutable::create($anio, 1, 1);
        $hasta = $hasta->min(FechaMexico::inicioDeHoyInmutable());
        $sucursales = Sucursal::query()->pluck('id')->map(fn ($id): int => (int) $id)->values();
        $f = new FiltrosRotacion('rango', $anio, null, $desde, $hasta, $sucursales, null, null, null, null, null, null, null, 'todas', false, externas: true);

        $this->info(sprintf('Rotación %s a %s (todas las sucursales y unidades externas, sin filtros de alcance)', $desde->toDateString(), $hasta->toDateString()));
        $this->line(sprintf('Inicio del módulo de Bajas (config bajas.tablero.mes_inicio_sistema): %s', $motor->mesInicioSistema()->format('Y-m')));

        $celdas = $motor->celdas($f)->groupBy('mes');
        $eventos = $motor->eventos($f, false)->groupBy('mes');
        $filas = [];

        for ($m = $desde; $m->lte($hasta); $m = $m->addMonth()) {
            $clave = $m->format('Y-m');
            $c = $celdas->get($clave, collect());
            $ev = $eventos->get($clave, collect());
            $sinRegistro = $this->sinRegistro($m, $hasta);

            $filas[] = [
                $clave,
                (int) $c->sum('total'),
                $ev->where('fuente', 'sistema')->count(),
                $ev->where('fuente', 'historico')->count(),
                $c->whereNotNull('agregado')->isEmpty() ? '—' : (int) $c->sum('agregado'),
                (int) $c->sum('sin_desglose'),
                $c->where('discrepancia', true)->count(),
                $sinRegistro,
            ];
        }

        $this->table(['Mes', 'Total', 'Sistema', 'Histórico ind.', 'Excel (agregado)', 'Sin desglose', 'Descuadres', 'Expedientes con baja sin registro'], $filas);

        $resumen = $analisis->resumen($f);
        $calidad = $analisis->calidad($f);
        $this->table(['Indicador', 'Valor'], [
            ['Total de bajas', $resumen['total']],
            ['Voluntarias', $resumen['voluntarias']['bajas']],
            ['Involuntarias', $resumen['involuntarias']['bajas']],
            ['Sin clasificar', $resumen['sin_clasificar']['bajas']],
            ['   de ellas sin desglose (Excel)', $resumen['sin_clasificar']['sin_desglose']],
            ['Cuadra total = vol + invol + sin clasificar', $resumen['identidad'] ? 'Sí' : 'NO'],
            ['Bajas antes de 6 meses / con ingreso válido', sprintf('%d / %d', $resumen['temprana']['bajas'], $resumen['temprana']['base'])],
            ['Sin fecha de ingreso', $calidad['sin_fecha_ingreso']],
            ['Fechas inconsistentes', $calidad['fecha_inconsistente']],
            ['Sin causal', $calidad['sin_causal']],
            ['Causal del catálogo sin homologar', $calidad['sin_homologar']],
            ['Posibles duplicados histórico ↔ sistema', $calidad['posibles_duplicados']],
            ['Históricas ligadas como duplicadas', $calidad['duplicadas_ligadas']],
            ['Bajas históricas cargadas (todas)', BajaHistorica::query()->count()],
            ['Bajas del sistema (todas, sin «No procede»)', Baja::query()->where('estatus', '!=', 'cancelada')->count()],
        ]);

        $causales = $analisis->causales($f);
        $this->table(['Causal homologada', 'Bajas', '%'], array_map(fn (array $c): array => [$c['nombre'], $c['bajas'], $c['porcentaje']], $causales['causales']));
        $this->table(['Sucursal', 'Bajas', 'Sin desglose'], array_map(fn (array $s): array => [$s['sucursal'], $s['bajas'], $s['sin_desglose']], $analisis->sucursales($f)));

        foreach ($calidad['discrepancias'] as $d) {
            $this->warn(sprintf('Descuadre %s · %s: Excel %d, individuales %d.', $d['mes'], $d['sucursal'], (int) $d['agregado'], $d['individuales']));
        }

        $this->line('Nada se modificó.');

        return self::SUCCESS;
    }

    /**
     * Personas con fecha de baja en su expediente dentro del mes que no
     * tienen baja del sistema ni baja histórica: posibles eventos faltantes.
     */
    private function sinRegistro(CarbonImmutable $mes, CarbonImmutable $hasta): int
    {
        $fin = $mes->endOfMonth()->startOfDay()->min($hasta);

        return Colaborador::query()->withTrashed()
            ->where('estatus', EstadoUsuario::Inactivo->value)
            ->whereBetween('fecha_baja', [$mes->toDateString(), $fin->toDateString()])
            ->whereNotIn('id', Baja::query()->whereNotNull('colaborador_id')->select('colaborador_id'))
            ->whereNotIn('id', BajaHistorica::query()->whereNotNull('colaborador_id')->select('colaborador_id'))
            ->count();
    }
}
