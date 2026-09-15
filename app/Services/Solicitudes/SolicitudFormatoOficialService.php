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
     * principal).
     */
    public function generarSiAplica(SolicitudInterna $solicitud, User $actor): ?OfficialFormatGeneration
    {
        $existente = OfficialFormatGeneration::query()
            ->where('solicitud_interna_id', $solicitud->id)
            ->first();

        if ($existente !== null) {
            return $existente;
        }

        $formato = $this->formatoEsperado($solicitud);

        if ($formato === null || ! $formato->is_active || ! $formato->tieneConfiguracion()) {
            if ($solicitud->tipo->formatoOficialSlug() !== null) {
                Log::warning('Documento oficial no generado: formato no configurado.', [
                    'solicitud_id' => $solicitud->id,
                    'slug' => $solicitud->tipo->formatoOficialSlug(),
                ]);
            }

            return null;
        }

        try {
            $solicitud->loadMissing('usuario');
            $datos = $this->resolver->resolver($solicitud->usuario, $this->extraDeSolicitud($solicitud));
            $pdf = $this->overlay->generar($formato, $datos);

            $ruta = $this->storage->rutaGenerado();
            $this->storage->guardarContenido($ruta, $pdf);

            return OfficialFormatGeneration::create([
                'official_format_id' => $formato->id,
                'solicitud_interna_id' => $solicitud->id,
                'user_id' => $solicitud->user_id,
                'generated_by_id' => $actor->id,
                'generated_disk' => config('formatos_oficiales.disk'),
                'generated_path' => $ruta,
                'generated_name' => str($formato->nombre)->slug().'-'.$solicitud->folio.'.pdf',
                'data_snapshot' => $datos,
                'status' => EstadoFormatoOficialGeneracion::Generado,
            ]);
        } catch (Throwable $e) {
            Log::warning('No fue posible generar el documento oficial automático de una solicitud.', [
                'solicitud_id' => $solicitud->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
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
        $generacion->loadMissing('solicitud', 'usuario');
        $solicitud = $generacion->solicitud;
        $colaborador = $generacion->usuario;

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
