<?php

namespace App\Services\Expedientes;

use App\Models\User;

/**
 * Registro manual de aviso de privacidad / consentimiento de datos para
 * colaboradores que nunca pasaron por el flujo de Alta digital (ver
 * App\Models\AltaDigital) — la mayoría de la plantilla que se sembró
 * directamente, no vía reclutamiento. Es una declaración explícita de RH
 * ("ya se lo di a firmar en papel / se lo mandé por correo y aceptó"), con
 * menor peso probatorio que un alta digital real (que trae firma + fecha del
 * propio colaborador), por eso queda auditado quién lo registró.
 */
class AvisoPrivacidadService
{
    public function registrar(
        User $colaborador,
        bool $avisoPrivacidad,
        bool $consentimientoDatos,
        User $registradoPor,
    ): void {
        $colaborador->update([
            'aviso_privacidad_aceptado' => $avisoPrivacidad,
            'aviso_privacidad_aceptado_en' => $avisoPrivacidad ? now() : null,
            'consentimiento_datos_aceptado' => $consentimientoDatos,
            'consentimiento_datos_aceptado_en' => $consentimientoDatos ? now() : null,
            'avisos_registrado_por_id' => $registradoPor->id,
        ]);
    }
}
