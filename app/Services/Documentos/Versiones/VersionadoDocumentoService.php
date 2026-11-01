<?php

namespace App\Services\Documentos\Versiones;

use App\Enums\EstadoFlujoDocumento;
use App\Enums\TipoEmisionDocumento;
use App\Models\BajaDocumento;
use App\Models\DocumentoEvento;
use App\Models\GeneratedDocument;
use App\Models\SeguimientoDocumentoFisico;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Correcciones\CorreccionService;
use App\Services\DocumentosLaborales\FlujoDocumentalService;
use App\Services\DocumentosLaborales\MotorDocumentalService;
use App\Services\Organigrama\AutoridadJerarquicaService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Flujo uniforme de documentos generados (docs/DOCUMENTOS_VERSIONES.md). Un PDF
 * nunca se edita: se corrigen datos y se emite de nuevo desde la plantilla.
 *
 * Orden seguro: lista blanca → huella (si no cambió, la vigente) → render fuera
 * de transacción → NAS → en transacción crea la versión y, AL FINAL, cancela la
 * anterior. Si la base no admite rollback (MyISAM) la compensación retira la
 * versión nueva y su archivo; nunca queda el registro sin documento vigente.
 */
class VersionadoDocumentoService
{
    private const MENSAJE_CAMBIO_EN_CURSO = 'Este documento acaba de cambiar mientras se generaba la nueva versión. Revisa la versión vigente e intenta de nuevo.';

    public function __construct(
        private readonly MotorDocumentalService $motor,
        private readonly FlujoDocumentalService $flujo,
        private readonly AuditoriaService $auditoria,
        private readonly CorreccionService $correcciones,
        private readonly AutoridadJerarquicaService $autoridad,
    ) {}

    /**
     * Sin $correcciones (null) se conservan las de la versión vigente cuya fuente no
     * cambió: una nueva versión no pierde lo que RH ya corrigió.
     *
     * @param  array<string, mixed>|null  $correcciones
     *
     * @throws ValidationException
     */
    public function emitir(FuenteDocumento $fuente, ?array $correcciones, ?string $motivo, User $actor, bool $rectificar = false): ResultadoEmision
    {
        // Vigente y huella se leen bajo el bloqueo del registro: otra emisión en curso termina primero.
        [$vigente, $limpias, $firmado, $huella] = DB::transaction(function () use ($fuente, $correcciones, $actor, $rectificar): array {
            $this->bloquearRegistro($fuente);
            $vigente = $this->vigente($fuente);
            $limpias = $this->limpiar($fuente, $correcciones ?? $this->correccionesVigentes($fuente, $vigente, $actor), $actor);
            $firmado = $vigente?->estado_flujo?->firmaFisicaRegistrada() ?? false;
            $this->exigirPermitido($fuente, $vigente, $firmado, $rectificar, $actor);

            return [$vigente, $limpias, $firmado, $this->huella($fuente, $limpias, $actor)];
        });

        if ($vigente !== null && $vigente->huella_datos !== null && hash_equals($vigente->huella_datos, $huella)) {
            return new ResultadoEmision($vigente, false);
        }

        $motivo = $this->exigirMotivo($motivo, $vigente !== null || $limpias !== []);

        if ($vigente !== null) {
            $this->autoridad->exigirPuedeModificar($actor, [$this->autoridad->autorPorId($vigente->generated_by)]);
        }

        // Fuera de transacción: un fallo aquí no tocó nada.
        $render = $this->renderizar($fuente, $limpias, $actor);
        $opciones = $render->opciones;
        $nombre = (string) ($opciones['nombre_archivo'] ?? sprintf('%s - %s.pdf', $render->titulo, now()->format('Y-m-d His')));
        [$ruta, $rutaDocx] = $this->motor->guardarArchivos($fuente->colaborador(), $render->categoria, $nombre, $render->pdf, isset($opciones['docx']) ? (string) $opciones['docx'] : null, $fuente->clave());
        $nuevo = null;

        try {
            $resultado = DB::transaction(function () use ($fuente, $limpias, $motivo, $actor, $render, $opciones, $nombre, $ruta, $rutaDocx, $huella, $vigente, $firmado, $rectificar, &$nuevo): ResultadoEmision {
                $registro = $this->bloquearRegistro($fuente);
                $actual = $this->vigente($fuente);

                // Mientras se renderizaba, otra petición emitió o firmó: si emitió lo mismo
                // (doble clic) vale esa versión; si no, seguir dejaría dos versiones vivas.
                if ($actual?->id !== $vigente?->id) {
                    if ($actual !== null && $actual->huella_datos === $huella) {
                        return new ResultadoEmision($actual, false);
                    }

                    throw ValidationException::withMessages(['documento' => self::MENSAJE_CAMBIO_EN_CURSO]);
                }

                if (($actual?->estado_flujo?->firmaFisicaRegistrada() ?? false) !== $firmado) {
                    throw ValidationException::withMessages(['documento' => self::MENSAJE_CAMBIO_EN_CURSO]);
                }

                $nuevo = $this->motor->registrarPdf($fuente->colaborador(), $render->pdf, $render->titulo, $actor, [
                    ...$opciones,
                    'clave' => $fuente->clave(),
                    'documentable' => $registro,
                    'nombre_archivo' => $nombre,
                    'ruta_guardada' => $ruta,
                    'ruta_docx_guardada' => $rutaDocx,
                    'huella_datos' => $huella,
                    'correcciones' => $this->detalleCorrecciones($fuente, $limpias, $actor, $vigente),
                    'tipo_emision' => $this->tipoEmision($vigente, $firmado, $rectificar, $limpias),
                    'revision_de_id' => $vigente?->id,
                    'motivo_revision' => $vigente !== null ? $motivo : null,
                ]);

                $fuente->despuesDeEmitir($nuevo, $vigente, $limpias, $motivo, $actor);

                if ($limpias !== [] && $motivo !== null) {
                    $this->correcciones->registrarEmisionDocumento($nuevo, $this->cambiosParaHistorial($fuente, $limpias, $actor), $motivo, $vigente, $actor);
                }

                // Lo último: hasta aquí la versión nueva ya está lista.
                if ($vigente !== null && ! $firmado) {
                    $this->flujo->cancelar($vigente, $actor, mb_substr(sprintf('Sustituido por una nueva versión: %s', (string) $motivo), 0, 500));
                }

                return new ResultadoEmision($nuevo, true);
            });
        } catch (Throwable $e) {
            $this->compensar($nuevo, $ruta, $rutaDocx);

            if ($e instanceof ValidationException) {
                throw $e;
            }

            Log::error('VersionadoDocumentoService: no se pudo registrar la nueva versión.', ['clave' => $fuente->clave(), 'colaborador_id' => $fuente->colaborador()->id, 'excepcion' => $e::class, 'error' => $e->getMessage()]);

            throw ValidationException::withMessages(['documento' => 'No se pudo registrar la nueva versión. El documento anterior sigue vigente; intenta de nuevo y, si continúa, avisa a Sistemas.']);
        }

        if (! $resultado->creado) {
            $this->motor->retirarArchivo($ruta);

            if ($rutaDocx !== null) {
                $this->motor->retirarArchivo($rutaDocx);
            }

            return $resultado;
        }

        if ($vigente !== null) {
            $this->auditoria->registrar('documento_regenerado', $resultado->documento, $actor, [
                'documento_anterior_id' => $vigente->id,
                'estado_anterior' => $firmado ? $vigente->estado_flujo?->value : EstadoFlujoDocumento::Cancelado->value,
                'anterior_firmado' => $firmado,
                'version' => $this->numeroVersion($resultado->documento),
                'motivo' => $motivo,
                'colaborador_id' => $fuente->colaborador()->id,
                'clave' => $fuente->clave(),
            ]);

            if ($firmado) {
                $this->auditoria->registrar('documento_revision_firmado', $resultado->documento, $actor, [
                    'documento_firmado_id' => $vigente->id,
                    'motivo' => $motivo,
                    'colaborador_id' => $fuente->colaborador()->id,
                    'clave' => $fuente->clave(),
                ]);
            }
        }

        return $resultado;
    }

    /**
     * PDF con lo que RH está corrigiendo y marca de vista previa. No guarda nada.
     *
     * @param  array<string, mixed>  $correcciones
     */
    public function vistaPrevia(FuenteDocumento $fuente, array $correcciones, User $actor): string
    {
        return $fuente->vistaPrevia($this->limpiar($fuente, $correcciones, $actor), $actor);
    }

    /**
     * Campos para el editor, con la corrección vigente aplicada. Si la fuente cambió
     * después de corregir, la corrección no se reaplica: se muestra como obsoleta.
     *
     * @return list<CampoEditable>
     */
    public function camposParaEditar(FuenteDocumento $fuente, User $actor): array
    {
        $previas = $this->vigente($fuente)->correcciones ?? [];

        return array_map(function (CampoEditable $campo) use ($previas): CampoEditable {
            $previa = $previas[$campo->clave] ?? null;

            if ($previa === null) {
                return $campo;
            }

            return $this->fuenteCambio($campo, $previa)
                ? new CampoEditable($campo->clave, $campo->etiqueta, $campo->tipo, $campo->original, $campo->original, (string) $previa['nuevo'])
                : new CampoEditable($campo->clave, $campo->etiqueta, $campo->tipo, (string) $previa['nuevo'], $campo->original);
        }, $fuente->campos($actor));
    }

    /**
     * @return array<string, string>
     */
    public function correccionesVigentes(FuenteDocumento $fuente, ?GeneratedDocument $vigente, User $actor): array
    {
        $previas = $vigente->correcciones ?? [];

        if ($previas === []) {
            return [];
        }

        $vigentes = [];

        foreach ($fuente->campos($actor) as $campo) {
            $previa = $previas[$campo->clave] ?? null;

            if ($previa !== null && ! $this->fuenteCambio($campo, $previa)) {
                $vigentes[$campo->clave] = (string) $previa['nuevo'];
            }
        }

        return $vigentes;
    }

    /**
     * Última versión no cancelada del documento.
     */
    public function vigente(FuenteDocumento $fuente): ?GeneratedDocument
    {
        $registro = $fuente->registro();

        return GeneratedDocument::query()
            ->where('documentable_type', $registro->getMorphClass())
            ->where('documentable_id', $registro->getKey())
            ->where('clave_plantilla', $fuente->clave())
            ->where(fn ($q) => $q->whereNull('estado_flujo')->orWhere('estado_flujo', '!=', EstadoFlujoDocumento::Cancelado->value))
            ->latest('id')
            ->first();
    }

    /**
     * ¿La versión ya no corresponde a los datos? Una versión anterior a la huella
     * no se puede comparar: queda como no desactualizada y cada módulo decide.
     */
    public function estaDesactualizado(FuenteDocumento $fuente, GeneratedDocument $documento, User $actor): bool
    {
        if ($documento->huella_datos === null) {
            return false;
        }

        $correcciones = array_map(fn (array $c): string => (string) $c['nuevo'], $documento->correcciones ?? []);

        return ! hash_equals($documento->huella_datos, $this->huella($fuente, $correcciones, $actor));
    }

    /**
     * Cadena completa, más reciente primero. Nunca incluye rutas físicas.
     *
     * @return list<array<string, mixed>>
     */
    public function versiones(FuenteDocumento $fuente, User $actor): array
    {
        if (! $fuente->puedeVerVersiones($actor)) {
            throw new AccessDeniedHttpException('No tienes permiso para consultar las versiones de este documento.');
        }

        $registro = $fuente->registro();
        $vigente = $this->vigente($fuente);
        $documentos = GeneratedDocument::query()->with('generadoPor:id,name,apellidos')
            ->where('documentable_type', $registro->getMorphClass())
            ->where('documentable_id', $registro->getKey())
            ->where('clave_plantilla', $fuente->clave())
            ->orderBy('id')
            ->get();
        $firmados = BajaDocumento::query()->whereIn('generated_document_id', $documentos->pluck('id'))->get()->groupBy('generated_document_id');
        $filas = [];

        foreach ($documentos->values() as $indice => $documento) {
            $filas[] = [
                'id' => $documento->id,
                'version' => $indice + 1,
                'titulo' => $documento->titulo,
                'vigente' => $documento->id === $vigente?->id,
                'estado' => $documento->estado_flujo?->value,
                'estado_etiqueta' => $documento->estado_flujo?->etiqueta(),
                'firmado' => $documento->estado_flujo?->firmaFisicaRegistrada() ?? false,
                'tipo_emision' => $documento->tipo_emision?->value,
                'tipo_emision_etiqueta' => $documento->tipo_emision?->etiqueta(),
                'motivo' => $documento->motivo_revision,
                'motivo_cancelacion' => $documento->motivo_cancelacion,
                'revision_de_id' => $documento->revision_de_id,
                'usuario' => $documento->generadoPor !== null ? trim(sprintf('%s %s', $documento->generadoPor->name, $documento->generadoPor->apellidos)) : null,
                'fecha' => $documento->created_at?->toIso8601String(),
                'campos_corregidos' => array_keys($documento->correcciones ?? []),
                'archivo_disponible' => $this->archivoDisponible($documento),
                'puede_descargar' => $actor->can('descargar', $documento),
                'firmados' => array_values(($firmados->get($documento->id) ?? collect())->map(fn (BajaDocumento $d): array => ['id' => $d->id, 'nombre' => $d->nombre_original])->all()),
            ];
        }

        return array_reverse($filas);
    }

    public function numeroVersion(GeneratedDocument $documento): int
    {
        if ($documento->documentable_type === null || $documento->clave_plantilla === null) {
            return 1;
        }

        return GeneratedDocument::query()
            ->where('documentable_type', $documento->documentable_type)
            ->where('documentable_id', $documento->documentable_id)
            ->where('clave_plantilla', $documento->clave_plantilla)
            ->where('id', '<=', $documento->id)
            ->count();
    }

    /**
     * Solo campos de la lista blanca y distintos del original. Un vacío regresa
     * al original. Una clave ajena se rechaza: nadie cambia el nombre, la empresa
     * o el representante legal por esta vía.
     *
     * @param  array<string, mixed>  $correcciones
     * @return array<string, string>
     */
    public function limpiar(FuenteDocumento $fuente, array $correcciones, User $actor): array
    {
        if ($correcciones === []) {
            return [];
        }

        $campos = [];

        foreach ($fuente->campos($actor) as $campo) {
            $campos[$campo->clave] = $campo;
        }

        $limpias = [];

        foreach ($correcciones as $clave => $valor) {
            $campo = $campos[(string) $clave] ?? null;

            if ($campo === null) {
                throw ValidationException::withMessages(['campos' => sprintf('El dato «%s» no se corrige desde este documento.', str_replace('_', ' ', (string) $clave))]);
            }

            $texto = is_scalar($valor) ? trim((string) $valor) : '';

            if ($texto !== '' && $texto !== trim($campo->original)) {
                $limpias[(string) $clave] = mb_substr($texto, 0, 5000);
            }
        }

        return $limpias;
    }

    /**
     * @param  array<string, string>  $correcciones
     */
    public function huella(FuenteDocumento $fuente, array $correcciones, User $actor): string
    {
        $valores = $fuente->valoresHuella($correcciones, $actor);
        ksort($valores);

        return hash('sha256', (string) json_encode($valores, JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array{anterior: string|null, nuevo: string, original_fuente: string|null}  $previa
     */
    private function fuenteCambio(CampoEditable $campo, array $previa): bool
    {
        return ! DatosVolatiles::esVolatil($campo->clave)
            && $previa['original_fuente'] !== null
            && trim($previa['original_fuente']) !== trim($campo->original);
    }

    /**
     * Fila del registro, también si está en papelera: toda emisión del documento se serializa aquí.
     */
    private function bloquearRegistro(FuenteDocumento $fuente): Model
    {
        $registro = $fuente->registro();
        $registro->newQueryWithoutScopes()->whereKey($registro->getKey())->lockForUpdate()->first();

        return $registro;
    }

    private function exigirPermitido(FuenteDocumento $fuente, ?GeneratedDocument $vigente, bool $firmado, bool $rectificar, User $actor): void
    {
        $bloqueo = $fuente->bloqueo($vigente, $rectificar);

        if ($bloqueo !== null) {
            throw ValidationException::withMessages(['documento' => $bloqueo]);
        }

        if ($firmado && ! $rectificar) {
            throw ValidationException::withMessages(['documento' => 'Este documento ya está firmado: el original se conserva. Solo admite rectificación con motivo.']);
        }

        if ($rectificar && ! $firmado) {
            throw ValidationException::withMessages(['documento' => 'La rectificación solo aplica a un documento ya firmado. Si aún no se firma, corrígelo y regenéralo.']);
        }

        // La policy además impide rectificar el propio documento y exige el alcance.
        if ($rectificar && (! $fuente->puedeRectificar($actor) || ($vigente !== null && ! $actor->can('regenerar', $vigente)))) {
            throw new AccessDeniedHttpException('No tienes permiso para rectificar un documento firmado.');
        }
    }

    private function exigirMotivo(?string $motivo, bool $obligatorio): ?string
    {
        $motivo = trim((string) $motivo);
        $minimo = (int) config('correcciones.motivo_minimo', 5);
        $maximo = (int) config('correcciones.motivo_maximo', 500);

        if ($obligatorio && (mb_strlen($motivo) < $minimo || mb_strlen($motivo) > $maximo)) {
            throw ValidationException::withMessages(['motivo' => sprintf('Explica el motivo de la nueva versión (de %d a %d caracteres): queda en el historial.', $minimo, $maximo)]);
        }

        return $motivo !== '' ? $motivo : null;
    }

    /**
     * @param  array<string, string>  $limpias
     */
    private function renderizar(FuenteDocumento $fuente, array $limpias, User $actor): DocumentoRenderizado
    {
        try {
            return $fuente->renderizar($limpias, $actor);
        } catch (Throwable $e) {
            // Los errores propios (datos faltantes, maestro sin QA…) ya explican qué pasó.
            if ($e instanceof ValidationException || $e instanceof HttpExceptionInterface || str_starts_with($e::class, 'App\\Exceptions\\')) {
                throw $e;
            }

            Log::error('VersionadoDocumentoService: no se pudo generar el PDF.', ['clave' => $fuente->clave(), 'excepcion' => $e::class, 'error' => $e->getMessage()]);

            throw ValidationException::withMessages(['documento' => 'No se pudo generar el PDF. El documento anterior sigue vigente; intenta de nuevo y, si continúa, avisa a Sistemas.']);
        }
    }

    /**
     * @param  array<string, string>  $limpias
     */
    private function tipoEmision(?GeneratedDocument $vigente, bool $firmado, bool $rectificar, array $limpias): TipoEmisionDocumento
    {
        return match (true) {
            $firmado && $rectificar => TipoEmisionDocumento::Rectificacion,
            $vigente !== null || $limpias !== [] => TipoEmisionDocumento::Correccion,
            default => TipoEmisionDocumento::Original,
        };
    }

    /**
     * @param  array<string, string>  $limpias
     * @return array<string, array{anterior: string|null, nuevo: string, original_fuente: string|null}>|null
     */
    private function detalleCorrecciones(FuenteDocumento $fuente, array $limpias, User $actor, ?GeneratedDocument $vigente): ?array
    {
        if ($limpias === []) {
            return null;
        }

        $originales = [];

        foreach ($fuente->campos($actor) as $campo) {
            $originales[$campo->clave] = $campo->original;
        }

        $previas = $vigente->correcciones ?? [];
        $detalle = [];

        foreach ($limpias as $clave => $nuevo) {
            $detalle[$clave] = [
                'anterior' => isset($previas[$clave]) ? (string) $previas[$clave]['nuevo'] : ($originales[$clave] ?? null),
                'nuevo' => $nuevo,
                'original_fuente' => $originales[$clave] ?? null,
            ];
        }

        return $detalle;
    }

    /**
     * @param  array<string, string>  $limpias
     * @return list<array{campo: string, etiqueta: string, anterior: string|null, nuevo: string}>
     */
    private function cambiosParaHistorial(FuenteDocumento $fuente, array $limpias, User $actor): array
    {
        $cambios = [];

        foreach ($fuente->campos($actor) as $campo) {
            if (array_key_exists($campo->clave, $limpias)) {
                $cambios[] = ['campo' => $campo->clave, 'etiqueta' => $campo->etiqueta, 'anterior' => $campo->original, 'nuevo' => $limpias[$campo->clave]];
            }
        }

        return $cambios;
    }

    /**
     * Sin rollback (MyISAM) la fila nueva sobrevive a la excepción: se retira a
     * mano para no dejar dos vigentes ni un archivo sin registro.
     */
    private function compensar(?GeneratedDocument $nuevo, string $ruta, ?string $rutaDocx): void
    {
        try {
            if ($nuevo !== null && GeneratedDocument::query()->whereKey($nuevo->id)->exists()) {
                DocumentoEvento::query()->where('generated_document_id', $nuevo->id)->delete();
                SeguimientoDocumentoFisico::query()->where('generated_document_id', $nuevo->id)->delete();
                GeneratedDocument::query()->whereKey($nuevo->id)->delete();
            }

            $this->motor->retirarArchivo($ruta);

            if ($rutaDocx !== null) {
                $this->motor->retirarArchivo($rutaDocx);
            }
        } catch (Throwable $e) {
            Log::warning('VersionadoDocumentoService: no se pudo limpiar la versión fallida.', ['error' => $e->getMessage()]);
        }
    }

    private function archivoDisponible(GeneratedDocument $documento): bool
    {
        try {
            return Storage::disk($documento->disk)->exists($documento->path);
        } catch (Throwable) {
            return false;
        }
    }
}
