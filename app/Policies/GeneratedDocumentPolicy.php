<?php

namespace App\Policies;

use App\Enums\CategoriaDocumento;
use App\Enums\DocumentoSalida;
use App\Enums\EstadoFlujoDocumento;
use App\Models\ContratoLaboral;
use App\Models\GeneratedDocument;
use App\Models\Responsiva;
use App\Models\User;
use App\Policies\Concerns\ConSuperAdminOperativo;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Documentos\Versiones\Fuentes\FuenteResponsiva;
use App\Services\Onboarding\EvaluacionInduccionPdfService;
use App\Services\Permisos\PermisoAccionService;
use Illuminate\Auth\Access\Response;

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

    public function ver(User $usuario, GeneratedDocument $documento): Response
    {
        return $this->puedeVer($usuario, $documento) ? Response::allow() : Response::deny(self::motivoSinAcceso($documento));
    }

    private function puedeVer(User $usuario, GeneratedDocument $documento): bool
    {
        if ($this->esPropio($usuario, $documento) && ! $this->esAutoservicioBloqueado($documento)) {
            return $documento->estado_flujo !== null && $documento->estado_flujo !== EstadoFlujoDocumento::Borrador;
        }

        if ($documento->clave_plantilla === FuenteResponsiva::CLAVE) {
            $responsiva = Responsiva::query()->whereKey($documento->documentable_id)->first();

            return $responsiva !== null && $usuario->can('descargar', $responsiva);
        }

        // Contratos: permiso propio (contratos.ver_documento) — el gerente
        // consulta los demás documentos laborales de su gente, pero no ve ni
        // descarga contratos. RH lo tiene por su base (hereda de generar).
        if ($this->esContrato($documento)) {
            return self::puedeVerContratos($usuario) && $this->enAlcance($usuario, $documento);
        }

        // Recibos de nómina y documentos de salida con importes: su permiso
        // propio, además de documentos_laborales.ver (el gerente consulta los
        // documentos de su gente, pero nunca la nómina ni el finiquito).
        if (! self::puedeVerCategoriaSensible($usuario, $documento)) {
            return false;
        }

        return $usuario->can('documentos_laborales.ver') && $this->enAlcance($usuario, $documento);
    }

    /**
     * Motivo en español cuando ver() niega: la app lo muestra tal cual.
     */
    public static function motivoSinAcceso(GeneratedDocument $documento): string
    {
        if (self::esContrato($documento)) {
            return 'No tienes permiso para ver contratos. Si lo necesitas, pide acceso a RH.';
        }

        if ($documento->categoria === CategoriaDocumento::NominaInterna) {
            return 'Los recibos de nómina solo los consulta RH. Si lo necesitas, pide acceso a RH.';
        }

        if (self::esSalidaConImportes($documento)) {
            return 'Los documentos de finiquito y terminación solo los consulta RH. Si lo necesitas, pide acceso a RH.';
        }

        return 'No tienes permiso para ver este documento o la persona está fuera de tu alcance.';
    }

    /**
     * Categorías con datos económicos: el recibo de nómina exige
     * nomina.recibos.ver; finiquito, convenio, aviso de rescisión y
     * constancia de la baja exigen finiquitos.ver (o gestionar bajas, RH).
     * La carta de renuncia queda con la regla general.
     */
    public static function puedeVerCategoriaSensible(User $usuario, GeneratedDocument $documento): bool
    {
        if ($documento->categoria === CategoriaDocumento::NominaInterna) {
            return $usuario->can('nomina.recibos.ver');
        }

        if (self::esSalidaConImportes($documento)) {
            return $usuario->can('finiquitos.ver') || $usuario->can('bajas.gestionar');
        }

        return true;
    }

    /** Documento de salida de la baja distinto de la carta de renuncia. */
    public static function esSalidaConImportes(GeneratedDocument $documento): bool
    {
        return $documento->categoria === CategoriaDocumento::BajaFiniquito
            && $documento->clave_plantilla !== DocumentoSalida::CartaRenuncia->value;
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

    /**
     * Ver contratos de otras personas (PDF generado / vista previa). Manda
     * SOLO la acción del módulo «Contratos» (configurable en «Roles y
     * permisos»); documentos_laborales.generar ya no la abre por sí solo —
     * únicamente mientras la acción no exista en la base (herencia,
     * PermisoAccionService).
     */
    public static function puedeVerContratos(User $usuario): bool
    {
        return app(PermisoAccionService::class)->puede($usuario, 'contratos.ver_documento');
    }

    /** Descargar contratos de otras personas (archivo PDF / Word). Misma regla. */
    public static function puedeDescargarContratos(User $usuario): bool
    {
        return app(PermisoAccionService::class)->puede($usuario, 'contratos.descargar');
    }

    /**
     * Generar (o regenerar) contratos: individual, «Generar todos», paquete de
     * contratación/renovación y nueva versión. Solo contratos.generar (por
     * defecto, RH): quitarlo en «Roles y permisos» quita el botón y el
     * backend lo rechaza, aunque el rol conserve documentos_laborales.generar.
     */
    public static function puedeGenerarContratos(User $usuario): bool
    {
        return app(PermisoAccionService::class)->puede($usuario, 'contratos.generar');
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

        // El colaborador no consulta sus responsivas (ver ResponsivaPolicy).
        if ($documento->clave_plantilla === FuenteResponsiva::CLAVE) {
            return true;
        }

        if ($documento->categoria === CategoriaDocumento::NominaInterna) {
            return ! config('features.recibos_colaborador');
        }

        if ($documento->categoria === CategoriaDocumento::BajaFiniquito) {
            return true;
        }

        // Sin autoservicio documental el colaborador recibe su copia física:
        // solo abre lo que tiene pendiente de su firma digital
        // (config('features.documentos_laborales_colaborador')).
        return ! config('features.documentos_laborales_colaborador')
            && $documento->estado_flujo !== EstadoFlujoDocumento::PendienteFirmaColaborador;
    }

    /**
     * Descargar el archivo (adjunto, o desde la app). Un contrato ajeno exige
     * además contratos.descargar; verlo en el visor basta con ver().
     */
    public function descargar(User $usuario, GeneratedDocument $documento): bool
    {
        if (! $this->puedeVer($usuario, $documento)) {
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

        // Un contrato se regenera con la acción de Contratos, no con la general.
        $genera = self::esContrato($documento) ? self::puedeGenerarContratos($usuario) : $usuario->can('documentos_laborales.generar');

        return $genera
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
