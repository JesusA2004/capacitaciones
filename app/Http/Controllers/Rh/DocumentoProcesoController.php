<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Http\Requests\CicloLaboral\GenerarDocumentoProcesoRequest;
use App\Http\Requests\CicloLaboral\ProcedimientoBajaRequest;
use App\Models\CierreLaboral;
use App\Models\Colaborador;
use App\Models\GeneratedDocument;
use App\Services\CierreLaboral\ProcedimientoBajaService;
use App\Services\DocumentosLaborales\FlujoDocumentalService;
use App\Services\DocumentosLaborales\MotorDocumentalService;
use App\Services\DocumentosMaestros\DocumentoProcesoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Documentos del proceso" (JSON): lo consume el componente web
 * DocumentosProceso.vue y, por herencia, la API móvil
 * (Api\V1\Rh\DocumentoProcesoController). Toda decisión —qué documento
 * toca, si se puede generar, qué paso físico sigue— la toma
 * DocumentoProcesoService; aquí solo se autoriza y se responde.
 *
 * El registro del proceso se resuelve SIEMPRE desde la URL
 * ({tipo}/{id}); nunca se confía en ids que vengan en el cuerpo.
 */
class DocumentoProcesoController extends Controller
{
    public function __construct(
        protected readonly DocumentoProcesoService $documentos,
        protected readonly FlujoDocumentalService $flujo,
        protected readonly ProcedimientoBajaService $procedimiento,
        protected readonly MotorDocumentalService $motor,
    ) {}

    /**
     * Secciones documentales de la ficha del colaborador (contratación y renovación).
     */
    public function colaborador(Request $request, Colaborador $colaborador): JsonResponse
    {
        return response()->json(['data' => $this->documentos->seccionesColaborador($colaborador, $request->user())]);
    }

    public function show(Request $request, string $tipo, int $id): JsonResponse
    {
        $registro = $this->documentos->registro($tipo, $id);

        return response()->json(['data' => $this->documentos->seccion((string) $request->query('proceso', ''), $registro, $request->user())]);
    }

    public function generar(GenerarDocumentoProcesoRequest $request, string $tipo, int $id): JsonResponse
    {
        $registro = $this->documentos->registro($tipo, $id);
        $proceso = (string) ($request->validated('proceso') ?? $this->procesoPorDefecto($tipo));
        $documento = $this->documentos->generar(
            $proceso,
            $registro,
            (string) $request->validated('clave'),
            $request->user(),
            $request->completar(),
            $request->manuales(),
            (bool) $request->validated('regenerar', false),
            (bool) $request->validated('revision', false),
            $request->validated('motivo') !== null ? (string) $request->validated('motivo') : null,
        );

        return response()->json([
            'message' => 'Documento generado.',
            'documento_id' => $documento->id,
            'data' => $this->documentos->seccion($proceso, $registro->refresh(), $request->user()),
        ], 201);
    }

    public function paquete(GenerarDocumentoProcesoRequest $request, string $tipo, int $id): JsonResponse
    {
        $registro = $this->documentos->registro($tipo, $id);
        $proceso = (string) ($request->validated('proceso') ?? $this->procesoPorDefecto($tipo));
        $generados = $this->documentos->generarPaquete($proceso, $registro, $request->user(), $request->completar());

        return response()->json([
            'message' => sprintf('%d documento(s) generado(s).', count($generados)),
            'documentos' => array_map(fn (GeneratedDocument $d): int => $d->id, $generados),
            'data' => $this->documentos->seccion($proceso, $registro->refresh(), $request->user()),
        ], 201);
    }

    /**
     * Word llenado del documento (misma estructura del original de
     * Jurídico). Solo quien opera el documento: GeneratedDocumentPolicy.
     */
    public function word(GeneratedDocument $documento): StreamedResponse
    {
        $this->authorize('descargarWord', $documento);

        return $this->motor->respuestaDocx($documento);
    }

    /**
     * Paso del flujo físico de un documento (impreso, firma, envío,
     * recepción, escaneo, archivo) con respuesta JSON para la tarjeta.
     */
    public function operar(Request $request, GeneratedDocument $documento, string $accion): JsonResponse
    {
        $this->authorize('operar', $documento);
        $datos = $request->validate([
            'observaciones' => ['nullable', 'string', 'max:1000'],
            'fecha' => ['nullable', 'date', 'before_or_equal:now'],
            'huella_registrada' => ['sometimes', 'boolean'],
            'testigos' => ['nullable', 'array', 'max:5'],
            'testigos.*.nombre' => ['required_with:testigos', 'string', 'max:160'],
            'testigos.*.puesto' => ['nullable', 'string', 'max:120'],
            'paqueteria' => [$accion === 'envio' ? 'required' : 'nullable', 'string', 'max:80'],
            'numero_guia' => [$accion === 'envio' ? 'required' : 'nullable', 'string', 'max:80'],
            'archivo' => [$accion === 'escaneo' ? 'required' : 'nullable', 'file', 'max:'.((int) config('contratos.max_upload_mb', 20) * 1024), 'mimes:'.implode(',', (array) config('contratos.extensiones_permitidas', ['pdf']))],
            'comprobante' => ['nullable', 'file', 'max:'.((int) config('contratos.max_upload_mb', 20) * 1024), 'mimes:'.implode(',', (array) config('contratos.extensiones_permitidas', ['pdf']))],
        ]);
        $actor = $request->user();
        $observaciones = isset($datos['observaciones']) ? (string) $datos['observaciones'] : null;
        $fecha = isset($datos['fecha']) ? (string) $datos['fecha'] : null;

        match ($accion) {
            'imprimir' => $this->flujo->marcarImpreso($documento, $actor, $observaciones),
            'firma-fisica' => $this->flujo->registrarFirmaFisica($documento, $actor, [
                'huella_registrada' => (bool) ($datos['huella_registrada'] ?? false),
                'testigos' => $datos['testigos'] ?? null,
                'observaciones' => $observaciones,
                'fecha' => $fecha,
            ]),
            'envio' => $this->flujo->registrarEnvio($documento, $actor, [
                'paqueteria' => (string) $datos['paqueteria'],
                'numero_guia' => (string) $datos['numero_guia'],
                'observaciones' => $observaciones,
                'fecha' => $fecha,
            ], $request->file('comprobante') instanceof UploadedFile ? $request->file('comprobante') : null),
            'recepcion' => $this->flujo->registrarRecepcion($documento, $actor, $observaciones, $fecha),
            'escaneo' => $this->flujo->registrarEscaneo($documento, $actor, $this->archivo($request, 'archivo'), $observaciones),
            'archivar' => $this->flujo->archivar($documento, $actor, $observaciones),
            default => abort(404),
        };

        return response()->json(['message' => 'Listo.', 'documento_id' => $documento->id, 'estado' => $documento->refresh()->estado_flujo?->value]);
    }

    public function negativa(ProcedimientoBajaRequest $request, CierreLaboral $cierre): JsonResponse
    {
        $this->procedimiento->registrarNegativa($cierre, [
            'documentos' => array_values(array_map('strval', (array) $request->validated('documentos', []))),
            'observaciones' => $request->validated('observaciones'),
            'finiquito_a_disposicion' => (bool) $request->validated('finiquito_a_disposicion', true),
            'testigos' => $this->testigosDe($request),
            'participantes' => $this->participantesDe($request),
        ], $request->user());

        return response()->json(['message' => 'Negativa registrada. Ya puedes generar el acta.', 'data' => $this->documentos->seccionCierre($cierre->refresh(), $request->user())]);
    }

    public function testigos(ProcedimientoBajaRequest $request, CierreLaboral $cierre): JsonResponse
    {
        $this->procedimiento->capturarTestigos($cierre, $this->testigosDe($request), $this->participantesDe($request), $request->user());

        return response()->json(['message' => 'Testigos guardados.', 'data' => $this->documentos->seccionCierre($cierre->refresh(), $request->user())]);
    }

    public function etapa(ProcedimientoBajaRequest $request, CierreLaboral $cierre): JsonResponse
    {
        $evidencias = array_values(array_filter((array) $request->file('evidencias', []), fn (mixed $f): bool => $f instanceof UploadedFile));
        $this->procedimiento->registrarEtapa(
            $cierre,
            (string) $request->validated('etapa'),
            $request->user(),
            $evidencias,
            array_values(array_map('strval', (array) $request->validated('medios', []))),
            $request->validated('observaciones'),
        );

        return response()->json(['message' => 'Etapa registrada.', 'data' => $this->documentos->seccionCierre($cierre->refresh(), $request->user())]);
    }

    /**
     * @return list<array{nombre: string, cargo: string}>
     */
    private function testigosDe(ProcedimientoBajaRequest $request): array
    {
        $testigos = [];

        foreach ((array) $request->validated('testigos', []) as $testigo) {
            if (is_array($testigo)) {
                $testigos[] = ['nombre' => (string) ($testigo['nombre'] ?? ''), 'cargo' => (string) ($testigo['cargo'] ?? '')];
            }
        }

        return $testigos;
    }

    /**
     * @return array<string, string>
     */
    private function participantesDe(ProcedimientoBajaRequest $request): array
    {
        $participantes = [];

        foreach ((array) $request->validated('participantes', []) as $clave => $valor) {
            if (is_string($valor)) {
                $participantes[(string) $clave] = $valor;
            }
        }

        return $participantes;
    }

    private function archivo(Request $request, string $campo): UploadedFile
    {
        $archivo = $request->file($campo);
        abort_unless($archivo instanceof UploadedFile, 422, 'Adjunta el archivo.');

        return $archivo;
    }

    private function procesoPorDefecto(string $tipo): string
    {
        return match ($tipo) {
            'cierre' => 'baja',
            'solicitud' => 'permiso',
            'prestamo' => 'prestamo',
            'evaluacion' => 'evaluacion',
            'entrega_activo' => 'activos',
            default => 'alta',
        };
    }
}
