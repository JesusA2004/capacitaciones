<?php

namespace App\Services\DocumentosMaestros;

use App\Models\DocumentTemplate;
use App\Support\JsonCanonico;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Pasa el mapa de campos de cada versión de documento maestro del registro
 * heredado (config/documentos_maestros.php) a base de datos
 * (document_template_fields + document_templates.configuracion), y VERIFICA
 * la equivalencia: con el mapa ya en BD se vuelve a preparar el original y
 * el mapa compilado debe ser idéntico (byte a byte) al que la versión ya
 * tenía — así el QA visual aprobado sigue siendo válido y ningún documento
 * cambia. Nunca reescribe el mapa compilado ni el master: solo informa.
 *
 * Idempotente: una versión que ya tiene espacios en BD no se toca.
 */
class MigracionMapeosService
{
    public function __construct(
        private readonly DefinicionMaestroService $definiciones,
        private readonly PreparacionMaestroService $preparacion,
        private readonly AlmacenMaestrosService $almacen,
    ) {}

    /**
     * @return list<array{id: int, familia: string, version: int, accion: string, campos: int, equivalencia: string, detalle: string|null}>
     */
    public function migrar(bool $simular = false, bool $verificar = true): array
    {
        $reporte = [];

        $versiones = DocumentTemplate::query()
            ->whereNotNull('familia')
            ->whereNotNull('estado_master')
            ->withCount('campos')
            ->orderBy('familia')
            ->orderBy('version')
            ->get();

        foreach ($versiones as $version) {
            $familia = (string) $version->familia;
            $accion = 'ya_en_bd';
            $campos = (int) $version->campos_count;

            if ($campos === 0) {
                $legado = $this->definiciones->paraVersion($familia, null, $version->version);

                if ($legado === null) {
                    $reporte[] = ['id' => $version->id, 'familia' => $familia, 'version' => $version->version, 'accion' => 'sin_definicion', 'campos' => 0, 'equivalencia' => '—', 'detalle' => 'La familia no está en el registro heredado ni tiene espacios en BD.'];

                    continue;
                }

                $filas = $this->definiciones->camposDesdeLegado($legado, $version->version);
                $campos = count($filas);
                // Documento de referencia (no se llena): no tiene espacios.
                $accion = match (true) {
                    $filas === [] => 'sin_espacios',
                    $simular => 'por_migrar',
                    default => 'migrada',
                };

                if (! $simular) {
                    DB::transaction(function () use ($version, $filas, $legado): void {
                        foreach ($filas as $fila) {
                            $version->campos()->create($fila);
                        }

                        if ($version->configuracion === null) {
                            $opciones = $this->definiciones->opcionesDesde($legado);
                            $version->update(['configuracion' => $opciones === [] ? null : $opciones]);
                        }
                    });
                }
            }

            [$equivalencia, $detalle] = $verificar && ! $simular ? $this->verificar($version->refresh()) : ['no_verificada', null];
            $reporte[] = ['id' => $version->id, 'familia' => $familia, 'version' => $version->version, 'accion' => $accion, 'campos' => $campos, 'equivalencia' => $equivalencia, 'detalle' => $detalle];
        }

        return $reporte;
    }

    /**
     * Prepara el ORIGINAL con el mapa en BD y compara el mapa compilado con
     * el que la versión ya tenía.
     *
     * @return array{0: string, 1: string|null}
     */
    public function verificar(DocumentTemplate $version): array
    {
        if ($version->original_path === null) {
            return ['sin_original', 'La versión no tiene archivo original guardado.'];
        }

        try {
            $original = $this->almacen->original($version);
        } catch (Throwable $e) {
            return ['sin_original', $e->getMessage()];
        }

        $definicion = $this->definiciones->paraVersion((string) $version->familia, $version, $version->version);

        if ($definicion === null) {
            return ['sin_definicion', null];
        }

        try {
            $preparado = $this->preparacion->preparar($original, $definicion, $version->version);
        } catch (Throwable $e) {
            return ['error', $e->getMessage()];
        }

        // Comparación canónica: MySQL devuelve las columnas JSON con las
        // llaves ordenadas; el contenido (y el orden de las listas) es lo
        // que debe ser idéntico.
        if (JsonCanonico::igual($version->mapping, $preparado['mapping'])) {
            return ['identica', null];
        }

        return ['diferente', 'El mapa compilado desde BD difiere del vigente.'];
    }
}
