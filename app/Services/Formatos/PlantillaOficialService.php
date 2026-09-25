<?php

namespace App\Services\Formatos;

use App\Enums\AplicaFormato;
use App\Enums\EstadoVersionFormato;
use App\Enums\EstrategiaFormato;
use App\Enums\TipoArchivoFormato;
use App\Enums\TipoFormatoOficial;
use App\Models\OfficialFormat;
use App\Models\OfficialFormatVersion;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Formatos\Analisis\AnalisisPlantillaService;
use App\Services\Formatos\Analisis\AnalizadorDocx;
use App\Services\Formatos\Motor\NormalizadorBaseFormato;
use App\Services\Formatos\Motor\ValidadorArchivoPlantilla;
use App\Services\Formatos\Variables\CatalogoVariablesFormato;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Administración de plantillas oficiales (docs/FORMATOS_OFICIALES.md):
 * subir, versionar, mapear campos, publicar y archivar.
 *
 * Reglas:
 *  - una versión publicada nunca se modifica: cambiar algo = versión nueva;
 *  - publicar una versión retira (conserva) la anterior;
 *  - solo puede haber un borrador por formato a la vez;
 *  - un formato con documentos generados nunca se borra: se archiva.
 */
class PlantillaOficialService
{
    public function __construct(
        private readonly OfficialFormatStorageService $storage,
        private readonly ValidadorArchivoPlantilla $validador,
        private readonly NormalizadorBaseFormato $normalizador,
        private readonly AnalisisPlantillaService $analisis,
        private readonly AnalizadorDocx $docx,
        private readonly CatalogoVariablesFormato $catalogo,
        private readonly AuditoriaService $auditoria,
    ) {}

    /**
     * @param  array{nombre: string, tipo: string, aplica_a?: string|null, empresa_id?: int|null, descripcion?: string|null}  $datos
     */
    public function crear(array $datos, UploadedFile $archivo, User $actor): OfficialFormat
    {
        $validado = $this->validador->validar($archivo);

        $formato = OfficialFormat::query()->create([
            'slug' => $this->slugUnico($datos['nombre']),
            'nombre' => $datos['nombre'],
            'descripcion' => $datos['descripcion'] ?? null,
            'tipo' => TipoFormatoOficial::from($datos['tipo']),
            'aplica_a' => AplicaFormato::from($datos['aplica_a'] ?? AplicaFormato::Colaborador->value),
            'empresa_id' => $datos['empresa_id'] ?? null,
            'is_active' => true,
            'file_type' => $validado['tipo']->value,
            'created_by' => $actor->id,
        ]);

        try {
            $this->crearVersion($formato, (string) $archivo->getRealPath(), $validado, $this->nombreSeguro($archivo->getClientOriginalName(), $validado['extension']), $actor, null, 'Versión inicial.');
        } catch (Throwable $e) {
            // Sin versión el formato no sirve: no se deja un registro huérfano.
            $formato->delete();

            throw $e;
        }

        $this->auditoria->registrar('formato_oficial_creado', $formato, $actor, ['nombre' => $formato->nombre, 'tipo_archivo' => $validado['tipo']->value]);

        return $formato->refresh();
    }

    /**
     * Versión nueva en borrador. Con archivo: nuevo documento base (los
     * campos de la versión anterior se conservan en las páginas que sigan
     * existiendo). Sin archivo: mismo documento, para reajustar el mapeo.
     */
    public function nuevaVersion(OfficialFormat $formato, ?UploadedFile $archivo, User $actor, ?string $notas = null): OfficialFormatVersion
    {
        if ($formato->estaArchivado()) {
            throw ValidationException::withMessages(['formato' => 'El formato está archivado. Reactívalo antes de crear una versión.']);
        }

        $borrador = $formato->versiones()->where('estado', EstadoVersionFormato::Borrador->value)->first();

        if ($borrador !== null) {
            throw ValidationException::withMessages([
                'formato' => sprintf('Ya existe el borrador v%d. Publícalo o descártalo antes de crear otra versión.', $borrador->numero),
            ]);
        }

        $anterior = $formato->versionVigente ?? $formato->versiones()->first();

        if ($archivo === null) {
            if ($anterior === null) {
                throw ValidationException::withMessages(['archivo' => 'Sube el archivo del formato.']);
            }

            return $this->copiarVersion($formato, $anterior, $actor, $notas);
        }

        $validado = $this->validador->validar($archivo);

        $version = $this->crearVersion($formato, (string) $archivo->getRealPath(), $validado, $this->nombreSeguro($archivo->getClientOriginalName(), $validado['extension']), $actor, $anterior, $notas);
        $formato->update(['file_type' => $validado['tipo']->value]);

        return $version;
    }

    /**
     * Alta desde un archivo local ya existente (importador de los PDFs
     * oficiales de claude/formatos/originales).
     */
    public function nuevaVersionDesdeRuta(OfficialFormat $formato, string $rutaLocal, string $nombreOriginal, ?User $actor, ?string $notas = null): OfficialFormatVersion
    {
        $archivo = new UploadedFile($rutaLocal, $nombreOriginal, null, null, true);
        $validado = $this->validador->validar($archivo);
        $anterior = $formato->versionVigente ?? $formato->versiones()->first();

        return $this->crearVersion($formato, $rutaLocal, $validado, $this->nombreSeguro($nombreOriginal, $validado['extension']), $actor, $anterior, $notas);
    }

    /**
     * @param  list<array<string, mixed>>  $campos  Ya validados por GuardarCamposFormatoRequest.
     */
    public function guardarCampos(OfficialFormatVersion $version, array $campos, User $actor): OfficialFormatVersion
    {
        $this->exigirBorrador($version);
        $campos = $this->normalizarCampos($version, $campos);

        $version->update(['campos' => $campos]);

        activity('formatos')
            ->performedOn($version)
            ->causedBy($actor)
            ->withProperties(['formato_id' => $version->official_format_id, 'version' => $version->numero, 'campos' => count($campos)])
            ->log('formato_campos_guardados');

        return $version->refresh();
    }

    public function publicar(OfficialFormatVersion $version, User $actor): OfficialFormatVersion
    {
        $this->exigirBorrador($version);
        $campos = $version->camposConfigurados();

        if ($campos === []) {
            throw ValidationException::withMessages(['campos' => 'Coloca al menos un campo antes de publicar la versión.']);
        }

        if ($version->estrategia === EstrategiaFormato::DocxVariables) {
            $sinMapear = array_filter($campos, fn (array $c) => ($c['tipo'] ?? '') === 'variable' && ! $this->catalogo->existe((string) ($c['variable'] ?? '')));

            if ($sinMapear !== []) {
                throw ValidationException::withMessages(['campos' => 'Hay marcadores del Word sin dato asignado. Asigna una variable o conviértelos en campo manual.']);
            }
        }

        DB::transaction(function () use ($version, $actor): void {
            $formato = OfficialFormat::query()->whereKey($version->official_format_id)->lockForUpdate()->firstOrFail();

            OfficialFormatVersion::query()
                ->where('official_format_id', $formato->id)
                ->where('estado', EstadoVersionFormato::Publicada->value)
                ->update(['estado' => EstadoVersionFormato::Retirada->value]);

            $version->update([
                'estado' => EstadoVersionFormato::Publicada,
                'publicada_por' => $actor->id,
                'publicada_en' => now(),
            ]);

            $formato->update(['version_vigente_id' => $version->id, 'is_active' => true]);
        });

        $this->auditoria->registrar('formato_oficial_version_publicada', $version, $actor, [
            'formato_id' => $version->official_format_id,
            'version' => $version->numero,
            'hash' => $version->source_hash,
        ]);

        return $version->refresh();
    }

    /**
     * Un borrador que nunca se publicó se puede descartar por completo
     * (ningún documento lo usa). Sus archivos solo se borran si ninguna
     * otra versión los comparte.
     */
    public function descartarBorrador(OfficialFormatVersion $version, User $actor): void
    {
        $this->exigirBorrador($version);

        if ($version->generaciones()->exists()) {
            throw ValidationException::withMessages(['version' => 'Esta versión ya tiene documentos generados; no se puede descartar.']);
        }

        $formato = $version->formato;

        if ($formato->versiones()->count() === 1) {
            throw ValidationException::withMessages(['version' => 'Es la única versión del formato. Archiva el formato en su lugar.']);
        }

        $rutas = array_filter([$version->source_path, $version->base_path]);
        $version->delete();

        foreach (array_unique($rutas) as $ruta) {
            $compartida = OfficialFormatVersion::query()->where('source_path', $ruta)->orWhere('base_path', $ruta)->exists();

            if (! $compartida) {
                $this->storage->eliminar($ruta);
            }
        }

        activity('formatos')->performedOn($formato)->causedBy($actor)->withProperties(['version' => $version->numero])->log('formato_borrador_descartado');
    }

    /**
     * Archivar = dejar de ofrecerlo para generar. Nunca borra versiones ni
     * documentos generados.
     */
    public function archivar(OfficialFormat $formato, User $actor): OfficialFormat
    {
        $formato->update(['is_active' => false, 'archivado_en' => now(), 'archivado_por' => $actor->id]);
        $this->auditoria->registrar('formato_oficial_archivado', $formato, $actor, ['nombre' => $formato->nombre]);

        return $formato;
    }

    public function reactivar(OfficialFormat $formato, User $actor): OfficialFormat
    {
        $formato->update(['is_active' => true, 'archivado_en' => null, 'archivado_por' => null]);
        $this->auditoria->registrar('formato_oficial_reactivado', $formato, $actor, ['nombre' => $formato->nombre]);

        return $formato;
    }

    /**
     * @param  array{tipo: TipoArchivoFormato, extension: string, mime: string}  $validado
     */
    private function crearVersion(OfficialFormat $formato, string $rutaLocal, array $validado, string $nombreOriginal, ?User $actor, ?OfficialFormatVersion $anterior, ?string $notas): OfficialFormatVersion
    {
        $numero = (int) OfficialFormatVersion::query()->where('official_format_id', $formato->id)->max('numero') + 1;
        $contenido = (string) file_get_contents($rutaLocal);
        $tipo = $validado['tipo'];

        $placeholders = $tipo === TipoArchivoFormato::Docx ? $this->docx->placeholders($rutaLocal) : [];
        $estrategia = $placeholders !== [] ? EstrategiaFormato::DocxVariables : EstrategiaFormato::Overlay;
        $base = $this->normalizador->normalizar($rutaLocal, $tipo);

        $rutaFuente = $this->storage->rutaFuenteVersion($formato->id, $numero, $validado['extension']);
        $this->storage->guardarContenido($rutaFuente, $contenido);
        $rutaBase = $rutaFuente;

        if ($tipo !== TipoArchivoFormato::Pdf) {
            $rutaBase = $this->storage->rutaBaseVersion($formato->id, $numero);
            $this->storage->guardarContenido($rutaBase, $base['pdf']);
        }

        $campos = match (true) {
            $estrategia === EstrategiaFormato::DocxVariables => $this->camposDesdePlaceholders($placeholders),
            $anterior !== null && $anterior->estrategia === EstrategiaFormato::Overlay => array_values(array_filter(
                $anterior->camposConfigurados(),
                fn (array $c) => (int) ($c['pagina'] ?? 1) <= count($base['paginas']),
            )),
            default => [],
        };

        try {
            $version = OfficialFormatVersion::query()->create([
                'official_format_id' => $formato->id,
                'numero' => $numero,
                'estado' => EstadoVersionFormato::Borrador,
                'estrategia' => $estrategia,
                'file_type' => $tipo,
                'source_disk' => config('formatos_oficiales.disk'),
                'source_path' => $rutaFuente,
                'original_filename' => $nombreOriginal,
                'source_mime' => $validado['mime'],
                'source_size' => strlen($contenido),
                'source_hash' => hash('sha256', $contenido),
                'base_path' => $rutaBase,
                'base_hash' => hash('sha256', $base['pdf']),
                'fidelidad' => $base['fidelidad'],
                'paginas' => $base['paginas'],
                'campos' => $campos,
                'notas' => $notas,
                'created_by' => $actor?->id,
            ]);
        } catch (Throwable $e) {
            $this->storage->eliminar($rutaFuente);
            $this->storage->eliminar($rutaBase);

            throw $e;
        }

        $this->analisis->analizar($version, $rutaLocal);

        activity('formatos')
            ->performedOn($version)
            ->causedBy($actor)
            ->withProperties(['formato_id' => $formato->id, 'version' => $numero, 'hash' => $version->source_hash, 'archivo' => $nombreOriginal])
            ->log('formato_version_creada');

        return $version->refresh();
    }

    private function copiarVersion(OfficialFormat $formato, OfficialFormatVersion $anterior, User $actor, ?string $notas): OfficialFormatVersion
    {
        $numero = (int) OfficialFormatVersion::query()->where('official_format_id', $formato->id)->max('numero') + 1;

        $version = OfficialFormatVersion::query()->create([
            ...$anterior->only([
                'estrategia', 'file_type', 'source_disk', 'source_path', 'original_filename', 'source_mime',
                'source_size', 'source_hash', 'base_path', 'base_hash', 'fidelidad', 'paginas', 'campos', 'analisis',
            ]),
            'official_format_id' => $formato->id,
            'numero' => $numero,
            'estado' => EstadoVersionFormato::Borrador,
            'notas' => $notas ?? sprintf('Ajuste de mapeo a partir de la v%d.', $anterior->numero),
            'created_by' => $actor->id,
            'publicada_por' => null,
            'publicada_en' => null,
        ]);

        activity('formatos')->performedOn($version)->causedBy($actor)->withProperties(['formato_id' => $formato->id, 'version' => $numero, 'desde' => $anterior->numero])->log('formato_version_creada');

        return $version;
    }

    /**
     * @param  list<string>  $placeholders
     * @return list<array<string, mixed>>
     */
    private function camposDesdePlaceholders(array $placeholders): array
    {
        return array_map(function (string $placeholder): array {
            $variable = $this->catalogo->claveDePlaceholder($placeholder);

            return [
                'id' => Str::lower(Str::random(10)),
                'tipo' => $variable !== null ? 'variable' : 'manual',
                'variable' => $variable,
                'placeholder' => $placeholder,
                'etiqueta' => $variable !== null ? null : Str::of($placeholder)->replace(['_', '.'], ' ')->ucfirst()->toString(),
                'formato' => null,
                'requerido' => true,
            ];
        }, $placeholders);
    }

    /**
     * Completa valores por omisión y descarta lo que no pertenezca al
     * esquema (nada arbitrario se guarda en `campos`).
     *
     * @param  list<array<string, mixed>>  $campos
     * @return list<array<string, mixed>>
     */
    public function normalizarCampos(OfficialFormatVersion $version, array $campos): array
    {
        $totalPaginas = max(1, count($version->paginas ?? []));
        $normalizados = [];

        foreach ($campos as $campo) {
            $tipo = (string) $campo['tipo'];
            $variable = in_array($tipo, ['variable', 'imagen'], true) ? (string) ($campo['variable'] ?? '') : null;
            $definicion = $variable !== null ? $this->catalogo->definicion($variable) : null;

            if ($variable !== null && $definicion === null) {
                throw ValidationException::withMessages(['campos' => "La variable «{$variable}» no existe en el catálogo."]);
            }

            $formato = isset($campo['formato']) && $campo['formato'] !== '' ? (string) $campo['formato'] : null;

            if ($definicion !== null && ! $this->catalogo->formatoValido($definicion['tipo'], $formato)) {
                throw ValidationException::withMessages(['campos' => "El formato «{$formato}» no aplica a «{$definicion['etiqueta']}»."]);
            }

            $pagina = (int) ($campo['pagina'] ?? 1);

            if ($version->estrategia === EstrategiaFormato::Overlay && ($pagina < 1 || $pagina > $totalPaginas)) {
                throw ValidationException::withMessages(['campos' => "Un campo está en la página {$pagina}, pero el documento tiene {$totalPaginas}."]);
            }

            $normalizados[] = [
                'id' => (string) ($campo['id'] ?? Str::lower(Str::random(10))),
                'tipo' => $tipo,
                'variable' => $variable,
                'placeholder' => isset($campo['placeholder']) ? (string) $campo['placeholder'] : null,
                'etiqueta' => isset($campo['etiqueta']) && $campo['etiqueta'] !== '' ? (string) $campo['etiqueta'] : null,
                'texto' => $tipo === 'texto' ? (string) ($campo['texto'] ?? '') : null,
                'pagina' => $pagina,
                'x' => round((float) ($campo['x'] ?? 0), 2),
                'y' => round((float) ($campo['y'] ?? 0), 2),
                'ancho' => round((float) ($campo['ancho'] ?? 60), 2),
                'alto' => round((float) ($campo['alto'] ?? 6), 2),
                'font_size' => round((float) ($campo['font_size'] ?? 10), 1),
                'align' => in_array($campo['align'] ?? 'left', ['left', 'center', 'right'], true) ? (string) ($campo['align'] ?? 'left') : 'left',
                'negrita' => (bool) ($campo['negrita'] ?? false),
                'color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($campo['color'] ?? '')) === 1 ? (string) $campo['color'] : '#111111',
                'formato' => $formato,
                'max_caracteres' => isset($campo['max_caracteres']) && (int) $campo['max_caracteres'] > 0 ? (int) $campo['max_caracteres'] : null,
                'multilinea' => (bool) ($campo['multilinea'] ?? false),
                'requerido' => $tipo === 'texto' ? false : (bool) ($campo['requerido'] ?? false),
            ];
        }

        return $normalizados;
    }

    private function exigirBorrador(OfficialFormatVersion $version): void
    {
        if (! $version->esEditable()) {
            throw ValidationException::withMessages([
                'version' => sprintf('La v%d ya está %s y no se modifica. Crea una versión nueva para cambiarla.', $version->numero, mb_strtolower($version->estado->etiqueta())),
            ]);
        }
    }

    private function slugUnico(string $nombre): string
    {
        $base = Str::slug($nombre) ?: 'formato';
        $slug = $base;
        $i = 2;

        while (OfficialFormat::query()->where('slug', $slug)->exists()) {
            $slug = sprintf('%s-%d', $base, $i++);
        }

        return $slug;
    }

    /**
     * El nombre del cliente solo se conserva como dato informativo, nunca
     * como ruta: se limpia de separadores y caracteres de control.
     */
    private function nombreSeguro(string $nombre, string $extension): string
    {
        $base = Str::of(pathinfo($nombre, PATHINFO_FILENAME))->replaceMatches('/[^\pL\pN \-_.()]+/u', '')->trim()->limit(120, '')->toString();

        return sprintf('%s.%s', $base !== '' ? $base : 'formato', $extension);
    }
}
