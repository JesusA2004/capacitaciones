<?php

namespace App\Enums;

/**
 * Resultado de comparar un dato detectado en el PDF histórico contra lo
 * capturado del colaborador (reporte «datos faltantes / discrepancias»).
 *  - faltante: el sistema no lo tiene y el PDF sí (RH confirma y aplica);
 *  - discrepancia: el sistema tiene otro valor (RH decide; nunca se pisa solo);
 *  - conflicto_documentos: dos partes del PDF dicen cosas distintas;
 *  - coincide: el sistema ya tiene ese mismo valor;
 *  - aplicado: RH ya lo aplicó desde esta pantalla.
 */
enum EstadoDatoDetectado: string
{
    case Faltante = 'faltante';
    case Discrepancia = 'discrepancia';
    case ConflictoDocumentos = 'conflicto_documentos';
    case Coincide = 'coincide';
    case Aplicado = 'aplicado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Faltante => 'Falta en el sistema',
            self::Discrepancia => 'Diferente a lo capturado',
            self::ConflictoDocumentos => 'Los documentos no coinciden entre sí',
            self::Coincide => 'Coincide',
            self::Aplicado => 'Aplicado por RH',
        };
    }
}
