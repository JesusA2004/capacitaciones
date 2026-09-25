<?php

namespace App\Services\Formatos;

use App\Models\OfficialFormatGeneration;
use App\Models\OfficialFormatVersion;

/**
 * Forma pública (web y API móvil) de versiones, análisis, preparaciones y
 * documentos generados de plantillas oficiales. Nunca incluye rutas del
 * NAS: los archivos se sirven por URLs protegidas.
 *
 * @phpstan-import-type Preparacion from GeneradorFormatoService
 */
class FormatoOficialPresenter
{
    public function __construct(private readonly GeneradorFormatoService $generador) {}

    /**
     * @param  Preparacion  $preparacion
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function preparacion(array $preparacion, array $extra = []): array
    {
        return [
            'version' => $preparacion['version']->numero,
            'puede_generar' => $preparacion['puede_generar'],
            'motivo' => $this->generador->motivoBloqueo($preparacion),
            'faltantes' => $preparacion['faltantes'],
            'manuales' => $preparacion['manuales'],
            'contextos_faltantes' => $preparacion['contextos_faltantes'],
            'datos' => $preparacion['datos'],
            ...$extra,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function generacion(OfficialFormatGeneration $g, bool $api = false): array
    {
        $g->loadMissing(['formato:id,nombre,tipo', 'colaborador:id,name,apellidos', 'candidato:id,nombre,apellidos', 'generadoPor:id,name', 'solicitud:id,folio']);

        return [
            'id' => $g->id,
            'formato_id' => $g->official_format_id,
            'formato' => $g->formato->nombre,
            'categoria' => $g->formato->tipo->etiqueta(),
            'version' => $g->version_numero,
            'persona' => $g->colaborador?->nombreCompleto() ?? $g->candidato?->nombreCompleto(),
            'tipo_persona' => $g->colaborador_id !== null ? 'colaborador' : 'candidato',
            'colaborador_id' => $g->colaborador_id,
            'solicitud_folio' => $g->solicitud?->folio,
            'generado_por' => $g->generadoPor?->name,
            'generado_en' => $g->created_at?->toIso8601String(),
            'estado' => $g->status->value,
            'en_expediente' => $g->en_expediente,
            'checksum' => $g->checksum !== null ? substr($g->checksum, 0, 12) : null,
            'ver_url' => route($api ? 'api.v1.rh.formatos-oficiales.generaciones.ver' : 'rh.formatos-oficiales.previsualizar', $g->id),
            'descargar_url' => route($api ? 'api.v1.rh.formatos-oficiales.generaciones.descargar' : 'rh.formatos-oficiales.descargar', $g->id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function version(OfficialFormatVersion $v, bool $api = false): array
    {
        return [
            'id' => $v->id,
            'numero' => $v->numero,
            'estado' => $v->estado->value,
            'estado_etiqueta' => $v->estado->etiqueta(),
            'editable' => $v->esEditable(),
            'estrategia' => $v->estrategia->value,
            'file_type' => $v->file_type->value,
            'fidelidad' => $v->fidelidad,
            'paginas' => $v->paginas ?? [],
            'campos' => $v->camposConfigurados(),
            'analisis' => $this->analisis($v),
            'archivo_url' => route($api ? 'api.v1.rh.formatos-oficiales.versiones.base' : 'rh.formatos-oficiales.versiones.base', $v->id),
            'original_filename' => $v->original_filename,
            'hash' => $v->source_hash !== null ? substr($v->source_hash, 0, 12) : null,
            'notas' => $v->notas,
            'creada_en' => $v->created_at?->toIso8601String(),
            'publicada_en' => $v->publicada_en?->toIso8601String(),
        ];
    }

    /**
     * Sin los bloques de texto crudos (pueden ser cientos).
     *
     * @return array<string, mixed>
     */
    public function analisis(OfficialFormatVersion $version): array
    {
        $analisis = $version->analisis ?? [];

        return [
            'metodo' => $analisis['metodo'] ?? null,
            'sugerencias' => $analisis['sugerencias'] ?? [],
            'placeholders' => $analisis['placeholders'] ?? [],
            'mensajes' => $analisis['mensajes'] ?? [],
            'posiciones_aproximadas' => $analisis['posiciones_aproximadas'] ?? false,
            'total_bloques' => count($analisis['bloques'] ?? []),
            'analizado_en' => $analisis['analizado_en'] ?? null,
        ];
    }
}
