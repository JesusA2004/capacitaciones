<?php

namespace App\Services\DocumentosLaborales;

use App\Enums\CategoriaDocumento;
use App\Enums\EstadoDocumentoGenerado;
use App\Enums\EstadoFlujoDocumento;
use App\Enums\MotorPlantilla;
use App\Models\Colaborador;
use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Expedientes\DocumentoStorageService;
use App\Services\Formatos\FormatoPreviewService;
use App\Services\Formatos\OfficialFormatOverlayService;
use App\Services\Plantillas\PlaceholderResolver;
use App\Services\Plantillas\PlantillaDocumentoService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Motor general de documentos laborales. Un solo camino para TODOS los
 * formatos (contratos, pagaré, finiquito, actas, comprobantes, recibos...):
 *
 *   plantilla (DocumentTemplate: clave + versión + motor + banderas)
 *   + variables del colaborador (PlaceholderResolver) + extra del contexto
 *   → PDF (HTML→DomPDF, DOCX→PhpWord→PDF, o overlay sobre formato oficial)
 *   → archivo en el expediente del colaborador (carpeta de su categoría en el NAS)
 *   → GeneratedDocument con SNAPSHOT del payload usado + checksum + flujo.
 *
 * El snapshot es lo que hace que un contrato histórico nunca cambie: si
 * después cambia el sueldo del colaborador, el documento emitido conserva
 * el payload y el PDF con el que se generó.
 *
 * El sistema nunca redacta cláusulas: el texto lo aporta RH/Jurídico en la
 * plantilla. Si no hay plantilla activa para una clave, se lanza una
 * ValidationException explícita (no se inventa un formato).
 */
class MotorDocumentalService
{
    public function __construct(
        private readonly PlaceholderResolver $resolver,
        private readonly PlantillaDocumentoService $docx,
        private readonly FormatoPreviewService $convertidor,
        private readonly OfficialFormatOverlayService $overlay,
        private readonly DocumentoStorageService $expediente,
        private readonly FlujoDocumentalService $flujo,
        private readonly AuditoriaService $auditoria,
    ) {}

    /**
     * Versión activa más reciente de una plantilla por clave.
     */
    public function plantillaActiva(string $clave): ?DocumentTemplate
    {
        return DocumentTemplate::query()
            ->where('clave', $clave)
            ->where('activo', true)
            ->orderByDesc('version')
            ->first();
    }

    public function tienePlantillaActiva(string $clave): bool
    {
        return $this->plantillaActiva($clave) !== null;
    }

    /**
     * Catálogo de variables disponibles para las plantillas ({{variable}}).
     *
     * @return list<string>
     */
    public function variablesDisponibles(): array
    {
        return array_values(array_unique([
            ...array_keys($this->resolver->resolver(null)),
            'referencia_documento',
        ]));
    }

    /**
     * Genera y persiste un documento laboral para el colaborador.
     *
     * @param  array<string, mixed>  $extra  Variables del contexto (fechas del contrato, montos del préstamo, datos del acta...).
     *
     * @throws ValidationException Si no hay plantilla activa o el render/almacenamiento falla.
     */
    public function generar(
        Colaborador $colaborador,
        DocumentTemplate|string $plantilla,
        User $actor,
        array $extra = [],
        ?Model $documentable = null,
        ?string $titulo = null,
    ): GeneratedDocument {
        $plantilla = $this->resolverPlantilla($plantilla);

        $colaborador->loadMissing(['sucursalPrincipal.empresa', 'puesto', 'departamento', 'jefe.jefe', 'gerente', 'user']);

        $referencia = strtoupper(Str::random(10));
        $payload = $this->resolver->resolver($colaborador, [...$extra, 'referencia_documento' => $referencia]);
        $titulo ??= $plantilla->nombre;

        $pdf = $this->renderizar($plantilla, $payload, $titulo, $referencia);

        return $this->registrarPdf($colaborador, $pdf, $titulo, $actor, [
            'plantilla' => $plantilla,
            'clave' => $plantilla->clave,
            'version' => $plantilla->version,
            'categoria' => $this->categoriaDe($plantilla),
            'payload' => $payload,
            'documentable' => $documentable,
            'requiere_firma_digital' => $plantilla->requiere_firma_digital,
            'requiere_impresion' => $plantilla->requiere_impresion,
            'requiere_firma_fisica' => $plantilla->requiere_firma_fisica,
            'requiere_huella' => $plantilla->requiere_huella,
            'requiere_testigos' => $plantilla->requiere_testigos,
        ]);
    }

    /**
     * Persiste un PDF ya renderizado como documento laboral del colaborador:
     * archivo en la carpeta de su categoría en el expediente (NAS) +
     * GeneratedDocument con snapshot, checksum y flujo documental. Lo usan
     * generar() y los módulos cuyo PDF se arma con una vista propia no
     * jurídica (recibo interno de nómina, comprobantes, respaldo de finiquito).
     *
     * @param  array{plantilla?: DocumentTemplate|null, clave?: string|null, version?: int|null, categoria: CategoriaDocumento, payload?: array<string, string>, documentable?: Model|null, requiere_firma_digital?: bool, requiere_impresion?: bool, requiere_firma_fisica?: bool, requiere_huella?: bool, requiere_testigos?: bool}  $opciones
     */
    public function registrarPdf(Colaborador $colaborador, string $pdf, string $titulo, User $actor, array $opciones): GeneratedDocument
    {
        $colaborador->loadMissing(['sucursalPrincipal.empresa', 'user']);
        $plantilla = $opciones['plantilla'] ?? null;
        $documentable = $opciones['documentable'] ?? null;
        $categoria = $opciones['categoria'];
        $payload = $opciones['payload'] ?? [];
        $nombreArchivo = sprintf('%s - %s.pdf', $titulo, now()->format('Y-m-d His'));

        try {
            $ruta = $this->expediente->guardarContenidoEnExpediente($colaborador, $categoria, $nombreArchivo, $pdf);
        } catch (Throwable $e) {
            Log::error('MotorDocumentalService: no se pudo guardar el PDF en el almacenamiento.', [
                'colaborador_id' => $colaborador->id,
                'clave' => $opciones['clave'] ?? null,
                'error' => $e->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'documento' => 'No se pudo guardar el documento en el almacenamiento (NAS no disponible). Intenta de nuevo; si continúa, avisa a sistemas.',
            ]);
        }

        try {
            $documento = DB::transaction(function () use ($colaborador, $plantilla, $actor, $documentable, $titulo, $payload, $pdf, $categoria, $ruta, $nombreArchivo, $opciones): GeneratedDocument {
                $documento = GeneratedDocument::query()->create([
                    'document_template_id' => $plantilla?->id,
                    'user_id' => $colaborador->user?->id,
                    'colaborador_id' => $colaborador->id,
                    'empresa_id' => $colaborador->sucursalPrincipal?->empresa_id,
                    'sucursal_id' => $colaborador->sucursal_principal_id,
                    'disk' => config('expedientes.disk'),
                    'path' => $ruta,
                    'original_name' => $nombreArchivo,
                    'generated_name' => basename($ruta),
                    'mime' => 'application/pdf',
                    'size' => strlen($pdf),
                    'status' => EstadoDocumentoGenerado::Generado,
                    'generated_by' => $actor->id,
                    'documentable_type' => $documentable?->getMorphClass(),
                    'documentable_id' => $documentable?->getKey(),
                    'clave_plantilla' => $opciones['clave'] ?? null,
                    'version_plantilla' => $opciones['version'] ?? null,
                    'categoria' => $categoria,
                    'titulo' => $titulo,
                    'payload' => $payload,
                    'checksum' => hash('sha256', $pdf),
                    'estado_flujo' => EstadoFlujoDocumento::Generado,
                    'requiere_firma_digital' => $opciones['requiere_firma_digital'] ?? false,
                    'requiere_impresion' => $opciones['requiere_impresion'] ?? false,
                    'requiere_firma_fisica' => $opciones['requiere_firma_fisica'] ?? false,
                    'requiere_huella' => $opciones['requiere_huella'] ?? false,
                    'requiere_testigos' => $opciones['requiere_testigos'] ?? false,
                ]);

                $this->flujo->iniciar($documento, $actor);

                return $documento;
            });
        } catch (Throwable $e) {
            // La fila no llegó a existir: el PDF recién escrito no debe
            // quedar huérfano en el NAS.
            $this->expediente->eliminar($ruta);

            throw $e;
        }

        $this->auditoria->registrar('documento_generado', $documento, $actor, [
            'clave_plantilla' => $opciones['clave'] ?? null,
            'version_plantilla' => $opciones['version'] ?? null,
            'colaborador_id' => $colaborador->id,
            'checksum' => $documento->checksum,
        ]);

        return $documento->refresh();
    }

    /**
     * Vista previa sin persistir nada (mismo render que generar()).
     *
     * @param  array<string, mixed>  $extra
     */
    public function previsualizar(Colaborador $colaborador, DocumentTemplate|string $plantilla, array $extra = []): string
    {
        $plantilla = $this->resolverPlantilla($plantilla);
        $payload = $this->resolver->resolver($colaborador, [...$extra, 'referencia_documento' => 'VISTA-PREVIA']);

        return $this->renderizar($plantilla, $payload, $plantilla->nombre, 'VISTA-PREVIA');
    }

    /**
     * @param  array<string, string>  $payload
     *
     * @throws ValidationException Si la plantilla no puede renderizarse.
     */
    public function renderizar(DocumentTemplate $plantilla, array $payload, string $titulo, string $referencia): string
    {
        try {
            $pdf = match ($plantilla->motor) {
                MotorPlantilla::Html => $this->renderizarHtml($plantilla, $payload, $titulo, $referencia),
                MotorPlantilla::Docx => $this->convertidor->aPdf($this->docx->generarConValores($plantilla, $payload)),
                MotorPlantilla::PdfOverlay => $this->renderizarOverlay($plantilla, $payload),
            };
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::warning('MotorDocumentalService: fallo al renderizar la plantilla.', ['plantilla_id' => $plantilla->id, 'error' => $e->getMessage()]);
            $pdf = null;
        }

        if ($pdf === null || $pdf === '') {
            throw ValidationException::withMessages([
                'plantilla' => "No se pudo generar el PDF con la plantilla «{$plantilla->nombre}». Revisa que su contenido/archivo sea válido.",
            ]);
        }

        return $pdf;
    }

    public function disco(GeneratedDocument $documento): Filesystem
    {
        return Storage::disk($documento->disk);
    }

    public function contenido(GeneratedDocument $documento): string
    {
        $contenido = $this->disco($documento)->get($documento->path);

        if ($contenido === null) {
            throw new RuntimeException('El archivo del documento no está disponible en el almacenamiento.');
        }

        return $contenido;
    }

    /**
     * Descarga/visualización privada: siempre por streaming a través del
     * backend (la autorización la hace la Policy antes de llegar aquí);
     * nunca una URL pública al NAS.
     */
    public function respuesta(GeneratedDocument $documento, bool $inline = true): StreamedResponse
    {
        $disco = $this->disco($documento);

        abort_unless($disco->exists($documento->path), 404, 'El archivo del documento no está disponible.');

        $nombre = str_replace(['"', '\\', '/'], '', $documento->original_name);

        return $disco->response($documento->path, $nombre, [
            'Content-Type' => $documento->mime ?? 'application/pdf',
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.$nombre.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function categoriaDe(DocumentTemplate $plantilla): CategoriaDocumento
    {
        if ($plantilla->categoria !== null) {
            return $plantilla->categoria;
        }

        $configurada = config("contratos.plantillas.{$plantilla->clave}.categoria");

        return is_string($configurada) ? (CategoriaDocumento::tryFrom($configurada) ?? CategoriaDocumento::Otros) : CategoriaDocumento::Otros;
    }

    private function resolverPlantilla(DocumentTemplate|string $plantilla): DocumentTemplate
    {
        if ($plantilla instanceof DocumentTemplate) {
            if (! $plantilla->activo) {
                throw ValidationException::withMessages(['plantilla' => "La plantilla «{$plantilla->nombre}» está inactiva."]);
            }

            return $plantilla;
        }

        $activa = $this->plantillaActiva($plantilla);

        if ($activa === null) {
            $nombre = config("contratos.plantillas.{$plantilla}.nombre", $plantilla);

            throw ValidationException::withMessages([
                'plantilla' => sprintf('No hay una plantilla activa para «%s» (clave %s). RH/Jurídico debe cargar el formato antes de generarlo.', is_string($nombre) ? $nombre : $plantilla, $plantilla),
            ]);
        }

        return $activa;
    }

    /**
     * @param  array<string, string>  $payload
     */
    private function renderizarHtml(DocumentTemplate $plantilla, array $payload, string $titulo, string $referencia): string
    {
        $cuerpo = (string) $plantilla->contenido_html;

        // El HTML de la plantilla lo captura RH; se retiran scripts/iframes
        // por higiene aunque DomPDF no los ejecute.
        $cuerpo = (string) preg_replace('#<(script|iframe|object|embed)\b[^>]*>.*?</\1>#is', '', $cuerpo);

        $cuerpo = (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/',
            fn (array $m): string => e($payload[$m[1]] ?? ''),
            $cuerpo,
        );

        return Pdf::loadView('pdf.documento-laboral', [
            'cuerpo' => $cuerpo,
            'titulo' => $titulo,
            'folio' => $referencia,
            'clave' => $plantilla->clave,
            'version' => $plantilla->version,
            'generadoEn' => now()->format('d/m/Y H:i'),
        ])->setPaper('letter', 'portrait')->output();
    }

    /**
     * @param  array<string, string>  $payload
     */
    private function renderizarOverlay(DocumentTemplate $plantilla, array $payload): string
    {
        $formato = $plantilla->formatoOficial;

        if ($formato === null || ! $formato->tieneConfiguracion()) {
            throw ValidationException::withMessages([
                'plantilla' => "La plantilla «{$plantilla->nombre}» no tiene un formato oficial configurado.",
            ]);
        }

        return $this->overlay->generar($formato, $payload);
    }
}
