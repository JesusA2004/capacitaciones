<?php

namespace App\Services\Rotacion;

use App\Models\User;

/**
 * Hallazgos cortos y calculados (nunca texto decorativo): cada uno sale de las
 * mismas cifras del tablero y trae solo las acciones que el usuario puede
 * abrir. Son patrones para que RH investigue, no conclusiones sobre causas.
 *
 * Umbrales en config/rotacion.php → hallazgos.
 */
class HallazgosRotacionService
{
    /**
     * @param  array<string, mixed>  $resumen  RotacionAnalisisService::resumen()
     * @param  array<string, mixed>  $causales  RotacionAnalisisService::causales()
     * @param  array<string, mixed>  $calidad  RotacionAnalisisService::calidad()
     * @return list<array{clave: string, tono: string, titulo: string, detalle: string, acciones: list<array{etiqueta: string, tipo: string, href?: string, filtros?: array<string, mixed>}>}>
     */
    public function hallazgos(User $usuario, FiltrosRotacion $f, array $resumen, array $causales, array $calidad): array
    {
        $hallazgos = [];
        $total = (int) $resumen['total'];
        $umbralOferta = (float) config('rotacion.hallazgos.oferta_minimo_pct', 15);
        $umbralTemprana = (float) config('rotacion.hallazgos.temprana_minimo_pct', 30);
        $top = array_slice($causales['causales'], 0, 3);

        if ($total === 0) {
            return [];
        }

        foreach ($top as $causal) {
            if ($causal['clave'] === 'oferta_laboral' && ($causal['porcentaje'] ?? 0) >= $umbralOferta) {
                $acciones = [['etiqueta' => 'Ver colaboradores', 'tipo' => 'filtro', 'filtros' => ['causal_analitica_id' => $causal['id'], 'seccion' => 'historico']]];

                if ($usuario->can('salarios.ver')) {
                    $acciones[] = ['etiqueta' => 'Revisar salarios', 'tipo' => 'enlace', 'href' => route('administracion.puestos.index', [], false)];
                }

                $hallazgos[] = [
                    'clave' => 'ofertas',
                    'tono' => 'alerta',
                    'titulo' => 'Las ofertas laborales concentran una parte importante de las bajas',
                    'detalle' => sprintf('%d de %d bajas con causal (%s %%).', $causal['bajas'], $causales['base'], $this->numero($causal['porcentaje'])),
                    'acciones' => $acciones,
                ];
            }
        }

        $temprana = $resumen['temprana'];

        if ($temprana['porcentaje'] !== null && $temprana['porcentaje'] >= $umbralTemprana) {
            $acciones = [['etiqueta' => 'Ver bajas tempranas', 'tipo' => 'filtro', 'filtros' => ['rapido' => 'temprana', 'seccion' => 'historico']]];

            if ($usuario->can('onboarding.gestionar')) {
                $acciones[] = ['etiqueta' => 'Revisar inducción', 'tipo' => 'enlace', 'href' => route('rh.induccion-puesto.index', [], false)];
            }

            $hallazgos[] = [
                'clave' => 'tempranas',
                'tono' => 'alerta',
                'titulo' => 'Una proporción importante de las salidas ocurre durante los primeros seis meses',
                'detalle' => sprintf('%d de %d bajas con fecha de ingreso válida (%s %%).', $temprana['bajas'], $temprana['base'], $this->numero($temprana['porcentaje'])),
                'acciones' => $acciones,
            ];
        }

        $conducta = array_values(array_filter($causales['causales'], fn (array $c): bool => in_array($c['clave'], ['bajo_desempeno', 'probidad_conducta', 'abandono_faltas'], true)));
        $sumaConducta = array_sum(array_column($conducta, 'bajas'));

        if ($sumaConducta > 0) {
            $hallazgos[] = [
                'clave' => 'desempeno_conducta',
                'tono' => 'info',
                'titulo' => 'Desempeño, conducta y abandono',
                'detalle' => sprintf('%d bajas: %s. Es un patrón a investigar, no una causa comprobada.', $sumaConducta, implode(' · ', array_map(fn (array $c): string => sprintf('%s %d', $c['nombre'], $c['bajas']), $conducta))),
                'acciones' => [['etiqueta' => 'Comparar en la matriz', 'tipo' => 'seccion', 'filtros' => ['seccion' => 'sucursales']]],
            ];
        }

        $pendientes = (int) $calidad['sin_causal'] + (int) $calidad['sin_fecha_ingreso'] + (int) $calidad['fecha_inconsistente'] + (int) $calidad['posibles_duplicados'] + count($calidad['discrepancias']);

        if ($pendientes > 0) {
            $partes = array_filter([
                $calidad['sin_causal'] > 0 ? sprintf('%d sin causal', $calidad['sin_causal']) : null,
                $calidad['sin_fecha_ingreso'] > 0 ? sprintf('%d sin fecha de ingreso', $calidad['sin_fecha_ingreso']) : null,
                $calidad['fecha_inconsistente'] > 0 ? sprintf('%d con fechas inconsistentes', $calidad['fecha_inconsistente']) : null,
                $calidad['posibles_duplicados'] > 0 ? sprintf('%d posibles duplicados', $calidad['posibles_duplicados']) : null,
                $calidad['discrepancias'] !== [] ? sprintf('%d descuadres con el histórico agregado', count($calidad['discrepancias'])) : null,
            ]);

            $hallazgos[] = [
                'clave' => 'incompletos',
                'tono' => 'peligro',
                'titulo' => 'Registros que necesitan revisión',
                'detalle' => implode(' · ', $partes).'.',
                'acciones' => [['etiqueta' => 'Resolver pendientes', 'tipo' => 'filtro', 'filtros' => ['rapido' => 'pendientes', 'seccion' => 'historico']]],
            ];
        }

        if ((int) $resumen['estructurales'] > 0) {
            $hallazgos[] = [
                'clave' => 'estructurales',
                'tono' => 'neutro',
                'titulo' => 'Salidas estructurales',
                'detalle' => sprintf('%d por reestructura, cierre o fin de contrato. Siguen dentro del total oficial.', $resumen['estructurales']),
                'acciones' => [['etiqueta' => 'Ver colaboradores', 'tipo' => 'filtro', 'filtros' => ['rapido' => 'estructural', 'seccion' => 'historico']]],
            ];
        }

        return $hallazgos;
    }

    private function numero(?float $valor): string
    {
        return $valor === null ? '—' : number_format($valor, 1);
    }
}
