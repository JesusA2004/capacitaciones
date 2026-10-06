<?php

namespace App\Services\Pdf;

use App\Enums\MotorPdf;
use Spatie\Browsershot\Browsershot;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

/**
 * Chrome/Chromium headless vía spatie/browsershot: CSS de impresión real
 * (@page con tamaño y márgenes, thead repetido en cada página,
 * break-inside, object-fit, fuentes web). Rutas de node/npm/Chrome por
 * .env (config/pdf.php); nada fijo de Windows ni de un servidor concreto.
 */
class BrowsershotRenderer implements PdfRendererInterface
{
    public function motor(): MotorPdf
    {
        return MotorPdf::Browsershot;
    }

    public function renderizar(string $html, OpcionesPdf $opciones): string
    {
        if (! class_exists(Browsershot::class)) {
            throw new PdfRendererException('Falta el paquete spatie/browsershot en el servidor (composer install).');
        }

        try {
            $navegador = Browsershot::html($html)
                ->showBackground()
                ->emulateMedia('print')
                ->format($opciones->tamano === 'a4' ? 'A4' : 'Letter')
                ->landscape($opciones->orientacion === 'landscape')
                // Tamaño y márgenes salen del @page del propio HTML.
                ->setOption('preferCSSPageSize', true)
                ->timeout((int) config('pdf.browsershot.timeout', 60));

            $node = config('pdf.browsershot.node_binary');
            $npm = config('pdf.browsershot.npm_binary');
            $chrome = config('pdf.browsershot.chrome_path');
            $modulos = config('pdf.browsershot.node_modules_path');

            if (is_string($node) && $node !== '') {
                $navegador->setNodeBinary($node);
            }

            if (is_string($npm) && $npm !== '') {
                $navegador->setNpmBinary($npm);
            }

            // Sin ruta configurada: Chrome/Chromium del sistema (PATH). Si no
            // hay, puppeteer usa el navegador que haya descargado él.
            $rutaChrome = is_string($chrome) && $chrome !== '' ? $chrome : $this->chromeDelSistema();

            if ($rutaChrome !== null) {
                $navegador->setChromePath($rutaChrome);
            }

            if (is_string($modulos) && $modulos !== '') {
                $navegador->setNodeModulePath($modulos);
            }

            if ((bool) config('pdf.browsershot.no_sandbox', true)) {
                $navegador->noSandbox();
            }

            if ($opciones->numerarPaginas || $opciones->textoPie !== '') {
                $navegador->showBrowserHeaderAndFooter()
                    ->headerHtml('<span></span>')
                    ->footerHtml(sprintf(
                        '<div style="width:100%%;font-size:8px;text-align:center;color:%s;font-family:Helvetica,Arial,sans-serif;margin-bottom:%.1fmm;">%s%s%s</div>',
                        e($opciones->colorPie),
                        max(0, $opciones->distanciaPieMm - 4),
                        e($opciones->textoPie),
                        $opciones->textoPie !== '' && $opciones->numerarPaginas ? ' · ' : '',
                        $opciones->numerarPaginas ? 'Página <span class="pageNumber"></span> de <span class="totalPages"></span>' : '',
                    ));
            }

            return $navegador->pdf();
        } catch (Throwable $e) {
            throw new PdfRendererException('Chrome (Browsershot) no pudo generar el documento: '.$e->getMessage(), 0, $e);
        }
    }

    /** Chrome/Chromium en el PATH del proceso, o null si no hay. */
    private function chromeDelSistema(): ?string
    {
        $buscador = new ExecutableFinder;

        foreach (['google-chrome', 'google-chrome-stable', 'chromium', 'chromium-browser'] as $nombre) {
            $ruta = $buscador->find($nombre);

            if ($ruta !== null) {
                return $ruta;
            }
        }

        return null;
    }
}
