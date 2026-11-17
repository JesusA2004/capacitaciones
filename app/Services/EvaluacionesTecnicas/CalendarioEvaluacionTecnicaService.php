<?php

namespace App\Services\EvaluacionesTecnicas;

use App\Enums\EstadoUsuario;
use App\Enums\QuienContestaEvaluacionTecnica;
use App\Models\Colaborador;
use App\Models\PasoEvaluacionTecnica;
use App\Models\PlantillaEvaluacionTecnica;
use App\Models\Puesto;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Calendario de evaluaciones técnicas por puesto: «Seguimiento 15»,
 * «Seguimiento 30», «Seguimiento 60 · técnica final»… El día se cuenta
 * desde la fecha de ingreso del colaborador (colaboradores.fecha_ingreso).
 * La sugerencia parte de la duración real del contrato de capacitación del
 * puesto (puestos.meses_periodo_prueba), nunca de un valor inventado.
 */
class CalendarioEvaluacionTecnicaService
{
    public const MAX_PASOS = 12;

    public function __construct(private readonly AuditoriaService $auditoria) {}

    /**
     * Puestos activos con su calendario, para el configurador.
     *
     * @return list<array<string, mixed>>
     */
    public function puestos(): array
    {
        $pasos = PasoEvaluacionTecnica::query()
            ->with('plantilla:id,nombre,activa')
            ->orderBy('dias')
            ->get()
            ->groupBy('puesto_id');

        /** @var Collection<int|string, int> $activos */
        $activos = Colaborador::query()
            ->where('estatus', EstadoUsuario::Activo->value)
            ->whereNotNull('puesto_id')
            ->groupBy('puesto_id')
            ->selectRaw('puesto_id, count(*) as total')
            ->pluck('total', 'puesto_id');

        return array_values(Puesto::query()
            ->where('activo', true)
            ->with('departamento:id,nombre')
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'departamento_id', 'meses_periodo_prueba'])
            ->map(fn (Puesto $puesto): array => [
                'id' => $puesto->id,
                'nombre' => $puesto->nombre,
                'departamento' => $puesto->departamento?->nombre,
                'meses_periodo_prueba' => $puesto->meses_periodo_prueba,
                'colaboradores_activos' => (int) ($activos[$puesto->id] ?? 0),
                'sugerencia' => $this->sugerencia($puesto),
                'pasos' => array_values($pasos->get($puesto->id, collect())->map(fn (PasoEvaluacionTecnica $p): array => $this->pasoArray($p))->all()),
            ])
            ->all());
    }

    /**
     * Días sugeridos a partir de la duración del contrato de capacitación
     * del puesto: 15, 30 y cada 30 días hasta el final del periodo (la última
     * es la técnica final). Gestor (2 meses) → 15 · 30 · 60. Sin duración
     * cargada no se sugiere nada.
     *
     * @return list<int>
     */
    public function sugerencia(Puesto $puesto): array
    {
        $meses = (int) ($puesto->meses_periodo_prueba ?? 0);

        if ($meses <= 0) {
            return [];
        }

        $total = $meses * 30;
        $dias = [];

        foreach ([15, 30] as $d) {
            if ($d < $total) {
                $dias[] = $d;
            }
        }

        for ($d = 60; $d < $total; $d += 30) {
            $dias[] = $d;
        }

        $dias[] = $total;

        return array_values(array_unique($dias));
    }

    /**
     * Reemplaza el calendario del puesto. Un paso que se quita se borra; sus
     * evaluaciones ya generadas se conservan (guardan su propio nombre y día).
     *
     * @param  array<array-key, array<string, mixed>>  $pasos
     */
    public function guardar(Puesto $puesto, User $actor, array $pasos): void
    {
        $limpios = $this->validar($pasos);

        DB::transaction(function () use ($puesto, $actor, $limpios): void {
            $dias = array_column($limpios, 'dias');

            PasoEvaluacionTecnica::query()
                ->where('puesto_id', $puesto->id)
                ->whereNotIn('dias', $dias === [] ? [0] : $dias)
                ->delete();

            foreach ($limpios as $paso) {
                PasoEvaluacionTecnica::query()->updateOrCreate(
                    ['puesto_id' => $puesto->id, 'dias' => $paso['dias']],
                    [...$paso, 'actualizado_por' => $actor->id],
                );
            }
        });

        $this->auditoria->registrar('calendario_evaluacion_tecnica_guardado', $puesto, $actor, [
            'pasos' => array_map(fn (array $p): string => sprintf('%s (día %d)', $p['nombre'], $p['dias']), $limpios),
        ]);
    }

    /**
     * Copia el calendario de un puesto a otros (p. ej. Gestor → Gestor Volante).
     *
     * @param  list<int>  $destinos
     */
    public function copiar(Puesto $origen, User $actor, array $destinos): int
    {
        $pasos = PasoEvaluacionTecnica::query()
            ->where('puesto_id', $origen->id)
            ->orderBy('dias')
            ->get()
            ->map(fn (PasoEvaluacionTecnica $p): array => $this->pasoArray($p))
            ->values()
            ->all();

        $copiados = 0;

        foreach (Puesto::query()->whereIn('id', $destinos)->where('id', '!=', $origen->id)->get() as $destino) {
            $this->guardar($destino, $actor, $pasos);
            $copiados++;
        }

        return $copiados;
    }

    /**
     * @return array<string, mixed>
     */
    public function pasoArray(PasoEvaluacionTecnica $paso): array
    {
        return [
            'id' => $paso->id,
            'dias' => $paso->dias,
            'nombre' => $paso->nombre,
            'plantilla_id' => $paso->plantilla_evaluacion_tecnica_id,
            'plantilla' => $paso->plantilla?->nombre,
            'contesta' => $paso->contesta->value,
            'contesta_etiqueta' => $paso->contesta->etiqueta(),
            'calificacion_minima' => (float) $paso->calificacion_minima,
            'dias_para_contestar' => $paso->dias_para_contestar,
            'es_final' => $paso->es_final,
            'activo' => $paso->activo,
        ];
    }

    /**
     * @param  array<array-key, array<string, mixed>>  $pasos
     * @return list<array{dias: int, nombre: string, plantilla_evaluacion_tecnica_id: int, contesta: QuienContestaEvaluacionTecnica, calificacion_minima: float, dias_para_contestar: int, es_final: bool, activo: bool}>
     */
    private function validar(array $pasos): array
    {
        if (count($pasos) > self::MAX_PASOS) {
            throw ValidationException::withMessages(['pasos' => sprintf('Un puesto puede tener hasta %d evaluaciones técnicas.', self::MAX_PASOS)]);
        }

        $plantillasActivas = PlantillaEvaluacionTecnica::query()->where('activa', true)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $limpios = [];
        $errores = [];

        foreach (array_values($pasos) as $i => $p) {
            $n = $i + 1;
            $dias = is_numeric($p['dias'] ?? null) ? (int) $p['dias'] : 0;
            $plantilla = is_numeric($p['plantilla_id'] ?? null) ? (int) $p['plantilla_id'] : 0;
            $contesta = QuienContestaEvaluacionTecnica::tryFrom((string) ($p['contesta'] ?? ''));
            $minima = is_numeric($p['calificacion_minima'] ?? null) ? (float) $p['calificacion_minima'] : -1;
            $plazo = is_numeric($p['dias_para_contestar'] ?? null) ? (int) $p['dias_para_contestar'] : 0;

            if ($dias < 1 || $dias > 730) {
                $errores[] = sprintf('Evaluación %d: el día debe estar entre 1 y 730 desde el ingreso.', $n);
            }

            if (in_array($dias, array_column($limpios, 'dias'), true)) {
                $errores[] = sprintf('Hay dos evaluaciones el día %d: cada día solo puede tener una.', $dias);
            }

            if (! in_array($plantilla, $plantillasActivas, true)) {
                $errores[] = sprintf('Evaluación %d: elige qué formulario técnico usa.', $n);
            }

            if ($contesta === null) {
                $errores[] = sprintf('Evaluación %d: elige quién la contesta.', $n);
            }

            if ($minima < 0 || $minima > 100) {
                $errores[] = sprintf('Evaluación %d: la calificación mínima va de 0 a 100.', $n);
            }

            if ($plazo < 1 || $plazo > 60) {
                $errores[] = sprintf('Evaluación %d: el plazo para contestar va de 1 a 60 días.', $n);
            }

            $nombre = Str::limit(trim((string) ($p['nombre'] ?? '')), 120, '');

            $limpios[] = [
                'dias' => $dias,
                'nombre' => $nombre !== '' ? $nombre : PasoEvaluacionTecnica::nombrePorDefecto($dias),
                'plantilla_evaluacion_tecnica_id' => $plantilla,
                'contesta' => $contesta ?? QuienContestaEvaluacionTecnica::Colaborador,
                'calificacion_minima' => round(max(0, min(100, $minima)), 2),
                'dias_para_contestar' => $plazo,
                'es_final' => filter_var($p['es_final'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'activo' => filter_var($p['activo'] ?? true, FILTER_VALIDATE_BOOLEAN),
            ];
        }

        if (count(array_filter($limpios, fn (array $p): bool => $p['es_final'])) > 1) {
            $errores[] = 'Solo una evaluación puede ser la técnica final.';
        }

        if ($errores !== []) {
            throw ValidationException::withMessages(['pasos' => array_values(array_unique($errores))]);
        }

        usort($limpios, fn (array $a, array $b): int => $a['dias'] <=> $b['dias']);

        return $limpios;
    }
}
