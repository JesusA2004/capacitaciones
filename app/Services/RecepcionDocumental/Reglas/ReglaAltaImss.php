<?php

namespace App\Services\RecepcionDocumental\Reglas;

use App\Enums\ReglaRecepcionDocumental;
use App\Models\Colaborador;
use App\Services\RecepcionDocumental\EsperadoRecepcion;
use App\Services\RecepcionDocumental\PeriodoRecepcion;

/**
 * ALTA IMSS: un original «Alta IMSS firmada» por colaborador cuya
 * fecha_alta_imss (DATE) cae en el mes. Activable en
 * config/recepcion_documental.php (reglas.alta_imss).
 */
class ReglaAltaImss implements ReglaRecepcion
{
    public const TIPO = 'alta_imss';

    public function regla(): ReglaRecepcionDocumental
    {
        return ReglaRecepcionDocumental::AltaImss;
    }

    public function esperados(PeriodoRecepcion $periodo): array
    {
        $colaboradores = Colaborador::query()
            ->whereBetween('fecha_alta_imss', $periodo->rangoFechas())
            ->with(['puesto:id,nombre', 'sucursalPrincipal:id,nombre'])
            ->orderBy('id')
            ->get();

        $esperados = [];

        foreach ($colaboradores as $colaborador) {
            $esperados[] = EsperadoRecepcion::deColaborador(
                $this->regla(),
                self::TIPO,
                EsperadoRecepcion::etiquetaTipo(self::TIPO),
                $colaborador,
                $colaborador,
                $colaborador->fecha_alta_imss?->toDateString(),
                sprintf('Alta ante el IMSS del %s', $colaborador->fecha_alta_imss?->format('d/m/Y') ?? 'sin fecha'),
            );
        }

        return $esperados;
    }
}
