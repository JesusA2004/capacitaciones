<?php

namespace App\Enums;

/**
 * Qué le pasará (vista previa) o qué le pasó (al confirmar) a cada documento
 * resultante del editor de hojas del PDF histórico
 * (App\Services\Expedientes\DivisionHistorico\EditorPaginasDivisionService).
 */
enum AccionDocumentoReorganizado: string
{
    /** Mismas hojas, mismo orden y mismo tipo que lo guardado: no se toca. */
    case SinCambios = 'sin_cambios';

    /** Reemplaza la versión que vino de este PDF: nueva versión EN REVISIÓN (la anterior se archiva, no se borra). */
    case NuevaVersion = 'nueva_version';

    /** El tipo no estaba cubierto: se carga al checklist EN REVISIÓN. */
    case CargaNueva = 'carga_nueva';

    /** Ya hay un documento vigente de otro origen: no se reemplaza; se guarda la copia. */
    case YaCubierta = 'ya_cubierta';

    /** Otro documento del mismo tipo ya se carga en esta corrección: se guarda como copia. */
    case Duplicado = 'duplicado';

    /** Sin tipo: se guarda la copia pero no entra al expediente. */
    case SinClasificar = 'sin_clasificar';

    public function etiqueta(): string
    {
        return match ($this) {
            self::SinCambios => 'Sin cambios',
            self::NuevaVersion => 'Nueva versión en revisión (reemplaza la anterior)',
            self::CargaNueva => 'Se carga al expediente (en revisión)',
            self::YaCubierta => 'Ya hay un documento vigente: solo se guarda la copia',
            self::Duplicado => 'Tipo repetido: solo se guarda la copia',
            self::SinClasificar => 'Sin clasificar: queda fuera del expediente',
        };
    }

    /** ¿Se carga un archivo nuevo al checklist del expediente? */
    public function cargaAlExpediente(): bool
    {
        return $this === self::NuevaVersion || $this === self::CargaNueva;
    }

    /** Estado con el que nace la parte al confirmar. */
    public function estadoParte(): EstadoParteDivision
    {
        return match ($this) {
            self::NuevaVersion, self::CargaNueva => EstadoParteDivision::AsignadaManual,
            self::YaCubierta, self::Duplicado => EstadoParteDivision::YaCubierta,
            self::SinClasificar, self::SinCambios => EstadoParteDivision::SinClasificar,
        };
    }
}
