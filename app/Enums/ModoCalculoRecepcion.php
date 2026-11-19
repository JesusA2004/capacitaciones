<?php

namespace App\Enums;

/**
 * Cómo calcula una entrada del catálogo de Recepción documental cuántos
 * papeles se esperan cada mes (docs/RECEPCION_DOCUMENTAL_RH.md → «Catálogo
 * de lo que se recibe»).
 *
 *  - Regla: entrada del SISTEMA (altas, bajas, nómina…): su clase en
 *    App\Services\RecepcionDocumental\Reglas calcula lo esperado.
 *  - El resto son entradas AGREGADAS por RH (ReglaPersonalizada):
 *    cantidad fija al mes, por sucursal, por colaborador activo o por cada
 *    evento de una regla del sistema.
 */
enum ModoCalculoRecepcion: string
{
    case Regla = 'regla';
    case FijoMensual = 'fijo_mensual';
    case PorSucursal = 'por_sucursal';
    case PorColaboradorActivo = 'por_colaborador_activo';
    case PorEvento = 'por_evento';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Regla => 'Lo calcula el sistema',
            self::FijoMensual => 'Cantidad fija al mes',
            self::PorSucursal => 'Por sucursal',
            self::PorColaboradorActivo => 'Por colaborador activo',
            self::PorEvento => 'Por cada evento',
        };
    }

    /** Explicación corta para la pantalla del catálogo. */
    public function descripcion(): string
    {
        return match ($this) {
            self::Regla => 'Sale de los eventos reales del sistema (altas, bajas, recibos…).',
            self::FijoMensual => 'Llega el mismo número de papeles cada mes, sin importar la sucursal (p. ej. 1 reporte general).',
            self::PorSucursal => 'Cada sucursal elegida manda esa cantidad de papeles al mes (p. ej. 1 reporte de caja por sucursal).',
            self::PorColaboradorActivo => 'Cada sucursal manda esa cantidad por cada colaborador activo en el mes (p. ej. 1 constancia por persona).',
            self::PorEvento => 'Se espera esa cantidad por cada evento de una regla del sistema (p. ej. 1 carta de bienvenida por cada alta).',
        };
    }

    /** Las que agrega RH (todas menos las reglas del sistema). */
    public function esPersonalizado(): bool
    {
        return $this !== self::Regla;
    }

    /**
     * Modos que RH puede elegir al agregar una entrada.
     *
     * @return list<self>
     */
    public static function personalizables(): array
    {
        return [self::FijoMensual, self::PorSucursal, self::PorColaboradorActivo, self::PorEvento];
    }

    /** ¿Tiene sentido acotarla por sucursal / empresa? */
    public function admiteAlcance(): bool
    {
        return $this !== self::FijoMensual;
    }

    /** ¿Tiene sentido acotarla por área (departamento)? */
    public function admiteAreas(): bool
    {
        return $this === self::PorColaboradorActivo || $this === self::PorEvento;
    }
}
