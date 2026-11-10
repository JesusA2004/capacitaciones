<?php

namespace App\Enums;

/**
 * Por qué una persona hace que «personas activas» no sea igual a «plazas
 * ocupadas» (o por qué ya no cuenta), en el cuadre de plantilla
 * (App\Services\Headcount\CuadrePlantillaService). Nunca se esconde a nadie
 * para cuadrar: cada caso se lista con su motivo.
 */
enum MotivoDiferenciaPlantilla: string
{
    /** Contratado aún sin activar: SÍ ocupa plaza (cuenta en ocupadas). */
    case EnIncorporacion = 'en_incorporacion';

    /** Vigente en un par (sucursal, puesto) sin ninguna plaza autorizada. */
    case FueraDePlantilla = 'fuera_de_plantilla';

    /** Más personas que plazas autorizadas en un par que sí tiene plazas. */
    case Excedente = 'excedente';

    /** Vigente sin sucursal o sin puesto: no puede ocupar plaza. */
    case SinUbicacion = 'sin_ubicacion';

    /** Cobertura «Ocupa ambos puestos»: una persona, dos plazas ocupadas. */
    case SegundaPlaza = 'segunda_plaza';

    /** Baja registrada con su último día ya pasado (finiquito pendiente): ya no cuenta. */
    case BajaEnTramite = 'baja_en_tramite';

    /** Baja registrada con último día futuro: sigue laborando y ocupa su plaza hasta ese día. */
    case BajaProgramada = 'baja_programada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::EnIncorporacion => 'En incorporación (ocupa plaza)',
            self::FueraDePlantilla => 'Fuera de plantilla (sin plaza autorizada)',
            self::Excedente => 'Excedente (más personas que plazas)',
            self::SinUbicacion => 'Sin sucursal o sin puesto',
            self::SegundaPlaza => 'Plaza cubierta por doble puesto',
            self::BajaEnTramite => 'Baja en trámite (ya no cuenta)',
            self::BajaProgramada => 'Baja programada (aún labora)',
        };
    }

    /**
     * ¿Hace que personas activas ≠ plazas ocupadas? En incorporación y las
     * bajas solo explican la diferencia con «Autorizada − Activos»; no
     * descuadran personas contra plazas.
     */
    public function descuadraPersonasContraPlazas(): bool
    {
        return in_array($this, [self::FueraDePlantilla, self::Excedente, self::SinUbicacion, self::SegundaPlaza], true);
    }
}
