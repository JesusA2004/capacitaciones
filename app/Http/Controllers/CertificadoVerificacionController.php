<?php

namespace App\Http\Controllers;

use App\Models\Certificado;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Verificacion publica de una constancia por folio: no requiere sesion
 * iniciada (el proposito es que un tercero, p. ej. RH de otra empresa,
 * pueda confirmar la validez sin credenciales), y solo expone los datos
 * minimos no sensibles (nombre, curso, fecha), nunca el correo, telefono u
 * otro dato personal del colaborador.
 */
class CertificadoVerificacionController extends Controller
{
    public function show(string $folio): Response
    {
        $certificado = Certificado::query()
            ->where('folio', $folio)
            ->with(['usuario:id,name,apellidos', 'curso:id,titulo'])
            ->first();

        return Inertia::render('Constancias/Verificar', [
            'folio' => $folio,
            'valido' => $certificado !== null,
            'certificado' => $certificado ? [
                'nombre' => $certificado->usuario->nombreCompleto(),
                'curso' => $certificado->curso->titulo,
                'emitido_en' => $certificado->emitido_en->format('d/m/Y'),
            ] : null,
        ]);
    }
}
