<?php

namespace App\Services\Reclutamiento;

use App\Enums\EstadoCandidato;
use App\Enums\MotorPdf;
use App\Models\CampanaReclutamiento;
use App\Models\Candidato;
use App\Services\Pdf\OpcionesPdf;
use App\Services\Pdf\PdfRendererException;
use App\Services\Pdf\PdfRendererFactory;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Reporte PDF (Chrome/Browsershot) de una o varias campañas de
 * reclutamiento: cuántos llegaron, hasta qué fase avanzaron, en qué fase se
 * detuvieron y por qué (motivo de catálogo agrupado por categoría), y cuánto
 * costó cada candidato y cada contratado.
 *
 * Solo agrega datos reales: los candidatos que RH registró con esa campaña
 * (`candidatos.campana_reclutamiento_id`). Contratados y costo por
 * colaborador salen de CostoReclutamientoService::porCampana() — la misma
 * cifra que muestra la pantalla de Campañas.
 */
class ReporteCampanasService
{
    public function __construct(
        private readonly CostoReclutamientoService $costos,
        private readonly PdfRendererFactory $pdf,
    ) {}

    /**
     * @param  iterable<CampanaReclutamiento>  $campanas
     *
     * @throws PdfRendererException
     */
    public function pdf(iterable $campanas, string $titulo, string $periodo, bool $conCandidatos): string
    {
        $datos = $this->datos($campanas, $conCandidatos);

        $html = view('pdf.reporte-campanas', [
            ...$datos,
            'titulo' => $titulo,
            'periodo' => $periodo,
            'conCandidatos' => $conCandidatos,
            'generado' => now()->format('d/m/Y H:i'),
        ])->render();

        return $this->pdf->renderizar(
            MotorPdf::Browsershot,
            $html,
            new OpcionesPdf(tamano: 'letter', orientacion: 'portrait', numerarPaginas: true, textoPie: 'MR. LANA PEOPLE · Reporte de campañas de reclutamiento'),
        )['pdf'];
    }

    /**
     * @param  iterable<CampanaReclutamiento>  $campanas
     * @return array{campanas: list<array<string, mixed>>, totales: array<string, mixed>}
     */
    public function datos(iterable $campanas, bool $conCandidatos = true): array
    {
        $lista = new EloquentCollection(collect($campanas)->values()->all());
        $lista->loadMissing(['vacante.puesto:id,nombre', 'vacante.sucursal:id,nombre', 'responsable:id,name,apellidos']);

        $costos = $this->costos->porCampana($lista);
        $candidatos = Candidato::query()
            ->whereIn('campana_reclutamiento_id', $lista->pluck('id'))
            ->with(['motivoRechazo:id,nombre,categoria', 'colaborador:id,activado_en'])
            ->orderBy('created_at')
            ->get()
            ->groupBy('campana_reclutamiento_id');

        $filas = array_values($lista->map(function (CampanaReclutamiento $campana) use ($costos, $candidatos, $conCandidatos): array {
            /** @var Collection<int, Candidato> $suyos */
            $suyos = $candidatos->get($campana->id, collect());

            return $this->campana($campana, $suyos, $costos['por_campana'][$campana->id] ?? [], $conCandidatos);
        })->all());

        /** @var Collection<int, Candidato> $todos */
        $todos = $candidatos->flatten(1);
        $gasto = (float) $costos['gasto'];

        return [
            'campanas' => $filas,
            'totales' => [
                'campanas' => count($filas),
                'gasto' => $gasto,
                'presupuesto' => (float) $lista->sum(fn (CampanaReclutamiento $c): float => (float) ($c->presupuesto ?? 0)),
                ...$this->resumen($todos, $gasto, (int) $costos['contratados']),
                'costo_por_contratado' => $costos['costo_por_colaborador'],
            ],
        ];
    }

    /**
     * @param  Collection<int, Candidato>  $candidatos
     * @param  array<string, mixed>  $costo  Fila de CostoReclutamientoService::porCampana().
     * @return array<string, mixed>
     */
    private function campana(CampanaReclutamiento $campana, Collection $candidatos, array $costo, bool $conCandidatos): array
    {
        $gasto = (float) $campana->monto;
        $contratados = (int) ($costo['contratados'] ?? 0);
        $resumen = $this->resumen($candidatos, $gasto, $contratados);

        return [
            'id' => $campana->id,
            'nombre' => $campana->nombre !== null && $campana->nombre !== '' ? $campana->nombre : $campana->canal->etiqueta(),
            'canal' => $campana->canal->etiqueta(),
            'vacante' => $campana->vacante !== null
                ? sprintf('#%d · %s · %s', $campana->vacante->id, $campana->vacante->puesto->nombre ?? 'Sin puesto', $campana->vacante->sucursal->nombre ?? 'Sin sucursal')
                : 'Gasto general (sin vacante)',
            'fecha_inicio' => $campana->fecha_inicio?->format('d/m/Y'),
            'fecha_fin' => $campana->fecha_fin?->format('d/m/Y'),
            'responsable' => $campana->responsable?->nombreCompleto(),
            'presupuesto' => $campana->presupuesto !== null ? (float) $campana->presupuesto : null,
            'gasto' => $gasto,
            'impresiones' => $campana->impresiones,
            'clics' => $campana->clics,
            'costo_por_clic' => $costo['costo_por_clic'] ?? null,
            'dias_cobertura' => $costo['dias_cobertura'] ?? null,
            ...$resumen,
            'costo_por_contratado' => $costo['costo_por_colaborador'] ?? null,
            'candidatos' => $conCandidatos ? $candidatos->map(fn (Candidato $c): array => [
                'nombre' => $c->nombreCompleto(),
                'registrado' => $c->created_at?->format('d/m/Y'),
                'fase_alcanzada' => $this->etiquetaFase($this->faseAlcanzada($c)),
                'estado' => $c->etiquetaEstado(),
                'sigue' => ! $c->estado->esTerminal(),
                'salio' => $c->estado->esSalida(),
                'motivo' => $c->estado->esSalida()
                    ? ($c->motivoRechazo !== null ? $c->motivoRechazo->nombre : ($c->motivo_salida ?? 'Sin motivo capturado'))
                    : null,
            ])->values()->all() : [],
        ];
    }

    /**
     * Embudo, fases donde se detuvieron, motivos y costos de un grupo de
     * candidatos (una campaña o todas).
     *
     * @param  Collection<int, Candidato>  $candidatos
     * @return array<string, mixed>
     */
    private function resumen(Collection $candidatos, float $gasto, int $contratados): array
    {
        $llegaron = $candidatos->count();
        $salidas = $candidatos->filter(fn (Candidato $c): bool => $c->estado->esSalida());
        $enProceso = $candidatos->filter(fn (Candidato $c): bool => ! $c->estado->esTerminal());
        $costoPorCandidato = $llegaron > 0 ? round($gasto / $llegaron, 2) : null;

        return [
            'llegaron' => $llegaron,
            'en_proceso' => $enProceso->count(),
            'no_continuaron' => $salidas->count(),
            'contratados' => $contratados,
            'laborando' => $candidatos->filter(fn (Candidato $c): bool => $c->estado === EstadoCandidato::Contratado && $c->colaborador?->activado_en !== null)->count(),
            'conversion' => $llegaron > 0 ? round($contratados / $llegaron * 100, 1) : null,
            'costo_por_candidato' => $costoPorCandidato,
            // Lo que se pagó por candidatos que no continuaron (mismo costo
            // por candidato que todos: no se inventa otro reparto).
            'gasto_en_no_continuaron' => $llegaron > 0 ? round($gasto * $salidas->count() / $llegaron, 2) : null,
            'embudo' => $this->embudo($candidatos),
            'detenidos_por_fase' => $this->contarPorFase($salidas),
            'en_proceso_por_fase' => $this->contarPorFase($enProceso),
            'motivos' => $this->motivos($salidas),
        ];
    }

    /**
     * Cuántos llegaron al menos a cada hito del pipeline (etapa_maxima
     * conserva el hito más avanzado aunque después hayan salido).
     *
     * @param  Collection<int, Candidato>  $candidatos
     * @return list<array{etapa: string, total: int, porcentaje: float|null}>
     */
    private function embudo(Collection $candidatos): array
    {
        $llegaron = $candidatos->count();
        $hitos = [
            'Llegaron (registrados)' => EstadoCandidato::Recibidos->orden(),
            'Pasaron el filtro de RH' => EstadoCandidato::EntrevistaPendiente->orden(),
            'Llegaron a psicométricos' => EstadoCandidato::PsicometricasPendientes->orden(),
            'Llegaron a socioeconómico' => EstadoCandidato::SocioeconomicoPendiente->orden(),
            'Llegaron a referencias' => EstadoCandidato::ReferenciasPendientes->orden(),
            'Llegaron a autorización' => EstadoCandidato::PreseleccionGerente->orden(),
        ];

        $filas = [];

        foreach ($hitos as $etapa => $orden) {
            $total = $candidatos->filter(fn (Candidato $c): bool => $this->ordenAlcanzado($c) >= $orden)->count();
            $filas[] = ['etapa' => $etapa, 'total' => $total, 'porcentaje' => $llegaron > 0 ? round($total / $llegaron * 100, 1) : null];
        }

        $contratados = $candidatos->filter(fn (Candidato $c): bool => $c->estado === EstadoCandidato::Contratado)->count();
        $filas[] = ['etapa' => 'Contratados', 'total' => $contratados, 'porcentaje' => $llegaron > 0 ? round($contratados / $llegaron * 100, 1) : null];

        return $filas;
    }

    /**
     * @param  Collection<int, Candidato>  $candidatos
     * @return list<array{fase: string, total: int}>
     */
    private function contarPorFase(Collection $candidatos): array
    {
        $conteo = $candidatos->countBy(fn (Candidato $c): string => $this->faseAlcanzada($c));
        $filas = [];

        // En el orden real del tablero, no alfabético.
        foreach (EstadoCandidato::fases() as $clave => $etiqueta) {
            if (($conteo[$clave] ?? 0) > 0) {
                $filas[] = ['fase' => $etiqueta, 'total' => (int) $conteo[$clave]];
            }
        }

        return $filas;
    }

    /**
     * Por qué ya no continuaron: motivo de catálogo agrupado por categoría.
     * Una salida sin motivo de catálogo (histórico anterior a la
     * clasificación automática) se reporta como tal, nunca se adivina.
     *
     * @param  Collection<int, Candidato>  $salidas
     * @return list<array{categoria: string, total: int, motivos: list<array{motivo: string, total: int}>}>
     */
    private function motivos(Collection $salidas): array
    {
        return array_values($salidas
            ->groupBy(fn (Candidato $c): string => $c->motivoRechazo?->categoria->etiqueta() ?? 'Sin clasificar')
            ->map(fn (Collection $grupo, string $categoria): array => [
                'categoria' => $categoria,
                'total' => $grupo->count(),
                'motivos' => array_values($grupo
                    ->countBy(fn (Candidato $c): string => $c->motivoRechazo->nombre ?? 'Sin motivo de catálogo')
                    ->sortDesc()
                    ->map(fn (int $total, string $motivo): array => ['motivo' => $motivo, 'total' => $total])
                    ->all()),
            ])
            ->sortByDesc('total')
            ->all());
    }

    /**
     * Hito más avanzado: el estado actual si sigue abierto; si ya salió, el
     * que quedó guardado en etapa_maxima.
     */
    private function ordenAlcanzado(Candidato $candidato): int
    {
        return max($candidato->etapa_maxima, $candidato->estado->orden());
    }

    /**
     * Columna del tablero en la que está (abierto/contratado) o en la que se
     * detuvo (salida).
     */
    private function faseAlcanzada(Candidato $candidato): string
    {
        if (! $candidato->estado->esSalida()) {
            return $candidato->fase;
        }

        $orden = $this->ordenAlcanzado($candidato);

        foreach (EstadoCandidato::cases() as $estado) {
            if ($estado->orden() === $orden && $orden > 0) {
                return $estado->faseCanonica();
            }
        }

        return 'filtro_rh';
    }

    private function etiquetaFase(string $fase): string
    {
        return EstadoCandidato::fases()[$fase] ?? $fase;
    }
}
