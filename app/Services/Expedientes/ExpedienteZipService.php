<?php

namespace App\Services\Expedientes;

use App\Enums\CategoriaDocumento;
use App\Enums\EstadoFlujoDocumento;
use App\Models\Colaborador;
use App\Models\EmployeeDocument;
use App\Models\GeneratedDocument;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * «Descargar expediente completo»: arma un ZIP temporal con todo el
 * expediente de un colaborador para abrirlo en una computadora.
 *
 *   {numero} - {Nombre}/
 *     01 Personales/{Tipo} ({estado}).pdf         ← versión vigente
 *     Historial de versiones/{Tipo} - v{n} ({estado}).pdf
 *     Documentos generados/{Título} ({estado}) - {fecha}.pdf
 *     Foto de perfil.jpg
 *     INDICE.csv                                  ← UTF-8 con BOM (Excel)
 *
 * El índice lista TODOS los tipos requeridos (los faltantes como «No
 * entregado»). Un archivo que no se puede leer del NAS nunca se omite en
 * silencio ni tumba el ZIP: queda en el índice como «No se pudo leer del
 * almacenamiento» y se registra con Log::warning.
 *
 * La autorización (EmployeeDocumentPolicy::descargarExpediente) la hace el
 * controlador antes de llegar aquí.
 */
class ExpedienteZipService
{
    public const NO_ENTREGADO = 'No entregado';

    public const NO_LEIDO = 'No se pudo leer del almacenamiento';

    private const ENCABEZADOS_INDICE = ['Sección', 'Documento', 'Categoría', 'Requerido', 'Estado', 'Versión', 'Fecha de carga', 'Revisado por', 'Archivo en zip'];

    /** @var list<string> */
    private array $temporales = [];

    /** @var array<string, true> */
    private array $nombresUsados = [];

    public function __construct(
        private readonly DocumentoStorageService $storage,
        private readonly ExpedienteService $expediente,
        private readonly AuditoriaService $auditoria,
    ) {}

    /**
     * Nombre con el que el navegador guarda el ZIP.
     */
    public function nombreDescarga(Colaborador $colaborador): string
    {
        return sprintf('Expediente %s.zip', $this->carpetaRaiz($colaborador));
    }

    /**
     * Construye el ZIP y devuelve su ruta local (el llamador lo borra
     * después de enviarlo, p. ej. con deleteFileAfterSend()).
     */
    public function generar(Colaborador $colaborador, User $usuario): string
    {
        // $this->temporales siempre queda vacío al salir (bloque finally).
        $this->nombresUsados = [];

        $directorio = storage_path('app/tmp/expedientes-zip');
        File::ensureDirectoryExists($directorio);

        $rutaZip = sprintf('%s/%s.zip', $directorio, Str::uuid()->toString());
        $zip = new ZipArchive;

        if ($zip->open($rutaZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo crear el archivo ZIP del expediente.');
        }

        $raiz = $this->carpetaRaiz($colaborador);
        $filas = [];
        $noLeidos = 0;
        $incluidos = 0;

        try {
            $vigentes = $this->expediente->documentosVigentes($colaborador);
            $requeridos = $this->expediente->tiposRequeridosPara($colaborador);
            $idsRequeridos = $requeridos->pluck('id')->all();

            // 1) Tipos requeridos: el vigente o «No entregado».
            foreach ($requeridos as $tipo) {
                $documento = $vigentes->get($tipo->id);

                if ($documento === null) {
                    $filas[] = ['Vigente', $tipo->nombre, $tipo->categoria->etiqueta(), 'Sí', self::NO_ENTREGADO, '', '', '', ''];

                    continue;
                }

                $filas[] = $this->agregarVigente($zip, $raiz, $documento, true, $incluidos, $noLeidos);
            }

            // 2) Vigentes de tipos no requeridos (o requeridos ya inactivos).
            foreach ($vigentes as $idTipo => $documento) {
                if (! in_array($idTipo, $idsRequeridos, true)) {
                    $filas[] = $this->agregarVigente($zip, $raiz, $documento, false, $incluidos, $noLeidos);
                }
            }

            // 3) Versiones anteriores/archivadas.
            $idsVigentes = $vigentes->pluck('id')->all();
            $historial = EmployeeDocument::query()
                ->where('colaborador_id', $colaborador->id)
                ->whereNotIn('id', $idsVigentes)
                ->with(['tipo', 'revisadoPor:id,name,apellidos'])
                ->orderBy('document_type_id')
                ->orderBy('version')
                ->get();

            foreach ($historial as $documento) {
                $tipo = $documento->tipo;
                $nombreTipo = $tipo !== null ? $tipo->nombre : 'Documento';
                $nombre = sprintf('%s - v%d (%s)%s', $nombreTipo, $documento->version, $documento->status->etiqueta(), $this->extension($documento->extension, $documento->original_name, $documento->mime));
                $destino = $this->agregarArchivo($zip, sprintf('%s/Historial de versiones', $raiz), $nombre, $documento->path, null, ['employee_document_id' => $documento->id]);
                $destino === null ? $noLeidos++ : $incluidos++;

                $filas[] = [
                    'Historial de versiones',
                    $nombreTipo,
                    $tipo !== null ? $tipo->categoria->etiqueta() : '',
                    $tipo !== null && in_array($tipo->id, $idsRequeridos, true) ? 'Sí' : 'No',
                    $documento->status->etiqueta(),
                    sprintf('v%d', $documento->version),
                    $documento->created_at?->format('d/m/Y H:i') ?? '',
                    $this->nombreUsuario($documento->revisadoPor),
                    $destino ?? self::NO_LEIDO,
                ];
            }

            // 4) Documentos generados (contratos, responsivas, actas...).
            foreach ($this->generados($colaborador, $usuario) as $generado) {
                $estado = $generado->estado_flujo !== null ? $generado->estado_flujo->etiqueta() : $generado->status->etiqueta();
                $titulo = $generado->titulo ?? pathinfo($generado->original_name, PATHINFO_FILENAME);
                $fecha = $generado->created_at?->format('Y-m-d') ?? '';
                $nombre = sprintf('%s (%s)%s%s', $titulo, $estado, $fecha !== '' ? ' - '.$fecha : '', $this->extension(null, $generado->original_name, $generado->mime));

                try {
                    $disco = Storage::disk($generado->disk);
                } catch (Throwable $e) {
                    $disco = null;
                    Log::warning('ExpedienteZipService: disco inválido de documento generado.', ['generated_document_id' => $generado->id, 'error' => $e->getMessage()]);
                }

                $destino = $disco !== null
                    ? $this->agregarArchivo($zip, sprintf('%s/Documentos generados', $raiz), $nombre, $generado->path, $disco, ['generated_document_id' => $generado->id])
                    : null;
                $destino === null ? $noLeidos++ : $incluidos++;

                $filas[] = [
                    'Documentos generados',
                    $titulo,
                    $generado->categoria !== null ? $generado->categoria->etiqueta() : '',
                    'No',
                    $estado,
                    '',
                    $generado->created_at?->format('d/m/Y H:i') ?? '',
                    '',
                    $destino ?? self::NO_LEIDO,
                ];
            }

            // 5) Foto de perfil.
            if ($colaborador->foto_path !== null && $colaborador->foto_path !== '') {
                $nombre = $this->storage->nombreFoto(pathinfo($colaborador->foto_path, PATHINFO_EXTENSION));
                $destino = $this->agregarArchivo($zip, $raiz, $nombre, $colaborador->foto_path, null, ['foto' => true]);
                $destino === null ? $noLeidos++ : $incluidos++;
                $filas[] = ['Foto de perfil', 'Foto de perfil', '', 'No', '', '', '', '', $destino ?? self::NO_LEIDO];
            }

            $zip->addFromString(sprintf('%s/INDICE.csv', $raiz), $this->indiceCsv($filas));

            if (! $zip->close()) {
                throw new RuntimeException('No se pudo cerrar el archivo ZIP del expediente.');
            }
        } catch (Throwable $e) {
            @unlink($rutaZip);

            throw $e;
        } finally {
            foreach ($this->temporales as $temporal) {
                @unlink($temporal);
            }
            $this->temporales = [];
        }

        $this->auditoria->registrar('expediente_descargado_zip', $colaborador, $usuario, [
            'archivos_incluidos' => $incluidos,
            'archivos_no_leidos' => $noLeidos,
        ]);

        return $rutaZip;
    }

    /**
     * @return list<string>
     */
    private function agregarVigente(ZipArchive $zip, string $raiz, EmployeeDocument $documento, bool $requerido, int &$incluidos, int &$noLeidos): array
    {
        $tipo = $documento->tipo;
        $nombreTipo = $tipo !== null ? $tipo->nombre : 'Documento';
        $categoria = $tipo !== null ? $tipo->categoria : CategoriaDocumento::Otros;
        $nombre = sprintf('%s (%s)%s', $nombreTipo, $documento->status->etiqueta(), $this->extension($documento->extension, $documento->original_name, $documento->mime));
        $destino = $this->agregarArchivo($zip, sprintf('%s/%s', $raiz, $this->carpetaCategoria($categoria)), $nombre, $documento->path, null, ['employee_document_id' => $documento->id]);
        $destino === null ? $noLeidos++ : $incluidos++;

        return [
            'Vigente',
            $nombreTipo,
            $categoria->etiqueta(),
            $requerido ? 'Sí' : 'No',
            $documento->status->etiqueta(),
            sprintf('v%d', $documento->version),
            $documento->created_at?->format('d/m/Y H:i') ?? '',
            $this->nombreUsuario($documento->revisadoPor),
            $destino ?? self::NO_LEIDO,
        ];
    }

    /**
     * Copia el archivo a un temporal local y lo agrega al ZIP. Devuelve la
     * ruta dentro del ZIP (sin la carpeta raíz) o null si no se pudo leer.
     *
     * @param  array<string, mixed>  $contexto
     */
    private function agregarArchivo(ZipArchive $zip, string $carpeta, string $nombre, string $ruta, ?Filesystem $disco, array $contexto): ?string
    {
        $temporal = tempnam(storage_path('app/tmp/expedientes-zip'), 'doc-');

        if ($temporal === false) {
            Log::warning('ExpedienteZipService: no se pudo crear un archivo temporal.', $contexto);

            return null;
        }

        $this->temporales[] = $temporal;

        try {
            $this->storage->copiarALocal($ruta, $temporal, $disco);
        } catch (Throwable $e) {
            Log::warning('ExpedienteZipService: no se pudo leer un archivo del almacenamiento.', $contexto + ['error' => $e->getMessage()]);

            return null;
        }

        $entrada = $this->nombreUnico(sprintf('%s/%s', $carpeta, $this->sanitizar($nombre)));

        if (! $zip->addFile($temporal, $entrada)) {
            Log::warning('ExpedienteZipService: no se pudo agregar el archivo al ZIP.', $contexto);

            return null;
        }

        return Str::after($entrada, '/');
    }

    /**
     * Evita que dos archivos con el mismo nombre se pisen dentro del ZIP:
     * «Nombre (2).pdf», «Nombre (3).pdf»...
     */
    private function nombreUnico(string $entrada): string
    {
        $candidato = $entrada;
        $base = pathinfo($entrada, PATHINFO_EXTENSION) !== '' ? Str::beforeLast($entrada, '.') : $entrada;
        $extension = pathinfo($entrada, PATHINFO_EXTENSION) !== '' ? '.'.pathinfo($entrada, PATHINFO_EXTENSION) : '';
        $n = 2;

        while (isset($this->nombresUsados[mb_strtolower($candidato)])) {
            $candidato = sprintf('%s (%d)%s', $base, $n++, $extension);
        }

        $this->nombresUsados[mb_strtolower($candidato)] = true;

        return $candidato;
    }

    /**
     * @return iterable<GeneratedDocument>
     */
    private function generados(Colaborador $colaborador, User $usuario): iterable
    {
        $idCuenta = $colaborador->user?->id;

        return GeneratedDocument::query()
            ->where(function (Builder $query) use ($colaborador, $idCuenta): void {
                $query->where('colaborador_id', $colaborador->id);

                if ($idCuenta !== null) {
                    $query->orWhere(fn (Builder $q) => $q->whereNull('colaborador_id')->where('user_id', $idCuenta));
                }
            })
            ->where(fn (Builder $query) => $query->whereNull('estado_flujo')->orWhere('estado_flujo', '!=', EstadoFlujoDocumento::Borrador->value))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (GeneratedDocument $documento): bool => Gate::forUser($usuario)->allows('ver', $documento));
    }

    /**
     * @param  list<list<string>>  $filas
     */
    private function indiceCsv(array $filas): string
    {
        $flujo = fopen('php://temp', 'r+');

        if ($flujo === false) {
            throw new RuntimeException('No se pudo generar el índice del expediente.');
        }

        fputcsv($flujo, self::ENCABEZADOS_INDICE, ',', '"', '');

        foreach ($filas as $fila) {
            fputcsv($flujo, $fila, ',', '"', '');
        }

        rewind($flujo);
        $contenido = (string) stream_get_contents($flujo);
        fclose($flujo);

        // BOM: Excel abre el CSV como UTF-8 y respeta los acentos.
        return "\u{FEFF}".$contenido;
    }

    private function carpetaRaiz(Colaborador $colaborador): string
    {
        return $this->sanitizar(sprintf('%s - %s', $colaborador->numero_empleado ?? $colaborador->id, $colaborador->nombreCompleto()));
    }

    private function carpetaCategoria(CategoriaDocumento $categoria): string
    {
        $indice = array_search($categoria, CategoriaDocumento::cases(), true);

        return sprintf('%02d %s', ($indice === false ? count(CategoriaDocumento::cases()) : $indice) + 1, $this->sanitizar($categoria->etiqueta()));
    }

    private function extension(?string $extension, string $nombreOriginal, ?string $mime): string
    {
        $extension = strtolower(trim((string) $extension, '. '));

        if ($extension === '') {
            $extension = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION));
        }

        if ($extension === '' && $mime === 'application/pdf') {
            $extension = 'pdf';
        }

        $extension = (string) preg_replace('/[^a-z0-9]/', '', $extension);

        return $extension !== '' ? '.'.$extension : '';
    }

    /**
     * Nombre seguro en Windows/macOS/Linux: sin / \ : * ? " < > | ni
     * caracteres de control; conserva acentos y ñ.
     */
    public function sanitizar(string $valor): string
    {
        $limpio = (string) preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]/u', '', $valor);
        $limpio = trim((string) preg_replace('/\s+/u', ' ', $limpio), " .\t");

        if (mb_strlen($limpio) > 150) {
            $limpio = rtrim(mb_substr($limpio, 0, 150), ' .');
        }

        return $limpio !== '' ? $limpio : 'Sin nombre';
    }

    private function nombreUsuario(?User $usuario): string
    {
        return $usuario !== null ? trim(sprintf('%s %s', $usuario->name, $usuario->apellidos ?? '')) : '';
    }
}
