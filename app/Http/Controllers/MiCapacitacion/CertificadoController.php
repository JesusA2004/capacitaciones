<?php

namespace App\Http\Controllers\MiCapacitacion;

use App\Http\Controllers\Controller;
use App\Models\Certificado;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CertificadoController extends Controller
{
    public function descargar(Request $request, Certificado $certificado): Response
    {
        abort_unless($certificado->user_id === $request->user()->id, 403);

        $certificado->loadMissing(['usuario', 'curso']);

        $pdf = Pdf::loadView('pdf.constancia', [
            'certificado' => $certificado,
            'urlVerificacion' => route('constancias.verificar', $certificado->folio),
        ])->setPaper('letter', 'landscape');

        return $pdf->download("constancia-{$certificado->folio}.pdf");
    }
}
