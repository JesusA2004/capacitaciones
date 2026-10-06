<?php

namespace App\Services\DocumentosMaestros;

use App\Enums\AjusteFondo;
use App\Enums\TipoDocumentAsset;
use App\Models\DocumentAsset;
use App\Models\DocumentFamilyLayout;
use App\Models\DocumentLayoutPreset;
use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use App\Services\DocumentosMaestros\Docx\AplicadorLayoutDocx;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Diseño de página de los documentos maestros (docs/MOTOR_DOCUMENTOS_MAESTROS.md,
 * «Diseño de página y fondos»).
 *
 * Herencia: preset base → override de la familia → override de la
 * versión. Solo se aplica a masters DOCX (el overlay PDF ya es el original
 * de Jurídico tal cual). El diseño nunca toca el texto jurídico: fondo
 * detrás del texto, márgenes y formato de párrafos normales (ver
 * Docx\AplicadorLayoutDocx).
 *
 * Biblioteca de recursos (DocumentAsset): fondos, logos, sellos, marcas de
 * agua. Un archivo nunca se sobrescribe: reemplazar crea otra versión y los
 * documentos ya generados conservan en su snapshot el id y SHA-256 del
 * fondo con el que se hicieron.
 */
class LayoutDocumentoService
{
    public const PRESET_INDETERMINADO = 'contrato_indeterminado_mr_lana';

    public const FONDO_INDETERMINADO = 'mr-lana-contrato-indeterminado';

    public function __construct(
        private readonly AplicadorLayoutDocx $aplicador,
        private readonly AlmacenMaestrosService $almacen,
        private readonly AuditoriaService $auditoria,
    ) {}

    // ---------------------------------------------------------------------
    // Resolución
    // ---------------------------------------------------------------------

    /**
     * Diseño efectivo del master (null = se genera con el diseño del
     * original, sin cambios).
     *
     * @return array<string, mixed>|null
     */
    public function resolver(DocumentTemplate $master): ?array
    {
        if (($master->mapping['motor'] ?? 'docx') === 'pdf_overlay' || $master->familia === null) {
            return null;
        }

        $familia = DocumentFamilyLayout::query()->with('preset')->where('familia', $master->familia)->first();

        return $this->combinar($familia, is_array($master->layout_overrides) ? $master->layout_overrides : null);
    }

    /**
     * @param  array<string, mixed>|null  $overridesVersion
     * @return array<string, mixed>|null
     */
    private function combinar(?DocumentFamilyLayout $familia, ?array $overridesVersion): ?array
    {
        $capas = array_values(array_filter([
            $familia?->preset !== null && $familia->preset->activo ? $familia->preset->config : null,
            $familia?->overrides,
            $overridesVersion,
        ], fn ($c) => is_array($c) && $c !== []));

        if ($capas === []) {
            return null;
        }

        $resultado = [];

        foreach ($capas as $capa) {
            $resultado = $this->fusionar($resultado, $capa);
        }

        return $resultado;
    }

    /**
     * Fusión profunda donde un `null` explícito sí gana (p. ej. una familia
     * que dice «sin fondo» sobre un preset con fondo).
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $encima
     * @return array<string, mixed>
     */
    private function fusionar(array $base, array $encima): array
    {
        foreach ($encima as $clave => $valor) {
            $base[$clave] = is_array($valor) && isset($base[$clave]) && is_array($base[$clave]) && ! array_is_list($valor)
                ? $this->fusionar($base[$clave], $valor)
                : $valor;
        }

        return $base;
    }

    /**
     * @param  array<string, mixed>|null  $layout
     */
    public function fondoDe(?array $layout): ?DocumentAsset
    {
        $id = $layout['background']['asset_id'] ?? null;

        return $id !== null ? DocumentAsset::query()->where('id', (int) $id)->first() : null;
    }

    /**
     * Huella del diseño (cambia si cambia cualquier regla o el archivo del fondo).
     *
     * @param  array<string, mixed>  $layout
     */
    public function hash(array $layout): string
    {
        $fondo = $this->fondoDe($layout);

        return hash('sha256', (string) json_encode([$this->normalizarParaHash($layout), $fondo?->sha256]));
    }

    /**
     * @param  array<string, mixed>  $valor
     * @return array<string, mixed>
     */
    private function normalizarParaHash(array $valor): array
    {
        ksort($valor);

        foreach ($valor as $clave => $v) {
            if (is_array($v) && ! array_is_list($v)) {
                $valor[$clave] = $this->normalizarParaHash($v);
            }
        }

        return $valor;
    }

    /**
     * Aplica el diseño a un DOCX ya llenado.
     *
     * @return array{docx: string, snapshot: array<string, mixed>|null, hash: string|null}
     */
    public function aplicar(DocumentTemplate $master, string $docx): array
    {
        $layout = $this->resolver($master);

        if ($layout === null) {
            return ['docx' => $docx, 'snapshot' => null, 'hash' => null];
        }

        $fondo = $this->fondoDe($layout);
        $datosFondo = null;

        if ($fondo !== null) {
            $bytes = Storage::disk($fondo->disk)->get($fondo->path);

            if ($bytes === null || hash('sha256', $bytes) !== $fondo->sha256) {
                throw ValidationException::withMessages(['documento' => sprintf('El fondo «%s» no está disponible o su archivo no coincide con su huella. Revisa Documentos maestros → Fondos.', $fondo->nombre)]);
            }

            $datosFondo = ['bytes' => $bytes, 'extension' => $fondo->extension(), 'width' => $fondo->width, 'height' => $fondo->height, 'sha256' => $fondo->sha256];
        }

        return [
            'docx' => $this->aplicador->aplicar($docx, $layout, $datosFondo),
            'snapshot' => $this->snapshot($master, $layout, $fondo),
            'hash' => $this->hash($layout),
        ];
    }

    /**
     * @param  array<string, mixed>  $layout
     * @return array<string, mixed>
     */
    private function snapshot(DocumentTemplate $master, array $layout, ?DocumentAsset $fondo): array
    {
        $familia = DocumentFamilyLayout::query()->with('preset:id,slug')->where('familia', $master->familia)->first();

        return [
            'layout_hash' => $this->hash($layout),
            'preset' => $familia?->preset?->slug,
            'master_version' => $master->version,
            'page' => $layout['page'] ?? null,
            'paragraph' => $layout['paragraph'] ?? null,
            'header_logo_enabled' => $layout['header_logo_enabled'] ?? true,
            'background' => $fondo !== null ? [
                'asset_id' => $fondo->id,
                'slug' => $fondo->slug,
                'version' => $fondo->version,
                'sha256' => $fondo->sha256,
                'fit' => $layout['background']['fit'] ?? $fondo->fit_mode->value,
                'opacity' => (int) ($layout['background']['opacity'] ?? $fondo->default_opacity),
                'apply_to' => $layout['background']['apply_to'] ?? 'all_pages',
            ] : null,
        ];
    }

    /**
     * Advertencias del área segura: si el área de texto (márgenes de
     * página + sangría) invade la zona gráfica del fondo. No se mueve nada
     * automáticamente: solo se avisa.
     *
     * @param  array<string, mixed>|null  $layout
     * @param  array{page_w_mm: float, page_h_mm: float, top_mm: float, right_mm: float, bottom_mm: float, left_mm: float}|null  $pagina
     * @return list<string>
     */
    public function advertencias(?array $layout, ?array $pagina): array
    {
        $fondo = $this->fondoDe($layout);

        if ($fondo === null || $pagina === null) {
            return [];
        }

        $m = $this->margenesEfectivos($layout, $pagina);
        $lista = [];

        foreach (['top' => 'superior', 'bottom' => 'inferior', 'left' => 'izquierdo', 'right' => 'derecho'] as $lado => $nombre) {
            $seguro = $fondo->{'safe_area_'.$lado.'_mm'};

            if ($seguro !== null && (float) $seguro > $m[$lado] + 0.05) {
                $lista[] = sprintf('El contenido invade el área gráfica del fondo: margen %s de %.1f mm y el área segura pide %.1f mm.', $nombre, $m[$lado], (float) $seguro);
            }
        }

        return $lista;
    }

    /**
     * @param  array<string, mixed>|null  $layout
     * @param  array{top_mm: float, right_mm: float, bottom_mm: float, left_mm: float}  $pagina
     * @return array{top: float, right: float, bottom: float, left: float}
     */
    private function margenesEfectivos(?array $layout, array $pagina): array
    {
        $cm = $layout['page']['margins_cm'] ?? [];

        return [
            'top' => isset($cm['top']) ? (float) $cm['top'] * 10 : $pagina['top_mm'],
            'right' => isset($cm['right']) ? (float) $cm['right'] * 10 : $pagina['right_mm'],
            'bottom' => isset($cm['bottom']) ? (float) $cm['bottom'] * 10 : $pagina['bottom_mm'],
            'left' => isset($cm['left']) ? (float) $cm['left'] * 10 : $pagina['left_mm'],
        ];
    }

    // ---------------------------------------------------------------------
    // Diseño por familia (editor)
    // ---------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function disenoFamilia(string $familia): array
    {
        $registro = DocumentFamilyLayout::query()->with('preset')->where('familia', $familia)->first();
        $master = DocumentTemplate::query()->where('familia', $familia)->orderByDesc('activo')->orderByDesc('version')->first();
        $efectivo = $this->combinar($registro, is_array($master?->layout_overrides) ? $master->layout_overrides : null);
        $pagina = null;

        if ($master !== null && ($master->mapping['motor'] ?? 'docx') !== 'pdf_overlay') {
            try {
                $pagina = $this->aplicador->geometria($this->almacen->master($master));
            } catch (\Throwable) {
                $pagina = null;
            }
        }

        return [
            'familia' => $familia,
            'preset' => $registro?->preset !== null ? ['id' => $registro->preset->id, 'slug' => $registro->preset->slug, 'nombre' => $registro->preset->nombre] : null,
            'presets' => DocumentLayoutPreset::query()->where('activo', true)->orderBy('nombre')->get(['id', 'slug', 'nombre'])->map(fn (DocumentLayoutPreset $p) => ['id' => $p->id, 'slug' => $p->slug, 'nombre' => $p->nombre])->all(),
            'fondos' => DocumentAsset::query()->where('tipo', TipoDocumentAsset::Fondo->value)->orderBy('nombre')->orderByDesc('version')->get()->map(fn (DocumentAsset $a) => $this->assetArray($a, false))->all(),
            'overrides' => $registro?->overrides,
            'tiene_personalizacion' => is_array($registro?->overrides) && $registro->overrides !== [],
            'efectivo' => $efectivo,
            'pagina' => $pagina,
            'advertencias' => $this->advertencias($efectivo, $pagina),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $overrides
     */
    public function guardarDiseno(string $familia, ?int $presetId, ?array $overrides, User $actor): DocumentFamilyLayout
    {
        if ($presetId !== null && ! DocumentLayoutPreset::query()->whereKey($presetId)->exists()) {
            throw ValidationException::withMessages(['preset_id' => 'El preset no existe.']);
        }

        $assetId = $overrides['background']['asset_id'] ?? null;

        if ($assetId !== null && ! DocumentAsset::query()->whereKey((int) $assetId)->where('tipo', TipoDocumentAsset::Fondo->value)->exists()) {
            throw ValidationException::withMessages(['overrides.background.asset_id' => 'El fondo elegido no existe.']);
        }

        $registro = DocumentFamilyLayout::query()->updateOrCreate(['familia' => $familia], [
            'preset_id' => $presetId,
            'overrides' => $overrides === [] ? null : $overrides,
            'actualizado_por' => $actor->id,
        ]);

        $this->auditoria->registrar('diseno_documento_actualizado', $registro, $actor, ['familia' => $familia, 'preset_id' => $presetId, 'overrides' => $overrides]);

        return $registro;
    }

    // ---------------------------------------------------------------------
    // Biblioteca de fondos
    // ---------------------------------------------------------------------

    /**
     * @param  array{nombre: string, tipo?: string|null, fit_mode?: string|null, default_opacity?: int|string|null, safe_area_top_mm?: float|string|null, safe_area_right_mm?: float|string|null, safe_area_bottom_mm?: float|string|null, safe_area_left_mm?: float|string|null}  $datos
     */
    public function subirFondo(UploadedFile $archivo, array $datos, ?User $actor): DocumentAsset
    {
        return $this->registrar((string) file_get_contents($archivo->getRealPath() ?: ''), (string) $archivo->getMimeType(), $datos, Str::slug($datos['nombre']), $actor);
    }

    /**
     * Registra un fondo desde bytes. Idempotente por SHA-256: si ese mismo
     * archivo ya existe con ese slug, devuelve el registro existente.
     *
     * @param  array{nombre: string, tipo?: string|null, fit_mode?: string|null, default_opacity?: int|string|null, safe_area_top_mm?: float|string|null, safe_area_right_mm?: float|string|null, safe_area_bottom_mm?: float|string|null, safe_area_left_mm?: float|string|null}  $datos
     */
    public function registrar(string $bytes, string $mime, array $datos, string $slug, ?User $actor, ?DocumentAsset $reemplazaA = null): DocumentAsset
    {
        [$bytes, $mime, $ancho, $alto] = $this->normalizarImagen($bytes, $mime);
        $sha = hash('sha256', $bytes);

        $existente = DocumentAsset::query()->where('slug', $slug)->where('sha256', $sha)->first();

        if ($existente !== null) {
            return $existente;
        }

        $extension = $mime === 'image/jpeg' ? 'jpeg' : 'png';
        $ruta = sprintf('documentos-maestros/recursos/%s.%s', $sha, $extension);
        $disco = $this->almacen->disco();

        if (! $disco->exists($ruta)) {
            $disco->put($ruta, $bytes);
        }

        if (! $disco->exists($ruta)) {
            throw ValidationException::withMessages(['archivo' => 'No se pudo guardar el fondo en el almacenamiento.']);
        }

        $asset = DB::transaction(function () use ($datos, $slug, $actor, $reemplazaA, $mime, $ancho, $alto, $sha, $ruta): DocumentAsset {
            $version = (int) DocumentAsset::query()->where('slug', $slug)->lockForUpdate()->max('version') + 1;

            return DocumentAsset::query()->create([
                // Fondo por defecto; la biblioteca también guarda logos, sellos y marcas de agua.
                'tipo' => TipoDocumentAsset::tryFrom((string) ($datos['tipo'] ?? '')) ?? TipoDocumentAsset::Fondo,
                'nombre' => $datos['nombre'],
                'slug' => $slug,
                'version' => $version,
                'disk' => $this->almacen->nombreDisco(),
                'path' => $ruta,
                'mime_type' => $mime,
                'width' => $ancho,
                'height' => $alto,
                'sha256' => $sha,
                'fit_mode' => AjusteFondo::tryFrom((string) ($datos['fit_mode'] ?? '')) ?? AjusteFondo::Estirar,
                'default_opacity' => max(0, min(100, (int) ($datos['default_opacity'] ?? 100))),
                'safe_area_top_mm' => $this->numeroONulo($datos['safe_area_top_mm'] ?? null),
                'safe_area_right_mm' => $this->numeroONulo($datos['safe_area_right_mm'] ?? null),
                'safe_area_bottom_mm' => $this->numeroONulo($datos['safe_area_bottom_mm'] ?? null),
                'safe_area_left_mm' => $this->numeroONulo($datos['safe_area_left_mm'] ?? null),
                'activo' => true,
                'reemplaza_a_id' => $reemplazaA?->id,
                'creado_por' => $actor?->id,
            ]);
        });

        $this->auditoria->registrar('documento_fondo_registrado', $asset, $actor, ['slug' => $slug, 'version' => $asset->version, 'sha256' => $sha]);

        return $asset;
    }

    /**
     * Reemplaza un fondo en uso: crea la versión siguiente (nunca
     * sobrescribe) y mueve a ella los presets y familias que usaban la
     * anterior. Los documentos generados conservan la anterior.
     */
    public function reemplazar(DocumentAsset $anterior, UploadedFile $archivo, User $actor): DocumentAsset
    {
        $nuevo = $this->registrar((string) file_get_contents($archivo->getRealPath() ?: ''), (string) $archivo->getMimeType(), [
            'nombre' => $anterior->nombre,
            'tipo' => $anterior->tipo->value,
            'fit_mode' => $anterior->fit_mode->value,
            'default_opacity' => $anterior->default_opacity,
            'safe_area_top_mm' => $anterior->safe_area_top_mm,
            'safe_area_right_mm' => $anterior->safe_area_right_mm,
            'safe_area_bottom_mm' => $anterior->safe_area_bottom_mm,
            'safe_area_left_mm' => $anterior->safe_area_left_mm,
        ], $anterior->slug, $actor, $anterior);

        if ($nuevo->id === $anterior->id) {
            throw ValidationException::withMessages(['archivo' => 'Ese archivo es idéntico al fondo actual.']);
        }

        DB::transaction(function () use ($anterior, $nuevo): void {
            foreach (DocumentLayoutPreset::query()->get() as $preset) {
                if ((int) ($preset->config['background']['asset_id'] ?? 0) === $anterior->id) {
                    $config = $preset->config;
                    $config['background']['asset_id'] = $nuevo->id;
                    $preset->update(['config' => $config]);
                }
            }

            foreach (DocumentFamilyLayout::query()->whereNotNull('overrides')->get() as $familia) {
                $overrides = (array) $familia->overrides;

                if ((int) ($overrides['background']['asset_id'] ?? 0) === $anterior->id) {
                    $overrides['background']['asset_id'] = $nuevo->id;
                    $familia->update(['overrides' => $overrides]);
                }
            }
        });

        return $nuevo;
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    public function actualizarFondo(DocumentAsset $asset, array $datos, User $actor): DocumentAsset
    {
        $cambios = array_intersect_key($datos, array_flip(['nombre', 'fit_mode', 'default_opacity', 'activo', 'safe_area_top_mm', 'safe_area_right_mm', 'safe_area_bottom_mm', 'safe_area_left_mm']));

        foreach (['safe_area_top_mm', 'safe_area_right_mm', 'safe_area_bottom_mm', 'safe_area_left_mm'] as $campo) {
            if (array_key_exists($campo, $cambios)) {
                $cambios[$campo] = $this->numeroONulo($cambios[$campo]);
            }
        }

        $asset->update($cambios);
        $this->auditoria->registrar('documento_fondo_actualizado', $asset, $actor, $cambios);

        return $asset;
    }

    /**
     * Solo se borra un fondo que nadie usa (ni presets, ni familias, ni
     * documentos generados). El archivo se conserva si otra versión lo comparte.
     */
    public function eliminarFondo(DocumentAsset $asset, User $actor): void
    {
        $uso = $this->uso($asset);

        if (array_sum($uso) > 0) {
            throw ValidationException::withMessages(['fondo' => 'Este fondo está en uso (diseños o documentos generados): desactívalo en lugar de eliminarlo.']);
        }

        $asset->delete();

        if (! DocumentAsset::query()->where('path', $asset->path)->exists()) {
            Storage::disk($asset->disk)->delete($asset->path);
        }

        $this->auditoria->registrar('documento_fondo_eliminado', null, $actor, ['asset_id' => $asset->id, 'slug' => $asset->slug, 'sha256' => $asset->sha256]);
    }

    /**
     * @return array{presets: int, familias: int, documentos: int}
     */
    public function uso(DocumentAsset $asset): array
    {
        return [
            'presets' => DocumentLayoutPreset::query()->get()->filter(fn (DocumentLayoutPreset $p) => (int) ($p->config['background']['asset_id'] ?? 0) === $asset->id)->count(),
            'familias' => DocumentFamilyLayout::query()->whereNotNull('overrides')->get()->filter(fn (DocumentFamilyLayout $f) => (int) ($f->overrides['background']['asset_id'] ?? 0) === $asset->id)->count(),
            'documentos' => GeneratedDocument::query()->where('layout_snapshot->background->asset_id', $asset->id)->count(),
        ];
    }

    public function imagen(DocumentAsset $asset): StreamedResponse
    {
        $disco = Storage::disk($asset->disk);
        abort_unless($disco->exists($asset->path), 404);

        return $disco->response($asset->path, sprintf('%s-v%d.%s', $asset->slug, $asset->version, $asset->extension()), [
            'Content-Type' => $asset->mime_type,
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function assetArray(DocumentAsset $asset, bool $conUso = true): array
    {
        $uso = $conUso ? $this->uso($asset) : ['presets' => 0, 'familias' => 0, 'documentos' => 0];
        $area = $asset->safe_area_top_mm === null && $asset->safe_area_right_mm === null && $asset->safe_area_bottom_mm === null && $asset->safe_area_left_mm === null
            ? null
            : [
                'top' => $asset->safe_area_top_mm !== null ? (float) $asset->safe_area_top_mm : null,
                'right' => $asset->safe_area_right_mm !== null ? (float) $asset->safe_area_right_mm : null,
                'bottom' => $asset->safe_area_bottom_mm !== null ? (float) $asset->safe_area_bottom_mm : null,
                'left' => $asset->safe_area_left_mm !== null ? (float) $asset->safe_area_left_mm : null,
            ];

        return [
            'id' => $asset->id,
            'nombre' => $asset->nombre,
            'slug' => $asset->slug,
            'version' => $asset->version,
            'tipo' => $asset->tipo->value,
            'tipo_etiqueta' => $asset->tipo->etiqueta(),
            'url_imagen' => route('rh.documentos-maestros.fondos.imagen', $asset),
            'mime_type' => $asset->mime_type,
            'width' => $asset->width,
            'height' => $asset->height,
            'sha256' => $asset->sha256,
            'fit_mode' => $asset->fit_mode->value,
            'default_opacity' => $asset->default_opacity,
            'safe_area' => $area,
            'activo' => $asset->activo,
            'reemplaza_a_id' => $asset->reemplaza_a_id,
            'en_uso' => $uso,
            'puede_eliminar' => $conUso && array_sum($uso) === 0,
            'creado_por' => $asset->creadoPor?->nombreCompleto(),
            'created_at' => $asset->created_at?->toIso8601String(),
        ];
    }

    /**
     * PNG/JPG se guardan tal cual; WEBP se convierte a PNG (Word no lo
     * incrusta de forma confiable). Devuelve bytes, mime y dimensiones.
     *
     * @return array{0: string, 1: string, 2: int, 3: int}
     */
    private function normalizarImagen(string $bytes, string $mime): array
    {
        if (! in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            throw ValidationException::withMessages(['archivo' => 'El fondo debe ser PNG, JPG o WEBP.']);
        }

        $info = @getimagesizefromstring($bytes);

        if ($info === false) {
            throw ValidationException::withMessages(['archivo' => 'La imagen no se pudo leer.']);
        }

        if ($mime === 'image/webp') {
            $imagen = @imagecreatefromstring($bytes);

            if ($imagen === false) {
                throw ValidationException::withMessages(['archivo' => 'El WEBP no se pudo convertir; súbelo como PNG.']);
            }

            imagesavealpha($imagen, true);
            ob_start();
            imagepng($imagen, null, 6);
            $bytes = (string) ob_get_clean();
            $mime = 'image/png';
        }

        return [$bytes, $mime, (int) $info[0], (int) $info[1]];
    }

    private function numeroONulo(mixed $valor): ?float
    {
        return $valor === null || $valor === '' ? null : round((float) $valor, 2);
    }

    // ---------------------------------------------------------------------
    // Preset inicial
    // ---------------------------------------------------------------------

    /**
     * Registra el fondo oficial `bgDocs.png` y el preset «Contrato
     * indeterminado MR. LANA»; lo asigna a las familias indicadas.
     * Idempotente.
     *
     * @param  list<string>  $familias
     * @return array{fondo: DocumentAsset, preset: DocumentLayoutPreset, familias: list<string>}
     */
    public function instalarPresetIndeterminado(string $rutaFondo, array $familias, ?User $actor = null): array
    {
        if (! is_file($rutaFondo)) {
            throw ValidationException::withMessages(['fondo' => "No existe el archivo del fondo: {$rutaFondo}"]);
        }

        $fondo = $this->registrar((string) file_get_contents($rutaFondo), 'image/png', [
            'nombre' => 'MR. LANA — Fondo contrato indeterminado',
            'fit_mode' => AjusteFondo::Estirar->value,
            'default_opacity' => 100,
            // Zona blanca central de bgDocs: logo arriba-izquierda (~26 mm)
            // y ola de color abajo (~27 mm en las orillas).
            'safe_area_top_mm' => 28,
            'safe_area_right_mm' => 10,
            'safe_area_bottom_mm' => 28,
            'safe_area_left_mm' => 10,
        ], self::FONDO_INDETERMINADO, $actor);

        $preset = DocumentLayoutPreset::query()->updateOrCreate(['slug' => self::PRESET_INDETERMINADO], [
            'nombre' => 'Contrato indeterminado MR. LANA',
            'config' => [
                'page' => ['size' => 'letter', 'orientation' => 'portrait', 'margins_cm' => ['top' => 2.9, 'right' => 3.0, 'bottom' => 2.8, 'left' => 3.0]],
                'paragraph' => ['left_indent_cm' => 0.64, 'right_indent_cm' => 0, 'space_before_pt' => 0, 'space_after_pt' => 0, 'justify' => true, 'keep_lines' => true],
                'background' => ['asset_id' => $fondo->id, 'apply_to' => 'all_pages', 'fit' => AjusteFondo::Estirar->value, 'opacity' => 100],
                'header_logo_enabled' => false,
            ],
            'activo' => true,
        ]);

        foreach ($familias as $familia) {
            DocumentFamilyLayout::query()->firstOrCreate(['familia' => $familia], ['preset_id' => $preset->id, 'actualizado_por' => $actor?->id]);
        }

        return ['fondo' => $fondo, 'preset' => $preset, 'familias' => $familias];
    }
}
