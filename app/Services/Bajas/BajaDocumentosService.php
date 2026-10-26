<?php

namespace App\Services\Bajas;

use App\Enums\AccionBitacoraBaja;
use App\Enums\EstadoFiniquito;
use App\Enums\EstatusBaja;
use App\Enums\OrigenBaja;
use App\Models\Baja;
use App\Models\BajaDocumento;
use App\Models\DocumentoRequeridoBaja;
use App\Models\FiniquitoCalculo;
use App\Models\User;
use App\Services\DocumentosMaestros\DocumentoProcesoService;
use App\Support\ExtensionArchivo;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Documentos de la baja (RN-05): cada documento requerido (forma +
 * adicionales de la causal) tiene su propio espacio; el gerente puede
 * enviar aunque falten, RH no puede cerrar hasta que los obligatorios estén.
 *
 * Un espacio se cumple con un archivo adjunto (PDF o imagen, disco del NAS
 * de expedientes) o, si el documento lo genera/lleva el sistema
 * (`documento_sistema`), con lo que ya está en el cierre laboral: la carta
 * de renuncia o el aviso de terminación marcados como firmados físicamente,
 * o el finiquito entregado. El aviso de rescisión siempre se adjunta.
 */
class BajaDocumentosService
{
    public function __construct(
        private readonly CatalogoBajasService $catalogo,
        private readonly BajaRegistroService $registro,
        private readonly DocumentoProcesoService $documentosProceso,
    ) {}

    /**
     * Espacios de documentos de la baja con su estado y los adjuntos.
     *
     * @return list<array{id: int|null, clave: string|null, nombre: string, obligatorio: bool, de_causal: bool, cumplido: bool, cumplido_por_sistema: string|null, archivos: list<array<string, mixed>>}>
     */
    public function espacios(Baja $baja): array
    {
        $baja->loadMissing(['causal', 'documentos.cargadoPor']);
        $adjuntos = $baja->documentos->groupBy(fn (BajaDocumento $d): string => (string) ($d->documento_requerido_id ?? 'adicional'));
        $espacios = [];

        foreach ($this->requeridos($baja) as $requerido) {
            $archivos = $adjuntos->get((string) $requerido->id) ?? collect();
            $sistema = $requerido->documento_sistema !== null ? $this->cumplidoPorSistema($baja, $requerido->documento_sistema) : null;

            $espacios[] = [
                'id' => $requerido->id,
                'clave' => $requerido->clave,
                'nombre' => $requerido->nombre,
                'obligatorio' => $requerido->obligatorio_para_cerrar,
                'de_causal' => $requerido->causal_rotacion_id !== null,
                'cumplido' => $archivos->isNotEmpty() || $sistema !== null,
                'cumplido_por_sistema' => $sistema,
                'archivos' => array_values($archivos->map(fn (BajaDocumento $d): array => $this->archivoArray($d))->all()),
            ];
        }

        $adicionales = $adjuntos->get('adicional') ?? collect();

        // Adjuntos de un espacio que ya no aplica (cambió la causal) o
        // documentos adicionales: se muestran, nunca se pierden.
        $idsVigentes = array_filter(array_column($espacios, 'id'));
        $huerfanos = $baja->documentos->filter(fn (BajaDocumento $d): bool => $d->documento_requerido_id !== null && ! in_array($d->documento_requerido_id, $idsVigentes, true));
        $otros = $adicionales->merge($huerfanos);

        if ($otros->isNotEmpty()) {
            $espacios[] = [
                'id' => null,
                'clave' => null,
                'nombre' => 'Otros documentos',
                'obligatorio' => false,
                'de_causal' => false,
                'cumplido' => true,
                'cumplido_por_sistema' => null,
                'archivos' => array_values($otros->map(fn (BajaDocumento $d): array => $this->archivoArray($d))->all()),
            ];
        }

        return $espacios;
    }

    /**
     * Documentos requeridos de la baja (forma + adicionales de la causal).
     * Una baja de un cierre anterior al módulo no exige documentos.
     *
     * @return Collection<int, DocumentoRequeridoBaja>
     */
    public function requeridos(Baja $baja): Collection
    {
        if ($baja->origen === OrigenBaja::CierrePrevio) {
            return collect();
        }

        return $this->catalogo->documentosRequeridos($baja->forma_salida, $baja->causal);
    }

    /**
     * Nombres de los obligatorios que faltan (RN-05).
     *
     * @return list<string>
     */
    public function faltantes(Baja $baja): array
    {
        return array_values(array_map(
            fn (array $e): string => $e['nombre'],
            array_filter($this->espacios($baja), fn (array $e): bool => $e['obligatorio'] && ! $e['cumplido']),
        ));
    }

    public function completos(Baja $baja): bool
    {
        return $this->faltantes($baja) === [];
    }

    public function adjuntar(Baja $baja, ?DocumentoRequeridoBaja $requerido, UploadedFile $archivo, User $actor): BajaDocumento
    {
        if (in_array($baja->estatus, [EstatusBaja::Cerrada, EstatusBaja::Cancelada], true)) {
            throw ValidationException::withMessages(['archivo' => 'La baja ya está cerrada: no se adjuntan más documentos (RH puede reabrirla con motivo).']);
        }

        if ($requerido !== null && ! $this->requeridos($baja)->contains('id', $requerido->id)) {
            throw ValidationException::withMessages(['documento_requerido_id' => 'Ese documento no corresponde a la forma de salida y causal de esta baja.']);
        }

        $disco = $this->disco();
        $ruta = sprintf('%s/%d/%s.%s', (string) config('bajas.documentos.carpeta', 'bajas'), $baja->id, (string) Str::uuid(), ExtensionArchivo::real($archivo));

        // Sin confirmación del NAS no se registra nada: un renglón que apunta
        // a un archivo que no existe mostraría «ya no está en el NAS».
        try {
            $guardado = $disco->putFileAs(dirname($ruta), $archivo, basename($ruta));
        } catch (Throwable $e) {
            $guardado = false;
            Log::error('BajaDocumentosService: fallo al guardar el adjunto en el almacenamiento.', ['baja_id' => $baja->id, 'error' => $e->getMessage()]);
        }

        if ($guardado === false || ! $disco->exists($ruta)) {
            throw ValidationException::withMessages(['archivo' => 'No se pudo guardar el archivo en el almacenamiento (NAS no disponible). Intenta de nuevo; si continúa, avisa a sistemas.']);
        }

        return DB::transaction(function () use ($baja, $requerido, $archivo, $actor, $ruta): BajaDocumento {
            $documento = BajaDocumento::query()->create([
                'baja_id' => $baja->id,
                'documento_requerido_id' => $requerido?->id,
                'nombre' => Str::limit($requerido->nombre ?? pathinfo($archivo->getClientOriginalName(), PATHINFO_FILENAME), 150, ''),
                'nombre_original' => Str::limit($archivo->getClientOriginalName(), 255, ''),
                'disco' => (string) config('expedientes.disk', 'nas'),
                'ruta' => $ruta,
                'mime' => $archivo->getMimeType(),
                'tamano' => (int) $archivo->getSize(),
                'cargado_por' => $actor->id,
            ]);

            $this->registro->bitacora($baja, $actor, AccionBitacoraBaja::AdjuntoDocumento, 'documento', null, sprintf('%s (%s)', $documento->nombre, $documento->nombre_original));

            return $documento;
        });
    }

    /**
     * Retira un adjunto de la baja (no de una cerrada). El archivo físico se
     * conserva y la bitácora registra quién y cuándo.
     */
    public function quitar(BajaDocumento $documento, User $actor): void
    {
        $baja = $documento->baja;

        if (in_array($baja->estatus, [EstatusBaja::Cerrada, EstatusBaja::Cancelada], true)) {
            throw ValidationException::withMessages(['documento' => 'La baja ya está cerrada: sus documentos no se modifican.']);
        }

        DB::transaction(function () use ($documento, $baja, $actor): void {
            $documento->update(['quitado_por' => $actor->id]);
            $documento->delete();
            $this->registro->bitacora($baja, $actor, AccionBitacoraBaja::QuitoDocumento, 'documento', sprintf('%s (%s)', $documento->nombre, $documento->nombre_original), null);
        });
    }

    public function respuesta(BajaDocumento $documento, bool $descargar): StreamedResponse
    {
        $disco = $this->disco($documento->disco);
        abort_unless($disco->exists($documento->ruta), 404, 'El archivo ya no está en el NAS.');
        $nombre = str_replace('"', '', $documento->nombre_original);

        return $disco->response($documento->ruta, $nombre, [
            'Content-Type' => $documento->mime ?? 'application/octet-stream',
            'Content-Disposition' => sprintf('%s; filename="%s"', $descargar ? 'attachment' : 'inline', $nombre),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function archivoArray(BajaDocumento $documento): array
    {
        return [
            'id' => $documento->id,
            'nombre' => $documento->nombre,
            'nombre_original' => $documento->nombre_original,
            'mime' => $documento->mime,
            'tamano' => $documento->tamano,
            'subido_por' => $documento->cargadoPor?->name,
            'fecha' => $documento->created_at?->toDateTimeString(),
            'url' => route('rh.bajas.documentos.ver', [$documento->baja_id, $documento->id]),
            'url_descarga' => route('rh.bajas.documentos.ver', [$documento->baja_id, $documento->id, 'descargar' => 1]),
        ];
    }

    /**
     * Texto de lo que ya cumple el espacio en el cierre laboral, o null.
     */
    private function cumplidoPorSistema(Baja $baja, string $clave): ?string
    {
        $cierre = $baja->cierre;

        if ($cierre === null) {
            return null;
        }

        if ($clave === 'finiquito') {
            $finiquito = $cierre->solicitud_interna_id !== null ? FiniquitoCalculo::query()->where('solicitud_interna_id', $cierre->solicitud_interna_id)->first() : null;

            return $finiquito !== null && (in_array($finiquito->estado, [EstadoFiniquito::Firmado, EstadoFiniquito::Pagado], true) || $finiquito->pagado_en !== null)
                ? 'Finiquito entregado (registrado en Bajas y finiquitos).'
                : null;
        }

        $documento = $this->documentosProceso->documentoVigente($cierre, $clave);

        return $documento !== null && ($documento->estado_flujo?->firmaFisicaRegistrada() ?? false)
            ? sprintf('%s generado por el sistema y marcado como firmado físicamente.', $this->documentosProceso->nombreDocumento($clave))
            : null;
    }

    private function disco(?string $nombre = null): FilesystemAdapter
    {
        return Storage::disk($nombre ?? (string) config('expedientes.disk', 'nas'));
    }
}
