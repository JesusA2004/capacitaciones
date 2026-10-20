<?php

namespace App\Enums;

/**
 * Estado de un contrato que le corresponde a un colaborador (módulo
 * «Contratos», RegularizacionContratosService). También se usa como estado
 * global de la fila (el más urgente de sus documentos).
 */
enum EstadoContratoCorrespondiente: string
{
    case Generado = 'generado';
    case PorGenerar = 'por_generar';
    case FaltanDatos = 'faltan_datos';
    case FormatoNoListo = 'formato_no_listo';
    /** Su evaluación de periodo de prueba está en curso: el indeterminado sale de esa evaluación, no de aquí. */
    case PendienteEvaluacion = 'pendiente_evaluacion';
    case Completo = 'completo';
    case NoAplica = 'no_aplica';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Generado => 'En expediente (generado)',
            self::PorGenerar => 'Por generar',
            self::FaltanDatos => 'Faltan datos',
            self::FormatoNoListo => 'Formato no listo',
            self::PendienteEvaluacion => 'Pendiente de evaluación',
            self::Completo => 'Contratos completos',
            self::NoAplica => 'No aplica',
        };
    }

    /**
     * Estados que se pueden filtrar en la pantalla (estado de la fila).
     *
     * @return list<self>
     */
    public static function deFila(): array
    {
        return [self::Completo, self::PorGenerar, self::FaltanDatos, self::FormatoNoListo, self::PendienteEvaluacion, self::NoAplica];
    }
}
