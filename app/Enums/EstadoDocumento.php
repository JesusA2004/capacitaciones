<?php

namespace App\Enums;

enum EstadoDocumento: string
{
    case Pendiente = 'pendiente';
    case Cargado = 'cargado';
    case EnRevision = 'en_revision';
    case Aprobado = 'aprobado';
    case Rechazado = 'rechazado';
    case RequiereCorreccion = 'requiere_correccion';
    case Vencido = 'vencido';
    case Archivado = 'archivado';

    /** El colaborador ya subio el documento y pidio poder reemplazarlo; espera autorizacion de RH (ver IncorporacionService::solicitarCambio). */
    case CambioSolicitado = 'cambio_solicitado';

    /** RH autorizo el reemplazo solicitado: el colaborador ya puede subir la nueva version (ver IncorporacionService::autorizarCambio). */
    case CambioAutorizado = 'cambio_autorizado';

    /**
     * Estados en los que un documento de expediente YA tiene archivo cargado
     * y espera una decisión de RH (bandeja de validación de documentos).
     * «pendiente» no entra: significa que todavía no se ha subido nada, no es
     * algo que RH pueda revisar. Fuente única: la usan tanto la bandeja web
     * (ContratacionListaService) como la móvil (RhPendientesService).
     *
     * @return array<int, string>
     */
    public static function porRevisarValues(): array
    {
        return [self::Cargado->value, self::EnRevision->value, self::CambioSolicitado->value];
    }

    /**
     * ¿RH puede decidir sobre este documento (aprobar, rechazar o pedir
     * corrección)? Sí en todo estado «por revisar» (porRevisarValues),
     * incluido cambio_solicitado: RH puede autorizar el reemplazo o aprobar
     * la versión actual tal cual (aprobarla cierra la solicitud de cambio).
     * También «pendiente» de registros heredados que ya traen archivo. No en
     * aprobado/rechazado/requiere_correccion/vencido/archivado ni en
     * cambio_autorizado (ahí se espera la nueva versión del colaborador).
     * Fuente única para IncorporacionService (web + API móvil) y para el
     * botón «Aprobar» del expediente web (bandera `revisable` del payload).
     */
    public function admiteDecisionDeRh(): bool
    {
        return $this === self::Pendiente || in_array($this->value, self::porRevisarValues(), true);
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::Cargado => 'Cargado',
            self::EnRevision => 'En revisión',
            self::Aprobado => 'Aprobado',
            self::Rechazado => 'Rechazado',
            self::RequiereCorreccion => 'Requiere corrección',
            self::Vencido => 'Vencido',
            self::Archivado => 'Archivado',
            self::CambioSolicitado => 'Cambio solicitado',
            self::CambioAutorizado => 'Cambio autorizado',
        };
    }
}
