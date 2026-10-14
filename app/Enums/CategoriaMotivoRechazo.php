<?php

namespace App\Enums;

/**
 * Clasificación de los motivos por los que un candidato ya no continuó
 * (catálogo App\Models\MotivoRechazoCandidato). Sirve para que el reporte
 * de campañas responda «¿por qué se nos caen?» sin leer texto libre:
 * no cumplió un requisito, lo descartó la empresa, desistió él o nunca
 * se le pudo contactar.
 */
enum CategoriaMotivoRechazo: string
{
    case Requisito = 'requisito';
    case Empresa = 'empresa';
    case Candidato = 'candidato';
    case SinContacto = 'sin_contacto';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Requisito => 'No cumple un requisito indispensable',
            self::Empresa => 'Lo descartó la empresa',
            self::Candidato => 'El candidato desistió',
            self::SinContacto => 'Sin respuesta / no se presentó',
        };
    }

    /**
     * @return list<array{value: string, etiqueta: string}>
     */
    public static function opciones(): array
    {
        return array_map(fn (self $c): array => ['value' => $c->value, 'etiqueta' => $c->etiqueta()], self::cases());
    }
}
