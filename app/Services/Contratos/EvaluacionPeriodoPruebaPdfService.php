<?php

namespace App\Services\Contratos;

use App\Enums\MotorPdf;
use App\Models\EvaluacionPeriodoPrueba;
use App\Services\DocumentosMaestros\FirmaPatronService;
use App\Services\Pdf\LogoPdfService;
use App\Services\Pdf\OpcionesPdf;
use App\Services\Pdf\PdfRendererFactory;
use Carbon\CarbonInterface;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * PDF para firma de la Evaluación de Término de Periodo de Prueba ya
 * contestada (formato oficial FO-RH-EPP-001 · Personal Operativo, versión 02
 * — docs/onboarding/Evaluacion_Periodo_de_Prueba_Operativo.pdf): datos del
 * colaborador, Paso 1 (calificaciones 4-3-2-1 por criterio), Paso 2
 * (preguntas filtro Sí/No), Paso 3 (total y resultado con sus motivos),
 * comentarios del jefe inmediato y las tres firmas del formato: EVALUÓ (jefe
 * inmediato), VALIDÓ (Gerencia de Recursos Humanos) y ENTERADO
 * (colaborador evaluado).
 *
 * Las respuestas salen de lo GUARDADO en la evaluación
 * (EvaluacionPeriodoPruebaService::respuestas), nunca del catálogo vigente.
 * Nunca imprime el nombre de la cuenta que capturó: cada firma va a pluma
 * con su nombre impreso solo cuando es una persona del organigrama
 * (evaluado y jefe inmediato). No guarda nada: vista previa y descarga se
 * renderizan al momento con el mismo motor que el resto de los formatos.
 */
class EvaluacionPeriodoPruebaPdfService
{
    public const CLAVE = 'FO-RH-EPP-001';

    public function __construct(
        private readonly PdfRendererFactory $pdf,
        private readonly EvaluacionPeriodoPruebaService $evaluaciones,
        private readonly FirmaPatronService $firmaPatron,
        private readonly LogoPdfService $logos,
    ) {}

    /** PDF en el navegador (vista previa dentro de PEOPLE); no guarda nada. */
    public function vistaPrevia(EvaluacionPeriodoPrueba $evaluacion): Response
    {
        return $this->respuesta($evaluacion, 'inline');
    }

    public function descargar(EvaluacionPeriodoPrueba $evaluacion): Response
    {
        return $this->respuesta($evaluacion, 'attachment');
    }

    public function renderizar(EvaluacionPeriodoPrueba $evaluacion): string
    {
        if (! $this->evaluaciones->tieneRespuestas($evaluacion)) {
            throw ValidationException::withMessages(['evaluacion' => 'El colaborador todavía no contesta su evaluación: no hay respuestas que imprimir.']);
        }

        $motor = MotorPdf::tryFrom((string) config('pdf.renderer', 'browsershot')) ?? MotorPdf::Browsershot;

        return $this->pdf->renderizarCon(
            $motor,
            fn (MotorPdf $m): string => $this->html($evaluacion, $m),
            fn (MotorPdf $m): OpcionesPdf => new OpcionesPdf(
                tamano: 'letter',
                numerarPaginas: true,
                textoPie: sprintf('%s · Evaluación de término de periodo de prueba', self::CLAVE),
                margenInferiorMm: 16.0,
            ),
        )['pdf'];
    }

    public function html(EvaluacionPeriodoPrueba $evaluacion, MotorPdf $motor = MotorPdf::Browsershot): string
    {
        return view('pdf.contratos.evaluacion-periodo-prueba', ['d' => $this->datos($evaluacion), 'motor' => $motor->value])->render();
    }

    /**
     * @return array<string, mixed>
     */
    public function datos(EvaluacionPeriodoPrueba $evaluacion): array
    {
        $evaluacion->loadMissing(['colaborador.puesto', 'colaborador.sucursalPrincipal.empresa', 'colaborador.jefe', 'contrato', 'evaluador', 'capturadaPor']);
        $colaborador = $evaluacion->colaborador;
        $empresa = $colaborador->sucursalPrincipal?->empresa;
        $fecha = fn (?CarbonInterface $f): ?string => $f?->copy()->settings(['locale' => 'es'])->isoFormat('D [de] MMMM [de] YYYY');

        return [
            'clave' => self::CLAVE,
            'logo' => $this->logos->dataUri($empresa?->logo_path),
            'empresa' => $this->firmaPatron->razonSocialDeEmpresa($empresa),
            'colaborador' => [
                'nombre' => $colaborador->nombreCompleto(),
                'numero_empleado' => $colaborador->numero_empleado,
                'puesto' => $colaborador->puesto?->nombre,
                'sucursal' => $colaborador->sucursalPrincipal?->nombre,
                'fecha_ingreso' => $fecha($colaborador->fecha_ingreso),
            ],
            // Jefe inmediato del organigrama (firma ENTERADO). En evaluaciones del
            // flujo anterior, el jefe que quedó como evaluador.
            'jefe' => ($colaborador->jefe ?? ($evaluacion->evaluador_colaborador_id !== $colaborador->id ? $evaluacion->evaluador : null))?->nombreCompleto(),
            // Autoevaluación (contestada por el propio colaborador) o captura del flujo anterior (jefe).
            'autoevaluacion' => $evaluacion->capturadaPor !== null && $evaluacion->capturadaPor->colaborador_id === $colaborador->id,
            'termino_contrato' => $fecha($evaluacion->contrato->fecha_fin ?? $evaluacion->fecha_limite),
            'fecha_evaluacion' => $fecha($evaluacion->fecha_evaluacion),
            'r' => $this->evaluaciones->respuestas($evaluacion),
        ];
    }

    private function respuesta(EvaluacionPeriodoPrueba $evaluacion, string $disposicion): Response
    {
        return response($this->renderizar($evaluacion), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('%s; filename="%s.pdf"', $disposicion, $this->nombreBase($evaluacion)),
            'Cache-Control' => 'no-store',
        ]);
    }

    private function nombreBase(EvaluacionPeriodoPrueba $evaluacion): string
    {
        $evaluacion->loadMissing('colaborador');
        $nombre = (string) preg_replace('/[^\pL\pN ]+/u', '', $evaluacion->colaborador->nombreCompleto());

        return sprintf('Evaluacion periodo de prueba - %s', trim($nombre));
    }
}
