<?php

namespace App\Services\DocumentosMaestros\Calidad;

use App\Enums\EstadoCampoDocumento;
use App\Enums\ZonaCampoDocumento;
use App\Models\DocumentTemplate;
use App\Models\DocumentTemplateField;
use App\Services\DocumentosMaestros\DatosDocumentoService;
use App\Services\DocumentosMaestros\Variables\CatalogoVariablesDocumento;
use Illuminate\Support\Collection;

/**
 * Cobertura del documento: ZONAS que el documento realmente tiene (cada
 * línea, blanco, celda, casilla o dato de ejemplo que encontró el
 * detector) contra lo que RH configuró. Ninguna zona queda sin destino:
 *
 *   dinámica   → la llena un dato de PEOPLE (obligatorio: si falta, bloquea)
 *   manual     → se captura al generar (testigos, hora del acta…)
 *   firma      → firma, huella o testigo físico (se queda en blanco)
 *   intencional→ RH decidió que puede quedar en blanco, con su razón
 *   sin clasificar → todavía nadie decidió qué va ahí
 *
 * Una versión solo está preparada con «sin clasificar» = 0. Es la misma
 * cuenta que ve RH en el editor, la que bloquea «Activar» y la que reporta
 * la auditoría de formatos.
 *
 * @phpstan-type Problema array{field_key: string|null, etiqueta: string, contexto: string|null, mensaje: string}
 * @phpstan-type Cobertura array{zonas: int, listas: int, dinamicas: int, manuales: int, firmas: int, intencionales: int, sin_clasificar: int, problemas: list<Problema>, marcas: array{detectadas: int, retiradas: int, decision: string|null}, mensaje: string}
 */
class CoberturaZonasService
{
    public function __construct(
        private readonly DatosDocumentoService $datos,
        private readonly CatalogoVariablesDocumento $variables,
    ) {}

    /**
     * @param  Collection<int, DocumentTemplateField>|null  $campos
     * @return Cobertura
     */
    public function de(DocumentTemplate $master, ?Collection $campos = null): array
    {
        $campos ??= $master->campos()->get();
        $analisis = (array) ($master->analisis ?? []);
        $conteo = ['dinamicas' => 0, 'manuales' => 0, 'firmas' => 0, 'intencionales' => 0];
        $problemas = [];

        foreach ($campos as $campo) {
            if (in_array($campo->zona, [ZonaCampoDocumento::Firma, ZonaCampoDocumento::Huella, ZonaCampoDocumento::Testigo], true)) {
                $conteo['firmas']++;

                continue;
            }

            if ($campo->zona === ZonaCampoDocumento::Ignorar) {
                $conteo['intencionales']++;

                continue;
            }

            if ($campo->estado !== EstadoCampoDocumento::Confirmado || ! $campo->asignado()) {
                $problemas[] = [
                    'field_key' => $campo->field_key,
                    'etiqueta' => $campo->label ?? ($campo->source_key !== null ? $this->variables->etiqueta($campo->source_key) : 'Sin dato asignado'),
                    'contexto' => isset($campo->metadata['contexto']) ? (string) $campo->metadata['contexto'] : null,
                    'mensaje' => $campo->estado === EstadoCampoDocumento::Sugerido ? 'Sugerencia por confirmar.' : 'Este espacio no tiene un dato asignado.',
                ];

                continue;
            }

            if (! $campo->required && $campo->type !== 'casilla') {
                if (trim((string) ($campo->metadata['motivo_blanco'] ?? '')) === '') {
                    $problemas[] = [
                        'field_key' => $campo->field_key,
                        'etiqueta' => $campo->label ?? $this->variables->etiqueta((string) $campo->source_key),
                        'contexto' => isset($campo->metadata['contexto']) ? (string) $campo->metadata['contexto'] : null,
                        'mensaje' => 'Puede quedar en blanco sin una razón: decide si es obligatorio o explica por qué puede ir vacío.',
                    ];

                    continue;
                }

                $conteo['intencionales']++;

                continue;
            }

            $conteo[$this->esManual($campo) ? 'manuales' : 'dinamicas']++;
        }

        // Lo que el detector encontró en el documento y no está en el mapa
        // (DOCX: blancos sin mapear, datos de ejemplo, fechas concretas).
        // «campo_por_confirmar» ya se contó arriba desde la BD, y un espacio
        // de la BD aún sin confirmar no se compila: el detector lo vuelve a
        // ver como blanco/fecha sin mapear — es la MISMA zona, no otra.
        $yaContadas = ['blanco' => 0, 'texto' => 0];

        foreach ($campos as $campo) {
            // «entre» = línea de espacios subrayados: el detector la ve como blanco.
            $tipo = match ((string) ($campo->selector['tipo'] ?? '')) {
                'blanco', 'entre' => 'blanco',
                'texto' => 'texto',
                default => null,
            };

            if ($tipo !== null && $campo->zona === ZonaCampoDocumento::Dato && ($campo->estado !== EstadoCampoDocumento::Confirmado || ! $campo->asignado())) {
                $yaContadas[$tipo]++;
            }
        }

        foreach ((array) ($analisis['pendientes'] ?? []) as $pendiente) {
            if (! is_array($pendiente) || ($pendiente['tipo'] ?? null) === 'campo_por_confirmar') {
                continue;
            }

            $grupo = ($pendiente['tipo'] ?? null) === 'blanco_sin_mapear' ? 'blanco' : 'texto';

            if ($yaContadas[$grupo] > 0) {
                $yaContadas[$grupo]--;

                continue;
            }

            $problemas[] = [
                'field_key' => null,
                'etiqueta' => match ($pendiente['tipo'] ?? null) {
                    'dato_de_ejemplo' => 'Dato de ejemplo impreso',
                    'fecha_concreta' => 'Fecha escrita en el formato',
                    default => 'Línea sin dato asignado',
                },
                'contexto' => isset($pendiente['contexto']) ? (string) $pendiente['contexto'] : null,
                'mensaje' => match ($pendiente['tipo'] ?? null) {
                    'dato_de_ejemplo' => 'El formato trae un dato de ejemplo que se imprimiría tal cual.',
                    'fecha_concreta' => 'El formato trae una fecha fija que se imprimiría tal cual.',
                    default => 'El documento tiene aquí una línea para un dato y nadie decidió qué va.',
                },
            ];
        }

        foreach ((array) ($analisis['reglas_pendientes'] ?? []) as $regla) {
            $problemas[] = [
                'field_key' => null,
                'etiqueta' => is_array($regla) && isset($regla['campo']) ? $this->variables->etiqueta((string) $regla['campo']) : 'Espacio',
                'contexto' => null,
                'mensaje' => 'El espacio configurado ya no se encuentra en el documento.',
            ];
        }

        // DOCX: líneas de firma que el detector reconoció (sin espacio en BD).
        $conteo['firmas'] += max(0, (int) ($analisis['firmas'] ?? 0));
        // DOCX: blancos que el registro jurídico deja vacíos a propósito.
        $conteo['intencionales'] += max(0, (int) ($analisis['intencionales'] ?? 0));
        $sinClasificar = count($problemas);
        $listas = array_sum($conteo);
        $marcas = (array) ($analisis['marcas_editoriales'] ?? []);

        return [
            'zonas' => $listas + $sinClasificar,
            'listas' => $listas,
            ...$conteo,
            'sin_clasificar' => $sinClasificar,
            'problemas' => $problemas,
            'marcas' => [
                'detectadas' => (int) ($marcas['detectadas'] ?? 0),
                'retiradas' => (int) ($marcas['retiradas'] ?? 0),
                'decision' => isset($marcas['decision']) ? (string) $marcas['decision'] : null,
            ],
            'mensaje' => $sinClasificar === 0
                ? sprintf('Los %d espacios del documento tienen un destino.', $listas + $sinClasificar)
                : sprintf('Hay %d espacio%s del documento que todavía no tiene%s un dato asignado.', $sinClasificar, $sinClasificar === 1 ? '' : 's', $sinClasificar === 1 ? '' : 'n'),
        ];
    }

    private function esManual(DocumentTemplateField $campo): bool
    {
        if ($campo->source_key === null) {
            return false;
        }

        return $this->datos->fuente($campo->source_key)['fuente'] === 'manual';
    }
}
