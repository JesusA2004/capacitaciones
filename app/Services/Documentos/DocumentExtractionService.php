<?php

namespace App\Services\Documentos;

use App\Enums\EstadoCivil;
use App\Enums\EstadoExtraccion;
use App\Enums\Genero;
use App\Models\Colaborador;
use App\Models\DocumentExtraction;
use App\Models\EmployeeDocument;
use App\Models\ExpedienteHistorico;
use App\Models\User;
use App\Services\Expedientes\DocumentoStorageService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Smalot\PdfParser\Document as PdfDocument;
use Smalot\PdfParser\Parser as PdfParser;
use Throwable;

/**
 * Orquesta la extraccion automatica de datos personales de un documento de
 * expediente (docs/DOCUMENT_EXTRACTION.md): lee el archivo, si es un PDF le
 * saca el texto (smalot/pdfparser; si no tiene capa de texto y hay Tesseract
 * + un rasterizador local, usa OCR pagina por pagina) y corre
 * RegexPersonalDataExtractor sobre ese texto. JPG/PNG usan OCR local directo.
 * Nunca truena ni usa un servicio externo/cloud.
 *
 * La fuente puede ser un EmployeeDocument (checklist normal del expediente) o
 * un ExpedienteHistorico (PDF único de la migración inicial, solo cuando ya
 * está vinculado a un colaborador) — exactamente una de las dos FKs de
 * DocumentExtraction tiene valor para cada fila.
 *
 * Es solo una SUGERENCIA para RH: procesar()/procesarHistorico()/reprocesar()
 * nunca modifican los datos del colaborador. Solo aplicar() lo hace, y solo
 * con los campos que RH decide explicitamente aceptar (o corrige) en el panel.
 */
class DocumentExtractionService
{
    /**
     * Tipos de documento elegibles para extraccion automatica (claves de
     * document_types) — el resto de tipos (contrato, recibo, fotografia,
     * etc.) no traen datos personales estructurados que valga la pena
     * intentar leer. Solo aplica a EmployeeDocument: un ExpedienteHistorico
     * no tiene "tipo", es un PDF único con todo junto.
     */
    public const TIPOS_ELEGIBLES = ['ine', 'curp', 'rfc', 'nss', 'acta_nacimiento', 'comprobante_domicilio'];

    /**
     * Mapeo campo detectado por RegexPersonalDataExtractor → columna real de
     * Colaborador (misma fuente que PlaceholderResolver). 'sexo' y
     * 'estado_civil' son catálogos cerrados (Genero/EstadoCivil): solo se
     * aplican si el texto detectado tiene una equivalencia inequívoca (ver
     * normalizarEnum()) — si no, quedan como sugerencia sin aplicar.
     *
     * @var array<string, string>
     */
    public const CAMPO_COLUMNA = [
        'curp' => 'curp',
        'rfc' => 'rfc',
        'nss' => 'nss',
        'fecha_nacimiento' => 'fecha_nacimiento',
        'codigo_postal' => 'domicilio_cp',
        'sexo' => 'genero',
        'clave_elector' => 'clave_elector',
        'correo' => 'correo_personal',
        'telefono' => 'telefono',
        'nacionalidad' => 'nacionalidad',
        'estado_civil' => 'estado_civil',
        'lugar_nacimiento' => 'lugar_nacimiento',
        'profesion' => 'profesion',
        'domicilio' => 'domicilio',
        'colonia' => 'domicilio_colonia',
        'municipio' => 'domicilio_municipio',
        'estado_domicilio' => 'domicilio_estado',
        'beneficiario_nombre' => 'beneficiario_nombre',
        'beneficiario_parentesco' => 'beneficiario_parentesco',
        'contacto_emergencia_nombre' => 'contacto_emergencia_nombre',
        'contacto_emergencia_telefono' => 'contacto_emergencia_telefono',
        'contacto_emergencia_parentesco' => 'contacto_emergencia_parentesco',
    ];

    /** Campos cuyo valor real es un enum de PHP (conversión especial, nunca comparación de texto plano). */
    private const CAMPOS_ENUM = ['sexo', 'estado_civil'];

    /** Etiqueta humana de los campos enum, para el mensaje de aplicar() cuando no hay equivalencia segura. */
    private const ETIQUETA_ENUM = ['sexo' => 'Sexo', 'estado_civil' => 'Estado civil'];

    private const OCR_PDF_MAX_PAGINAS = 15;

    private const OCR_PDF_DPI = 150;

    private const OCR_PDF_TIMEOUT_POR_PAGINA = 25;

    public function __construct(
        private readonly DocumentoStorageService $storage,
        private readonly RegexPersonalDataExtractor $extractor,
    ) {}

    /**
     * @return list<string> Los campos detectados que tienen una columna real de Colaborador.
     */
    public static function camposAplicables(): array
    {
        return array_keys(self::CAMPO_COLUMNA);
    }

    /**
     * @return list<string> Columnas únicas de Colaborador usadas por --solo-faltantes.
     */
    public static function columnasColaborador(): array
    {
        return array_values(array_unique(self::CAMPO_COLUMNA));
    }

    public static function tipoElegible(string $claveTipoDocumento): bool
    {
        return in_array($claveTipoDocumento, self::TIPOS_ELEGIBLES, true);
    }

    public function esElegible(EmployeeDocument $documento): bool
    {
        return self::tipoElegible($documento->tipo->clave);
    }

    /**
     * ExpedienteHistorico solo se procesa cuando ya está vinculado a un
     * colaborador (CLAUDE.md/encargo §4) — los pendientes de vincular no se
     * tocan automáticamente.
     */
    public static function historicoElegible(ExpedienteHistorico $historico): bool
    {
        return $historico->estado === ExpedienteHistorico::VINCULADO && $historico->colaborador_id !== null;
    }

    public function procesar(EmployeeDocument $documento): DocumentExtraction
    {
        $extraccion = DocumentExtraction::query()->updateOrCreate(
            ['employee_document_id' => $documento->id],
            ['user_id' => $documento->user_id, 'colaborador_id' => $documento->colaborador_id, 'status' => EstadoExtraccion::Procesando->value],
        );

        try {
            $bytes = $this->storage->disco()->get($documento->path);
        } catch (Throwable $e) {
            return $this->marcarFallida($extraccion, 'No se pudo leer el archivo del documento.', $e);
        }

        $hash = $documento->hash ?? hash('sha256', $bytes);

        return $this->procesarBytes($extraccion, $bytes, $documento->extension, $documento->colaborador, $hash);
    }

    public function reprocesar(EmployeeDocument $documento): DocumentExtraction
    {
        return $this->procesar($documento);
    }

    /**
     * Mismo flujo que procesar(), pero leyendo el PDF histórico único de la
     * migración inicial — nunca se mueve, renombra, corta ni borra, solo se
     * LEE. Abortar silenciosamente aquí sería un bug: el llamador
     * (comando/job) ya filtra por historicoElegible() antes de invocar esto.
     */
    public function procesarHistorico(ExpedienteHistorico $historico): DocumentExtraction
    {
        if (! self::historicoElegible($historico)) {
            throw new \InvalidArgumentException('Este expediente histórico no está vinculado a un colaborador; no se procesa automáticamente.');
        }

        $extraccion = DocumentExtraction::query()->updateOrCreate(
            ['expediente_historico_id' => $historico->id],
            // user_id (FK a users) puede quedar null: un colaborador migrado
            // no siempre tiene cuenta de acceso todavía.
            ['user_id' => $historico->colaborador?->user?->id, 'colaborador_id' => $historico->colaborador_id, 'status' => EstadoExtraccion::Procesando->value],
        );

        try {
            $bytes = Storage::disk($historico->disk)->get($historico->path);
        } catch (Throwable $e) {
            return $this->marcarFallida($extraccion, 'No se pudo leer el archivo histórico del NAS.', $e);
        }

        if ($bytes === null) {
            return $this->marcarFallida($extraccion, 'El archivo histórico ya no está disponible en el almacenamiento.');
        }

        return $this->procesarBytes($extraccion, $bytes, 'pdf', $historico->colaborador, $historico->hash);
    }

    public function reprocesarHistorico(ExpedienteHistorico $historico): DocumentExtraction
    {
        return $this->procesarHistorico($historico);
    }

    /**
     * @param  string|null  $extension  Extensión declarada del archivo (puede faltar; se detecta también por firma binaria).
     */
    private function procesarBytes(DocumentExtraction $extraccion, string $bytes, ?string $extension, ?Colaborador $colaborador, ?string $hash): DocumentExtraction
    {
        if ($colaborador === null) {
            return $this->marcarFallida($extraccion, 'El documento no tiene un colaborador asociado.', null, $hash);
        }

        $ocrUsado = false;

        if ($this->esPdf($extension, $bytes)) {
            $resultadoPdf = $this->extraerTextoPdf($bytes);
            $texto = $resultadoPdf['texto'];
            $ocrUsado = $resultadoPdf['ocr_used'];

            if ($texto === null) {
                return $this->marcarFallida($extraccion, $resultadoPdf['mensaje'] ?? 'No se pudieron leer datos automáticamente del PDF.', null, $hash, $ocrUsado);
            }
        } elseif ($this->esImagen($extension, $bytes)) {
            if (! $this->tesseractDisponible()) {
                return $this->marcarFallida(
                    $extraccion,
                    'No se pudieron leer datos automáticamente: es una imagen (foto/escaneo) y requiere OCR local (Tesseract no está instalado en este servidor).',
                    null,
                    $hash,
                );
            }

            $texto = $this->textoViaOcrImagen($bytes, $extension ?? 'jpg');
            $ocrUsado = true;

            if ($texto === null) {
                return $this->marcarFallida($extraccion, 'No se pudieron leer datos automáticamente: el OCR local no encontró texto legible en la imagen.', null, $hash, true);
            }
        } else {
            return $this->marcarFallida(
                $extraccion,
                'No se pudieron leer datos automáticamente: el formato del archivo no es PDF, JPG ni PNG. Revisa manualmente.',
                null,
                $hash,
            );
        }

        $resultado = $this->extractor->extraer($texto);
        $diferencias = $this->calcularDiferencias($colaborador, $resultado['data']);

        $extraccion->update([
            'status' => EstadoExtraccion::Procesado->value,
            'extracted_text' => Str::limit($texto, 20000, ''),
            'extracted_data' => $resultado['data'],
            'confidence' => $resultado['confidence'],
            'differences' => $diferencias,
            'error_message' => null,
            'source_hash' => $hash,
            'extractor_version' => RegexPersonalDataExtractor::VERSION,
            'ocr_used' => $ocrUsado,
        ]);

        return $extraccion->fresh();
    }

    /**
     * true si ya existe una extracción vigente (procesada o revisada por RH)
     * sobre los MISMOS bytes del documento (`source_hash` == hash actual) y
     * con la versión actual del extractor — usado por
     * `expedientes:extraer-existentes` para no releer del NAS en cada
     * corrida lo que ya se procesó sin cambios.
     */
    public function tieneExtraccionVigente(EmployeeDocument $documento): bool
    {
        return $this->extraccionVigente($documento->extraccion, $documento->hash);
    }

    public function tieneExtraccionVigenteHistorico(ExpedienteHistorico $historico): bool
    {
        return $this->extraccionVigente($historico->extraccion, $historico->hash);
    }

    private function extraccionVigente(?DocumentExtraction $extraccion, ?string $hashActual): bool
    {
        if ($extraccion === null || ! in_array($extraccion->status, [EstadoExtraccion::Procesado, EstadoExtraccion::Revisado], true)) {
            return false;
        }

        return $extraccion->extractor_version === RegexPersonalDataExtractor::VERSION
            && $hashActual !== null
            && $extraccion->source_hash === $hashActual;
    }

    /**
     * Sugerencias pendientes de revisión (con al menos un campo detectado
     * que no coincide con el dato actual) de TODOS los documentos de un
     * colaborador — para el resumen en el expediente (encargo §17).
     *
     * @return Collection<int, DocumentExtraction>
     */
    public function pendientes(Colaborador $colaborador): Collection
    {
        return DocumentExtraction::query()
            ->where('colaborador_id', $colaborador->id)
            ->where('status', EstadoExtraccion::Procesado->value)
            ->with(['documento.tipo', 'expedienteHistorico'])
            ->get()
            ->filter(fn (DocumentExtraction $e): bool => collect($e->differences ?? [])->contains(fn (array $d): bool => ! ($d['coincide'] ?? false)));
    }

    /**
     * Campos donde dos o más documentos del mismo colaborador detectaron
     * valores distintos — RH decide, el sistema nunca elige solo (encargo §16).
     *
     * @return array<string, list<array{valor: string, fuente: string, extraccion_id: int}>>
     */
    public function conflictos(Colaborador $colaborador): array
    {
        $extracciones = DocumentExtraction::query()
            ->where('colaborador_id', $colaborador->id)
            ->whereIn('status', [EstadoExtraccion::Procesado->value, EstadoExtraccion::Revisado->value])
            ->with(['documento.tipo', 'expedienteHistorico'])
            ->get();

        $porCampo = [];
        foreach ($extracciones as $extraccion) {
            foreach ((array) $extraccion->extracted_data as $campo => $valor) {
                if (! array_key_exists($campo, self::CAMPO_COLUMNA)) {
                    continue;
                }

                $porCampo[$campo][] = [
                    'valor' => $valor,
                    'normalizado' => $this->normalizar($valor),
                    'fuente' => $extraccion->fuenteNombre(),
                    'extraccion_id' => $extraccion->id,
                ];
            }
        }

        $conflictos = [];
        foreach ($porCampo as $campo => $entradas) {
            $unicos = collect($entradas)->unique('normalizado')->values();

            if ($unicos->count() > 1) {
                $conflictos[$campo] = array_values($unicos->map(fn (array $e): array => [
                    'valor' => $e['valor'],
                    'fuente' => $e['fuente'],
                    'extraccion_id' => $e['extraccion_id'],
                ])->all());
            }
        }

        return $conflictos;
    }

    /**
     * RH acepta (o corrige manualmente) los valores indicados y los aplica
     * al colaborador. $valores puede traer el valor detectado tal cual o
     * uno corregido a mano — aquí no se distingue, ambos son una decisión
     * explícita de RH. Solo escribe columnas reales de colaboradores
     * (self::CAMPO_COLUMNA); cualquier otra clave se ignora. Beneficiario y
     * contacto de emergencia nunca se cruzan entre sí (son campos distintos,
     * cada uno con su propia clave en CAMPO_COLUMNA).
     *
     * Atómico: si CUALQUIER valor seleccionado no se puede aplicar (p. ej.
     * un sexo/estado civil sin equivalencia segura al enum real), no se
     * aplica NINGUNO y la extracción NO queda marcada como revisada — RH
     * corrige ese valor y vuelve a intentar. Nunca se adivina (CLAUDE.md/
     * encargo §14) ni se aplica "lo demás" dejando el campo problemático
     * como si ya se hubiera decidido.
     *
     * @param  array<string, string>  $valores
     *
     * @throws ValidationException Si algún valor no tiene equivalencia segura.
     */
    public function aplicar(DocumentExtraction $extraccion, array $valores, User $revisor): DocumentExtraction
    {
        $colaborador = $extraccion->colaboradorExpediente;
        $aAplicar = [];

        foreach (array_intersect_key($valores, self::CAMPO_COLUMNA) as $campo => $valor) {
            $columna = self::CAMPO_COLUMNA[$campo];

            if (in_array($campo, self::CAMPOS_ENUM, true)) {
                $valorEnum = $this->normalizarEnum($campo, (string) $valor);

                if ($valorEnum === null) {
                    throw ValidationException::withMessages([
                        "valores.{$campo}" => sprintf(
                            '%s «%s» no corresponde a un valor permitido. Corrígelo antes de aplicar.',
                            self::ETIQUETA_ENUM[$campo],
                            $valor,
                        ),
                    ]);
                }

                $aAplicar[$columna] = $valorEnum;

                continue;
            }

            // fecha_nacimiento llega como texto "d/m/Y" (mismo formato que
            // valorActual() usa para comparar): Carbon::parse() de un cast de
            // fecha normal asume m/d/Y para ese formato con slashes y
            // interpretaria mal el dia/mes, asi que se convierte explicito aqui.
            $aAplicar[$columna] = $campo === 'fecha_nacimiento'
                ? Carbon::createFromFormat('d/m/Y', (string) $valor)->startOfDay()
                : $valor;
        }

        if ($aAplicar !== [] && $colaborador !== null) {
            $colaborador->update($aAplicar);
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

    private function esPdf(?string $extension, string $bytes): bool
    {
        if ($extension !== null && strtolower($extension) === 'pdf') {
            return true;
        }

        return str_starts_with($bytes, '%PDF-');
    }

    /**
     * JPG/PNG por firma binaria (nunca por extensión/Content-Type, igual
     * criterio que el visor móvil — CLAUDE.md "nunca confiar en extensión").
     */
    private function esImagen(?string $extension, string $bytes): bool
    {
        if (str_starts_with($bytes, "\xFF\xD8\xFF") || str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return true;
        }

        return in_array(strtolower((string) $extension), ['jpg', 'jpeg', 'png'], true);
    }

    /**
     * Detecta Tesseract instalado en el servidor (nunca un servicio cloud,
     * CLAUDE.md/encargo §36). Si el binario no existe o `Process` está
     * restringido en este entorno, se trata como "no disponible" — nunca
     * truena la extracción.
     */
    private function tesseractDisponible(): bool
    {
        return $this->binarioDisponible('tesseract');
    }

    private function binarioDisponible(string $binario): bool
    {
        try {
            $comando = PHP_OS_FAMILY === 'Windows' ? ['where', $binario] : ['which', $binario];

            return Process::run($comando)->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Primer rasterizador de PDF→imagen disponible en el servidor, o null si
     * ninguno lo está. pdftoppm (poppler-utils) es el preferido (más común
     * en Linux); mutool y magick/convert (ImageMagick) son alternativas.
     */
    private function rasterizadorDisponible(): ?string
    {
        foreach (['pdftoppm', 'mutool', 'magick', 'convert'] as $binario) {
            if ($this->binarioDisponible($binario)) {
                return $binario;
            }
        }

        return null;
    }

    /**
     * OCR local vía Tesseract (español). Nunca un servicio externo/pagado.
     * Regresa null si el binario falla o no encuentra texto — nunca lanza.
     */
    private function textoViaOcrImagen(string $bytes, string $extension): ?string
    {
        $extension = $extension !== '' ? $extension : 'jpg';
        $archivoTemporal = sys_get_temp_dir().'/'.Str::uuid().'.'.$extension;

        try {
            file_put_contents($archivoTemporal, $bytes);

            $resultado = Process::timeout(60)->run(['tesseract', $archivoTemporal, 'stdout', '-l', 'spa']);

            if (! $resultado->successful()) {
                Log::warning('document_extraction: tesseract no pudo procesar la imagen.', ['error' => $resultado->errorOutput()]);

                return null;
            }

            $texto = trim($resultado->output());

            return $texto !== '' ? $texto : null;
        } catch (Throwable $e) {
            Log::warning('document_extraction: fallo al ejecutar OCR local.', ['error' => $e->getMessage()]);

            return null;
        } finally {
            if (file_exists($archivoTemporal)) {
                unlink($archivoTemporal);
            }
        }
    }

    /**
     * @return array{texto: string|null, ocr_used: bool, mensaje: string|null}
     */
    private function extraerTextoPdf(string $bytes): array
    {
        $archivoTemporal = sys_get_temp_dir().'/'.Str::uuid().'.pdf';

        try {
            file_put_contents($archivoTemporal, $bytes);

            $parser = new PdfParser;
            $documentoPdf = $parser->parseFile($archivoTemporal);
            $texto = trim($documentoPdf->getText());

            if ($texto !== '') {
                return ['texto' => $texto, 'ocr_used' => false, 'mensaje' => null];
            }

            return $this->extraerTextoPdfEscaneado($documentoPdf, $archivoTemporal);
        } catch (Throwable $e) {
            Log::warning('document_extraction: fallo al leer texto del PDF.', ['error' => $e->getMessage()]);

            return ['texto' => null, 'ocr_used' => false, 'mensaje' => null];
        } finally {
            if (file_exists($archivoTemporal)) {
                unlink($archivoTemporal);
            }
        }
    }

    /**
     * PDF sin capa de texto (escaneo): si hay Tesseract Y un rasterizador
     * local disponibles, rasteriza página por página (resolución, timeout y
     * número de páginas acotados) y corre OCR en español sobre cada una.
     * Nunca usa un servicio cloud — si falta infraestructura, mensaje humano
     * explícito en vez de un error genérico.
     *
     * @return array{texto: string|null, ocr_used: bool, mensaje: string|null}
     */
    private function extraerTextoPdfEscaneado(PdfDocument $documentoPdf, string $rutaPdf): array
    {
        if (! $this->tesseractDisponible() || $this->rasterizadorDisponible() === null) {
            return [
                'texto' => null,
                'ocr_used' => false,
                'mensaje' => 'PDF escaneado; requiere Tesseract y un rasterizador PDF local (pdftoppm/mutool/ImageMagick).',
            ];
        }

        $rasterizador = $this->rasterizadorDisponible();
        $totalPaginas = min(count($documentoPdf->getPages()), self::OCR_PDF_MAX_PAGINAS);
        $texto = $this->ocrPdfEscaneado($rutaPdf, max($totalPaginas, 1), (string) $rasterizador);

        return [
            'texto' => $texto,
            'ocr_used' => true,
            'mensaje' => $texto === null ? 'PDF escaneado: el OCR local no encontró texto legible.' : null,
        ];
    }

    private function ocrPdfEscaneado(string $rutaPdf, int $paginas, string $rasterizador): ?string
    {
        $directorio = sys_get_temp_dir().'/'.Str::uuid();

        if (! mkdir($directorio) && ! is_dir($directorio)) {
            return null;
        }

        $textos = [];

        try {
            for ($pagina = 1; $pagina <= $paginas; $pagina++) {
                $imagen = $this->rasterizarPagina($rutaPdf, $pagina, $directorio, $rasterizador);

                if ($imagen === null) {
                    continue;
                }

                try {
                    $resultado = Process::timeout(self::OCR_PDF_TIMEOUT_POR_PAGINA)->run(['tesseract', $imagen, 'stdout', '-l', 'spa']);

                    if ($resultado->successful()) {
                        $textoPagina = trim($resultado->output());

                        if ($textoPagina !== '') {
                            $textos[] = $textoPagina;
                        }
                    }
                } finally {
                    if (file_exists($imagen)) {
                        unlink($imagen);
                    }
                }
            }
        } finally {
            foreach (glob($directorio.'/*') ?: [] as $residuo) {
                @unlink($residuo);
            }
            @rmdir($directorio);
        }

        $texto = trim(implode("\n", $textos));

        return $texto !== '' ? $texto : null;
    }

    private function rasterizarPagina(string $rutaPdf, int $pagina, string $directorio, string $rasterizador): ?string
    {
        $salida = $directorio.'/pagina-'.$pagina;

        $comando = match ($rasterizador) {
            'pdftoppm' => ['pdftoppm', '-r', (string) self::OCR_PDF_DPI, '-png', '-f', (string) $pagina, '-l', (string) $pagina, '-singlefile', $rutaPdf, $salida],
            'mutool' => ['mutool', 'draw', '-o', $salida.'.png', '-r', (string) self::OCR_PDF_DPI, $rutaPdf, (string) $pagina],
            default => [$rasterizador, '-density', (string) self::OCR_PDF_DPI, sprintf('%s[%d]', $rutaPdf, $pagina - 1), $salida.'.png'],
        };

        try {
            $resultado = Process::timeout(self::OCR_PDF_TIMEOUT_POR_PAGINA)->run($comando);

            if (! $resultado->successful() || ! file_exists($salida.'.png')) {
                return null;
            }

            return $salida.'.png';
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, string>  $detectado
     * @return array<string, array{detectado: string, actual: string|null, coincide: bool}>
     */
    private function calcularDiferencias(Colaborador $colaborador, array $detectado): array
    {
        $diferencias = [];

        foreach ($detectado as $campo => $valor) {
            $actual = array_key_exists($campo, self::CAMPO_COLUMNA)
                ? $this->valorActual($colaborador, $campo)
                : null;

            $diferencias[$campo] = [
                'detectado' => $valor,
                'actual' => $actual,
                'coincide' => $actual !== null && $this->coincide($campo, $actual, $valor),
            ];
        }

        return $diferencias;
    }

    private function coincide(string $campo, string $actual, string $detectado): bool
    {
        if (in_array($campo, self::CAMPOS_ENUM, true)) {
            $detectadoEnum = $this->normalizarEnum($campo, $detectado);

            return $detectadoEnum !== null && $detectadoEnum === $actual;
        }

        return $this->normalizar($actual) === $this->normalizar($detectado);
    }

    private function valorActual(Colaborador $colaborador, string $campo): ?string
    {
        $columna = self::CAMPO_COLUMNA[$campo];
        $valor = $colaborador->{$columna} ?? null;

        if ($valor === null) {
            return null;
        }

        if ($valor instanceof Carbon) {
            return $valor->format('d/m/Y');
        }

        if ($valor instanceof Genero || $valor instanceof EstadoCivil) {
            return $valor->value;
        }

        return (string) $valor;
    }

    /**
     * Convierte el texto detectado al valor real del enum (Genero/EstadoCivil)
     * solo cuando la equivalencia es inequívoca — nunca adivina (CLAUDE.md
     * "nunca deducir estado civil, nacionalidad..."). Regresa null si no
     * reconoce el texto, y el llamador lo deja como sugerencia sin aplicar.
     */
    private function normalizarEnum(string $campo, string $valor): ?string
    {
        $valor = strtoupper(trim($valor));

        if ($campo === 'sexo') {
            return match ($valor) {
                'H' => Genero::Masculino->value,
                'M' => Genero::Femenino->value,
                default => null,
            };
        }

        if ($campo === 'estado_civil') {
            return match (true) {
                str_starts_with($valor, 'SOLTER') => EstadoCivil::Soltero->value,
                str_starts_with($valor, 'CASAD') => EstadoCivil::Casado->value,
                str_contains($valor, 'UNION LIBRE') || str_contains($valor, 'UNIÓN LIBRE') => EstadoCivil::UnionLibre->value,
                str_starts_with($valor, 'DIVORCIAD') => EstadoCivil::Divorciado->value,
                str_starts_with($valor, 'VIUD') => EstadoCivil::Viudo->value,
                default => null,
            };
        }

        return null;
    }

    private function normalizar(string $valor): string
    {
        return strtoupper(trim($valor));
    }

    private function marcarFallida(DocumentExtraction $extraccion, string $mensaje, ?Throwable $e = null, ?string $hash = null, bool $ocrUsado = false): DocumentExtraction
    {
        if ($e !== null) {
            Log::warning('document_extraction: fallo al procesar documento.', [
                'document_extraction_id' => $extraccion->id,
                'employee_document_id' => $extraccion->employee_document_id,
                'expediente_historico_id' => $extraccion->expediente_historico_id,
                'error' => $e->getMessage(),
            ]);
        }

        $extraccion->update([
            'status' => EstadoExtraccion::Fallido->value,
            'error_message' => $mensaje,
            'source_hash' => $hash,
            'extractor_version' => RegexPersonalDataExtractor::VERSION,
            'ocr_used' => $ocrUsado,
        ]);

        return $extraccion->fresh();
    }
}
