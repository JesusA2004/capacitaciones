<?php

namespace App\Enums;

/**
 * Categoría de un App\Models\OfficialFormat (plantilla oficial, ver
 * docs/FORMATOS_OFICIALES.md) — agrupa/filtra el catálogo y decide en qué
 * carpeta del expediente se archiva el PDF generado; no cambia el flujo de
 * generación.
 */
enum TipoFormatoOficial: string
{
    case Contrato = 'contrato';
    case Permiso = 'permiso';
    case Vacaciones = 'vacaciones';
    case Constancia = 'constancia';
    case Carta = 'carta';
    case Prestamo = 'prestamo';
    case Acta = 'acta';
    case Recibo = 'recibo';
    case Ingreso = 'ingreso';
    case SolicitudEmpleo = 'solicitud_empleo';
    case Baja = 'baja';
    case Finiquito = 'finiquito';
    case Embarazo = 'embarazo';
    case Lactancia = 'lactancia';
    case Capacitacion = 'capacitacion';
    case Documentos = 'documentos';
    case Otro = 'otro';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Contrato => 'Contrato',
            self::Permiso => 'Permiso',
            self::Vacaciones => 'Vacaciones',
            self::Constancia => 'Constancia',
            self::Carta => 'Carta',
            self::Prestamo => 'Préstamo / pagaré',
            self::Acta => 'Acta',
            self::Recibo => 'Recibo interno',
            self::Ingreso => 'Formato de ingreso',
            self::SolicitudEmpleo => 'Solicitud de empleo',
            self::Baja => 'Baja de personal',
            self::Finiquito => 'Finiquito',
            self::Embarazo => 'Embarazo',
            self::Lactancia => 'Lactancia',
            self::Capacitacion => 'Capacitación',
            self::Documentos => 'Documentos',
            self::Otro => 'Otro',
        };
    }

    /**
     * Carpeta del expediente donde se archiva un documento generado con un
     * formato de esta categoría.
     */
    public function categoriaDocumento(): CategoriaDocumento
    {
        return match ($this) {
            self::Contrato => CategoriaDocumento::Contratos,
            self::Permiso, self::Embarazo, self::Lactancia => CategoriaDocumento::Permisos,
            self::Vacaciones => CategoriaDocumento::Vacaciones,
            self::Prestamo => CategoriaDocumento::Prestamos,
            self::Acta => CategoriaDocumento::Actas,
            self::Recibo => CategoriaDocumento::NominaInterna,
            self::Baja, self::Finiquito => CategoriaDocumento::BajaFiniquito,
            self::Ingreso, self::SolicitudEmpleo, self::Documentos, self::Constancia, self::Carta => CategoriaDocumento::Personales,
            self::Capacitacion, self::Otro => CategoriaDocumento::Otros,
        };
    }

    /**
     * @return list<array{value: string, etiqueta: string}>
     */
    public static function opciones(): array
    {
        return array_map(
            fn (self $tipo) => ['value' => $tipo->value, 'etiqueta' => $tipo->etiqueta()],
            self::cases(),
        );
    }
}
