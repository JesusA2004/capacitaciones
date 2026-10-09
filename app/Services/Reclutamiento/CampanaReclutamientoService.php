<?php

namespace App\Services\Reclutamiento;

use App\Models\CampanaReclutamiento;
use App\Models\CampanaReclutamientoAdjunto;
use App\Models\Candidato;
use App\Models\Vacante;
use App\Services\Vacantes\VacantesListadoService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * KPIs de gasto de reclutamiento (docs de referencia: encargo de campañas
 * de reclutamiento). No decide nada, solo agrega: App\Models\CampanaReclutamiento
 * (lo que RH capturó que gastó) y App\Models\Candidato (lo que realmente
 * entró al pipeline), cruzados por canal/periodo.
 *
 * Nota sobre atribución: `Candidato::fuente` es texto libre capturado a mano
 * por RH al dar de alta un candidato (no está restringido a los mismos
 * value-strings que App\Enums\CanalReclutamiento) — el cruce candidatos↔canal
 * solo es fiable cuando RH capturó la fuente igual al value del canal
 * ('meta', 'indeed', 'computrabajo', 'linkedin', 'referidos', 'otros'). Por
 * eso el fallback por fuente es exactamente eso, un fallback: se usa
 * candidatos_generados de la campaña si RH ya lo capturó a mano, y solo se
 * cae a contar candidatos por fuente cuando esa columna quedó vacía.
 */
class CampanaReclutamientoService
{
    public function __construct(private readonly CvStorageService $almacen) {}

    /**
     * Alta/edición de un gasto de reclutamiento. Si se liga a una vacante,
     * la empresa/sucursal/departamento/puesto salen de la vacante (un gasto
     * de "vacante de Gestor en Córdoba" no puede quedar con otra sucursal).
     *
     * @param  array<string, mixed>  $datos  Validado por Store/UpdateCampanaReclutamientoRequest.
     */
    public function guardar(array $datos, ?CampanaReclutamiento $campana, ?int $actorId): CampanaReclutamiento
    {
        $vacanteId = isset($datos['vacante_id']) && is_numeric($datos['vacante_id']) ? (int) $datos['vacante_id'] : null;

        if ($vacanteId !== null) {
            $vacante = Vacante::query()->whereKey($vacanteId)->firstOrFail();

            // Una campaña SIEMPRE parte de una vacante real con plaza disponible
            // (faltantes = autorizadas − ocupadas > 0). Una campaña ya ligada a
            // esa misma vacante se puede seguir editando (gasto real, cierre…).
            if ($campana?->vacante_id !== $vacante->id && ! app(VacantesListadoService::class)->tieneCupo($vacante)) {
                throw ValidationException::withMessages(['vacante_id' => 'Esa vacante ya no tiene plazas disponibles. Elige una vacante real abierta.']);
            }

            $datos = [
                ...$datos,
                'empresa_id' => $vacante->empresa_id,
                'sucursal_id' => $vacante->sucursal_id,
                'departamento_id' => $vacante->departamento_id,
                'puesto_id' => $vacante->puesto_id,
            ];
        }

        // El periodo de reporte (mes/año) sale de la fecha de inicio.
        if (! empty($datos['fecha_inicio'])) {
            $inicio = Carbon::parse((string) $datos['fecha_inicio']);
            $datos['mes'] = $inicio->month;
            $datos['anio'] = $inicio->year;
        }

        $adjuntos = array_values(array_filter((array) ($datos['adjuntos'] ?? []), fn ($a) => $a instanceof UploadedFile));
        unset($datos['adjuntos']);

        return DB::transaction(function () use ($datos, $campana, $actorId, $adjuntos): CampanaReclutamiento {
            if ($campana === null) {
                $campana = CampanaReclutamiento::query()->create([...$datos, 'created_by' => $actorId]);
            } else {
                $campana->update($datos);
            }

            foreach ($adjuntos as $archivo) {
                $this->agregarAdjunto($campana, $archivo, $actorId);
            }

            return $campana;
        });
    }

    /**
     * Guarda el arte/PDF de la campaña en el NAS privado (mismo disco de
     * reclutamiento que los CV).
     */
    public function agregarAdjunto(CampanaReclutamiento $campana, UploadedFile $archivo, ?int $actorId): CampanaReclutamientoAdjunto
    {
        $nombreOriginal = $archivo->getClientOriginalName();
        $ruta = $this->almacen->guardar($archivo, sprintf('campanas/%d/%s', $campana->id, $this->almacen->nombreInterno($nombreOriginal)));

        return $campana->adjuntos()->create([
            'disk' => (string) config('reclutamiento.disk'),
            'path' => $ruta,
            'nombre_original' => mb_substr($nombreOriginal, 0, 255),
            'mime' => (string) ($archivo->getMimeType() ?? 'application/octet-stream'),
            'tamano' => (int) $archivo->getSize(),
            'subido_por' => $actorId,
        ]);
    }

    public function respuestaAdjunto(CampanaReclutamientoAdjunto $adjunto): StreamedResponse
    {
        return $this->almacen->respuesta($adjunto->path, [
            'Content-Type' => $adjunto->mime,
            'Content-Disposition' => sprintf('inline; filename="%s"', addslashes($adjunto->nombre_original)),
        ]);
    }

    public function eliminarAdjunto(CampanaReclutamientoAdjunto $adjunto): void
    {
        $this->almacen->eliminar($adjunto->path);
        $adjunto->delete();
    }

    /**
     * Gasto agrupado por puesto (solo campañas CON puesto_id) + un bloque
     * aparte de "costo general" para las campañas sin puesto asignado.
     *
     * El gasto general NUNCA se reparte entre puestos aquí: no existe una
     * base objetiva para saber a qué puesto concreto atribuir, por ejemplo,
     * una campaña de Meta Ads sin puesto_id — inventar un reparto
     * (p. ej. por número de vacantes abiertas de cada puesto) sería un dato
     * fabricado, no uno medido. Si en el futuro se decide repartir, debe
     * documentarse aquí la fórmula exacta usada.
     *
     * @return array{
     *     por_puesto: array<int, array{puesto_id: int, puesto: string, gasto: float, candidatos_generados: int, costo_por_candidato: float}>,
     *     costo_general: array{gasto: float, candidatos_generados: int, costo_por_candidato: float},
     * }
     */
    public function resumenPorPuesto(int $mes, int $anio): array
    {
        $campanas = CampanaReclutamiento::query()
            ->where('mes', $mes)
            ->where('anio', $anio)
            ->with('puesto:id,nombre')
            ->get();

        $conPuesto = $campanas->whereNotNull('puesto_id');
        $sinPuesto = $campanas->whereNull('puesto_id');

        $porPuesto = $conPuesto
            ->groupBy('puesto_id')
            ->map(function (Collection|SupportCollection $grupo) use ($mes, $anio) {
                /** @var CampanaReclutamiento $primera */
                $primera = $grupo->first();
                $gasto = (float) $grupo->sum(fn (CampanaReclutamiento $c) => (float) $c->monto);
                $candidatos = $this->candidatosGeneradosPorCanal($grupo, $mes, $anio)->sum();

                return [
                    'puesto_id' => $primera->puesto_id,
                    'puesto' => $primera->puesto->nombre ?? 'Puesto sin nombre',
                    'gasto' => $gasto,
                    'candidatos_generados' => $candidatos,
                    'costo_por_candidato' => $candidatos > 0 ? $gasto / $candidatos : 0.0,
                ];
            })
            ->values()
            ->all();

        $gastoGeneral = (float) $sinPuesto->sum(fn (CampanaReclutamiento $c) => (float) $c->monto);
        $candidatosGeneral = $this->candidatosGeneradosPorCanal($sinPuesto, $mes, $anio)->sum();

        return [
            'por_puesto' => $porPuesto,
            'costo_general' => [
                'gasto' => $gastoGeneral,
                'candidatos_generados' => $candidatosGeneral,
                'costo_por_candidato' => $candidatosGeneral > 0 ? $gastoGeneral / $candidatosGeneral : 0.0,
            ],
        ];
    }

    /**
     * Candidatos generados por canal dentro del periodo, sin doble conteo:
     * si al menos una campaña de ese canal en el periodo quedó sin capturar
     * `candidatos_generados`, se cuenta UNA sola vez (no por fila) cuántos
     * candidatos con `fuente` = canal se registraron en el mes — sumarlo por
     * cada fila de campaña duplicaría el conteo cuando el canal tiene varias
     * campañas en el mismo periodo.
     *
     * @param  Collection<int, CampanaReclutamiento>|SupportCollection<int, CampanaReclutamiento>  $campanas
     * @return SupportCollection<string, int> candidatos generados, indexado por value de canal
     */
    private function candidatosGeneradosPorCanal(Collection|SupportCollection $campanas, int $mes, int $anio): SupportCollection
    {
        [$inicio, $fin] = $this->rangoPeriodo($mes, $anio);

        return $campanas
            ->groupBy(fn (CampanaReclutamiento $c) => $c->canal->value)
            ->map(function (Collection|SupportCollection $grupo, string $canal) use ($inicio, $fin) {
                $conColumna = $grupo->whereNotNull('candidatos_generados');
                $total = (int) $conColumna->sum('candidatos_generados');

                if ($grupo->count() > $conColumna->count()) {
                    $total += Candidato::query()
                        ->where('fuente', $canal)
                        ->whereBetween('created_at', [$inicio, $fin])
                        ->count();
                }

                return $total;
            });
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function rangoPeriodo(int $mes, int $anio): array
    {
        $inicio = Carbon::now()->setDate($anio, $mes, 1)->startOfMonth();

        return [$inicio, $inicio->clone()->endOfMonth()];
    }
}
