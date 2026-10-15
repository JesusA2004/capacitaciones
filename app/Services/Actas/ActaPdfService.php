<?php

namespace App\Services\Actas;

use App\Enums\CaracterLevantamientoActa;
use App\Enums\CategoriaDocumento;
use App\Enums\MotorPdf;
use App\Enums\RelacionTestigoActa;
use App\Enums\TipoActa;
use App\Enums\TipoIncidenciaActa;
use App\Models\ActaAdministrativa;
use App\Models\ActaAnexo;
use App\Models\GeneratedDocument;
use App\Models\User;
use App\Services\DocumentosLaborales\MotorDocumentalService;
use App\Services\Pdf\OpcionesPdf;
use App\Services\Pdf\PdfRendererFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * PDF del acta administrativa circunstanciada (docs/acta/ACTA ADMINISTRATIVA
 * CIRCUNSTANCIADA.docx), generado por PEOPLE con BrowserShot. El DOCX manda
 * en estructura, apartados y texto institucional; PEOPLE manda en los datos.
 * Las «INSTRUCCIÓN DE LLENADO» del DOCX son reglas del formulario: jamás se
 * imprimen. No se inventan personas, artículos de ley ni sanciones.
 *
 * Vista previa y final salen del MISMO html() con los MISMOS datos y el
 * mismo renderer; la vista previa lleva la marca «VISTA PREVIA · BORRADOR»
 * y no guarda nada. Las actas históricas (formato null) usan su formato
 * anterior (pdf.actas.acta).
 */
class ActaPdfService
{
    public function __construct(
        private readonly PdfRendererFactory $pdf,
        private readonly MotorDocumentalService $motor,
    ) {}

    /** Actas con formato propio de PEOPLE (la circunstanciada y las históricas en borrador). */
    public static function aplica(ActaAdministrativa $acta): bool
    {
        return in_array($acta->tipo, [TipoActa::Administrativa, TipoActa::Hechos], true);
    }

    public static function esCircunstanciada(ActaAdministrativa $acta): bool
    {
        return $acta->formato === ActaService::FORMATO_CIRCUNSTANCIADA;
    }

    /**
     * Lo que impide generar (null = se puede). Mismo criterio en la vista
     * previa y en el final.
     */
    public function faltante(ActaAdministrativa $acta): ?string
    {
        if (! self::esCircunstanciada($acta)) {
            return match (true) {
                trim((string) $acta->hechos) === '' => 'Describe los hechos antes de generar el acta.',
                count($acta->testigos ?? []) !== 2 => 'El acta lleva exactamente dos testigos.',
                default => null,
            };
        }

        $c = $acta->circunstanciada ?? [];
        $manifesto = $c['trabajador']['manifesto'] ?? null;
        $testigos = is_array($c['testigos_hechos'] ?? null) ? $c['testigos_hechos'] : [];
        $sinRelacion = array_filter($testigos, fn (mixed $t): bool => ! is_array($t) || ($t['relacion'] ?? null) === null);

        return match (true) {
            ($c['incidencia']['tipo'] ?? null) === null => 'Indica el tipo de incidencia.',
            ($c['incidencia']['fecha_hechos'] ?? null) === null => 'Indica la fecha o el período en que ocurrieron los hechos.',
            trim((string) $acta->hechos) === '' => 'Describe los hechos de manera circunstanciada.',
            ($c['responsable']['caracter'] ?? null) === null => 'Indica el carácter con el que interviene quien levanta el acta.',
            $manifesto === null => 'Registra la manifestación del trabajador o que no quiso manifestar.',
            $manifesto === true && ($c['trabajador']['manifestacion'] ?? null) === null => 'Captura lo que manifestó el trabajador.',
            $sinRelacion !== [] => 'Indica la relación de cada testigo con los hechos.',
            default => null,
        };
    }

    /** PDF en memoria para la vista previa (marca BORRADOR; no guarda nada). */
    public function vistaPrevia(ActaAdministrativa $acta): string
    {
        return $this->renderizar($acta, borrador: true)['pdf'];
    }

    /**
     * PDF FINAL: fija cierre y control documental, congela el PDF en el
     * expediente (carpeta Actas) y arranca su flujo físico.
     */
    public function registrar(ActaAdministrativa $acta, User $actor): GeneratedDocument
    {
        if (($motivo = $this->faltante($acta)) !== null) {
            throw ValidationException::withMessages(['documento' => $motivo]);
        }

        if (self::esCircunstanciada($acta)) {
            $c = $acta->circunstanciada ?? [];
            $ahora = Carbon::now('America/Mexico_City');
            $c['cierre'] = [
                'fecha' => $c['cierre']['fecha'] ?? $ahora->toDateString(),
                'hora' => $c['cierre']['hora'] ?? $ahora->format('H:i'),
            ];
            $c['control'] = [
                'fecha_integracion' => $ahora->toDateString(),
                'integrado_por' => ActaService::nombreDe($actor),
            ];
            $acta->update(['circunstanciada' => $c]);
        }

        $render = $this->renderizar($acta->refresh(), borrador: false);
        $titulo = sprintf('%s %s', $acta->tipo->etiqueta(), $acta->folio);

        return $this->motor->registrarPdf($acta->colaborador, $render['pdf'], $titulo, $actor, [
            'clave' => $acta->tipo->clavePlantilla(),
            'categoria' => CategoriaDocumento::Actas,
            'documentable' => $acta,
            'proceso' => 'acta',
            'requiere_impresion' => true,
            'requiere_firma_fisica' => true,
            // Solo si alguien firma como testigo: con 0 testigos (de hechos o
            // de asistencia) el registro de la firma física no los exige.
            'requiere_testigos' => self::tieneTestigos($acta),
            'conversion_engine' => $render['motor']->value,
            'paginas' => $render['paginas'],
            'nombre_archivo' => sprintf('%s - %s.pdf', $titulo, Carbon::now('America/Mexico_City')->format('Y-m-d His')),
        ]);
    }

    /**
     * ¿Firma algún testigo el acta? Circunstanciada: testigos de hechos o de
     * asistencia; histórica: su lista de testigos.
     */
    public static function tieneTestigos(ActaAdministrativa $acta): bool
    {
        if (! self::esCircunstanciada($acta)) {
            return count($acta->testigos ?? []) > 0;
        }

        $c = $acta->circunstanciada ?? [];

        return count((array) ($c['testigos_hechos'] ?? [])) + count((array) ($c['testigos_asistencia'] ?? [])) > 0;
    }

    /**
     * Dos pasadas: la primera cuenta las hojas reales con el mismo renderer;
     * la segunda imprime ese número en el control documental.
     *
     * @return array{pdf: string, motor: MotorPdf, respaldo: bool, paginas: int}
     */
    private function renderizar(ActaAdministrativa $acta, bool $borrador): array
    {
        $motor = MotorPdf::tryFrom((string) config('pdf.renderer', 'browsershot')) ?? MotorPdf::Browsershot;
        $opciones = fn (MotorPdf $m): OpcionesPdf => new OpcionesPdf(
            tamano: 'a4',
            numerarPaginas: true,
            textoPie: sprintf('Documento interno · %s · Folio %s', $acta->tipo->etiqueta(), $acta->folio),
            margenInferiorMm: 18.0,
        );

        $primera = $this->pdf->renderizarCon($motor, fn (MotorPdf $m): string => $this->html($acta, $m, $borrador), $opciones);
        $paginas = self::contarPaginas($primera['pdf']);

        if (! self::esCircunstanciada($acta)) {
            return [...$primera, 'paginas' => $paginas];
        }

        $final = $this->pdf->renderizarCon($primera['motor'], fn (MotorPdf $m): string => $this->html($acta, $m, $borrador, $paginas), $opciones);

        return [...$final, 'paginas' => self::contarPaginas($final['pdf'])];
    }

    public static function contarPaginas(string $pdf): int
    {
        return max(1, (int) preg_match_all('#/Type\s*/Page(?![sA-Za-z])#', $pdf));
    }

    public function html(ActaAdministrativa $acta, MotorPdf $motor = MotorPdf::Browsershot, bool $borrador = false, ?int $paginas = null): string
    {
        if (! self::esCircunstanciada($acta)) {
            return view('pdf.actas.acta', ['d' => $this->datos($acta), 'motor' => $motor->value])->render();
        }

        return view('pdf.actas.circunstanciada', [
            'd' => $this->datosCircunstanciada($acta, $paginas),
            'motor' => $motor->value,
            'borrador' => $borrador,
        ])->render();
    }

    /**
     * Datos del acta circunstanciada: lo de PEOPLE (empresa, centro de
     * trabajo, trabajador, jefe, folio, anexos) + lo capturado. Solo texto
     * para imprimir: ningún ID, rol técnico ni metadata.
     *
     * @return array<string, mixed>
     */
    public function datosCircunstanciada(ActaAdministrativa $acta, ?int $paginas = null): array
    {
        $acta->loadMissing(['colaborador.puesto.departamento', 'colaborador.departamento', 'colaborador.jefe', 'colaborador.sucursalPrincipal.empresa', 'anexos']);
        $c = $acta->circunstanciada ?? [];
        $trabajador = $acta->colaborador;
        $sucursal = $trabajador->sucursalPrincipal;
        $empresa = $sucursal?->empresa;
        $fecha = fn (?string $f): ?string => $f !== null && $f !== '' ? Carbon::parse($f)->settings(['locale' => 'es'])->isoFormat('D [de] MMMM [de] YYYY') : null;
        // Domicilio real de la sucursal, sin repetir lo que la dirección ya trae.
        $direccion = trim((string) $sucursal?->direccion);
        $domicilio = collect([$direccion, $sucursal?->colonia, $sucursal->municipio ?? $sucursal?->ciudad, $sucursal?->estado, $sucursal?->codigo_postal !== null ? 'C.P. '.$sucursal->codigo_postal : null])
            ->filter(fn ($v): bool => is_string($v) && trim($v) !== '')
            ->reject(fn (string $v, int $i): bool => $i > 0 && $direccion !== '' && mb_stripos($direccion, trim($v)) !== false)
            ->implode(', ');
        $tipo = TipoIncidenciaActa::tryFrom((string) ($c['incidencia']['tipo'] ?? ''));
        $responsable = (array) ($c['responsable'] ?? []);
        $jefe = ($c['jefe']['participa'] ?? false) ? (array) $c['jefe'] : null;
        // Responsable y jefe son la misma persona (mismo colaborador_id, nunca
        // por nombre: dos homónimos son personas distintas): una sola
        // manifestación y una sola firma.
        $jefeEsResponsable = $jefe !== null
            && is_numeric($jefe['colaborador_id'] ?? null)
            && is_numeric($responsable['colaborador_id'] ?? null)
            && (int) $jefe['colaborador_id'] === (int) $responsable['colaborador_id'];
        $anexos = $acta->anexos->values()->map(fn (ActaAnexo $a, int $i): array => [
            'numero' => $i + 1,
            'descripcion' => (string) ($a->descripcion ?: $a->original_name),
            'identificacion' => $a->fecha_identificacion,
            'relacion' => $a->relacion_hechos,
        ])->all();
        $cierre = (array) ($c['cierre'] ?? []);
        $control = (array) ($c['control'] ?? []);
        $texto = fn (mixed $v): ?string => is_string($v) && trim($v) !== '' ? $v : null;

        return [
            'folio' => (string) $acta->folio,
            'logo' => $this->logo($empresa?->logo_path),
            'empresa' => $empresa->razon_social ?? $empresa->nombre ?? 'MR. LANA',
            'centro' => $sucursal?->nombre,
            'domicilio' => $domicilio !== '' ? $domicilio : null,
            'lugar' => $texto($acta->lugar),
            'fecha_levantamiento' => $fecha($acta->fecha->toDateString()),
            'hora_levantamiento' => $acta->hora !== null && $acta->hora !== '' ? substr((string) $acta->hora, 0, 5) : null,
            'trabajador' => [
                'nombre' => $trabajador->nombreCompleto(),
                'numero_empleado' => $trabajador->numero_empleado,
                'puesto' => $trabajador->puesto?->nombre,
                'area' => $trabajador->departamento->nombre ?? $trabajador->puesto?->departamento?->nombre,
                'centro' => $sucursal?->nombre,
                'fecha_ingreso' => $fecha($trabajador->fecha_ingreso?->toDateString()),
                'jefe' => $trabajador->jefe?->nombreCompleto(),
            ],
            'disposiciones' => $texto($c['disposiciones'] ?? null),
            'incidencia' => [
                'tipo' => $tipo === TipoIncidenciaActa::Otra ? ($texto($c['incidencia']['otro'] ?? null) ?? $tipo->etiqueta()) : $tipo?->etiqueta(),
                'fecha' => $texto($c['incidencia']['fecha_hechos'] ?? null),
                'hora' => $texto($c['incidencia']['hora_hechos'] ?? null),
                'lugar' => $texto($c['incidencia']['lugar_hechos'] ?? null),
            ],
            'hechos' => trim((string) $acta->hechos),
            'responsable' => [
                'nombre' => $texto($responsable['nombre'] ?? null),
                'puesto' => $texto($responsable['puesto'] ?? null),
                'area' => $texto($responsable['area'] ?? null),
                'caracter' => CaracterLevantamientoActa::tryFrom((string) ($responsable['caracter'] ?? ''))?->etiqueta(),
                'manifestacion' => $texto($responsable['manifestacion'] ?? null),
            ],
            'jefe' => $jefe === null ? null : [
                'nombre' => $texto($jefe['nombre'] ?? null),
                'puesto' => $texto($jefe['puesto'] ?? null),
                'area' => $texto($jefe['area'] ?? null),
                'manifestacion' => $texto($jefe['manifestacion'] ?? null),
                'es_responsable' => $jefeEsResponsable,
            ],
            'testigos_hechos' => array_map(fn (array $t): array => [
                'nombre' => (string) ($t['nombre'] ?? ''),
                'puesto' => $texto($t['puesto'] ?? null),
                'area' => $texto($t['area'] ?? null),
                'relacion' => ($t['relacion'] ?? null) === RelacionTestigoActa::Otra->value
                    ? ($texto($t['relacion_otro'] ?? null) ?? RelacionTestigoActa::Otra->etiqueta())
                    : RelacionTestigoActa::tryFrom((string) ($t['relacion'] ?? ''))?->etiqueta(),
                'manifestacion' => $texto($t['manifestacion'] ?? null),
            ], array_values((array) ($c['testigos_hechos'] ?? []))),
            'testigos_asistencia' => array_map(fn (array $t): array => [
                'nombre' => (string) ($t['nombre'] ?? ''),
                'puesto' => $texto($t['puesto'] ?? null),
                'area' => $texto($t['area'] ?? null),
                'manifestacion' => $texto($t['manifestacion'] ?? null),
            ], array_values((array) ($c['testigos_asistencia'] ?? []))),
            'trabajador_manifesto' => (bool) ($c['trabajador']['manifesto'] ?? false),
            'trabajador_manifestacion' => $texto($c['trabajador']['manifestacion'] ?? null),
            'trabajador_elementos' => $texto($c['trabajador']['elementos'] ?? null),
            'anexos' => $anexos,
            'incidencias' => $texto($c['incidencias_levantamiento'] ?? null),
            'negativa_firma' => $acta->negativa_firma ? ($texto($acta->motivo_negativa) ?? 'Se registró la negativa a firmar.') : null,
            'cierre' => [
                'fecha' => $fecha($cierre['fecha'] ?? null) ?? $fecha(Carbon::now('America/Mexico_City')->toDateString()),
                'hora' => $cierre['hora'] ?? Carbon::now('America/Mexico_City')->format('H:i'),
            ],
            'entrega' => (array) ($c['entrega'] ?? ['estado' => 'pendiente']),
            'control' => [
                'hojas' => $paginas,
                'anexos' => count($anexos),
                'fecha_integracion' => $fecha($control['fecha_integracion'] ?? null),
                'integrado_por' => $texto($control['integrado_por'] ?? null),
            ],
        ];
    }

    /**
     * Datos del formato anterior (actas históricas sin formato circunstanciado).
     *
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
