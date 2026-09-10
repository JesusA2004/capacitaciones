<?php

namespace App\Services\Documentos;

use App\Enums\EstadoExtraccion;
use App\Models\DocumentExtraction;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Services\Expedientes\DocumentoStorageService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Smalot\PdfParser\Parser as PdfParser;
use Throwable;

/**
 * Orquesta la extraccion automatica de datos personales de un documento de
 * expediente (docs/DOCUMENT_EXTRACTION.md): lee el archivo, si es un PDF le
 * saca el texto (smalot/pdfparser, sin dependencias de servidor) y corre
 * RegexPersonalDataExtractor sobre ese texto. Documentos que no son PDF
 * (fotos JPG/PNG de una INE escaneada, el caso mas comun) quedan
 * "pendiente de revision manual" — nunca rompen la subida ni truenan.
 *
 * Es solo una SUGERENCIA para RH: procesar()/reprocesar() nunca modifican
 * los datos del colaborador. Solo aplicar() lo hace, y solo con los campos
 * que RH decide explicitamente aceptar (o corrige) en el panel.
 */
class DocumentExtractionService
{
    /**
     * Tipos de documento elegibles para extraccion automatica (claves de
     * document_types) — el resto de tipos (contrato, recibo, fotografia,
     * etc.) no traen datos personales estructurados que valga la pena
     * intentar leer.
     */
    public const TIPOS_ELEGIBLES = ['ine', 'curp', 'rfc', 'nss', 'acta_nacimiento', 'comprobante_domicilio'];

    /**
     * Campos que sí existen como columna en users y por lo tanto se pueden
     * comparar/aplicar. codigo_postal y sexo se detectan pero no tienen
     * columna propia en users (ver domicilio como texto libre), así que se
     * muestran como "detectado" sin comparación posible.
     */
    private const CAMPOS_APLICABLES = ['curp', 'rfc', 'nss', 'fecha_nacimiento'];

    public function __construct(
        private readonly DocumentoStorageService $storage,
        private readonly RegexPersonalDataExtractor $extractor,
    ) {}

    /**
     * Metodo estatico (no requiere inyectar el servicio completo) para que
     * App\Services\Expedientes\DocumentoStorageService pueda decidir si
     * encola la extraccion sin crear una dependencia circular entre ambos
     * servicios (DocumentExtractionService ya depende de
     * DocumentoStorageService para leer el archivo).
     */
    public static function tipoElegible(string $claveTipoDocumento): bool
    {
        return in_array($claveTipoDocumento, self::TIPOS_ELEGIBLES, true);
    }

    public function esElegible(EmployeeDocument $documento): bool
    {
        return self::tipoElegible($documento->tipo->clave);
    }

    public function procesar(EmployeeDocument $documento): DocumentExtraction
    {
        $extraccion = DocumentExtraction::query()->updateOrCreate(
            ['employee_document_id' => $documento->id],
            ['user_id' => $documento->user_id, 'status' => EstadoExtraccion::Procesando->value],
        );

        try {
            $bytes = $this->storage->disco()->get($documento->path);
        } catch (Throwable $e) {
            return $this->marcarFallida($extraccion, 'No se pudo leer el archivo del documento.', $e);
        }

        if (! $this->esPdf($documento, $bytes)) {
            return $this->marcarFallida(
                $extraccion,
                'No se pudieron leer datos automáticamente: el archivo no es un PDF (es una foto/escaneo). Revisa manualmente.',
            );
        }

        $texto = $this->textoDesdePdf($bytes);

        if ($texto === null) {
            return $this->marcarFallida(
                $extraccion,
                'No se pudieron leer datos automáticamente: el PDF no se pudo procesar (puede ser un escaneo sin texto). Revisa manualmente.',
            );
        }

        $resultado = $this->extractor->extraer($texto);
        $diferencias = $this->calcularDiferencias($documento->usuario, $resultado['data']);

        $extraccion->update([
            'status' => EstadoExtraccion::Procesado->value,
            'extracted_text' => Str::limit($texto, 20000, ''),
            'extracted_data' => $resultado['data'],
            'confidence' => $resultado['confidence'],
            'differences' => $diferencias,
            'error_message' => null,
        ]);

        return $extraccion->fresh();
    }

    public function reprocesar(EmployeeDocument $documento): DocumentExtraction
    {
        return $this->procesar($documento);
    }

    /**
     * RH acepta (o corrige manualmente) los valores indicados y los aplica
     * al colaborador. $valores puede traer el valor detectado tal cual o
     * uno corregido a mano — aquí no se distingue, ambos son una decisión
     * explícita de RH. Solo escribe columnas reales de users
     * (self::CAMPOS_APLICABLES); cualquier otra clave se ignora.
     *
     * @param  array<string, string>  $valores
     */
    public function aplicar(DocumentExtraction $extraccion, array $valores, User $revisor): DocumentExtraction
    {
        $aAplicar = array_intersect_key($valores, array_flip(self::CAMPOS_APLICABLES));

        // fecha_nacimiento llega como texto "d/m/Y" (mismo formato que
        // valorActual() usa para comparar): Carbon::parse() de un cast de
        // fecha normal asume m/d/Y para ese formato con slashes y
        // interpretaria mal el dia/mes, asi que se convierte explicito aqui.
        if (isset($aAplicar['fecha_nacimiento'])) {
            $aAplicar['fecha_nacimiento'] = Carbon::createFromFormat('d/m/Y', $aAplicar['fecha_nacimiento'])->startOfDay();
        }

        if ($aAplicar !== []) {
            $extraccion->colaborador->update($aAplicar);
        }

        $extraccion->update([
            'status' => EstadoExtraccion::Revisado->value,
            'reviewed_by_id' => $revisor->id,
            'reviewed_at' => now(),
        ]);

        return $extraccion->fresh();
    }

    public function ignorar(DocumentExtraction $extraccion, User $revisor): DocumentExtraction
    {
        $extraccion->update([
            'status' => EstadoExtraccion::Revisado->value,
            'reviewed_by_id' => $revisor->id,
            'reviewed_at' => now(),
        ]);

        return $extraccion->fresh();
    }

    private function esPdf(EmployeeDocument $documento, string $bytes): bool
    {
        if ($documento->extension !== null && strtolower($documento->extension) === 'pdf') {
            return true;
        }

        return str_starts_with($bytes, '%PDF-');
    }

    private function textoDesdePdf(string $bytes): ?string
    {
        $archivoTemporal = sys_get_temp_dir().'/'.Str::uuid().'.pdf';

        try {
            file_put_contents($archivoTemporal, $bytes);

            $parser = new PdfParser;
            $documentoPdf = $parser->parseFile($archivoTemporal);
            $texto = trim($documentoPdf->getText());

            return $texto !== '' ? $texto : null;
        } catch (Throwable $e) {
            Log::warning('document_extraction: fallo al leer texto del PDF.', ['error' => $e->getMessage()]);

            return null;
        } finally {
            if (file_exists($archivoTemporal)) {
                unlink($archivoTemporal);
            }
        }
    }

    /**
     * @param  array<string, string>  $detectado
     * @return array<string, array{detectado: string, actual: string|null, coincide: bool}>
     */
    private function calcularDiferencias(User $colaborador, array $detectado): array
    {
        $diferencias = [];

        foreach ($detectado as $campo => $valor) {
            $actual = in_array($campo, self::CAMPOS_APLICABLES, true)
                ? $this->valorActual($colaborador, $campo)
                : null;

            $diferencias[$campo] = [
                'detectado' => $valor,
                'actual' => $actual,
                'coincide' => $actual !== null && $this->normalizar($actual) === $this->normalizar($valor),
            ];
        }

        return $diferencias;
    }

    private function valorActual(User $colaborador, string $campo): ?string
    {
        $valor = $colaborador->{$campo} ?? null;

        if ($valor === null) {
            return null;
        }

        return $campo === 'fecha_nacimiento' ? $valor->format('d/m/Y') : (string) $valor;
    }

    private function normalizar(string $valor): string
    {
        return strtoupper(trim($valor));
    }

    private function marcarFallida(DocumentExtraction $extraccion, string $mensaje, ?Throwable $e = null): DocumentExtraction
    {
        if ($e !== null) {
            Log::warning('document_extraction: fallo al procesar documento.', [
                'document_extraction_id' => $extraccion->id,
                'employee_document_id' => $extraccion->employee_document_id,
                'error' => $e->getMessage(),
            ]);
        }

        $extraccion->update([
            'status' => EstadoExtraccion::Fallido->value,
            'error_message' => $mensaje,
        ]);

        return $extraccion->fresh();
    }
}
