<?php

namespace App\Policies;

use App\Enums\CategoriaDocumento;
use App\Enums\EstadoFlujoDocumento;
use App\Models\ContratoLaboral;
use App\Models\GeneratedDocument;
use App\Models\User;
use App\Policies\Concerns\ConSuperAdminOperativo;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Onboarding\EvaluacionInduccionPdfService;

/**
 * Documentos laborales emitidos por el motor documental. El colaborador ve,
 * descarga y firma SOLO los suyos; RH/Jurídico/Dirección los consultan
 * dentro de su alcance; la operación del original físico requiere
 * documentos_laborales.operar_fisico. Nunca se entrega una URL pública al
 * NAS: la descarga siempre pasa por aquí. super_admin opera/cancela incluso
 * su propio documento, ver ConSuperAdminOperativo.
 */
class GeneratedDocumentPolicy
{
    use ConSuperAdminOperativo;

    public function __construct(private readonly AlcanceOrganizacionalService $alcance) {}

    public function ver(User $usuario, GeneratedDocument $documento): bool
    {
        if ($this->esPropio($usuario, $documento) && ! $this->esAutoservicioBloqueado($documento)) {
            return $documento->estado_flujo !== null && $documento->estado_flujo !== EstadoFlujoDocumento::Borrador;
        }

        // Contratos: permiso propio (contratos.ver_documento) — el gerente
        // consulta los demás documentos laborales de su gente, pero no ve ni
        // descarga contratos. RH lo conserva con el general de generar.
        if ($this->esContrato($documento)) {
            return self::puedeVerContratos($usuario) && $this->enAlcance($usuario, $documento);
        }

        return $usuario->can('documentos_laborales.ver') && $this->enAlcance($usuario, $documento);
    }

    /**
     * Documento contractual: categoría «Contratos» o ligado a un contrato
     * laboral (paquete de alta / renovación).
     */
    public static function esContrato(GeneratedDocument $documento): bool
    {
        return $documento->categoria === CategoriaDocumento::Contratos
            || $documento->documentable_type === (new ContratoLaboral)->getMorphClass();
    }

    /** Ver contratos de otras personas (PDF generado / vista previa). */
    public static function puedeVerContratos(User $usuario): bool
    {
        return $usuario->can('contratos.ver_documento') || $usuario->can('documentos_laborales.generar');
    }

    /** Descargar contratos de otras personas (archivo PDF / Word). */
    public static function puedeDescargarContratos(User $usuario): bool
    {
        return $usuario->can('contratos.descargar') || $usuario->can('documentos_laborales.generar');
    }

    /**
     * Documentos que NUNCA llegan a la app del colaborador, aunque sean
     * suyos: se entregan impresos/en persona, directo de RH.
     *  - Recibo de nómina: config('features.recibos_colaborador') decide.
     *  - Renuncia/finiquito (baja): siempre — el colaborador se informa de
     *    su situación laboral en la app, pero el documento legal de la baja
     *    es de RH, nunca autoservicio (pedido explícito del negocio).
     */
    private function esAutoservicioBloqueado(GeneratedDocument $documento): bool
    {
        // Evaluación de inducción calificada: trae la clave de respuestas,
        // es solo de RH (EvaluacionInduccionPdfService).
        if ($documento->clave_plantilla === EvaluacionInduccionPdfService::CLAVE) {
            return true;
        }

        if ($documento->categoria === CategoriaDocumento::NominaInterna) {
            return ! config('features.recibos_colaborador');
        }

        return $documento->categoria === CategoriaDocumento::BajaFiniquito;
    }

    /**
     * Descargar el archivo (adjunto, o desde la app). Un contrato ajeno exige
     * además contratos.descargar; verlo en el visor basta con ver().
     */
    public function descargar(User $usuario, GeneratedDocument $documento): bool
    {
        if (! $this->ver($usuario, $documento)) {
            return false;
        }

        return $this->esPropio($usuario, $documento) || ! self::esContrato($documento) || self::puedeDescargarContratos($usuario);
    }

    /**
     * Word llenado: solo quien opera el documento en su proceso (RH, gerente,
     * regional dentro de su alcance). El colaborador recibe el PDF, no el
     * Word editable.
     */
    public function descargarWord(User $usuario, GeneratedDocument $documento): bool
    {
        return $documento->docx_path !== null
            && ! $this->esPropio($usuario, $documento)
            && $usuario->can('documentos_laborales.ver')
            && ($usuario->can('documentos_laborales.generar') || $usuario->can('documentos_laborales.operar_fisico'))
            && (! self::esContrato($documento) || self::puedeDescargarContratos($usuario))
            && $this->enAlcance($usuario, $documento);
    }

    public function firmar(User $usuario, GeneratedDocument $documento): bool
    {
        return $this->esPropio($usuario, $documento)
            && ! $this->esAutoservicioBloqueado($documento)
            && $documento->estado_flujo === EstadoFlujoDocumento::PendienteFirmaColaborador;
    }

    public function operar(User $usuario, GeneratedDocument $documento): bool
    {
        return $usuario->can('documentos_laborales.operar_fisico')
            && ! $this->esPropio($usuario, $documento)
            && $this->enAlcance($usuario, $documento);
    }

    /**
     * Regenerar (nueva versión con los datos actuales, con motivo): RH con
     * permiso de generar documentos laborales dentro de su alcance (y
     * super_admin, ConSuperAdminOperativo). Nunca sobre el propio documento.
     * Si el anterior ya está firmado, además documentos_laborales.revisar_firmado
     * (el firmado se conserva; la nueva versión queda ligada a él).
     */
    public function regenerar(User $usuario, GeneratedDocument $documento): bool
    {
        if ($documento->estado_flujo === EstadoFlujoDocumento::Cancelado) {
            return false;
        }

        $firmado = $documento->estado_flujo?->firmaFisicaRegistrada() ?? false;

        return $usuario->can('documentos_laborales.generar')
            && (! $firmado || $usuario->can('documentos_laborales.revisar_firmado'))
            && ! $this->esPropio($usuario, $documento)
            && $this->enAlcance($usuario, $documento);
    }

    public function cancelar(User $usuario, GeneratedDocument $documento): bool
    {
        return $usuario->can('documentos_laborales.cancelar')
            && ! $this->esPropio($usuario, $documento)
            && $this->enAlcance($usuario, $documento);
    }

    private function esPropio(User $usuario, GeneratedDocument $documento): bool
    {
        return $usuario->colaborador_id !== null && $documento->colaborador_id === $usuario->colaborador_id;
    }

    private function enAlcance(User $usuario, GeneratedDocument $documento): bool
    {
        $colaborador = $documento->colaborador()->withTrashed()->first();

        return $colaborador !== null && $this->alcance->alcanzaColaboradorEnModulo($usuario, $colaborador, 'documentos');
    }
}
