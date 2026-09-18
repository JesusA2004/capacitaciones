<?php

namespace App\Services\Solicitudes;

use App\Enums\EstadoFormatoOficialGeneracion;
use App\Models\DocumentType;
use App\Models\OfficialFormat;
use App\Models\OfficialFormatGeneration;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Services\Expedientes\DocumentoStorageService;
use App\Services\Formatos\OfficialFormatOverlayService;
use App\Services\Formatos\OfficialFormatStorageService;
use App\Services\Plantillas\PlaceholderResolver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use League\Flysystem\FilesystemException;
use Throwable;

/**
 * Documento oficial automático de una solicitud interna (config/solicitudes.php):
 * distinto de App\Services\Plantillas\PlantillaDocumentoService (plantillas
 * DOCX manuales/opcionales, ver GeneratedDocument). Ver docs/FORMATOS_OFICIALES.md.
 */
class SolicitudFormatoOficialService
{
    public function __construct(
        private readonly OfficialFormatOverlayService $overlay,
        private readonly OfficialFormatStorageService $storage,
        private readonly PlaceholderResolver $resolver,
        private readonly DocumentoStorageService $expedienteStorage,
    ) {}

    /**
     * true si el tipo de esta solicitud tiene un formato oficial mapeado en
     * config/solicitudes.php, sin importar si ese OfficialFormat ya está
     * configurado en el sistema. Usado por la UI para decidir si mostrar la
     * sección "Documento oficial" o no mostrar nada (sección 7 del encargo).
     */
    public function aplicaPara(SolicitudInterna $solicitud): bool
    {
        return $solicitud->tipo->formatoOficialSlug() !== null;
    }

    /**
     * El OfficialFormat configurado (o no) para el tipo de esta solicitud,
     * o null si el tipo no tiene formato mapeado. Se usa para poder avisar a
     * RH "requiere configurar «X»" en vez de fallar en silencio (sección 8).
     */
    public function formatoEsperado(SolicitudInterna $solicitud): ?OfficialFormat
    {
        $slug = $solicitud->tipo->formatoOficialSlug();

        if ($slug === null) {
            return null;
        }

        return OfficialFormat::query()->where('slug', $slug)->first();
    }

    /**
     * Genera (una sola vez, idempotente) el documento oficial automático al
     * aprobar la solicitud. Nunca lanza: si el tipo no tiene formato
     * mapeado, si el formato no existe/no está configurado, o si la
     * generación falla por cualquier razón, se registra un warning y se
     * continúa — la aprobación de la solicitud NUNCA debe tronar por esto
     * (ver CLAUDE.md: un fallo de una acción secundaria no deshace la
     * principal). A diferencia de un fallo de notificación, este resultado
     * SÍ debe mostrarse explícitamente a RH (no solo quedar en el log) — ver
     * App\Services\Solicitudes\SolicitudesService::ultimoResultadoDocumentoOficial().
     *
     * @return array{generacion: ?OfficialFormatGeneration, aplica: bool, motivo_error: ?string}
     */
    public function generarSiAplica(SolicitudInterna $solicitud, User $actor): array
    {
        $existente = OfficialFormatGeneration::query()
            ->where('solicitud_interna_id', $solicitud->id)
            ->first();

        if ($existente !== null) {
            return ['generacion' => $existente, 'aplica' => true, 'motivo_error' => null];
        }

        $formato = $this->formatoEsperado($solicitud);

        if ($formato === null || ! $formato->is_active || ! $formato->tieneConfiguracion()) {
            if ($solicitud->tipo->formatoOficialSlug() !== null) {
                Log::warning('Documento oficial no generado: formato no configurado.', [
                    'solicitud_id' => $solicitud->id,
                    'slug' => $solicitud->tipo->formatoOficialSlug(),
                ]);

                return [
                    'generacion' => null,
                    'aplica' => true,
                    'motivo_error' => 'no hay un formato oficial configurado para este tipo de solicitud',
                ];
            }

            return ['generacion' => null, 'aplica' => false, 'motivo_error' => null];
        }

        try {
            $solicitud->loadMissing(['colaborador', 'usuario.colaborador']);
            $datos = $this->resolver->resolver($solicitud->personaSolicitante(), $this->extraDeSolicitud($solicitud));
            $pdf = $this->overlay->generar($formato, $datos);

            $ruta = $this->storage->rutaGenerado();
            $this->storage->guardarContenido($ruta, $pdf);

            $generacion = OfficialFormatGeneration::create([
                'official_format_id' => $formato->id,
                'solicitud_interna_id' => $solicitud->id,
                'colaborador_id' => $solicitud->personaSolicitante()?->id,
                'generated_by_id' => $actor->id,
                'generated_disk' => config('formatos_oficiales.disk'),
                'generated_path' => $ruta,
                'generated_name' => str($formato->nombre)->slug().'-'.$solicitud->folio.'.pdf',
                'data_snapshot' => $datos,
                'status' => EstadoFormatoOficialGeneracion::Generado,
            ]);

            return ['generacion' => $generacion, 'aplica' => true, 'motivo_error' => null];
        } catch (Throwable $e) {
            Log::warning('No fue posible generar el documento oficial automático de una solicitud.', [
                'solicitud_id' => $solicitud->id,
                'error' => $e->getMessage(),
            ]);

            return ['generacion' => null, 'aplica' => true, 'motivo_error' => $this->motivoLegible($e)];
        }
    }

    /**
     * Traduce la excepción capturada a un mensaje de negocio entendible por
     * RH — nunca el mensaje crudo (puede traer rutas de servidor, nombres de
     * clase o detalle técnico que no le sirve a quien aprueba la solicitud).
     */
    private function motivoLegible(Throwable $e): string
    {
        if ($e instanceof FilesystemException) {
            return 'no se pudo conectar al almacenamiento';
        }

        return 'ocurrió un error inesperado al generar el documento; revisa el formato oficial configurado o contacta a sistemas';
    }

    /**
     * Archiva el PDF firmado subido por RH: actualiza la generación a
     * "firmado" y, si el tipo tiene un DocumentType de expediente mapeado
     * (config/solicitudes.php), lo sube también como nueva versión del
     * documento correspondiente en el expediente del colaborador.
     */
    public function archivarFirmado(OfficialFormatGeneration $generacion, UploadedFile $archivo, User $actor): OfficialFormatGeneration
    {
        if ($generacion->status === EstadoFormatoOficialGeneracion::Firmado) {
            throw ValidationException::withMessages([
                'archivo' => 'Este documento ya tiene un firmado archivado.',
            ]);
        }

        $nombreInterno = 'firmado-'.$generacion->id.'.'.$archivo->getClientOriginalExtension();
        $ruta = 'formatos-oficiales/firmados/'.$nombreInterno;
        $this->storage->guardarContenido($ruta, (string) file_get_contents($archivo->getRealPath()));

        $generacion->update([
            'status' => EstadoFormatoOficialGeneracion::Firmado,
            'signed_disk' => config('formatos_oficiales.disk'),
            'signed_path' => $ruta,
            'signed_name' => $archivo->getClientOriginalName(),
            'signed_uploaded_by' => $actor->id,
            'signed_uploaded_at' => now(),
        ]);

        $this->archivarEnExpedienteSiAplica($generacion, $archivo, $actor);

        return $generacion->refresh();
    }

    private function archivarEnExpedienteSiAplica(OfficialFormatGeneration $generacion, UploadedFile $archivo, User $actor): void
    {
        $generacion->loadMissing(['solicitud.colaborador', 'solicitud.usuario.colaborador', 'colaborador']);
        $solicitud = $generacion->solicitud;
        $colaborador = $generacion->colaborador ?? $solicitud?->personaSolicitante();

        if ($solicitud === null || $colaborador === null) {
            return;
        }

        $clave = config("solicitudes.formatos.{$solicitud->tipo->value}.documento_expediente_clave");

        if ($clave === null) {
            return;
        }

        $tipo = DocumentType::query()->where('clave', $clave)->where('activo', true)->first();

        if ($tipo === null) {
            Log::warning('DocumentType de expediente no encontrado para archivar formato firmado.', [
                'clave' => $clave,
                'solicitud_id' => $solicitud->id,
            ]);

            return;
        }

        $this->expedienteStorage->subirVersion($colaborador, $tipo, $archivo, $actor->id);
    }

    /**
     * @return array<string, string>
     */
    private function extraDeSolicitud(SolicitudInterna $solicitud): array
    {
        return [
            'folio_solicitud' => $solicitud->folio,
            'tipo_solicitud' => $solicitud->tipo->etiqueta(),
            'motivo_solicitud' => $solicitud->motivo,
            'observaciones' => (string) $solicitud->observaciones,
            'dias_vacaciones' => $solicitud->dias_solicitados !== null ? (string) $solicitud->dias_solicitados : '',
            'fecha_inicio_permiso' => $solicitud->fecha_inicio?->format('d/m/Y') ?? '',
            'fecha_fin_permiso' => $solicitud->fecha_fin?->format('d/m/Y') ?? '',
            'motivo_permiso' => $solicitud->motivo,
        ];
    }
}
