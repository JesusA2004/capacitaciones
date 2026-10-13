<?php

namespace App\Services\DocumentosMaestros;

use App\Enums\EstadoCampoDocumento;
use App\Enums\ZonaCampoDocumento;
use App\Models\DocumentTemplate;
use App\Models\DocumentTemplateField;
use App\Services\DocumentosMaestros\Variables\CatalogoVariablesDocumento;
use Illuminate\Support\Collection;

/**
 * Definición VIGENTE con la que se prepara una versión de documento
 * maestro, ya en el mismo formato que siempre consumió el preparador
 * (PreparacionMaestroService): `reglas` (DOCX) o `campos`/`requeridos`
 * (PDF overlay) más las opciones de la familia.
 *
 * Fuente (no-code):
 *  1. Los espacios de la versión en BD (document_template_fields) — lo que
 *     RH confirmó en el editor visual.
 *  2. Las opciones de la versión (document_templates.configuracion).
 *  3. Sin espacios en BD: el registro heredado config/documentos_maestros.php
 *     (compatibilidad mientras se migra; `documentos:migrar-mapeos`).
 *
 * Compatibilidad exacta: un espacio migrado del registro heredado guarda su
 * regla/caja original (`metadata.legado`); mientras RH no lo cambie se
 * compila VERBATIM, así el mapa compilado (y por tanto el QA visual ya
 * aprobado) no cambia en un solo byte.
 */
class DefinicionMaestroService
{
    /** Llaves de la regla DOCX que forman el ancla (todo menos campo/opcional). */
    private const LLAVES_SELECTOR = ['tipo', 'antes', 'despues', 'desde', 'hasta', 'buscar', 'etiqueta', 'parrafo', 'fila', 'ocurrencia', 'en_parrafo'];

    /** Opciones de la familia que pueden vivir en la versión (configuracion). */
    public const OPCIONES = ['representante', 'paginas', 'copias_offset_y', 'requeridos', 'ignorar', 'ejemplos', 'fechas_fijas', 'solo_resultado', 'zonas_sin_dato'];

    public function __construct(
        private readonly CatalogoMaestrosService $catalogo,
        private readonly CatalogoVariablesDocumento $variables,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function paraVersion(string $familia, ?DocumentTemplate $version, int $numero): ?array
    {
        $base = $this->catalogo->definicionLegado($familia)
            ?? ($version !== null ? $this->catalogo->metaDe($version) : $this->catalogo->definicion($familia));

        if ($base === null) {
            return null;
        }

        $base = [...$base, ...$this->opcionesDe($version)];

        if ($version === null || ! $version->exists) {
            return $base;
        }

        $campos = $version->campos()->get();

        return $campos->isEmpty() ? $base : $this->compilar($base, $campos);
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  Collection<int, DocumentTemplateField>  $campos
     * @return array<string, mixed>
     */
    public function compilar(array $base, Collection $campos): array
    {
        $motor = (string) ($base['motor'] ?? 'docx');
        $expresiones = [];
        $pendientes = [];
        $zonasSinDato = array_values(array_filter((array) ($base['zonas_sin_dato'] ?? []), 'is_array'));
        $reglas = [];
        $cajas = [];
        $requeridos = [];

        foreach ($campos as $campo) {
            if ($campo->zona !== ZonaCampoDocumento::Dato) {
                $ubicacion = (array) ($campo->metadata['ubicacion'] ?? []);

                if ($ubicacion !== []) {
                    $zonasSinDato[] = $ubicacion;
                }

                continue;
            }

            if ($campo->estado !== EstadoCampoDocumento::Confirmado || ! $campo->asignado()) {
                // Los blancos «____» sin confirmar ya los reporta el detector
                // del DOCX; el resto (celdas, texto de ejemplo, cajas PDF) se
                // reporta aquí para que la versión no se active con huecos.
                if ($motor === 'pdf_overlay' || ($campo->selector['tipo'] ?? null) !== 'blanco') {
                    $pendientes[] = ['referencia' => $campo->label ?? $campo->field_key, 'tipo' => 'campo_por_confirmar', 'contexto' => 'Espacio sin dato confirmado en el editor.'];
                }

                continue;
            }

            $nombre = $campo->campoMotor();

            if ($nombre !== $campo->source_key) {
                $expresiones[$nombre] = array_filter([
                    'source_key' => $campo->source_key,
                    'transform' => $campo->transform?->value,
                    'expression' => $campo->expression,
                    'etiqueta' => $campo->label,
                ], fn (mixed $v): bool => $v !== null);
            }

            if ($motor === 'pdf_overlay') {
                $cajas[] = $this->sinCambios($campo, $motor) ? $this->legado($campo) ?? [] : $this->caja($campo, $nombre);

                if ($campo->required) {
                    $requeridos[] = $nombre;
                }
            } else {
                $reglas[] = $this->sinCambios($campo, $motor) ? $this->legado($campo) ?? [] : $this->regla($campo, $nombre);
            }
        }

        $definicion = $base;
        unset($definicion['reglas_por_version']);

        if ($motor === 'pdf_overlay') {
            $definicion['campos'] = $cajas;
            $requeridos = array_values(array_unique($requeridos));
            $legado = array_values(array_map('strval', (array) ($base['requeridos'] ?? [])));
            // Mismo conjunto que el registro heredado = mismo orden (mapa idéntico).
            $definicion['requeridos'] = $this->mismoConjunto($requeridos, $legado) ? $legado : $requeridos;
        } else {
            $definicion['reglas'] = $reglas;
        }

        if ($expresiones !== []) {
            $definicion['expresiones'] = $expresiones;
        }

        if ($zonasSinDato !== []) {
            $definicion['zonas_sin_dato'] = $zonasSinDato;
        }

        if ($pendientes !== []) {
            $definicion['pendientes_extra'] = $pendientes;
        }

        return $definicion;
    }

    /**
     * Espacios de BD equivalentes a la definición heredada de una versión
     * (una fila por regla DOCX o caja PDF), sin guardarlos.
     *
     * @param  array<string, mixed>  $definicion
     * @return list<array<string, mixed>>
     */
    public function camposDesdeLegado(array $definicion, int $numero): array
    {
        $motor = (string) ($definicion['motor'] ?? 'docx');
        $filas = [];

        if ($motor === 'pdf_overlay') {
            $requeridos = array_map('strval', (array) ($definicion['requeridos'] ?? []));

            foreach (array_values(array_filter((array) ($definicion['campos'] ?? []), 'is_array')) as $i => $caja) {
                $filas[] = [
                    ...$this->columnasDeCaja($caja),
                    'field_key' => sprintf('c%d', $i + 1),
                    'label' => $this->variables->etiqueta((string) $caja['campo']),
                    'required' => in_array((string) $caja['campo'], $requeridos, true),
                    'sort_order' => $i + 1,
                    'metadata' => ['legado' => json_encode($caja, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)],
                ];
            }

            return $filas;
        }

        foreach ($this->catalogo->reglas($definicion, $numero) as $i => $regla) {
            $filas[] = [
                ...$this->columnasDeRegla($regla),
                'field_key' => sprintf('c%d', $i + 1),
                'label' => $this->variables->etiqueta((string) ($regla['campo'] ?? '')),
                'sort_order' => $i + 1,
                'metadata' => ['legado' => json_encode($regla, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)],
            ];
        }

        return $filas;
    }

    /**
     * Opciones de la familia en el formato de configuracion de la versión.
     *
     * @param  array<string, mixed>  $definicion
     * @return array<string, mixed>
     */
    public function opcionesDesde(array $definicion): array
    {
        return array_intersect_key($definicion, array_flip(self::OPCIONES));
    }

    /**
     * @return array<string, mixed>
     */
    private function opcionesDe(?DocumentTemplate $version): array
    {
        return array_intersect_key((array) ($version->configuracion ?? []), array_flip(self::OPCIONES));
    }

    /**
     * @param  array<string, mixed>  $regla
     * @return array<string, mixed>
     */
    private function columnasDeRegla(array $regla): array
    {
        return [
            'zona' => ZonaCampoDocumento::Dato,
            'estado' => EstadoCampoDocumento::Confirmado,
            'source_key' => (string) ($regla['campo'] ?? ''),
            'transform' => null,
            'expression' => null,
            'required' => ! (bool) ($regla['opcional'] ?? false),
            'selector' => array_intersect_key($regla, array_flip(self::LLAVES_SELECTOR)),
            'type' => ($regla['tipo'] ?? null) === 'texto' && preg_match('/[☐☒]/u', (string) ($regla['buscar'] ?? '')) === 1 ? 'casilla' : 'texto',
            'detection_method' => 'legado_config',
        ];
    }

    /**
     * @param  array<string, mixed>  $caja
     * @return array<string, mixed>
     */
    private function columnasDeCaja(array $caja): array
    {
        $campo = (string) ($caja['campo'] ?? '');

        return [
            'zona' => ZonaCampoDocumento::Dato,
            'estado' => EstadoCampoDocumento::Confirmado,
            'source_key' => $campo,
            'transform' => null,
            'expression' => null,
            'page' => (int) ($caja['pagina'] ?? 1),
            'x' => (float) $caja['x'],
            'y' => (float) $caja['y'],
            'width' => (float) ($caja['ancho'] ?? 60),
            'height' => (float) ($caja['alto'] ?? 5),
            'font_size' => isset($caja['tamano']) ? (float) $caja['tamano'] : null,
            'font_size_min' => isset($caja['tamano_minimo']) ? (float) $caja['tamano_minimo'] : null,
            'text_align' => isset($caja['alineacion']) ? (string) $caja['alineacion'] : null,
            'multiline' => (bool) ($caja['multilinea'] ?? false),
            'line_height' => isset($caja['interlineado']) ? (float) $caja['interlineado'] : null,
            'font_weight' => ($caja['negrita'] ?? false) === true ? 'bold' : null,
            'background_color' => is_string($caja['fondo'] ?? null) ? $caja['fondo'] : null,
            'type' => ($caja['tipo'] ?? null) === 'check' || str_starts_with($campo, 'marca_') ? 'casilla' : 'texto',
            'detection_method' => 'legado_config',
        ];
    }

    /**
     * ¿El espacio migrado sigue tal cual su regla/caja heredada?
     */
    private function sinCambios(DocumentTemplateField $campo, string $motor): bool
    {
        $legado = $this->legado($campo);

        if (! is_array($legado) || $campo->transform !== null || $campo->expression !== null) {
            return false;
        }

        $esperado = $motor === 'pdf_overlay' ? $this->columnasDeCaja($legado) : $this->columnasDeRegla($legado);
        $comparar = $motor === 'pdf_overlay'
            ? ['source_key', 'page', 'x', 'y', 'width', 'height', 'font_size', 'font_size_min', 'text_align', 'multiline', 'line_height', 'font_weight', 'background_color', 'type']
            : ['source_key', 'required', 'selector'];

        foreach ($comparar as $columna) {
            $actual = $campo->getAttribute($columna);
            $esperadoValor = $esperado[$columna] ?? null;

            if (is_float($esperadoValor) || is_float($actual)) {
                if ($actual === null || $esperadoValor === null || abs((float) $actual - (float) $esperadoValor) > 0.001) {
                    return false;
                }

                continue;
            }

            if ($actual != $esperadoValor) {
                return false;
            }
        }

        return true;
    }

    /**
     * Regla/caja heredada tal cual estaba en config. Se guarda como TEXTO
     * JSON (no como arreglo): MySQL reordena las llaves de las columnas JSON
     * y la regla debe compilarse en su orden exacto (el mapa compilado
     * incluye un hash de las reglas).
     *
     * @return array<string, mixed>|null
     */
    private function legado(DocumentTemplateField $campo): ?array
    {
        $legado = $campo->metadata['legado'] ?? null;
        $decodificado = is_string($legado) ? json_decode($legado, true) : null;

        return is_array($decodificado) ? $decodificado : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function regla(DocumentTemplateField $campo, string $nombre): array
    {
        $regla = [...array_intersect_key((array) $campo->selector, array_flip(self::LLAVES_SELECTOR)), 'campo' => $nombre];

        if (! $campo->required) {
            $regla['opcional'] = true;
        }

        return $regla;
    }

    /**
     * @return array<string, mixed>
     */
    private function caja(DocumentTemplateField $campo, string $nombre): array
    {
        return array_filter([
            'campo' => $nombre,
            'pagina' => $campo->page ?? 1,
            'x' => $this->numero($campo->x ?? 0.0),
            'y' => $this->numero($campo->y ?? 0.0),
            'ancho' => $this->numero($campo->width ?? 60.0),
            'alto' => $this->numero($campo->height ?? 5.0),
            'tamano' => $campo->font_size !== null ? $this->numero($campo->font_size) : null,
            'tamano_minimo' => $campo->font_size_min !== null ? $this->numero($campo->font_size_min) : null,
            'alineacion' => $campo->text_align,
            'multilinea' => $campo->multiline ? true : null,
            'interlineado' => $campo->line_height !== null ? $this->numero($campo->line_height) : null,
            'negrita' => $campo->font_weight === 'bold' ? true : null,
            'fondo' => $campo->background_color,
            'tipo' => $campo->type === 'casilla' ? 'check' : null,
        ], fn (mixed $v): bool => $v !== null);
    }

    private function numero(float $valor): float|int
    {
        $redondeado = round($valor, 2);

        return floor($redondeado) === $redondeado ? (int) $redondeado : $redondeado;
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private function mismoConjunto(array $a, array $b): bool
    {
        sort($a);
        sort($b);

        return $a === $b;
    }
}
