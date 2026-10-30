<?php

namespace App\Services\Incapacidades;

use App\Enums\SecuenciaIncapacidad;
use App\Enums\SubtipoRiesgoTrabajo;
use App\Enums\TipoDocumentoIncapacidad;
use App\Enums\TipoIncapacidad;
use App\Models\Colaborador;
use App\Models\Incapacidad;
use App\Models\IncapacidadDocumento;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Nomina\IncidenciasNominaService;
use App\Support\ExtensionArchivo;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Captura de incapacidades (RH). Reglas de la propuesta Etapa 1, págs. 7-9:
 *  - término = inicio + días naturales − 1, solo en el backend;
 *  - serie y folio IMSS obligatorio y único mientras no se cancele;
 *  - la subsecuente se liga a una INICIAL del mismo colaborador y tipo, nunca a
 *    otra subsecuente, y arranca después de que termina la anterior;
 *  - una persona no tiene dos incapacidades en los mismos días;
 *  - riesgo de trabajo: ST-7/ST-2/ST-3 en la inicial; el ST-2 lo concluye.
 *
 * Cada cambio recalcula la nómina en IncidenciasNominaService (fuente única) y
 * queda en la bitácora general (AuditoriaService).
 */
class IncapacidadService
{
    public function __construct(
        private readonly AuditoriaService $auditoria,
        private readonly IncidenciasNominaService $incidencias,
    ) {}

    /**
     * @param  array<string, mixed>  $datos
     *
     * @throws ValidationException
     */
    public function registrar(Colaborador $colaborador, array $datos, UploadedFile $certificado, ?UploadedFile $st7, User $actor): Incapacidad
    {
        $tipo = TipoIncapacidad::from((string) $datos['tipo']);
        $secuencia = SecuenciaIncapacidad::from((string) $datos['secuencia']);
        $inicio = CarbonImmutable::parse((string) $datos['fecha_inicio'])->startOfDay();
        $dias = (int) $datos['dias'];
        $fin = $this->fechaTermino($inicio, $dias);
        $folio = $this->normalizarFolio((string) $datos['serie_folio_imss']);
        $inicial = $secuencia === SecuenciaIncapacidad::Subsecuente ? $this->inicialValida($colaborador, $tipo, $datos['incapacidad_inicial_id'] ?? null, $inicio) : null;
        $subtipo = $inicial !== null ? $inicial->subtipo_riesgo : $this->subtipoDe($tipo, $datos['subtipo_riesgo'] ?? null);

        if ($st7 !== null && $tipo !== TipoIncapacidad::RiesgoTrabajo) {
            throw ValidationException::withMessages(['st7' => 'El ST-7 solo aplica a riesgo de trabajo.']);
        }

        $this->exigirFechasValidas($colaborador, $inicio, $fin);
        $this->exigirFolioLibre($folio, null);
        $this->exigirSinTraslape($colaborador->id, $inicio, $fin, null);

        // El archivo se guarda primero: sin confirmación del NAS no se registra nada.
        $archivoCertificado = $this->guardarArchivo($colaborador->id, $certificado);
        $archivoSt7 = $st7 !== null ? $this->guardarArchivo($colaborador->id, $st7) : null;

        try {
            $incapacidad = DB::transaction(function () use ($colaborador, $datos, $tipo, $secuencia, $inicio, $dias, $fin, $folio, $inicial, $subtipo, $certificado, $st7, $archivoCertificado, $archivoSt7, $actor): Incapacidad {
                // Dos capturas simultáneas de la misma persona se forman en fila.
                Colaborador::query()->whereKey($colaborador->id)->lockForUpdate()->first();
                $this->exigirSinTraslape($colaborador->id, $inicio, $fin, null);

                $esRaizDeRiesgo = $tipo === TipoIncapacidad::RiesgoTrabajo && $inicial === null;
                $incapacidad = Incapacidad::query()->create([
                    'colaborador_id' => $colaborador->id,
                    'sucursal_id' => $colaborador->sucursal_principal_id,
                    'tipo' => $tipo,
                    'subtipo_riesgo' => $subtipo,
                    'secuencia' => $secuencia,
                    'incapacidad_inicial_id' => $inicial?->id,
                    'serie_folio_imss' => $this->textoFolio((string) $datos['serie_folio_imss']),
                    'folio_vigente' => $folio,
                    'fecha_inicio' => $inicio,
                    'dias' => $dias,
                    'fecha_fin' => $fin,
                    'riesgo_folio' => $esRaizDeRiesgo ? $this->siguienteFolioRiesgo($inicio) : null,
                    'fecha_evento' => $esRaizDeRiesgo ? ($datos['fecha_evento'] ?? $inicio->toDateString()) : null,
                    'descripcion_evento' => $esRaizDeRiesgo ? ($datos['descripcion_evento'] ?? null) : null,
                    'observaciones' => $datos['observaciones'] ?? null,
                    'registrado_por' => $actor->id,
                    'actualizado_por' => $actor->id,
                ]);

                $this->crearDocumento($incapacidad, TipoDocumentoIncapacidad::Certificado, $certificado, $archivoCertificado, null, null, $actor);

                if ($st7 !== null && $archivoSt7 !== null) {
                    $this->crearDocumento($incapacidad, TipoDocumentoIncapacidad::St7, $st7, $archivoSt7, isset($datos['fecha_st7']) ? (string) $datos['fecha_st7'] : null, null, $actor);
                }

                return $incapacidad;
            });
        } catch (QueryException $e) {
            // Doble envío: el índice único es el último guardián del folio.
            $this->borrarArchivos([$archivoCertificado, $archivoSt7]);

            if ($this->esDuplicado($e)) {
                throw ValidationException::withMessages(['serie_folio_imss' => 'Ya existe una incapacidad con ese serie y folio del IMSS.']);
            }

            throw $e;
        } catch (Throwable $e) {
            $this->borrarArchivos([$archivoCertificado, $archivoSt7]);

            throw $e;
        }

        $this->auditoria->registrar('incapacidad_registrada', $incapacidad, $actor, [
            'tipo' => $tipo->value,
            'secuencia' => $secuencia->value,
            'fecha_inicio' => $inicio->toDateString(),
            'dias' => $dias,
            'fecha_fin' => $fin->toDateString(),
        ]);
        $this->recalcularNomina($colaborador, $inicio, $fin, $actor, sprintf('Incapacidad %s registrada', $incapacidad->serie_folio_imss));

        return $incapacidad->refresh();
    }

    /**
     * Corrige folio, fechas u observaciones con motivo. El término se vuelve a
     * calcular; el tipo y la persona no se corrigen (se cancela y se recaptura).
     *
     * @param  array<string, mixed>  $datos
     *
     * @throws ValidationException
     */
    public function corregir(Incapacidad $incapacidad, array $datos, string $motivo, User $actor): Incapacidad
    {
        $this->exigirNoCancelada($incapacidad);
        $raiz = $this->raizDe($incapacidad);
        $inicio = CarbonImmutable::parse((string) $datos['fecha_inicio'])->startOfDay();
        $dias = (int) $datos['dias'];
        $fin = $this->fechaTermino($inicio, $dias);
        $folio = $this->normalizarFolio((string) $datos['serie_folio_imss']);
        $inicioAnterior = CarbonImmutable::parse($incapacidad->fecha_inicio->toDateString());
        $finAnterior = CarbonImmutable::parse($incapacidad->fecha_fin->toDateString());
        $cambianFechas = ! $inicio->equalTo($inicioAnterior) || $dias !== $incapacidad->dias;

        if ($cambianFechas && $raiz->riesgo_concluido_en !== null) {
            throw ValidationException::withMessages(['fecha_inicio' => 'El riesgo ya se concluyó con su ST-2: sus fechas no se modifican.']);
        }

        $this->exigirFechasValidas($incapacidad->colaborador, $inicio, $fin);
        $this->exigirFolioLibre($folio, $incapacidad->id);
        $this->exigirSinTraslape($incapacidad->colaborador_id, $inicio, $fin, $incapacidad->id);

        if ($incapacidad->incapacidad_inicial_id !== null && $inicio->lte($raiz->fecha_fin)) {
            throw ValidationException::withMessages(['fecha_inicio' => sprintf('Una subsecuente empieza después de que termina su inicial (%s).', $raiz->fecha_fin->format('d/m/Y'))]);
        }

        $primera = $incapacidad->subsecuentes()->whereNull('cancelada_en')->orderBy('fecha_inicio')->first();

        if ($primera !== null && $fin->gte($primera->fecha_inicio)) {
            throw ValidationException::withMessages(['dias' => sprintf('Esta incapacidad debe terminar antes de su subsecuente del %s.', $primera->fecha_inicio->format('d/m/Y'))]);
        }

        $antes = [
            'serie_folio_imss' => $incapacidad->serie_folio_imss,
            'fecha_inicio' => $inicioAnterior->toDateString(),
            'dias' => $incapacidad->dias,
            'fecha_fin' => $finAnterior->toDateString(),
        ];

        try {
            $incapacidad->update([
                'serie_folio_imss' => $this->textoFolio((string) $datos['serie_folio_imss']),
                'folio_vigente' => $folio,
                'fecha_inicio' => $inicio,
                'dias' => $dias,
                'fecha_fin' => $fin,
                'observaciones' => $datos['observaciones'] ?? $incapacidad->observaciones,
                'actualizado_por' => $actor->id,
            ]);
        } catch (QueryException $e) {
            if ($this->esDuplicado($e)) {
                throw ValidationException::withMessages(['serie_folio_imss' => 'Ya existe una incapacidad con ese serie y folio del IMSS.']);
            }

            throw $e;
        }

        $this->auditoria->registrar('incapacidad_corregida', $incapacidad, $actor, [
            'motivo' => $motivo,
            'antes' => $antes,
            'despues' => ['serie_folio_imss' => $incapacidad->serie_folio_imss, 'fecha_inicio' => $inicio->toDateString(), 'dias' => $dias, 'fecha_fin' => $fin->toDateString()],
        ]);

        $this->recalcularNomina(
            $incapacidad->colaborador,
            $inicio->lt($inicioAnterior) ? $inicio : $inicioAnterior,
            $fin->gt($finAnterior) ? $fin : $finAnterior,
            $actor,
            sprintf('Incapacidad %s corregida: %s', $incapacidad->serie_folio_imss, $motivo),
        );

        return $incapacidad->refresh();
    }

    /**
     * Cancela una captura errónea: el registro y sus documentos se conservan,
     * sale de nómina y libera el folio para recapturarlo correctamente.
     *
     * @throws ValidationException
     */
    public function cancelar(Incapacidad $incapacidad, string $motivo, User $actor): Incapacidad
    {
        $this->exigirNoCancelada($incapacidad);

        if ($incapacidad->subsecuentes()->whereNull('cancelada_en')->exists()) {
            throw ValidationException::withMessages(['motivo' => 'Tiene subsecuentes vigentes: cancélalas primero.']);
        }

        $incapacidad->update([
            'cancelada_en' => now(),
            'cancelada_por' => $actor->id,
            'motivo_cancelacion' => $motivo,
            'folio_vigente' => null,
            'actualizado_por' => $actor->id,
        ]);

        $this->auditoria->registrar('incapacidad_cancelada', $incapacidad, $actor, ['motivo' => $motivo, 'serie_folio_imss' => $incapacidad->serie_folio_imss]);
        $this->recalcularNomina($incapacidad->colaborador, $incapacidad->fecha_inicio, $incapacidad->fecha_fin, $actor, sprintf('Incapacidad %s cancelada: %s', $incapacidad->serie_folio_imss, $motivo));

        return $incapacidad->refresh();
    }

    /**
     * Carga un documento. Si ya hay uno vigente del mismo tipo se exige motivo y
     * el anterior se conserva (nunca se borra el original del NAS).
     *
     * @throws ValidationException
     */
    public function subirDocumento(Incapacidad $incapacidad, TipoDocumentoIncapacidad $tipo, UploadedFile $archivo, ?string $fechaDocumento, ?string $motivo, User $actor): IncapacidadDocumento
    {
        $this->exigirNoCancelada($incapacidad);

        if ($tipo->esDelRiesgo() && $incapacidad->tipo !== TipoIncapacidad::RiesgoTrabajo) {
            throw ValidationException::withMessages(['tipo' => sprintf('%s solo aplica a riesgo de trabajo.', $tipo->etiqueta())]);
        }

        // Los ST son del riesgo completo: viven en la inicial.
        $destino = $tipo->esDelRiesgo() ? $this->raizDe($incapacidad) : $incapacidad;
        $vigente = $destino->documentos()->where('tipo', $tipo->value)->whereNull('reemplazado_en')->latest('id')->first();

        if ($vigente !== null && blank($motivo)) {
            throw ValidationException::withMessages(['motivo' => 'Ya hay un documento cargado: indica el motivo para reemplazarlo.']);
        }

        if ($tipo === TipoDocumentoIncapacidad::St2) {
            $this->exigirSt2Valido($destino, $fechaDocumento);
        }

        $guardado = $this->guardarArchivo($destino->colaborador_id, $archivo);

        try {
            $documento = DB::transaction(function () use ($destino, $tipo, $archivo, $guardado, $fechaDocumento, $motivo, $vigente, $actor): IncapacidadDocumento {
                $documento = $this->crearDocumento($destino, $tipo, $archivo, $guardado, $fechaDocumento, $vigente, $actor, $motivo);

                if ($tipo === TipoDocumentoIncapacidad::St2) {
                    $destino->update(['riesgo_concluido_en' => $fechaDocumento, 'actualizado_por' => $actor->id]);
                }

                return $documento;
            });
        } catch (Throwable $e) {
            $this->borrarArchivos([$guardado]);

            throw $e;
        }

        $this->auditoria->registrar('incapacidad_documento_cargado', $destino, $actor, [
            'documento' => $tipo->value,
            'documento_id' => $documento->id,
            'reemplaza_id' => $vigente?->id,
            'motivo' => $motivo,
        ]);

        return $documento;
    }

    public function descargarDocumento(IncapacidadDocumento $documento, User $actor): StreamedResponse
    {
        abort_unless(Storage::disk($documento->disco)->exists($documento->ruta), 404, 'El archivo ya no está en el almacenamiento.');
        $this->auditoria->registrar('incapacidad_documento_consultado', $documento->incapacidad, $actor, ['documento_id' => $documento->id, 'documento' => $documento->tipo->value]);
        $nombre = str_replace('"', '', $documento->nombre_original);

        return Storage::disk($documento->disco)->response($documento->ruta, $nombre, [
            'Content-Type' => $documento->mime ?? 'application/octet-stream',
            'Content-Disposition' => sprintf('inline; filename="%s"', $nombre),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function fechaTermino(CarbonImmutable $inicio, int $dias): CarbonImmutable
    {
        return $inicio->addDays($dias - 1);
    }

    public function raizDe(Incapacidad $incapacidad): Incapacidad
    {
        return $incapacidad->incapacidad_inicial_id !== null && $incapacidad->inicial !== null ? $incapacidad->inicial : $incapacidad;
    }

    /**
     * Sin espacios ni guiones y en mayúsculas: «RT 4471088» y «rt-4471088» son el mismo folio.
     */
    public function normalizarFolio(string $folio): string
    {
        $limpio = (string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($folio));

        if (strlen($limpio) < 4) {
            throw ValidationException::withMessages(['serie_folio_imss' => 'Captura la serie y el folio del certificado del IMSS.']);
        }

        return $limpio;
    }

    private function textoFolio(string $folio): string
    {
        return mb_strtoupper(trim((string) preg_replace('/\s+/', ' ', $folio)));
    }

    private function exigirFechasValidas(Colaborador $colaborador, CarbonImmutable $inicio, CarbonImmutable $fin): void
    {
        if ($colaborador->fecha_ingreso !== null && $inicio->lt($colaborador->fecha_ingreso->startOfDay())) {
            throw ValidationException::withMessages(['fecha_inicio' => sprintf('La incapacidad no puede empezar antes del ingreso (%s).', $colaborador->fecha_ingreso->format('d/m/Y'))]);
        }

        if ($colaborador->fecha_baja !== null && $inicio->gt($colaborador->fecha_baja->startOfDay())) {
            throw ValidationException::withMessages(['fecha_inicio' => sprintf('La incapacidad no puede empezar después de la baja (%s).', $colaborador->fecha_baja->format('d/m/Y'))]);
        }

        if ($fin->lt($inicio)) {
            throw ValidationException::withMessages(['dias' => 'El número de días debe ser mayor a cero.']);
        }
    }

    private function exigirFolioLibre(string $folio, ?int $excluir): void
    {
        $existe = Incapacidad::query()
            ->where('folio_vigente', $folio)
            ->when($excluir !== null, fn ($q) => $q->where('id', '!=', $excluir))
            ->exists();

        if ($existe) {
            throw ValidationException::withMessages(['serie_folio_imss' => 'Ya existe una incapacidad con ese serie y folio del IMSS.']);
        }
    }

    private function exigirSinTraslape(int $colaboradorId, CarbonImmutable $inicio, CarbonImmutable $fin, ?int $excluir): void
    {
        $traslape = Incapacidad::query()
            ->where('colaborador_id', $colaboradorId)
            ->whereNull('cancelada_en')
            ->whereDate('fecha_inicio', '<=', $fin->toDateString())
            ->whereDate('fecha_fin', '>=', $inicio->toDateString())
            ->when($excluir !== null, fn ($q) => $q->where('id', '!=', $excluir))
            ->first();

        if ($traslape !== null) {
            throw ValidationException::withMessages(['fecha_inicio' => sprintf('Se empalma con la incapacidad %s (%s al %s).', $traslape->serie_folio_imss, $traslape->fecha_inicio->format('d/m/Y'), $traslape->fecha_fin->format('d/m/Y'))]);
        }
    }

    private function inicialValida(Colaborador $colaborador, TipoIncapacidad $tipo, mixed $id, CarbonImmutable $inicio): Incapacidad
    {
        $inicial = is_numeric($id) ? Incapacidad::query()->where('id', (int) $id)->first() : null;

        if ($inicial === null) {
            throw ValidationException::withMessages(['incapacidad_inicial_id' => 'Elige la incapacidad inicial a la que se liga.']);
        }

        $errores = match (true) {
            $inicial->colaborador_id !== $colaborador->id => 'La incapacidad inicial es de otra persona.',
            $inicial->secuencia !== SecuenciaIncapacidad::Inicial => 'Liga la subsecuente a la incapacidad inicial, no a otra subsecuente.',
            $inicial->tipo !== $tipo => 'La incapacidad inicial es de otro tipo.',
            $inicial->estaCancelada() => 'La incapacidad inicial está cancelada.',
            $inicial->riesgo_concluido_en !== null => 'El riesgo ya se concluyó con su ST-2: no admite subsecuentes.',
            default => null,
        };

        if ($errores !== null) {
            throw ValidationException::withMessages(['incapacidad_inicial_id' => $errores]);
        }

        $ultimoFin = $inicial->subsecuentes()->whereNull('cancelada_en')->max('fecha_fin');
        $finCadena = $ultimoFin !== null ? CarbonImmutable::parse((string) $ultimoFin) : CarbonImmutable::parse($inicial->fecha_fin->toDateString());

        if ($inicio->lte($finCadena)) {
            throw ValidationException::withMessages(['fecha_inicio' => sprintf('La subsecuente debe empezar después del %s, último día de la cadena.', $finCadena->format('d/m/Y'))]);
        }

        return $inicial;
    }

    private function subtipoDe(TipoIncapacidad $tipo, mixed $valor): ?SubtipoRiesgoTrabajo
    {
        if ($tipo !== TipoIncapacidad::RiesgoTrabajo) {
            return null;
        }

        $subtipo = SubtipoRiesgoTrabajo::tryFrom((string) $valor);

        if ($subtipo === null) {
            throw ValidationException::withMessages(['subtipo_riesgo' => 'Elige el tipo de riesgo de trabajo.']);
        }

        return $subtipo;
    }

    private function exigirSt2Valido(Incapacidad $raiz, ?string $fecha): void
    {
        if ($fecha === null || $fecha === '') {
            throw ValidationException::withMessages(['fecha_documento' => 'Captura la fecha del dictamen de alta.']);
        }

        if (! $raiz->documentos()->where('tipo', TipoDocumentoIncapacidad::St7->value)->whereNull('reemplazado_en')->exists()) {
            throw ValidationException::withMessages(['tipo' => 'Carga primero el ST-7: el riesgo se concluye en orden.']);
        }

        $ultimoInicio = $raiz->subsecuentes()->whereNull('cancelada_en')->max('fecha_inicio') ?? $raiz->fecha_inicio->toDateString();

        if (CarbonImmutable::parse($fecha)->lt(CarbonImmutable::parse((string) $ultimoInicio))) {
            throw ValidationException::withMessages(['fecha_documento' => 'El alta no puede ser anterior a la última incapacidad del riesgo.']);
        }
    }

    private function exigirNoCancelada(Incapacidad $incapacidad): void
    {
        if ($incapacidad->estaCancelada()) {
            throw ValidationException::withMessages(['incapacidad' => 'Esta incapacidad está cancelada: se conserva solo como consulta.']);
        }
    }

    private function siguienteFolioRiesgo(CarbonImmutable $inicio): string
    {
        $anio = $inicio->year;
        $consecutivo = Incapacidad::query()->where('riesgo_folio', 'like', sprintf('RT-%d-%%', $anio))->count() + 1;

        return sprintf('RT-%d-%03d', $anio, $consecutivo);
    }

    /**
     * @return array{disco: string, ruta: string, checksum: string|null}
     *
     * @throws ValidationException
     */
    private function guardarArchivo(int $colaboradorId, UploadedFile $archivo): array
    {
        $disco = (string) config('expedientes.disk', 'nas');
        $ruta = sprintf('%s/%d/%s.%s', (string) config('incapacidades.documentos.carpeta', 'incapacidades'), $colaboradorId, (string) Str::uuid(), ExtensionArchivo::real($archivo));
        $checksum = hash_file('sha256', $archivo->getRealPath());

        try {
            $guardado = Storage::disk($disco)->putFileAs(dirname($ruta), $archivo, basename($ruta));
        } catch (Throwable $e) {
            $guardado = false;
            Log::error('IncapacidadService: fallo al guardar el documento.', ['colaborador_id' => $colaboradorId, 'error' => $e->getMessage()]);
        }

        if ($guardado === false || ! Storage::disk($disco)->exists($ruta)) {
            throw ValidationException::withMessages(['archivo' => 'No se pudo guardar el archivo en el almacenamiento (NAS no disponible). Intenta de nuevo; si continúa, avisa a sistemas.']);
        }

        return ['disco' => $disco, 'ruta' => $ruta, 'checksum' => $checksum !== false ? $checksum : null];
    }

    /**
     * @param  array{disco: string, ruta: string, checksum: string|null}  $guardado
     */
    private function crearDocumento(Incapacidad $incapacidad, TipoDocumentoIncapacidad $tipo, UploadedFile $archivo, array $guardado, ?string $fechaDocumento, ?IncapacidadDocumento $reemplaza, User $actor, ?string $motivo = null): IncapacidadDocumento
    {
        $reemplaza?->update(['reemplazado_en' => now()]);

        return IncapacidadDocumento::query()->create([
            'incapacidad_id' => $incapacidad->id,
            'tipo' => $tipo,
            'fecha_documento' => $fechaDocumento,
            'nombre_original' => Str::limit($archivo->getClientOriginalName(), 255, ''),
            'disco' => $guardado['disco'],
            'ruta' => $guardado['ruta'],
            'mime' => $archivo->getMimeType(),
            'tamano' => (int) $archivo->getSize(),
            'checksum' => $guardado['checksum'],
            'cargado_por' => $actor->id,
            'reemplaza_id' => $reemplaza?->id,
            'motivo_reemplazo' => $motivo,
        ]);
    }

    /**
     * Solo para una carga que no llegó a registrarse (nunca un documento ya registrado).
     *
     * @param  list<array{disco: string, ruta: string, checksum: string|null}|null>  $archivos
     */
    private function borrarArchivos(array $archivos): void
    {
        foreach ($archivos as $archivo) {
            if ($archivo === null) {
                continue;
            }

            try {
                Storage::disk($archivo['disco'])->delete($archivo['ruta']);
            } catch (Throwable $e) {
                Log::warning('IncapacidadService: no se pudo limpiar un archivo huérfano.', ['ruta' => $archivo['ruta'], 'error' => $e->getMessage()]);
            }
        }
    }

    private function esDuplicado(QueryException $e): bool
    {
        return str_contains($e->getMessage(), 'incapacidades_folio_vigente_unique')
            || str_contains($e->getMessage(), 'incapacidades.folio_vigente')
            || str_contains($e->getMessage(), 'Duplicate entry');
    }

    /**
     * Un fallo al recalcular la nómina nunca deshace la captura: queda en el log.
     */
    private function recalcularNomina(Colaborador $colaborador, CarbonImmutable|CarbonInterface $desde, CarbonImmutable|CarbonInterface $hasta, User $actor, string $motivo): void
    {
        try {
            $this->incidencias->sincronizarRecibos($colaborador, $desde, $hasta, $actor, $motivo);
        } catch (Throwable $e) {
            Log::warning('IncapacidadService: no se pudo recalcular la nómina.', ['colaborador_id' => $colaborador->id, 'error' => $e->getMessage()]);
        }
    }
}
