<?php

namespace App\Services\Responsivas;

use App\Enums\MotorPdf;
use App\Models\Responsiva;
use App\Models\ResponsivaActivo;
use App\Services\Pdf\OpcionesPdf;
use App\Services\Pdf\PdfRendererException;
use App\Services\Pdf\PdfRendererFactory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * PDF de la responsiva de equipo (docs/responsiva/ResponsivaLaptopPanta.docx),
 * impreso por Chrome (Browsershot) con la misma configuración que actas y
 * reportes (PdfRendererFactory). El DOCX manda en el texto y las cláusulas
 * (se conservan literales, solo cambia «equipo de cómputo» ↔ «equipo de
 * trabajo» según el tipo); PEOPLE manda en los datos, que salen del
 * snapshot congelado al generarla, nunca del estado actual del colaborador.
 *
 * Tamaño carta y márgenes del DOCX (1" arriba/abajo, 0.75" a los lados).
 */
class ResponsivaPdfService
{
    public function __construct(private readonly PdfRendererFactory $pdf) {}

    /**
     * @return array{pdf: string, motor: MotorPdf}
     */
    public function renderizar(Responsiva $responsiva, bool $borrador = false): array
    {
        $datos = $this->datos($responsiva);
        $opciones = fn (MotorPdf $m): OpcionesPdf => new OpcionesPdf(
            tamano: 'letter',
            numerarPaginas: true,
            textoPie: $borrador ? 'Vista previa · sin validez' : sprintf('Responsiva %s', $datos['folio']),
            colorPie: '#5b6b73',
            margenInferiorMm: 25.4,
        );

        try {
            $render = $this->pdf->renderizarCon(
                MotorPdf::porDefecto(),
                fn (MotorPdf $m): string => $this->html($datos, $m, $borrador),
                $opciones,
            );
        } catch (PdfRendererException $e) {
            Log::warning('Responsiva: no se pudo imprimir el PDF.', ['folio' => $datos['folio'], 'error' => $e->getPrevious()?->getMessage() ?? $e->getMessage()]);

            throw ValidationException::withMessages(['responsiva' => $e->getMessage().' Intenta de nuevo en unos minutos; si continúa, avisa a sistemas.']);
        }

        return ['pdf' => $render['pdf'], 'motor' => $render['motor']];
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function html(array $datos, MotorPdf $motor = MotorPdf::Browsershot, bool $borrador = false): string
    {
        return view('pdf.responsivas.responsiva', ['d' => $datos, 'motor' => $motor->value, 'borrador' => $borrador])->render();
    }

    /**
     * Solo texto para imprimir: ningún ID ni dato técnico.
     *
     * @return array<string, mixed>
     */
    public function datos(Responsiva $responsiva): array
    {
        $s = $responsiva->snapshot;
        $texto = fn (mixed $v): ?string => is_string($v) && trim($v) !== '' ? trim($v) : null;
        $receptor = (array) ($s['receptor'] ?? []);
        $otorgante = (array) ($s['otorgante'] ?? []);
        $empresa = (array) ($s['empresa'] ?? []);

        return [
            'folio' => $responsiva->folio ?? 'Sin folio',
            'titulo' => $responsiva->tipo->titulo(),
            'denominacion' => $responsiva->tipo->denominacion(),
            'lugar' => $responsiva->lugar,
            'fecha' => $responsiva->fecha_entrega->copy()->settings(['locale' => 'es'])->isoFormat('DD [de] MMMM [de] YYYY'),
            'empresa' => mb_strtoupper($texto($empresa['razon_social'] ?? null) ?? $texto($empresa['nombre'] ?? null) ?? 'MR. LANA', 'UTF-8'),
            'logo' => $this->logo($texto($empresa['logo_path'] ?? null)),
            'receptor' => [
                'nombre' => mb_strtoupper((string) ($receptor['nombre'] ?? ''), 'UTF-8'),
                'numero_empleado' => $texto($receptor['numero_empleado'] ?? null),
                'puesto' => $texto($receptor['puesto'] ?? null),
                'sucursal' => $texto($receptor['sucursal'] ?? null),
            ],
            'otorgante' => [
                'nombre' => mb_strtoupper((string) ($otorgante['nombre'] ?? ''), 'UTF-8'),
                'puesto' => $texto($otorgante['puesto'] ?? null) !== null ? mb_strtoupper((string) $otorgante['puesto'], 'UTF-8') : null,
            ],
            'activos' => $responsiva->activos->values()->map(fn (ResponsivaActivo $a, int $i): array => [
                'numero' => $i + 1,
                'filas' => $this->filasActivo($a, $i + 1),
            ])->all(),
            'observaciones' => $texto($responsiva->observaciones),
        ];
    }

    /**
     * Renglones «Nombre de la característica | Descripción detallada» de un
     * equipo, en el orden del formato: Equipo N, Marca, Modelo, (número de
     * serie), características, Color, Estado, Accesorios.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function filasActivo(ResponsivaActivo $activo, int $numero): array
    {
        $filas = [[sprintf('Equipo %d', $numero), $activo->descripcion]];

        foreach (['Marca' => $activo->marca, 'Modelo' => $activo->modelo, 'Número de serie' => $activo->numero_serie] as $nombre => $valor) {
            if (is_string($valor) && trim($valor) !== '') {
                $filas[] = [$nombre, trim($valor)];
            }
        }

        foreach ($activo->caracteristicasLista() as $c) {
            $filas[] = [$c['nombre'], $c['valor']];
        }

        if (is_string($activo->color) && trim($activo->color) !== '') {
            $filas[] = ['Color', trim($activo->color)];
        }

        if ($activo->cantidad > 1) {
            $filas[] = ['Cantidad', sprintf('%d piezas', $activo->cantidad)];
        }

        $filas[] = ['Estado', $activo->estado->etiqueta()];

        if (is_string($activo->accesorios) && trim($activo->accesorios) !== '') {
            $filas[] = ['Accesorios', trim($activo->accesorios)];
        }

        return $filas;
    }

    /** Logo como data URI (igual en Chrome y DomPDF, sin depender de la red). */
    private function logo(?string $logoEmpresa): ?string
    {
        if ($logoEmpresa !== null && Storage::disk('public')->exists($logoEmpresa)) {
            $mime = Storage::disk('public')->mimeType($logoEmpresa) ?: 'image/png';

            return sprintf('data:%s;base64,%s', $mime, base64_encode((string) Storage::disk('public')->get($logoEmpresa)));
        }

        $ruta = resource_path('documentos/logo-mr-lana.png');

        return is_file($ruta) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($ruta)) : null;
    }
}
