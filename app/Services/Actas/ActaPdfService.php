<?php

namespace App\Services\Actas;

use App\Enums\CategoriaDocumento;
use App\Enums\MotorPdf;
use App\Enums\TipoActa;
use App\Models\ActaAdministrativa;
use App\Models\GeneratedDocument;
use App\Models\User;
use App\Services\DocumentosLaborales\MotorDocumentalService;
use App\Services\Pdf\OpcionesPdf;
use App\Services\Pdf\PdfRendererFactory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Formato PROPIO (BrowserShot) y ÚNICO del acta administrativa
 * circunstanciada (decisión 2026-10-15: un solo tipo de acta, un solo
 * formato). Las actas de hechos históricas que sigan en borrador usan el
 * mismo formato. Es un documento administrativo FACTUAL: no cita
 * artículos de ley ni cláusulas, y no imprime metadata técnica (usuario
 * interno, timestamps de sistema, «autorizado por»).
 *
 * La vista previa y el documento final salen del MISMO html() con los
 * MISMOS datos: la vista previa nunca persiste nada.
 */
class ActaPdfService
{
    public function __construct(
        private readonly PdfRendererFactory $pdf,
        private readonly MotorDocumentalService $motor,
    ) {}

    /** El acta circunstanciada (y una de hechos histórica en borrador) usa este formato. */
    public static function aplica(ActaAdministrativa $acta): bool
    {
        return in_array($acta->tipo, [TipoActa::Administrativa, TipoActa::Hechos], true);
    }

    /**
     * Lo que impide generar el acta (null = se puede). Mismo criterio en la
     * vista previa y en el final.
     */
    public function faltante(ActaAdministrativa $acta): ?string
    {
        return match (true) {
            trim((string) $acta->hechos) === '' => 'Describe los hechos antes de generar el acta.',
            $acta->tipo === TipoActa::Administrativa && trim((string) $acta->motivo) === '' => 'Captura el motivo del acta administrativa.',
            count($acta->testigos ?? []) !== 2 => 'El acta lleva exactamente dos testigos.',
            default => null,
        };
    }

    /** PDF en memoria para la vista previa (no guarda nada). */
    public function vistaPrevia(ActaAdministrativa $acta): string
    {
        return $this->renderizar($acta)['pdf'];
    }

    /**
     * PDF final: se guarda en el expediente (carpeta Actas) y arranca su
     * flujo físico (imprimir, firmar, archivar) como cualquier documento.
     */
    public function registrar(ActaAdministrativa $acta, User $actor): GeneratedDocument
    {
        if (($motivo = $this->faltante($acta)) !== null) {
            throw ValidationException::withMessages(['documento' => $motivo]);
        }

        $render = $this->renderizar($acta);
        $titulo = sprintf('%s %s', $acta->tipo->etiqueta(), $acta->folio);

        return $this->motor->registrarPdf($acta->colaborador, $render['pdf'], $titulo, $actor, [
            'clave' => $acta->tipo->clavePlantilla(),
            'categoria' => CategoriaDocumento::Actas,
            'documentable' => $acta,
            'proceso' => 'acta',
            'requiere_impresion' => true,
            'requiere_firma_fisica' => true,
            'requiere_testigos' => true,
            'conversion_engine' => $render['motor']->value,
            'nombre_archivo' => sprintf('%s - %s.pdf', $titulo, now()->format('Y-m-d His')),
        ]);
    }

    /**
     * @return array{pdf: string, motor: MotorPdf, respaldo: bool}
     */
    private function renderizar(ActaAdministrativa $acta): array
    {
        $motor = MotorPdf::tryFrom((string) config('pdf.renderer', 'browsershot')) ?? MotorPdf::Browsershot;

        return $this->pdf->renderizarCon(
            $motor,
            fn (MotorPdf $m): string => $this->html($acta, $m),
            fn (MotorPdf $m): OpcionesPdf => new OpcionesPdf(
                tamano: 'letter',
                numerarPaginas: true,
                textoPie: 'MR. LANA PEOPLE',
                margenInferiorMm: 18.0,
            ),
        );
    }

    public function html(ActaAdministrativa $acta, MotorPdf $motor = MotorPdf::Browsershot): string
    {
        return view('pdf.actas.acta', ['d' => $this->datos($acta), 'motor' => $motor->value])->render();
    }

    /**
     * @return array<string, mixed>
     */
    public function datos(ActaAdministrativa $acta): array
    {
        $acta->loadMissing(['colaborador.puesto', 'colaborador.jefe.puesto', 'colaborador.sucursalPrincipal.empresa', 'responsable.colaborador.puesto', 'anexos']);
        $colaborador = $acta->colaborador;
        $sucursal = $colaborador->sucursalPrincipal;
        $empresa = $sucursal?->empresa;
        $declaraciones = array_values($acta->declaraciones ?? []);
        $testigos = array_values($acta->testigos ?? []);
        $responsable = $acta->responsable;

        return [
            'titulo' => mb_strtoupper($acta->tipo->etiqueta(), 'UTF-8'),
            'folio' => (string) $acta->folio,
            'fecha' => $acta->fecha->copy()->settings(['locale' => 'es'])->isoFormat('D [de] MMMM [de] YYYY'),
            'hora' => $acta->hora !== null && $acta->hora !== '' ? substr((string) $acta->hora, 0, 5) : null,
            'lugar' => $acta->lugar,
            'empresa' => $empresa->razon_social ?? $empresa->nombre ?? 'MR. LANA',
            'sucursal' => $sucursal?->nombre,
            'ciudad' => $sucursal->ciudad ?? $sucursal->municipio ?? null,
            'colaborador' => [
                'nombre' => $colaborador->nombreCompleto(),
                'numero_empleado' => $colaborador->numero_empleado,
                'puesto' => $colaborador->puesto?->nombre,
                'jefe' => $colaborador->jefe?->nombreCompleto(),
            ],
            'motivo' => $acta->motivo,
            'hechos' => trim((string) $acta->hechos),
            'manifestacion_colaborador' => isset($declaraciones[0]) ? $declaraciones[0]['declaracion'] : 'No quiso manifestar nada.',
            'otras_manifestaciones' => array_values(array_filter(array_slice($declaraciones, 1), fn (array $d): bool => trim($d['declaracion']) !== '')),
            'testigos' => array_map(fn (array $t): array => ['nombre' => $t['nombre'], 'cargo' => (string) ($t['puesto'] ?? '')], $testigos),
            'levanta' => [
                'nombre' => $responsable !== null ? trim($responsable->name.' '.($responsable->apellidos ?? '')) : '',
                'cargo' => $responsable?->colaborador?->puesto?->nombre,
            ],
            'anexos' => $acta->anexos->map(fn ($a): string => (string) ($a->descripcion ?: $a->original_name))->values()->all(),
            'logo' => $this->logo($empresa?->logo_path),
        ];
    }

    /** Logo como data URI (igual en Chrome y DomPDF, sin depender de la red). */
    private function logo(?string $logoEmpresa): ?string
    {
        if ($logoEmpresa !== null && Storage::disk('public')->exists($logoEmpresa)) {
            $contenido = (string) Storage::disk('public')->get($logoEmpresa);
            $mime = Storage::disk('public')->mimeType($logoEmpresa) ?: 'image/png';

            return sprintf('data:%s;base64,%s', $mime, base64_encode($contenido));
        }

        $ruta = resource_path('documentos/logo-mr-lana.png');

        return is_file($ruta) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($ruta)) : null;
    }
}
