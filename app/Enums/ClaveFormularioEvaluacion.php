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

    public function etiqueta(): string
    {
        return match ($this) {
            self::Induccion => 'Evaluación de inducción',
            self::PeriodoPrueba => 'Evaluación de capacitación inicial (periodo de prueba)',
        };
    }

    public function descripcion(): string
    {
        return match ($this) {
            self::Induccion => 'La contesta el colaborador al terminar su Inducción Mr. Lana. Las secciones A, B y C se califican solas; los casos prácticos los califica Recursos Humanos.',
            self::PeriodoPrueba => 'La contesta el jefe directo antes de que termine el contrato de prueba: decide si se renueva el contrato.',
        };
    }

    /** Código del formato oficial con el que nace la versión 1. */
    public function codigoPorDefecto(): string
    {
        return match ($this) {
            self::Induccion => 'FO-RH-EI-2026',
            self::PeriodoPrueba => 'FO-RH-EPP-001',
        };
    }

    /** Quién contesta el formulario (texto de la vista previa). */
    public function quienContesta(): string
    {
        return match ($this) {
            self::Induccion => 'colaborador',
            self::PeriodoPrueba => 'evaluador',
        };
    }
}
