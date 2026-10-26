<?php

namespace App\Services\Onboarding;

use App\Enums\CategoriaDocumento;
use App\Enums\EstadoFlujoDocumento;
use App\Enums\MotorPdf;
use App\Models\EvaluacionInduccionIntento;
use App\Models\GeneratedDocument;
use App\Models\User;
use App\Services\Actas\ActaPdfService;
use App\Services\DocumentosLaborales\FlujoDocumentalService;
use App\Services\DocumentosLaborales\MotorDocumentalService;
use App\Services\DocumentosMaestros\FirmaPatronService;
use App\Services\Evaluaciones\FormularioEvaluacionService;
use App\Services\Pdf\OpcionesPdf;
use App\Services\Pdf\PdfRendererFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * PDF de la evaluación oficial del Programa de Inducción (FO-RH-EI, 30
 * puntos — docs/onboarding/Evaluacion_Induccion_Mr_Lana 2026.pdf) con lo
 * que contestó el colaborador, la calificación de cada sección, la rúbrica
 * de los casos prácticos, el comentario de RH y el resultado.
 *
 * Mismo motor que las actas (PdfRendererFactory, Browsershot A4 numerado;
 * en pruebas DomPDF por PDF_RENDERER). Lleva la CLAVE DE RESPUESTAS: es un
 * documento SOLO de RH — el colaborador nunca lo ve ni lo descarga (ver
 * EvaluacionInduccionPolicy::calificar() en los controladores y
 * GeneratedDocumentPolicy/DocumentoLaboralConsultaService para la copia
 * guardada en el expediente).
 */
class EvaluacionInduccionPdfService
{
    /** clave_plantilla del documento guardado en el expediente. */
    public const CLAVE = 'FO-RH-EI';

    public const PROCESO = 'induccion';

    public function __construct(
        private readonly PdfRendererFactory $pdf,
        private readonly MotorDocumentalService $motor,
        private readonly EvaluacionInduccionService $evaluaciones,
        private readonly FirmaPatronService $firmaPatron,
        private readonly FormularioEvaluacionService $formularios,
        private readonly FlujoDocumentalService $flujo,
    ) {}

    /** Bytes del PDF (mismo render para vista previa, descarga y expediente). */
    public function renderizar(EvaluacionInduccionIntento $intento): string
    {
        return $this->render($intento)['pdf'];
    }

    /** PDF real en el navegador; no guarda nada. */
    public function vistaPrevia(EvaluacionInduccionIntento $intento): Response
    {
        return $this->respuesta($intento, 'inline');
    }

    public function descargar(EvaluacionInduccionIntento $intento): Response
    {
        return $this->respuesta($intento, 'attachment');
    }

    /**
     * Guarda la versión final (intento ya calificado) en el expediente del
     * colaborador, carpeta «Otros», con el mismo motor documental que las
     * actas. Idempotente: si ese intento ya tiene su PDF vigente en el
     * expediente, devuelve ese documento sin duplicarlo.
     */
    public function guardarEnExpediente(EvaluacionInduccionIntento $intento, User $actor, bool $nuevaVersion = false): GeneratedDocument
    {
        $intento->loadMissing('evaluacion.colaborador');

        if (! Gate::forUser($actor)->allows('calificar', $intento->evaluacion)) {
            throw new AuthorizationException('No tienes permiso para guardar esta evaluación en el expediente.');
        }

        if ($intento->pendienteCalificacionRh()) {
            throw ValidationException::withMessages(['evaluacion' => 'Califica los casos prácticos antes de guardar la evaluación en el expediente.']);
        }

        if (! $nuevaVersion && ($existente = $this->documentoDe($intento)) !== null) {
            return $existente;
        }

        $render = $this->render($intento);

        return DB::transaction(function () use ($intento, $actor, $render, $nuevaVersion): GeneratedDocument {
            // Serializa dos guardados simultáneos del mismo intento.
            EvaluacionInduccionIntento::query()->lockForUpdate()->findOrFail($intento->id);

            if (! $nuevaVersion && ($existente = $this->documentoDe($intento)) !== null) {
                return $existente;
            }

            $titulo = sprintf('Evaluación de inducción · intento %d', $intento->numero);

            return $this->motor->registrarPdf($intento->evaluacion->colaborador, $render['pdf'], $titulo, $actor, [
                'clave' => self::CLAVE,
                'categoria' => CategoriaDocumento::Otros,
                'documentable' => $intento,
                'proceso' => self::PROCESO,
                // Formato con firmas del colaborador, RH y jefe inmediato.
                'requiere_impresion' => true,
                'requiere_firma_fisica' => true,
                'conversion_engine' => $render['motor']->value,
                'paginas' => $render['paginas'],
                'nombre_archivo' => sprintf('%s.pdf', $this->nombreBase($intento)),
            ]);
        });
    }

    /**
     * Auto-adjunto al aprobar: nunca deshace la calificación si el guardado
     * falla (NAS caído, Chrome no disponible…); queda en el log y RH puede
     * guardarlo después a mano.
     */
    public function adjuntarSiAprobada(EvaluacionInduccionIntento $intento, User $actor): ?GeneratedDocument
    {
        if ($intento->pendienteCalificacionRh() || $intento->aprobado !== true) {
            return null;
        }

        try {
            return $this->guardarEnExpediente($intento, $actor);
        } catch (Throwable $e) {
            Log::warning('EvaluacionInduccionPdfService: no se pudo adjuntar la evaluación de inducción al expediente.', [
                'intento_id' => $intento->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * «Calificar de nuevo»: el formato del expediente refleja la calificación
     * corregida. El PDF anterior de este intento se cancela (si aún no se
     * firmó en papel; si ya se firmó se conserva como historial) y se guarda
     * el nuevo. Sin PDF previo, solo se guarda si con la corrección aprobó
     * (la vista previa y la descarga siempre salen al momento). Nunca
     * deshace la recalificación: un fallo queda en el log.
     */
    public function regenerarTrasRecalificar(EvaluacionInduccionIntento $intento, User $actor): ?GeneratedDocument
    {
        try {
            $anterior = $this->documentoDe($intento);

            if ($anterior === null && $intento->aprobado !== true) {
                return null;
            }

            if ($anterior !== null && ! ($anterior->estado_flujo?->estaConcluido() ?? false)) {
                $this->flujo->cancelar($anterior, $actor, 'Evaluación calificada de nuevo: se generó el formato corregido.');
            }

            return $this->guardarEnExpediente($intento, $actor, true);
        } catch (Throwable $e) {
            Log::warning('EvaluacionInduccionPdfService: no se pudo regenerar el formato tras calificar de nuevo.', [
                'intento_id' => $intento->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** PDF vigente (no cancelado) de este intento en el expediente, si ya existe. */
    public function documentoDe(EvaluacionInduccionIntento $intento): ?GeneratedDocument
    {
        return GeneratedDocument::query()
            ->where('documentable_type', $intento->getMorphClass())
            ->where('documentable_id', $intento->id)
            ->where('clave_plantilla', self::CLAVE)
            ->where(fn (Builder $q) => $q->whereNull('estado_flujo')->orWhere('estado_flujo', '!=', EstadoFlujoDocumento::Cancelado->value))
            ->orderByDesc('id')
            ->first();
    }

    public function html(EvaluacionInduccionIntento $intento, MotorPdf $motor = MotorPdf::Browsershot): string
    {
        return view('pdf.induccion.evaluacion', ['d' => $this->datos($intento), 'motor' => $motor->value])->render();
    }

    /**
     * Datos para imprimir: lo de PEOPLE (colaborador, puesto, sucursal,
     * ingreso, quién aplicó) + la revisión del intento. Ningún ID ni
     * metadata técnica.
     *
     * @return array<string, mixed>
     */
    public function datos(EvaluacionInduccionIntento $intento): array
    {
        $intento->loadMissing('evaluacion.colaborador.sucursalPrincipal.empresa');
        $evaluacion = $intento->evaluacion;
        $colaborador = $this->evaluaciones->aArray($evaluacion)['colaborador'];
        $empresa = $evaluacion->colaborador->sucursalPrincipal?->empresa;
        $revision = $this->evaluaciones->detalleRevision($intento);
        $fecha = fn (?string $f): ?string => $f !== null && $f !== '' ? Carbon::parse($f)->settings(['locale' => 'es'])->isoFormat('D [de] MMMM [de] YYYY') : null;
        // Versión del formulario con la que se CONTESTÓ (no la vigente).
        $definicion = $this->formularios->definicionInduccion($evaluacion);
        $seccionD = (array) $definicion['seccion_d'];

        return [
            'clave' => self::CLAVE,
            'logo' => $this->logo($empresa?->logo_path),
            'empresa' => $this->firmaPatron->razonSocialDeEmpresa($empresa),
            'colaborador' => [
                'nombre' => $colaborador['nombre'],
                'numero_empleado' => $colaborador['numero_empleado'],
                'puesto' => $colaborador['puesto'],
                'sucursal' => $colaborador['sucursal'],
                'fecha_ingreso' => $fecha($colaborador['fecha_ingreso']),
                'jefe' => $colaborador['jefe_inmediato'],
            ],
            'fecha_evaluacion' => $fecha($intento->presentado_en->toDateString()),
            'intento' => $intento->numero,
            // Aplicó: el área, nunca la cuenta que calificó (su nombre y firma van a pluma).
            'aplico' => $intento->calificado_por !== null ? 'Recursos Humanos' : null,
            'maximos' => [
                'a' => (int) collect((array) $definicion['seccion_a'])->sum('puntos'),
                'b' => (int) collect((array) $definicion['seccion_b'])->sum('puntos'),
                'c' => (int) collect((array) $definicion['seccion_c']['conceptos'])->sum('puntos'),
                'd' => (int) collect($seccionD)->sum('puntos_max'),
                'total' => (int) $definicion['total_puntos'],
            ],
            'aprobatoria' => $definicion['calificacion_aprobatoria'],
            'dias_reevaluacion' => (int) $definicion['dias_habiles_reevaluacion'],
            'opinion_aspectos' => (array) $definicion['seccion_e']['aspectos'],
            'opinion_pregunta' => (string) $definicion['seccion_e']['pregunta_abierta']['texto'],
            'escala_min' => (int) $definicion['seccion_e']['escala_min'],
            'escala_max' => (int) $definicion['seccion_e']['escala_max'],
            'r' => $revision,
        ];
    }

    /**
     * @return array{pdf: string, motor: MotorPdf, respaldo: bool, paginas: int}
     */
    private function render(EvaluacionInduccionIntento $intento): array
    {
        $motor = MotorPdf::tryFrom((string) config('pdf.renderer', 'browsershot')) ?? MotorPdf::Browsershot;
        $resultado = $this->pdf->renderizarCon(
            $motor,
            fn (MotorPdf $m): string => $this->html($intento, $m),
            fn (MotorPdf $m): OpcionesPdf => new OpcionesPdf(
                tamano: 'a4',
                numerarPaginas: true,
                textoPie: 'Evaluación del Programa de Inducción · Mr. Lana',
                margenInferiorMm: 18.0,
            ),
        );

        return [...$resultado, 'paginas' => ActaPdfService::contarPaginas($resultado['pdf'])];
    }

    private function respuesta(EvaluacionInduccionIntento $intento, string $disposicion): Response
    {
        return response($this->renderizar($intento), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('%s; filename="%s.pdf"', $disposicion, $this->nombreBase($intento)),
            'Cache-Control' => 'no-store',
        ]);
    }

    private function nombreBase(EvaluacionInduccionIntento $intento): string
    {
        $intento->loadMissing('evaluacion.colaborador');
        $nombre = (string) preg_replace('/[^\pL\pN ]+/u', '', $intento->evaluacion->colaborador->nombreCompleto());

        return sprintf('Evaluacion de induccion - %s - intento %d', trim($nombre), $intento->numero);
    }

    /** Logo como data URI (igual que las actas: sin depender de la red). */
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
