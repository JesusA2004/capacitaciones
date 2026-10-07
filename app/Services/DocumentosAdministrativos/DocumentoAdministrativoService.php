<?php

namespace App\Services\DocumentosAdministrativos;

use App\Enums\EstadoReciboNomina;
use App\Enums\FamiliaAdministrativa;
use App\Enums\MotorPdf;
use App\Models\Colaborador;
use App\Models\DocumentAsset;
use App\Models\PlantillaAdministrativa;
use App\Models\ReciboNomina;
use App\Models\User;
use App\Services\Pdf\OpcionesPdf;
use App\Services\Pdf\PdfRendererFactory;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Arma el HTML de un documento administrativo (datos + diseño de la versión
 * activa) y lo imprime con su motor (Chrome o DomPDF). Devuelve además el
 * SNAPSHOT que se guarda en GeneratedDocument: versión, hash del diseño,
 * diseño completo, motor real, recursos (id + SHA-256), fecha y usuario.
 * Así, cambiar el diseño mañana no altera ningún PDF ya generado.
 */
class DocumentoAdministrativoService
{
    public function __construct(
        private readonly PlantillasAdministrativasService $plantillas,
        private readonly DisenoAdministrativoService $disenos,
        private readonly PdfRendererFactory $renderers,
        private readonly DatosDocumentoAdministrativo $datos,
    ) {}

    /**
     * PDF con el diseño VIGENTE de la familia.
     *
     * @param  array<string, mixed>  $datos
     * @return array{pdf: string, opciones_registro: array{master_familia: string, master_version: int, master_hash: string, version: int, conversion_engine: string, layout_snapshot: array<string, mixed>}}
     */
    public function generar(FamiliaAdministrativa $familia, array $datos, ?User $actor): array
    {
        $vigente = $this->plantillas->vigente($familia);
        $resultado = $this->renderizar($familia, $datos, $vigente['diseno'], $vigente['motor']);
        $hash = $this->disenos->hash($vigente['diseno']);

        return [
            'pdf' => $resultado['pdf'],
            'opciones_registro' => [
                'master_familia' => $this->plantillas->claveSnapshot($familia),
                'master_version' => $vigente['version'],
                'master_hash' => $hash,
                'version' => $vigente['version'],
                'conversion_engine' => $resultado['motor']->value,
                'layout_snapshot' => [
                    'familia' => $familia->value,
                    'plantilla_id' => $vigente['plantilla']?->id,
                    'version' => $vigente['version'],
                    'diseno' => $vigente['diseno'],
                    'hash' => $hash,
                    'motor' => $resultado['motor']->value,
                    'motor_configurado' => $vigente['motor']->value,
                    'respaldo_dompdf' => $resultado['respaldo'],
                    'recursos' => $this->disenos->recursosUsados($vigente['diseno']),
                    'generado_en' => now()->toIso8601String(),
                    'generado_por' => $actor?->id,
                ],
            ],
        ];
    }

    /**
     * Vista previa REAL (mismo motor y mismo HTML que la generación) con
     * datos ficticios, de una versión concreta o del diseño por defecto.
     */
    public function vistaPrevia(FamiliaAdministrativa $familia, ?PlantillaAdministrativa $plantilla): string
    {
        $diseno = $plantilla !== null ? $this->disenos->normalizar($familia, $plantilla->diseno) : $this->disenos->porDefecto($familia);
        $motor = $plantilla?->motorEfectivo() ?? MotorPdf::porDefecto();

        return $this->renderizar($familia, $this->datos->ejemplo($familia), $diseno, $motor)['pdf'];
    }

    /**
     * Vista previa con los datos REALES de un colaborador ("Probar con
     * colaborador" del editor), en vez de los datos ficticios — mismo motor
     * y mismo HTML que la generación real. Nunca inventa ni calcula nada:
     * solo reutiliza el registro más reciente que ya existe. Si ese
     * colaborador no tiene un registro real para esta familia, devuelve el
     * motivo (nunca una excepción) para que la pantalla lo muestre tal cual.
     *
     * Solo recibo de nómina y constancia laboral tienen una fuente de datos
     * reales segura de resolver aquí sin más contexto (folio/solicitud
     * concreta); finiquito y comprobante de solicitud dependen de un
     * trámite específico y se quedan fuera de este atajo a propósito.
     *
     * @return array{pdf: string}|array{faltante: string}
     */
    public function vistaPreviaConColaborador(FamiliaAdministrativa $familia, ?PlantillaAdministrativa $plantilla, Colaborador $colaborador): array
    {
        $datos = match ($familia) {
            FamiliaAdministrativa::ReciboNomina => $this->reciboRealDe($colaborador),
            FamiliaAdministrativa::ConstanciaLaboral => $this->datos->constancia($colaborador),
            default => null,
        };

        if ($datos === null) {
            return ['faltante' => match ($familia) {
                FamiliaAdministrativa::ReciboNomina => sprintf('%s todavía no tiene un recibo de nómina emitido.', $colaborador->nombreCompleto()),
                FamiliaAdministrativa::Finiquito, FamiliaAdministrativa::ComprobanteSolicitud => 'La vista previa con colaborador real todavía no está disponible para este tipo de documento (depende de un trámite concreto). Usa la vista previa con datos de ejemplo.',
                default => 'No se encontraron datos reales para este colaborador.',
            }];
        }

        $diseno = $plantilla !== null ? $this->disenos->normalizar($familia, $plantilla->diseno) : $this->disenos->porDefecto($familia);
        $motor = $plantilla?->motorEfectivo() ?? MotorPdf::porDefecto();

        return ['pdf' => $this->renderizar($familia, $datos, $diseno, $motor)['pdf']];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function reciboRealDe(Colaborador $colaborador): ?array
    {
        $recibo = ReciboNomina::query()
            ->where('colaborador_id', $colaborador->id)
            ->where('estado', EstadoReciboNomina::Emitido->value)
            ->latest('fecha_pago')
            ->first();

        return $recibo !== null ? $this->datos->recibo($recibo) : null;
    }

    /**
     * @param  array<string, mixed>  $datos
     * @param  array<string, mixed>  $diseno
     * @return array{pdf: string, motor: MotorPdf, respaldo: bool}
     */
    public function renderizar(FamiliaAdministrativa $familia, array $datos, array $diseno, MotorPdf $motor): array
    {
        $opciones = fn (MotorPdf $m) => new OpcionesPdf(
            tamano: $diseno['page']['size'],
            orientacion: $diseno['page']['orientation'],
            numerarPaginas: (bool) ($diseno['footer']['show'] && $diseno['footer']['page_numbers']),
            textoPie: $m === MotorPdf::Browsershot && $diseno['footer']['show'] ? $this->sustituir((string) $diseno['footer']['text'], $datos) : '',
            distanciaPieMm: (float) $diseno['footer']['distance_mm'],
            colorPie: (string) $diseno['colors']['muted'],
            margenInferiorMm: (float) $diseno['page']['margins_mm']['bottom'],
        );

        // El HTML depende del motor (Chrome entiende más CSS que DomPDF): si
        // se cae a DomPDF por respaldo, se rearma para DomPDF.
        return $this->renderers->renderizarCon($motor, fn (MotorPdf $m) => $this->html($familia, $datos, $diseno, $m), $opciones);
    }

    /**
     * @param  array<string, mixed>  $datos
     * @param  array<string, mixed>  $diseno
     */
    public function html(FamiliaAdministrativa $familia, array $datos, array $diseno, MotorPdf $motor = MotorPdf::DomPdf): string
    {
        $contenido = array_map(fn (string $texto) => $this->sustituir($texto, $datos), $diseno['content']);
        $diseno['header']['brand_text'] = $this->sustituir((string) $diseno['header']['brand_text'], $datos);
        $diseno['footer']['text'] = $this->sustituir((string) $diseno['footer']['text'], $datos);
        [$ancho, $alto] = $diseno['page']['size'] === 'a4' ? [210, 297] : [215.9, 279.4];

        if ($diseno['page']['orientation'] === 'landscape') {
            [$ancho, $alto] = [$alto, $ancho];
        }

        return view('pdf.administrativos.documento', [
            'diseno' => $diseno,
            'motor' => $motor->value,
            'd' => $datos,
            'contenido' => $contenido,
            'vista_familia' => $familia->value,
            'titulo_documento' => $contenido['titulo'] !== '' ? $contenido['titulo'] : $familia->etiqueta(),
            'fuente_css' => DisenoAdministrativoService::FUENTES[$diseno['typography']['font_family']] ?? DisenoAdministrativoService::FUENTES['Helvetica'],
            'pagina_mm' => ['ancho' => $ancho, 'alto' => $alto],
            'fondo_data_uri' => $this->dataUri($diseno['background']['asset_id'] ?? null),
            'logo_data_uri' => $this->dataUri($diseno['header']['logo_asset_id'] ?? null),
        ])->render();
    }

    /**
     * {{ campo }} / {{ campo.sub }} dentro de los textos editables. Solo
     * texto plano (Blade lo escapa al pintarlo); un campo desconocido se
     * deja vacío.
     *
     * @param  array<string, mixed>  $datos
     */
    public function sustituir(string $texto, array $datos): string
    {
        return (string) preg_replace_callback('/\{\{\s*([a-z_]+(?:\.[a-z_]+)?)\s*\}\}/', function (array $m) use ($datos): string {
            $valor = data_get($datos, $m[1]);

            return is_scalar($valor) ? (string) $valor : '';
        }, $texto);
    }

    private function dataUri(mixed $assetId): ?string
    {
        if (! is_numeric($assetId)) {
            return null;
        }

        $asset = DocumentAsset::query()->where('id', (int) $assetId)->first();

        if ($asset === null) {
            return null;
        }

        try {
            $bytes = Storage::disk($asset->disk)->get($asset->path);
        } catch (Throwable) {
            return null;
        }

        return $bytes !== null ? sprintf('data:%s;base64,%s', $asset->mime_type, base64_encode($bytes)) : null;
    }
}
