<?php

namespace App\Services\Formatos\Motor;

use App\Enums\EstrategiaFormato;
use App\Models\OfficialFormatVersion;
use App\Services\Formatos\OfficialFormatStorageService;
use GdImage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpWord\TemplateProcessor;
use RuntimeException;
use setasign\Fpdi\Fpdi;
use Throwable;

/**
 * Produce el PDF FINAL de una versión de plantilla con valores ya
 * resueltos y formateados (no sabe de colaboradores: eso lo hace
 * GeneradorFormatoService).
 *
 * Overlay: cada página del PDF base se importa tal cual (tamaño,
 * orientación, márgenes, fondo) y encima se escribe cada campo en su caja.
 * El texto que no cabe se reduce de tamaño (hasta 6 pt) antes que salirse
 * de su caja. DOCX con variables: se rellenan los {{marcadores}} dentro
 * del Word y se convierte a PDF.
 */
class RenderizadorFormato
{
    private const PT_A_MM = 25.4 / 72;

    private const FUENTE_MINIMA = 6.0;

    public function __construct(
        private readonly OfficialFormatStorageService $storage,
        private readonly ConversorDocxPdf $conversor,
    ) {}

    /**
     * @param  array<string, string>  $textos  id de campo → texto a imprimir
     * @param  array<string, string>  $imagenes  id de campo → bytes de la imagen
     * @param  array<string, string>  $marcas  id de campo → aviso en rojo (vista previa con datos faltantes)
     * @param  string|null  $leyenda  Texto rojo en el margen superior de cada página ("VISTA PREVIA"); nunca en el documento final.
     */
    public function renderizar(OfficialFormatVersion $version, array $textos, array $imagenes = [], array $marcas = [], ?string $leyenda = null): string
    {
        return $version->estrategia === EstrategiaFormato::DocxVariables
            ? $this->docx($version, $textos, $marcas)
            : $this->overlay($version, $textos, $imagenes, $marcas, $leyenda);
    }

    /**
     * @param  array<string, string>  $textos
     * @param  array<string, string>  $imagenes
     * @param  array<string, string>  $marcas
     */
    private function overlay(OfficialFormatVersion $version, array $textos, array $imagenes, array $marcas, ?string $leyenda): string
    {
        if ($version->base_path === null) {
            throw new RuntimeException('La versión no tiene PDF base.');
        }

        $base = $this->storage->aTemporal($version->base_path, 'pdf');
        $porPagina = [];

        foreach ($version->camposConfigurados() as $campo) {
            $porPagina[max(1, (int) ($campo['pagina'] ?? 1))][] = $campo;
        }

        $temporales = [];

        try {
            $pdf = new Fpdi;
            $pdf->SetAutoPageBreak(false);
            $pdf->SetMargins(0, 0, 0);
            $pdf->SetCreator('MR. LANA People');
            $total = $pdf->setSourceFile($base);

            for ($pagina = 1; $pagina <= $total; $pagina++) {
                $plantilla = $pdf->importPage($pagina);
                $tamano = $pdf->getTemplateSize($plantilla);

                if (! is_array($tamano)) {
                    throw new RuntimeException("No se pudo leer el tamaño de la página {$pagina}.");
                }

                $pdf->AddPage($tamano['orientation'], [$tamano['width'], $tamano['height']]);
                $pdf->useTemplate($plantilla);

                if ($leyenda !== null) {
                    $pdf->SetFont('Helvetica', 'B', 7);
                    $pdf->SetTextColor(200, 30, 30);
                    $pdf->SetXY(4, 2);
                    $pdf->Cell((float) $tamano['width'] - 8, 4, $this->latin1($leyenda), 0, 0, 'R');
                }

                foreach ($porPagina[$pagina] ?? [] as $campo) {
                    $id = (string) $campo['id'];

                    if (isset($marcas[$id])) {
                        $this->texto($pdf, $campo, $marcas[$id], [200, 30, 30]);

                        continue;
                    }

                    if (($campo['tipo'] ?? '') === 'imagen') {
                        if (isset($imagenes[$id])) {
                            $temporales[] = $this->imagen($pdf, $campo, $imagenes[$id]);
                        }

                        continue;
                    }

                    $valor = $textos[$id] ?? '';

                    if (trim($valor) !== '') {
                        $this->texto($pdf, $campo, $valor, $this->rgb((string) ($campo['color'] ?? '#111111')));
                    }
                }
            }

            return (string) $pdf->Output('S');
        } finally {
            @unlink($base);

            foreach (array_filter($temporales) as $temporal) {
                @unlink($temporal);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $campo
     * @param  array{0: int, 1: int, 2: int}  $color
     */
    private function texto(Fpdi $pdf, array $campo, string $valor, array $color): void
    {
        $maximo = isset($campo['max_caracteres']) ? (int) $campo['max_caracteres'] : 0;

        if ($maximo > 0 && mb_strlen($valor) > $maximo) {
            $valor = mb_substr($valor, 0, $maximo);
        }

        $texto = $this->latin1($valor);
        $x = (float) $campo['x'];
        $y = (float) $campo['y'];
        $ancho = max(5.0, (float) ($campo['ancho'] ?? 60));
        $alto = max(3.0, (float) ($campo['alto'] ?? 6));
        $estilo = ($campo['negrita'] ?? false) === true ? 'B' : '';
        $alineacion = match ($campo['align'] ?? 'left') {
            'center' => 'C',
            'right' => 'R',
            default => 'L',
        };
        $tamano = (float) ($campo['font_size'] ?? 10);

        $pdf->SetTextColor($color[0], $color[1], $color[2]);

        if (($campo['multilinea'] ?? false) === true) {
            // Reduce la fuente hasta que todas las líneas quepan en la caja.
            do {
                $pdf->SetFont('Helvetica', $estilo, $tamano);
                $interlineado = $tamano * self::PT_A_MM * 1.2;
                $lineas = $this->contarLineas($pdf, $texto, $ancho);
                $cabe = $lineas * $interlineado <= $alto + 0.01;
                $tamano -= 0.5;
            } while (! $cabe && $tamano >= self::FUENTE_MINIMA);

            $pdf->SetXY($x, $y);
            $pdf->MultiCell($ancho, $interlineado, $texto, 0, $alineacion);

            return;
        }

        $pdf->SetFont('Helvetica', $estilo, $tamano);

        while ($pdf->GetStringWidth($texto) > $ancho && $tamano > self::FUENTE_MINIMA) {
            $tamano -= 0.5;
            $pdf->SetFont('Helvetica', $estilo, $tamano);
        }

        $pdf->SetXY($x, $y);
        $pdf->Cell($ancho, $alto, $texto, 0, 0, $alineacion);
    }

    private function contarLineas(Fpdi $pdf, string $texto, float $ancho): int
    {
        $lineas = 0;

        foreach (explode("\n", $texto) as $parrafo) {
            $actual = '';
            $lineas++;

            foreach (explode(' ', $parrafo) as $palabra) {
                $prueba = $actual === '' ? $palabra : $actual.' '.$palabra;

                if ($pdf->GetStringWidth($prueba) > $ancho - 2 && $actual !== '') {
                    $lineas++;
                    $actual = $palabra;
                } else {
                    $actual = $prueba;
                }
            }
        }

        return $lineas;
    }

    /**
     * Imagen ajustada a su caja conservando proporción, centrada.
     *
     * @param  array<string, mixed>  $campo
     */
    private function imagen(Fpdi $pdf, array $campo, string $bytes): ?string
    {
        $origen = @imagecreatefromstring($bytes);

        if (! $origen instanceof GdImage) {
            return null;
        }

        $ancho = max(5.0, (float) ($campo['ancho'] ?? 30));
        $alto = max(5.0, (float) ($campo['alto'] ?? 30));
        $escala = min($ancho / imagesx($origen), $alto / imagesy($origen));
        $w = imagesx($origen) * $escala;
        $h = imagesy($origen) * $escala;

        $lienzo = imagecreatetruecolor(imagesx($origen), imagesy($origen));
        imagefill($lienzo, 0, 0, (int) imagecolorallocate($lienzo, 255, 255, 255));
        imagecopy($lienzo, $origen, 0, 0, 0, 0, imagesx($origen), imagesy($origen));

        $temporal = sys_get_temp_dir().DIRECTORY_SEPARATOR.Str::uuid().'.jpg';
        imagejpeg($lienzo, $temporal, 90);

        $pdf->Image($temporal, (float) $campo['x'] + ($ancho - $w) / 2, (float) $campo['y'] + ($alto - $h) / 2, $w, $h, 'JPG');

        return $temporal;
    }

    /**
     * @param  array<string, string>  $textos
     * @param  array<string, string>  $marcas
     */
    private function docx(OfficialFormatVersion $version, array $textos, array $marcas): string
    {
        $origen = $this->storage->aTemporal($version->source_path, 'docx');
        $salida = sys_get_temp_dir().DIRECTORY_SEPARATOR.Str::uuid().'.docx';

        try {
            $procesador = new TemplateProcessor($origen);
            $procesador->setMacroChars('{{', '}}');

            foreach ($version->camposConfigurados() as $campo) {
                $marcador = (string) ($campo['placeholder'] ?? '');

                if ($marcador === '') {
                    continue;
                }

                $valor = $marcas[(string) $campo['id']] ?? ($textos[(string) $campo['id']] ?? '');
                $procesador->setValue($marcador, htmlspecialchars($valor, ENT_QUOTES | ENT_XML1));
            }

            $procesador->saveAs($salida);
            $convertido = $this->conversor->convertir((string) file_get_contents($salida));
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['plantilla' => 'No se pudo rellenar el Word de la plantilla ('.class_basename($e).').']);
        } finally {
            @unlink($origen);
            @unlink($salida);
        }

        if ($convertido === null) {
            throw ValidationException::withMessages(['plantilla' => 'No se pudo convertir el Word a PDF.']);
        }

        return $convertido['pdf'];
    }

    /**
     * Las fuentes core de FPDF esperan ISO-8859-1; sin esto los acentos y
     * la ñ salen corruptos.
     */
    private function latin1(string $texto): string
    {
        $convertido = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $texto);

        return $convertido !== false ? $convertido : $texto;
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        if (preg_match('/^[0-9a-fA-F]{6}$/', $hex) !== 1) {
            return [17, 17, 17];
        }

        return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
    }
}
