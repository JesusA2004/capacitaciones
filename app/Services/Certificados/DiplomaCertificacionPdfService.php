<?php

namespace App\Services\Certificados;

use App\Enums\MotorPdf;
use App\Models\CertificacionMrLana;
use App\Services\DocumentosMaestros\FirmaPatronService;
use App\Services\Pdf\LogoPdfService;
use App\Services\Pdf\OpcionesPdf;
use App\Services\Pdf\PdfRendererFactory;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Writer;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Certificado «Cuentas con tu Certificación Mr. Lana» (carta horizontal):
 * nombre, puesto, sucursal, promedio, fecha y folio verificable con QR
 * (constancias.verificar). Firma la EMPRESA — razón social + línea en
 * blanco + «Representante legal» —, nunca la cuenta que lo emitió.
 * Mismo motor que los demás documentos (PdfRendererFactory; DomPDF en
 * pruebas) y colores solo de config/pdf_identidad.php.
 */
class DiplomaCertificacionPdfService
{
    public function __construct(
        private readonly PdfRendererFactory $pdf,
        private readonly LogoPdfService $logo,
        private readonly FirmaPatronService $firmaPatron,
    ) {}

    public function renderizar(CertificacionMrLana $certificacion): string
    {
        $motor = MotorPdf::tryFrom((string) config('pdf.renderer', 'browsershot')) ?? MotorPdf::Browsershot;

        return $this->pdf->renderizarCon(
            $motor,
            fn (MotorPdf $m): string => $this->html($certificacion, $m),
            fn (MotorPdf $m): OpcionesPdf => new OpcionesPdf(tamano: 'letter', orientacion: 'landscape', margenInferiorMm: 0.0),
        )['pdf'];
    }

    public function html(CertificacionMrLana $certificacion, MotorPdf $motor = MotorPdf::Browsershot): string
    {
        return view('pdf.certificacion.diploma', ['d' => $this->datos($certificacion), 'motor' => $motor->value])->render();
    }

    /**
     * @return array<string, mixed>
     */
    public function datos(CertificacionMrLana $certificacion): array
    {
        $certificacion->loadMissing(['colaborador.sucursalPrincipal.empresa', 'colaborador.puesto']);
        $colaborador = $certificacion->colaborador;
        $empresa = $colaborador->sucursalPrincipal?->empresa;
        $folio = (string) $certificacion->folio;
        $url = route('constancias.verificar', $folio);
        $fecha = $certificacion->certificado_en ?? CarbonImmutable::now();

        return [
            'nombre' => $colaborador->nombreCompleto(),
            'puesto' => $colaborador->puesto->nombre ?? null,
            'sucursal' => $colaborador->sucursalPrincipal->nombre ?? null,
            'promedio' => $certificacion->promedio !== null ? rtrim(rtrim(number_format((float) $certificacion->promedio, 2, '.', ''), '0'), '.') : null,
            'fecha' => $fecha->settings(['locale' => 'es'])->isoFormat('D [de] MMMM [de] YYYY'),
            'folio' => $folio,
            'formato' => $certificacion->formato_clave,
            'url' => $url,
            'qr' => $this->qr($url),
            'logo' => $this->logo->dataUri($empresa?->logo_path),
            'empresa' => $this->firmaPatron->razonSocialDeEmpresa($empresa),
            'cargo' => $this->firmaPatron->cargo(),
        ];
    }

    private function qr(string $url): ?string
    {
        try {
            $png = (new Writer(new GDLibRenderer(300, 1, 'png')))->writeString($url);

            return sprintf('data:image/png;base64,%s', base64_encode($png));
        } catch (Throwable) {
            // Sin GD el certificado sale igual, con el folio y la liga escritos.
            return null;
        }
    }
}
