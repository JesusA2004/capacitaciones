<?php

namespace App\Enums;

/**
 * Formularios de evaluación configurables por RH (módulo «Formularios de
 * evaluación»): cada clave tiene su propio historial de versiones en
 * formularios_evaluacion. Ver App\Services\Evaluaciones\FormularioEvaluacionService.
 */
enum ClaveFormularioEvaluacion: string
{
    case Induccion = 'induccion';
    case PeriodoPrueba = 'periodo_prueba';
    /** Formularios técnicos por puesto («Seguimiento N»): una familia de versiones por plantilla. */
    case Tecnica = 'tecnica';

    /**
     * Las claves con UN formulario fijo (pantalla «Formularios de evaluación»).
     *
     * @return list<self>
     */
    public static function fijas(): array
    {
        return [self::Induccion, self::PeriodoPrueba];
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Induccion => 'Evaluación de inducción',
            self::PeriodoPrueba => 'Evaluación de capacitación inicial (periodo de prueba)',
            self::Tecnica => 'Evaluación técnica (Seguimiento)',
        };
    }

    public function descripcion(): string
    {
        return match ($this) {
            self::Induccion => 'La contesta el colaborador al terminar su Inducción Mr. Lana. Las secciones A, B y C se califican solas; los casos prácticos los califica Recursos Humanos.',
            self::PeriodoPrueba => 'La contesta el jefe directo antes de que termine el contrato de prueba: decide si se renueva el contrato.',
            self::Tecnica => 'Formulario técnico del calendario por puesto (Seguimiento 15, 30, 60…): lo contesta el colaborador como examen o su jefe directo, según el calendario.',
        };
    }

    /** Código del formato oficial con el que nace la versión 1. */
    public function codigoPorDefecto(): string
    {
        return match ($this) {
            self::Induccion => 'FO-RH-EI-2026',
            self::PeriodoPrueba => 'FO-RH-EPP-001',
            self::Tecnica => 'FO-RH-ET',
        };
    }

    /** Quién contesta el formulario (texto de la vista previa). */
    public function quienContesta(): string
    {
        return match ($this) {
            self::Induccion => 'colaborador',
            self::PeriodoPrueba, self::Tecnica => 'evaluador',
        };
    }
}
