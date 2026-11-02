<?php

namespace App\Services\DocumentosLaborales;

use App\Enums\EstadoFlujoDocumento;
use App\Models\GeneratedDocument;
use Illuminate\Database\Eloquent\Model;

/**
 * Criterio ÚNICO, neutral y reutilizable de «documento CONCLUIDO según su
 * flujo»: ¿este documento ya cumplió, de verdad, todos los requisitos de
 * firma que pide su plantilla?
 *
 * Antes este criterio vivía disperso (cada módulo decidía a mano cuándo un
 * documento estaba "firmado"), y varios gates confundían
 * EstadoFlujoDocumento::estaFirmado() —que devuelve true en estados PRE-firma
 * física (PendienteImpresion/Impreso/PendienteFirmaFisica)— con «concluido».
 * Eso permitía resguardar un pagaré sin imprimir, cerrar un acta sin firma
 * real o marcar un documento como "atendido" apenas impreso.
 *
 * Reglas (según las banderas requiere_* que el documento copió de su
 * plantilla al generarse):
 *   - requiere firma física o impresión  → concluye SOLO con el original
 *     físico firmado (firmaFisicaRegistrada(): firma + huella registradas por
 *     el gerente; incluye los estados legacy enviado/recibido/escaneado/
 *     archivado por compatibilidad).
 *   - requiere únicamente firma digital  → concluye con la aceptación digital
 *     del colaborador (estaFirmado()).
 *   - no requiere ninguna firma          → concluye al generarse.
 *
 * Lo comparten PrestamoAutorizacionService, PrestamoSeguimientoService,
 * ActaService, ContratoLaboralService y DestinoNotificacionService: un solo
 * lugar que cambiar si el flujo documental cambia.
 */
class FirmaDocumentalService
{
    public function documentoConcluidoSegunFlujo(?GeneratedDocument $documento): bool
    {
        $estado = $documento?->estado_flujo;

        if ($documento === null || $estado === null) {
            return false;
        }

        if ($documento->requiere_firma_fisica || $documento->requiere_impresion) {
            return $estado->firmaFisicaRegistrada();
        }

        if ($documento->requiere_firma_digital) {
            return $estado->estaFirmado();
        }

        return true;
    }

    /**
     * ¿Alguna versión viva del documento ya concluyó? Rectificar mueve el puntero del
     * registro a una versión nueva sin firmar, pero lo firmado sigue firmado: ningún
     * bloqueo que dependa de la firma debe abrirse por eso.
     */
    public function algunaVersionConcluida(Model $registro, string $clave): bool
    {
        return GeneratedDocument::query()
            ->where('documentable_type', $registro->getMorphClass())
            ->where('documentable_id', $registro->getKey())
            ->where('clave_plantilla', $clave)
            ->where('estado_flujo', '!=', EstadoFlujoDocumento::Cancelado->value)
            ->get()
            ->contains(fn (GeneratedDocument $documento): bool => $this->documentoConcluidoSegunFlujo($documento));
    }
}
