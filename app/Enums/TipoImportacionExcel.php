<?php

namespace App\Enums;

/**
 * Catálogo cerrado de `importaciones_excel.tipo`: qué importador generó cada
 * registro. Cada importador solo lee, aplica o borra registros de SU tipo
 * (un id de otra importación en la URL responde 404).
 *
 * La columna sigue siendo string (sin cast) para no cambiar a los
 * importadores que ya comparan contra sus constantes TIPO; estos valores son
 * los mismos.
 */
enum TipoImportacionExcel: string
{
    case TrabajadoresSalariosVacaciones = 'trabajadores_salarios_vacaciones';
    case ContactosEmergencia = 'contactos_emergencia';
    case PlazasAutorizadas = 'plazas_autorizadas';
    case AltasHistoricas = 'altas_historicas';
    case BajasHistoricas = 'bajas_historicas';
    case TabuladorSalarial = 'tabulador_salarial';
    case CargaInicial = 'carga_inicial';

    public function etiqueta(): string
    {
        return match ($this) {
            self::TrabajadoresSalariosVacaciones => 'Salarios y vacaciones de trabajadores',
            self::ContactosEmergencia => 'Contactos de emergencia',
            self::PlazasAutorizadas => 'Plazas autorizadas',
            self::AltasHistoricas => 'Altas históricas',
            self::BajasHistoricas => 'Bajas históricas',
            self::TabuladorSalarial => 'Tabulador salarial',
            self::CargaInicial => 'Carga inicial PEOPLE',
        };
    }
}
