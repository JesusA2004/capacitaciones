<?php

namespace App\Enums;

/**
 * Habilidades (permisos granulares) de un cliente de integración
 * servidor-a-servidor (tabla clientes_integracion). Cada endpoint de
 * /api/integracion/v1 exige UNA; los datos sensibles (identificación oficial,
 * salario) tienen la suya propia y nunca vienen incluidos en la de lectura
 * general. Ver docs/API_INTEGRACION_SISTEMAS.md.
 */
enum HabilidadIntegracion: string
{
    /** Contratos «people-auditoria/N» (/api/v1/auditoria/*), sin cambios. */
    case AuditoriaLeer = 'auditoria:leer';

    case CatalogosLeer = 'catalogos:leer';

    case ColaboradoresLeer = 'colaboradores:leer';

    case ColaboradoresIdentificacion = 'colaboradores:identificacion';

    case ColaboradoresSalario = 'colaboradores:salario';

    case MovimientosLeer = 'movimientos:leer';

    case RotacionLeer = 'rotacion:leer';

    public function etiqueta(): string
    {
        return match ($this) {
            self::AuditoriaLeer => 'Auditoría: contratos people-auditoria',
            self::CatalogosLeer => 'Catálogos: empresas, sucursales, puestos y departamentos',
            self::ColaboradoresLeer => 'Plantilla de colaboradores (sin datos sensibles)',
            self::ColaboradoresIdentificacion => 'Identificación oficial: CURP, RFC y NSS',
            self::ColaboradoresSalario => 'Salario diario, integrado y sueldo mensual',
            self::MovimientosLeer => 'Altas y bajas por rango de fechas',
            self::RotacionLeer => 'Rotación por periodo y sucursal',
        };
    }

    /** Lleva datos personales sensibles: se concede sólo de forma explícita. */
    public function esSensible(): bool
    {
        return in_array($this, [self::ColaboradoresIdentificacion, self::ColaboradoresSalario], true);
    }

    /**
     * @return list<string>
     */
    public static function valores(): array
    {
        return array_map(fn (self $h): string => $h->value, self::cases());
    }

    /**
     * Habilidades que necesita el sistema Reportes para mapear sucursales y
     * colaboradores y calcular la rotación (sin datos sensibles).
     *
     * @return list<string>
     */
    public static function paraReportes(): array
    {
        return [self::CatalogosLeer->value, self::ColaboradoresLeer->value, self::MovimientosLeer->value, self::RotacionLeer->value];
    }
}
