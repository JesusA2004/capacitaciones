<?php

namespace App\Services\DocumentosMaestros;

use App\Models\DocumentTemplate;

/**
 * Catálogo de familias de documentos maestros:
 *
 *  - HEREDADAS: el registro versionado config/documentos_maestros.php (los
 *    formatos de Jurídico que ya existían). Las familias llevan punto en su
 *    clave (contrato_capacitacion.gestor), así que NUNCA se leen con la
 *    notación de puntos de config(): siempre desde el arreglo completo.
 *  - NO-CODE: familias creadas desde Documentos maestros → «Nuevo formato»,
 *    que solo existen en base de datos. Su definición sale de las columnas
 *    de su versión más reciente (metaDe()) y de sus espacios
 *    (DefinicionMaestroService). No requieren editar PHP ni config.
 */
class CatalogoMaestrosService
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $familiasBd = null;

    /**
     * @return array<string, array<string, mixed>>
     */
    public function definiciones(): array
    {
        $legado = $this->definicionesLegado();

        return [...$legado, ...$this->familiasBd(array_keys($legado))];
    }

    /**
     * Solo el registro heredado (config).
     *
     * @return array<string, array<string, mixed>>
     */
    public function definicionesLegado(): array
    {
        $documentos = config('documentos_maestros.documentos', []);

        return is_array($documentos) ? $documentos : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function definicionLegado(string $familia): ?array
    {
        $definicion = $this->definicionesLegado()[$familia] ?? null;

        return is_array($definicion) ? $definicion : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function definicion(string $familia): ?array
    {
        $definicion = $this->definicionLegado($familia) ?? $this->definiciones()[$familia] ?? null;

        if ($definicion === null) {
            // Familia recién creada en esta misma petición.
            $this->familiasBd = null;
            $definicion = $this->definiciones()[$familia] ?? null;
        }

        return is_array($definicion) ? $definicion : null;
    }

    /**
     * Definición de una familia NO-CODE a partir de las columnas de una de
     * sus versiones (mismo formato que una entrada de config).
     *
     * @return array<string, mixed>
     */
    public function metaDe(DocumentTemplate $version): array
    {
        $configuracion = (array) ($version->configuracion ?? []);

        return [
            'clave' => (string) $version->clave,
            'nombre' => (string) $version->nombre,
            'proceso' => $version->proceso,
            'evento' => $version->evento,
            'causa' => $version->causa,
            'grupos' => $version->grupos_puesto,
            'motor' => $version->motor->value,
            'categoria' => $version->categoria?->value,
            'operativo' => $version->operativo,
            'no_code' => true,
            'banderas' => [
                'requiere_impresion' => $version->requiere_impresion,
                'requiere_firma_fisica' => $version->requiere_firma_fisica,
                'requiere_huella' => $version->requiere_huella,
                'requiere_testigos' => $version->requiere_testigos,
                'requiere_envio_corporativo' => $version->requiere_envio_corporativo,
                'cantidad_testigos' => $version->cantidad_testigos,
            ],
            'representante' => (string) ($configuracion['representante'] ?? 'empresa'),
            'fuentes' => [],
        ];
    }

    /**
     * @param  list<string>  $excluir
     * @return array<string, array<string, mixed>>
     */
    private function familiasBd(array $excluir): array
    {
        if ($this->familiasBd !== null) {
            return $this->familiasBd;
        }

        $familias = [];

        $versiones = DocumentTemplate::query()
            ->whereNotNull('familia')
            ->whereNotNull('estado_master')
            ->when($excluir !== [], fn ($q) => $q->whereNotIn('familia', $excluir))
            ->orderBy('version')
            ->get();

        foreach ($versiones->groupBy('familia') as $familia => $deFamilia) {
            /** @var DocumentTemplate $ultima */
            $ultima = $deFamilia->last();
            $definicion = $this->metaDe($ultima);

            foreach ($deFamilia as $version) {
                if ($version->original_hash !== null) {
                    $definicion['fuentes'][$version->original_hash] = ['version' => $version->version, 'activa' => $version->activo];
                }
            }

            $familias[(string) $familia] = $definicion;
        }

        return $this->familiasBd = $familias;
    }

    /**
     * Familia y versión que corresponden a un archivo por su SHA-256.
     *
     * @return array{familia: string, version: int, activa: bool, bloqueada: bool, nota: string|null}|null
     */
    public function porHash(string $sha256): ?array
    {
        foreach ($this->definiciones() as $familia => $definicion) {
            $fuente = $definicion['fuentes'][$sha256] ?? null;

            if (is_array($fuente)) {
                return [
                    'familia' => (string) $familia,
                    'version' => (int) ($fuente['version'] ?? 1),
                    'activa' => (bool) ($fuente['activa'] ?? false),
                    'bloqueada' => (bool) ($fuente['bloqueada'] ?? false),
                    'nota' => isset($fuente['nota']) ? (string) $fuente['nota'] : null,
                ];
            }
        }

        return null;
    }

    /**
     * Todas las familias que comparten un mismo original (el PDF de crédito
     * contiene carta + contrato + pagaré).
     *
     * @return list<array{familia: string, version: int, activa: bool, bloqueada: bool, nota: string|null}>
     */
    public function todasPorHash(string $sha256): array
    {
        $resultado = [];

        foreach ($this->definiciones() as $familia => $definicion) {
            $fuente = $definicion['fuentes'][$sha256] ?? null;

            if (is_array($fuente)) {
                $resultado[] = [
                    'familia' => (string) $familia,
                    'version' => (int) ($fuente['version'] ?? 1),
                    'activa' => (bool) ($fuente['activa'] ?? false),
                    'bloqueada' => (bool) ($fuente['bloqueada'] ?? false),
                    'nota' => isset($fuente['nota']) ? (string) $fuente['nota'] : null,
                ];
            }
        }

        return $resultado;
    }

    /**
     * Reglas DOCX que aplican a una versión (algunas versiones antiguas
     * tienen su propio juego de reglas).
     *
     * @param  array<string, mixed>  $definicion
     * @return list<array<string, mixed>>
     */
    public function reglas(array $definicion, int $version): array
    {
        $porVersion = $definicion['reglas_por_version'][$version] ?? null;
        $reglas = is_array($porVersion) ? $porVersion : ($definicion['reglas'] ?? []);

        return array_values(array_filter((array) $reglas, 'is_array'));
    }

    public function etiquetaGrupo(?string $grupo): ?string
    {
        if ($grupo === null) {
            return null;
        }

        $etiqueta = config('documentos_maestros.grupos.'.$grupo);

        return is_string($etiqueta) ? $etiqueta : $grupo;
    }

    public function etiquetaProceso(?string $proceso): ?string
    {
        if ($proceso === null) {
            return null;
        }

        $etiqueta = config('documentos_maestros.procesos.'.$proceso);

        return is_string($etiqueta) ? $etiqueta : $proceso;
    }

    /**
     * Nombre legible de un tipo de documento (clave) aunque no tenga master.
     */
    public function nombreClave(string $clave): string
    {
        foreach ($this->definiciones() as $definicion) {
            if (($definicion['clave'] ?? null) === $clave && ($definicion['grupos'] ?? null) === null) {
                return (string) $definicion['nombre'];
            }
        }

        $nombres = [
            'contrato_capacitacion' => 'Contrato de capacitación inicial',
            'contrato_indeterminado' => 'Contrato por tiempo indeterminado',
            'contrato_confidencialidad' => 'Contrato de confidencialidad',
            'contrato_no_competencia' => 'Acuerdo de no competencia',
            'contrato_periodo_prueba' => 'Contrato de periodo de prueba',
            'contrato_tiempo_determinado' => 'Contrato por tiempo determinado',
            'carta_responsiva' => 'Carta responsiva de activos',
            'finiquito' => 'Finiquito',
        ];

        if (isset($nombres[$clave])) {
            return $nombres[$clave];
        }

        $legado = config('contratos.plantillas.'.$clave.'.nombre') ?? config('ciclo_laboral.plantillas.'.$clave);

        return is_string($legado) ? $legado : ucfirst(str_replace('_', ' ', $clave));
    }
}
