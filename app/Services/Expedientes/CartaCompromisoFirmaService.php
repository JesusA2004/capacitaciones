<?php

namespace App\Services\Expedientes;

use App\Enums\CategoriaDocumento;
use App\Enums\EstadoCartaCompromiso;
use App\Enums\EstadoDocumento;
use App\Enums\EstadoFlujoDocumento;
use App\Models\CartaCompromiso;
use App\Models\DocumentType;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use App\Services\DocumentosLaborales\FlujoDocumentalService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * «Subir carta firmada»: quien entra firma la carta impresa (con su huella)
 * y el gerente o RH sube el escaneo — mismo patrón que «Subir contrato
 * firmado» (ContratoFirmadoService). El escaneo entra al expediente como
 * documento APROBADO del tipo `carta_compromiso_firmada` (no es un
 * documento exigible: no cuenta para pendientes ni bloqueo), la carta pasa
 * de «pendiente de firma» a «firmada» y el PDF generado avanza en su flujo
 * documental (impreso → firmado físicamente) para no dejar tareas abiertas.
 *
 * Quién puede subirlo lo decide CartaCompromisoPolicy::subirFirmada
 * (permiso cartas_compromiso.subir_firmada + alcance del módulo).
 */
class CartaCompromisoFirmaService
{
    public const CLAVE_TIPO = 'carta_compromiso_firmada';

    public function __construct(
        private readonly DocumentoStorageService $storage,
        private readonly AuditoriaService $auditoria,
        private readonly FlujoDocumentalService $flujo,
    ) {}

    /**
     * @throws ValidationException
     */
    public function subir(CartaCompromiso $carta, UploadedFile $archivo, User $actor): CartaCompromiso
    {
        $colaborador = $carta->colaborador()->withTrashed()->first();

        if ($colaborador === null) {
            throw ValidationException::withMessages(['archivo' => 'La carta no tiene una persona asociada.']);
        }

        $carta = DB::transaction(function () use ($carta, $colaborador, $archivo, $actor): CartaCompromiso {
            $bloqueada = CartaCompromiso::query()->whereKey($carta->id)->lockForUpdate()->firstOrFail();

            if ($bloqueada->estado === EstadoCartaCompromiso::Sustituida) {
                throw ValidationException::withMessages(['archivo' => 'Esta carta fue sustituida por una nueva: sube la firma de la carta vigente.']);
            }

            if ($bloqueada->firmada()) {
                throw ValidationException::withMessages(['archivo' => 'Esta carta ya tiene su escaneo firmado.']);
            }

            $documento = $this->storage->subirVersion($colaborador, $this->tipo(), $archivo, $actor->id, EstadoDocumento::Aprobado);

            $bloqueada->update([
                'firmada_en' => now(),
                'firma_registrada_por' => $actor->id,
                'firmada_document_id' => $documento->id,
            ]);

            $this->auditoria->registrar('carta_compromiso_firmada', $bloqueada, $actor, [
                'colaborador_id' => $colaborador->id,
                'folio' => $bloqueada->folio,
                'employee_document_id' => $documento->id,
            ]);

            return $bloqueada;
        });

        $this->cerrarFlujoDocumental($carta, $actor);

        return $carta->refresh();
    }

    /**
     * El PDF generado (GeneratedDocument) pide impresión, firma física y
     * huella: con el escaneo firmado esos pasos ya ocurrieron. Nunca rompe la
     * subida, que ya quedó guardada.
     */
    private function cerrarFlujoDocumental(CartaCompromiso $carta, User $actor): void
    {
        try {
            $documento = $carta->documentoGenerado;

            if ($documento === null) {
                return;
            }

            if (in_array($documento->estado_flujo, [EstadoFlujoDocumento::PendienteImpresion, EstadoFlujoDocumento::Generado, EstadoFlujoDocumento::FirmadoDigitalmente], true)) {
                $documento = $this->flujo->marcarImpreso($documento, $actor, 'Impresa para la firma de la carta compromiso.');
            }

            if (in_array($documento->estado_flujo, [EstadoFlujoDocumento::PendienteFirmaFisica, EstadoFlujoDocumento::Impreso], true)) {
                $this->flujo->registrarFirmaFisica($documento, $actor, [
                    'huella_registrada' => true,
                    'observaciones' => 'Escaneo firmado subido en Cartas compromiso.',
                ]);
            }
        } catch (Throwable $e) {
            Log::warning('CartaCompromiso: no se pudo cerrar el flujo documental del PDF tras subir la firmada.', ['carta_id' => $carta->id, 'error' => $e->getMessage()]);
        }
    }

    private function tipo(): DocumentType
    {
        return DocumentType::query()->firstOrCreate(
            ['clave' => self::CLAVE_TIPO],
            [
                'nombre' => 'Carta compromiso firmada',
                'categoria' => CategoriaDocumento::Personales->value,
                'requerido' => false,
                'aplica_alta' => false,
                'activo' => true,
            ],
        );
    }
}
