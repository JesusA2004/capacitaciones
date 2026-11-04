<?php

namespace App\Services\RecepcionDocumental;

use App\Enums\EstadoRecepcionDocumento;
use App\Enums\ReglaRecepcionDocumental;
use App\Models\RecepcionDocumentalCorte;
use App\Models\RecepcionDocumentalPdf;
use App\Models\RecepcionDocumentalRenglon;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Expedientes\DocumentoStorageService;
use App\Services\Pdf\LogoPdfService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * PDF operativo del corte (DomPDF, misma identidad de config/pdf_identidad):
 * SUCURSAL → evento/persona → documentos con casillas grandes para marcar a
 * mano, subtotales por sucursal, total general y espacio de firma.
 *
 *  - Variante «control»: casillas vacías para palomear lo que llega.
 *  - Variante «estado»: el estado digital (✓ recibido, ! observación /
 *    no recibido, N/A no aplica, vacío pendiente).
 *
 * Cada generación se GUARDA versionada (corte + variante + versión, con
 * fecha) en el disco de expedientes; nunca se sobreescribe la anterior, y
 * queda en la bitácora. Las casillas se dibujan con bordes CSS (DomPDF no
 * trae una fuente con ☐/☑ garantizada); el texto usa DejaVu Sans.
 *
 * @phpstan-import-type Metricas from RecepcionDocumentalService
 */
class RecepcionDocumentalPdfService
{
    public function __construct(
        private readonly RecepcionDocumentalService $servicio,
        private readonly DocumentoStorageService $almacen,
        private readonly AuditoriaService $auditoria,
        private readonly LogoPdfService $logos,
    ) {}

    public static function varianteValida(?string $variante): string
    {
        return $variante === RecepcionDocumentalPdf::VARIANTE_ESTADO ? RecepcionDocumentalPdf::VARIANTE_ESTADO : RecepcionDocumentalPdf::VARIANTE_CONTROL;
    }

    /**
     * Genera, guarda (versionado) y devuelve el registro del PDF.
     *
     * @param  array<string, mixed>  $filtros
     */
    public function generar(RecepcionDocumentalCorte $corte, User $actor, string $variante, array $filtros = []): RecepcionDocumentalPdf
    {
        $variante = self::varianteValida($variante);
        $contenido = $this->renderizar($corte, $actor, $variante, $filtros);
        $normalizados = $this->servicio->normalizarFiltros($filtros);
        $ahora = Carbon::now();

        $registro = DB::transaction(function () use ($corte, $actor, $variante, $contenido, $normalizados, $ahora): RecepcionDocumentalPdf {
            $version = (int) RecepcionDocumentalPdf::query()->where('corte_id', $corte->id)->where('variante', $variante)->lockForUpdate()->max('version') + 1;
            $nombre = sprintf('Recepcion documental %s %s v%d.pdf', $corte->periodo, $variante, $version);
            $ruta = sprintf(
                '%s/%s/pdf/%s-v%d-%s.pdf',
                trim((string) config('recepcion_documental.carpeta', 'RecepcionDocumental'), '/'),
                $corte->periodo,
                $variante,
                $version,
                $ahora->copy()->setTimezone('America/Mexico_City')->format('Ymd-His'),
            );

            $this->almacen->disco()->put($ruta, $contenido);

            return RecepcionDocumentalPdf::query()->create([
                'corte_id' => $corte->id,
                'variante' => $variante,
                'version' => $version,
                'sucursal_id' => $normalizados['sucursal_id'],
                'disk' => (string) config('expedientes.disk'),
                'path' => $ruta,
                'nombre' => $nombre,
                'tamano' => strlen($contenido),
                'generado_por' => $actor->id,
                'generado_en' => $ahora,
            ]);
        });

        $this->auditoria->registrar('recepcion_documental_pdf_generado', $corte, $actor, [
            'periodo' => $corte->periodo,
            'variante' => $variante,
            'version' => $registro->version,
            'filtros' => array_filter($normalizados, fn (mixed $v): bool => $v !== null && $v !== false),
        ]);

        return $registro;
    }

    public function respuesta(RecepcionDocumentalPdf $pdf): StreamedResponse
    {
        return $this->almacen->respuesta($pdf->path, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="%s"', $pdf->nombre),
        ]);
    }

    /**
     * Versiones guardadas del PDF de este corte (más reciente primero).
     *
     * @return list<array{id: int, variante: string, version: int, nombre: string, generado_en: string|null, generado_por: string|null}>
     */
    public function versiones(RecepcionDocumentalCorte $corte): array
    {
        return array_values(RecepcionDocumentalPdf::query()
            ->where('corte_id', $corte->id)
            ->with('generadoPor:id,name')
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (RecepcionDocumentalPdf $p): array => [
                'id' => $p->id,
                'variante' => $p->variante,
                'version' => $p->version,
                'nombre' => $p->nombre,
                'generado_en' => $p->generado_en?->toIso8601String(),
                'generado_por' => $p->generadoPor?->name,
            ])
            ->all());
    }

    /**
     * @param  array<string, mixed>  $filtros
     */
    public function renderizar(RecepcionDocumentalCorte $corte, User $usuario, string $variante, array $filtros = []): string
    {
        return Pdf::loadView('pdf.recepcion-documental.control', $this->datos($corte, $usuario, $variante, $filtros))
            ->setPaper('letter', 'portrait')
            ->output();
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return array<string, mixed>
     */
    public function datos(RecepcionDocumentalCorte $corte, User $usuario, string $variante, array $filtros = []): array
    {
        $renglones = $this->servicio->todosLosRenglones($corte, $usuario, $filtros);
        $secciones = [];
        $total = $this->metricasDe([]);

        /** @var array<string, list<RecepcionDocumentalRenglon>> $porSucursal */
        $porSucursal = [];

        foreach ($renglones as $r) {
            $porSucursal[$r->sucursal_nombre ?? 'Sin sucursal'][] = $r;
        }

        foreach ($porSucursal as $sucursal => $filas) {
            $bloques = [];

            foreach ($filas as $r) {
                $bloque = $r->regla->value;
                $evento = sprintf('%s|%d', $r->origen_type, $r->origen_id);

                if ($r->regla === ReglaRecepcionDocumental::RecibosNomina) {
                    // Nómina: una persona con todos sus recibos del mes.
                    $evento = sprintf('colaborador|%s', $r->colaborador_id ?? $r->colaborador_nombre ?? $r->id);
                }

                $bloques[$bloque]['etiqueta'] ??= mb_strtoupper($r->regla->etiqueta());
                $bloques[$bloque]['orden'] ??= $r->regla->orden();
                $bloques[$bloque]['eventos'][$evento]['titulo'] ??= trim(sprintf('%s%s%s', $r->colaborador_nombre ?? 'Sin persona', $r->numero_empleado !== null ? sprintf(' (No. %s)', $r->numero_empleado) : '', $r->puesto_nombre !== null ? sprintf(' · %s', $r->puesto_nombre) : ''));
                $bloques[$bloque]['eventos'][$evento]['descripcion'] ??= $r->regla === ReglaRecepcionDocumental::RecibosNomina ? null : $r->evento_descripcion;
                $bloques[$bloque]['eventos'][$evento]['documentos'][] = [
                    'etiqueta' => $r->regla === ReglaRecepcionDocumental::RecibosNomina ? (string) $r->evento_descripcion : $r->tipo_etiqueta,
                    'cantidad' => $r->cantidad_esperada,
                    'marca' => $this->marca($r->estado),
                    'estado' => $r->estado->etiqueta(),
                    'nota' => $r->observaciones ?? $r->justificacion,
                ];
            }

            uasort($bloques, fn (array $a, array $b): int => $a['orden'] <=> $b['orden']);
            $metricas = $this->metricasDe($filas);
            $total = $this->sumar($total, $metricas);
            $secciones[] = ['sucursal' => $sucursal, 'bloques' => array_values($bloques), 'metricas' => $metricas];
        }

        $periodo = PeriodoRecepcion::delCorte($corte);

        return [
            'titulo' => 'MR LANA — CONTROL DE RECEPCIÓN DOCUMENTAL RH',
            'periodo' => sprintf('Periodo %s al %s', $periodo->inicio()->format('d/m/Y'), $periodo->fin()->format('d/m/Y')),
            'periodo_etiqueta' => ucfirst($periodo->etiqueta()),
            'generado' => Carbon::now('America/Mexico_City')->format('d/m/Y H:i'),
            'variante' => $variante,
            'estado_corte' => $corte->estado->etiqueta(),
            'secciones' => $secciones,
            'total' => $total,
            'logo' => $this->logos->dataUri(),
            'pal' => config('pdf_identidad'),
        ];
    }

    private function marca(EstadoRecepcionDocumento $estado): string
    {
        return match ($estado) {
            EstadoRecepcionDocumento::Recibido => 'ok',
            EstadoRecepcionDocumento::RecibidoConObservacion, EstadoRecepcionDocumento::NoRecibido => 'alerta',
            EstadoRecepcionDocumento::NoAplica => 'na',
            EstadoRecepcionDocumento::Pendiente => 'vacio',
        };
    }

    /**
     * @param  list<RecepcionDocumentalRenglon>  $filas
     * @return array{esperados: int, recibidos: int, pendientes: int}
     */
    private function metricasDe(array $filas): array
    {
        $m = ['esperados' => 0, 'recibidos' => 0, 'pendientes' => 0];

        foreach ($filas as $r) {
            if ($r->estado === EstadoRecepcionDocumento::NoAplica) {
                continue;
            }

            $m['esperados'] += $r->cantidad_esperada;
            $m['recibidos'] += $r->estado->esRecibido() ? $r->cantidad_recibida : 0;
            $m['pendientes'] += $r->estado->esRecibido() ? 0 : $r->cantidad_esperada;
        }

        return $m;
    }

    /**
     * @param  array{esperados: int, recibidos: int, pendientes: int}  $a
     * @param  array{esperados: int, recibidos: int, pendientes: int}  $b
     * @return array{esperados: int, recibidos: int, pendientes: int}
     */
    private function sumar(array $a, array $b): array
    {
        return ['esperados' => $a['esperados'] + $b['esperados'], 'recibidos' => $a['recibidos'] + $b['recibidos'], 'pendientes' => $a['pendientes'] + $b['pendientes']];
    }
}
