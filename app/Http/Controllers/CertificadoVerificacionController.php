<?php

namespace App\Http\Controllers;

use App\Models\CertificacionMrLana;
use App\Models\Certificado;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Verificacion publica de una constancia por folio: no requiere sesion
 * iniciada (el proposito es que un tercero, p. ej. RH de otra empresa,
 * pueda confirmar la validez sin credenciales), y solo expone los datos
 * minimos no sensibles (nombre, curso, fecha), nunca el correo, telefono u
 * otro dato personal del colaborador.
 *
 * También verifica el certificado «Cuentas con tu Certificación Mr. Lana»
 * (folio MRLC-…, CertificacionMrLana) con los mismos datos mínimos.
 */
class CertificadoVerificacionController extends Controller
{
    public function show(string $folio): Response
    {
        $certificado = Certificado::query()
            ->where('folio', $folio)
            ->with(['usuario:id,name,apellidos', 'curso:id,titulo'])
            ->first();

        $datos = $certificado ? [
            'nombre' => $certificado->usuario->nombreCompleto(),
            'curso' => $certificado->curso->titulo,
            'emitido_en' => $certificado->emitido_en->format('d/m/Y'),
        ] : null;

        if ($datos === null) {
            $certificacion = CertificacionMrLana::query()
                ->where('folio', $folio)
                ->whereNotNull('certificado_en')
                ->with('colaborador:id,name,apellidos')
                ->first();

            $datos = $certificacion?->certificado_en !== null ? [
                'nombre' => $certificacion->colaborador->nombreCompleto(),
                'curso' => 'Certificación Mr. Lana',
                'emitido_en' => $certificacion->certificado_en->format('d/m/Y'),
            ] : null;
        }

        return Inertia::render('Constancias/Verificar', [
            'folio' => $folio,
            'valido' => $datos !== null,
            'certificado' => $datos,
        ]);
    }
}
