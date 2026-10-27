<?php

namespace App\Services\Expedientes;

use App\Enums\EstadoDocumento;
use App\Models\Colaborador;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Documentos del checklist que pueden venir en UN MISMO archivo
 * (config('expedientes.pares_mismo_archivo')): hoy, las dos cartas de
 * recomendación. Cada una es su propio tipo de documento — se sube, se
 * aprueba y se rechaza por separado —, pero si la persona trae las dos en
 * un solo archivo, al subirlo marca «trae también la otra carta» y aquí se
 * registra una COPIA física en el par (nunca dos filas apuntando al mismo
 * archivo: mover o reorganizar una rompería la otra). RH revisa cada carta
 * por su lado: puede aprobar las dos o rechazar solo una.
 *
 * Misma regla para la web de RH (Rh\EmployeeDocumentController) y la app
 * del colaborador (IncorporacionService::subirDocumento).
 */
class DocumentoParService
{
    /**
     * Estados del documento vigente del par en los que puede recibir el
     * archivo: igual que la subida directa del colaborador (sin archivo,
     * rechazado, requiere corrección, vencido o cambio autorizado). Uno en
     * revisión o aprobado nunca se pisa por arrastre.
     */
    private const ESTADOS_PAR_RECIBE = [
        EstadoDocumento::Rechazado,
        EstadoDocumento::RequiereCorreccion,
        EstadoDocumento::Vencido,
        EstadoDocumento::CambioAutorizado,
    ];

    /** @var array<string, DocumentType|null> */
    private array $tiposPorClave = [];

    public function __construct(
        private readonly DocumentoStorageService $storage,
        private readonly ExpedienteService $expediente,
    ) {}

    /** El tipo par de `$tipo` (activo), o null si no tiene. */
    public function par(DocumentType $tipo): ?DocumentType
    {
        $clavePar = config(sprintf('expedientes.pares_mismo_archivo.%s', $tipo->clave));

        if (! is_string($clavePar) || $clavePar === '') {
            return null;
        }

        if (! array_key_exists($clavePar, $this->tiposPorClave)) {
            $this->tiposPorClave[$clavePar] = DocumentType::query()->where('clave', $clavePar)->where('activo', true)->first();
        }

        return $this->tiposPorClave[$clavePar];
    }

    public function parPuedeRecibir(?EmployeeDocument $vigentePar): bool
    {
        return $vigentePar === null || in_array($vigentePar->status, self::ESTADOS_PAR_RECIBE, true);
    }

    /**
     * Lo que la web y la app necesitan para ofrecer «este archivo trae
     * también…»: el par y si hoy puede recibir el archivo.
     *
     * @param  Collection<int, EmployeeDocument>  $vigentes  documento vigente por document_type_id
     * @return array{id: int, nombre: string, puede_subir: bool}|null
     */
    public function resumenPar(DocumentType $tipo, Collection $vigentes): ?array
    {
        $par = $this->par($tipo);

        if ($par === null) {
            return null;
        }

        return [
            'id' => $par->id,
            'nombre' => $par->nombre,
            'puede_subir' => $this->parPuedeRecibir($vigentes->get($par->id)),
        ];
    }

    /**
     * Valida ANTES de subir nada que el par exista y pueda recibir el
     * archivo: así nunca queda una carta subida y la otra a medias.
     *
     * @throws RuntimeException
     */
    public function validarPar(Colaborador $colaborador, DocumentType $tipo): DocumentType
    {
        $par = $this->par($tipo);

        if ($par === null) {
            throw new RuntimeException(sprintf('«%s» no tiene un documento par que pueda venir en el mismo archivo.', $tipo->nombre));
        }

        if (! $this->parPuedeRecibir($this->expediente->documentosVigentes($colaborador)->get($par->id))) {
            throw new RuntimeException(sprintf('«%s» ya está en revisión o aprobada; sube este archivo solo como «%s».', $par->nombre, $tipo->nombre));
        }

        return $par;
    }

    /**
     * Sube `$archivo` como nueva versión de `$tipo` y, si `$incluyePar`,
     * también de su par. El par se valida ANTES de guardar nada.
     *
     * @return array{documento: EmployeeDocument, par: EmployeeDocument|null}
     *
     * @throws RuntimeException si se pidió el par y no puede recibirlo
     */
    public function subir(Colaborador $colaborador, DocumentType $tipo, UploadedFile $archivo, int $subidoPorId, bool $incluyePar): array
    {
        $par = $incluyePar ? $this->validarPar($colaborador, $tipo) : null;
        $documento = $this->storage->subirVersion($colaborador, $tipo, $archivo, $subidoPorId);

        return [
            'documento' => $documento,
            'par' => $par !== null ? $this->subirAlPar($colaborador, $tipo, $par, $archivo, $subidoPorId) : null,
        ];
    }

    /**
     * Registra el MISMO archivo como nueva versión del par (copia física,
     * en revisión), anotando de dónde salió.
     */
    public function subirAlPar(Colaborador $colaborador, DocumentType $tipo, DocumentType $par, UploadedFile $archivo, int $subidoPorId): EmployeeDocument
    {
        $documento = $this->storage->subirVersion($colaborador, $par, $archivo, $subidoPorId);
        $documento->update(['comments' => sprintf('Mismo archivo que «%s».', $tipo->nombre)]);

        return $documento;
    }

    /**
     * Separación de los expedientes que se cargaron cuando las dos cartas
     * se pedían en un solo archivo: por cada documento vigente de
     * `$claveOrigen` sin ningún documento de `$claveDestino`, copia el
     * archivo al par con el MISMO estado (RH ya lo revisó como «las dos
     * cartas»). Idempotente: quien ya tiene documento del par se omite.
     * Cada falla se reporta (auditable), nunca se ignora en silencio.
     *
     * @return array{copiados: int, omitidos: int, errores: list<string>}
     */
    public function separarArchivosCompartidos(string $claveOrigen, string $claveDestino, string $nota): array
    {
        $origen = DocumentType::query()->where('clave', $claveOrigen)->first();
        $destino = DocumentType::query()->where('clave', $claveDestino)->first();
        $resultado = ['copiados' => 0, 'omitidos' => 0, 'errores' => []];

        if ($origen === null || $destino === null) {
            $resultado['errores'][] = sprintf('No existen los tipos «%s» y «%s».', $claveOrigen, $claveDestino);

            return $resultado;
        }

        $vigentes = EmployeeDocument::query()
            ->where('document_type_id', $origen->id)
            ->whereNotNull('colaborador_id')
            ->where('status', '!=', EstadoDocumento::Archivado->value)
            ->orderByDesc('version')
            ->get()
            ->unique('colaborador_id');

        foreach ($vigentes as $documento) {
            $yaTienePar = EmployeeDocument::withTrashed()
                ->where('colaborador_id', $documento->colaborador_id)
                ->where('document_type_id', $destino->id)
                ->exists();

            if ($yaTienePar) {
                $resultado['omitidos']++;

                continue;
            }

            $disco = Storage::disk($documento->disk);
            $ruta = sprintf('%s/%s', dirname($documento->path), $this->storage->nombreDocumento($destino, 1, $documento->extension));

            try {
                if (! $disco->exists($documento->path)) {
                    throw new RuntimeException(sprintf('no existe el archivo «%s»', $documento->path));
                }

                if ($disco->exists($ruta)) {
                    throw new RuntimeException(sprintf('ya existe «%s»; no se sobrescribe', $ruta));
                }

                $disco->copy($documento->path, $ruta);
            } catch (Throwable $e) {
                $resultado['errores'][] = sprintf('Documento %d (colaborador %d): %s', $documento->id, (int) $documento->colaborador_id, $e->getMessage());

                continue;
            }

            try {
                $copia = new EmployeeDocument([
                    ...$documento->only(['user_id', 'colaborador_id', 'empresa_id', 'sucursal_id', 'disk', 'original_name', 'mime', 'extension', 'size', 'hash', 'status', 'origen', 'uploaded_by', 'reviewed_by', 'reviewed_at', 'rejection_reason']),
                    'document_type_id' => $destino->id,
                    'path' => $ruta,
                    'stored_name' => basename($ruta),
                    'version' => 1,
                    'previous_version_id' => null,
                    'comments' => $nota,
                ]);
                // Conserva la fecha real de carga (la ve el colaborador).
                $copia->created_at = $documento->created_at;
                $copia->save();
                $resultado['copiados']++;
            } catch (Throwable $e) {
                $disco->delete($ruta);
                $resultado['errores'][] = sprintf('Documento %d (colaborador %d): %s', $documento->id, (int) $documento->colaborador_id, $e->getMessage());
            }
        }

        return $resultado;
    }
}
