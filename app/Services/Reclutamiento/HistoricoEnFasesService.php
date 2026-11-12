<?php

namespace App\Services\Reclutamiento;

use App\Enums\EstadoAltaColaborador;
use App\Enums\EstadoCandidato;
use App\Enums\EstadoContratoLaboral;
use App\Enums\EstadoEvaluacionPrueba;
use App\Enums\EstadoUsuario;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\EvaluacionPeriodoPrueba;
use App\Services\Auditoria\AuditoriaService;
use App\Support\FechaMexico;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Ubica a la PLANTILLA HISTÓRICA (colaboradores que ya laboraban antes de
 * PEOPLE, sin candidato de reclutamiento) en el «Recorrido del candidato».
 * El tablero se alimenta de registros Candidato; ellos no tienen ninguno.
 *
 * Solución fiel y mínima, sin inventar nada:
 *
 *  1. Candidato HISTÓRICO (`candidatos.historico = true`) ligado al
 *     colaborador, en estado «contratado» y fechado en su FECHA DE INGRESO
 *     (created_at y contratado_en), para que «recibidos / contratados en el
 *     periodo» de los meses actuales no cambien. Sin vacante, sin QR, sin
 *     seguimientos, sin avisos; nunca se duplica. Además, los indicadores de
 *     reclutamiento excluyen `historico`.
 *  2. Hecho explícito «contrato indeterminado en papel»
 *     (`colaboradores.contrato_indeterminado_fisico_desde` = fin de su periodo
 *     de prueba + 1 día) SOLO si su periodo de prueba ya terminó por fecha
 *     (ingreso + duración del puesto) y PEOPLE no tiene un periodo abierto de
 *     esa persona (contrato vigente con término o evaluación en curso). Nunca
 *     se fabrica un documento generado ni se decide por `activado_en`.
 *  3. Quien sigue en su periodo de prueba queda donde digan sus hechos
 *     reales (FaseContratadoService).
 *
 * No se tocan: bajas (conservan su historial; no se muestran como activas),
 * quien ya tiene candidato de reclutamiento, altas en curso. Lo que no cuadra
 * (sin fecha de ingreso, puesto sin duración…) se reporta, nunca se ignora.
 *
 * @phpstan-type Fila array{colaborador_id: int, numero_empleado: string|null, nombre: string, sucursal: string|null, puesto: string|null, fecha_ingreso: string|null, fin_periodo: string|null, categoria: string, crear_candidato: bool, contrato_fisico_desde: string|null, fase_resultante: string|null, nota: string}
 */
class HistoricoEnFasesService
{
    /** Se ubica: candidato histórico (nuevo o ya existente). */
    public const UBICADO = 'ubicado';

    /** Ya viene de un proceso de reclutamiento de PEOPLE: no se toca. */
    public const CON_RECLUTAMIENTO = 'con_reclutamiento';

    public const BAJA = 'baja';

    public const EN_INCORPORACION = 'en_incorporacion';

    public const SIN_FECHA_INGRESO = 'sin_fecha_ingreso';

    public function __construct(
        private readonly FaseContratadoService $fases,
        private readonly AuditoriaService $auditoria,
    ) {}

    /**
     * Qué se haría con cada colaborador (no escribe nada).
     *
     * @param  int  $mesesSinDuracion  Duración que se asume SOLO para puestos sin duración de periodo de prueba configurada.
     * @param  int|string|null  $soloColaborador  ID o número de empleado (null = todos).
     * @return list<Fila>
     */
    public function plan(int $mesesSinDuracion = 6, int|string|null $soloColaborador = null, ?CarbonInterface $hoy = null): array
    {
        $hoy = CarbonImmutable::parse(($hoy ?? FechaMexico::inicioDeHoy())->toDateString());
        $mesesSinDuracion = max(1, $mesesSinDuracion);

        $colaboradores = Colaborador::withTrashed()
            ->with(['puesto', 'sucursalPrincipal:id,nombre,empresa_id'])
            ->when($soloColaborador !== null, fn (Builder $q) => $q->where(fn (Builder $s) => $s
                ->where('numero_empleado', (string) $soloColaborador)
                ->when(is_numeric($soloColaborador), fn (Builder $n) => $n->orWhere('id', (int) $soloColaborador))))
            ->orderBy('id')
            ->get();
        $ids = $colaboradores->pluck('id')->all();

        $candidatos = Candidato::withTrashed()->whereIn('colaborador_id', $ids)->get(['id', 'colaborador_id', 'historico', 'deleted_at'])->groupBy('colaborador_id');
        $hechos = $this->fases->hechosParaColaboradores($ids);
        $conPeriodoAbierto = ContratoLaboral::query()
            ->whereIn('colaborador_id', $ids)
            ->where('estado', EstadoContratoLaboral::Vigente->value)
            ->whereNotNull('fecha_fin')
            ->pluck('colaborador_id')
            ->concat(EvaluacionPeriodoPrueba::query()
                ->whereIn('colaborador_id', $ids)
                ->whereIn('estado', [EstadoEvaluacionPrueba::Pendiente->value, EstadoEvaluacionPrueba::Capturada->value, EstadoEvaluacionPrueba::Devuelta->value])
                ->pluck('colaborador_id'))
            ->map(fn ($id): int => (int) $id)
            ->flip();

        $filas = [];

        foreach ($colaboradores as $colaborador) {
            $base = [
                'colaborador_id' => $colaborador->id,
                'numero_empleado' => $colaborador->numero_empleado,
                'nombre' => $colaborador->nombreCompleto(),
                'sucursal' => $colaborador->sucursalPrincipal?->nombre,
                'puesto' => $colaborador->puesto?->nombre,
                'fecha_ingreso' => $colaborador->fecha_ingreso?->toDateString(),
                'fin_periodo' => null,
                'crear_candidato' => false,
                'contrato_fisico_desde' => null,
                'fase_resultante' => null,
            ];
            $delColaborador = $candidatos->get($colaborador->id);
            $deReclutamiento = $colaborador->candidato_id !== null
                || ($delColaborador !== null && $delColaborador->contains(fn (Candidato $c): bool => ! $c->historico));
            $historicoExistente = $delColaborador !== null && $delColaborador->contains(fn (Candidato $c): bool => $c->historico);

            if ($deReclutamiento) {
                $filas[] = [...$base, 'categoria' => self::CON_RECLUTAMIENTO, 'nota' => 'Ya tiene candidato de reclutamiento en PEOPLE: sigue su proceso normal.'];

                continue;
            }

            if ($colaborador->trashed() || $colaborador->estatus === EstadoUsuario::Inactivo || $colaborador->estado_alta === EstadoAltaColaborador::Baja) {
                $filas[] = [...$base, 'categoria' => self::BAJA, 'nota' => 'Baja: conserva su historial y no se muestra en las fases.'];

                continue;
            }

            if ($colaborador->estatus !== EstadoUsuario::Activo) {
                $filas[] = [...$base, 'categoria' => self::EN_INCORPORACION, 'nota' => sprintf('Estatus «%s» (alta en curso): se ubica cuando esté activo.', $colaborador->estatus->value)];

                continue;
            }

            if ($colaborador->fecha_ingreso === null) {
                $filas[] = [...$base, 'categoria' => self::SIN_FECHA_INGRESO, 'nota' => 'NO CUADRA: sin fecha de ingreso. Captúrala en su expediente y vuelve a correr el comando.'];

                continue;
            }

            $ingreso = CarbonImmutable::parse($colaborador->fecha_ingreso->toDateString());
            $mesesPuesto = (int) ($colaborador->puesto->meses_periodo_prueba ?? 0);
            $meses = $mesesPuesto >= 1 ? $mesesPuesto : $mesesSinDuracion;
            $fin = $ingreso->addMonthsNoOverflow($meses)->subDay();
            $delHecho = $hechos[$colaborador->id] ?? null;
            $notas = [];

            if ($mesesPuesto < 1) {
                $notas[] = sprintf('su puesto («%s») no tiene duración de periodo de prueba: se asumen %d meses', $colaborador->puesto->nombre ?? 'sin puesto', $mesesSinDuracion);
            }

            $fisicoDesde = null;
            $fase = $delHecho['fase'] ?? FaseContratadoService::INDUCCION;

            if (($delHecho['contrato_indeterminado'] ?? null) === FaseContratadoService::CONTRATO_GENERADO) {
                $notas[] = 'su contrato indeterminado ya está generado en PEOPLE';
            } elseif ($colaborador->contrato_indeterminado_fisico_desde !== null) {
                $notas[] = sprintf('contrato indeterminado en papel ya registrado (desde %s)', $colaborador->contrato_indeterminado_fisico_desde->format('d/m/Y'));
            } elseif ($ingreso->gt($hoy)) {
                $notas[] = 'NO CUADRA: su fecha de ingreso es futura; queda donde digan sus hechos';
            } elseif ($fin->gte($hoy)) {
                $notas[] = sprintf('sigue en su periodo de prueba (termina el %s): queda donde digan sus hechos', $fin->format('d/m/Y'));
            } elseif ($conPeriodoAbierto->has($colaborador->id)) {
                $notas[] = 'PEOPLE tiene abierto su periodo (contrato vigente con término o evaluación en curso): su indeterminado sale por Contratos / la evaluación';
            } else {
                $fisicoDesde = $fin->addDay()->toDateString();
                $fase = FaseContratadoService::CONTRATO_INDETERMINADO;
                $notas[] = sprintf('periodo de prueba terminado el %s: se registra su contrato indeterminado en papel', $fin->format('d/m/Y'));
            }

            $filas[] = [
                ...$base,
                'fin_periodo' => $fin->toDateString(),
                'categoria' => self::UBICADO,
                'crear_candidato' => ! $historicoExistente,
                'contrato_fisico_desde' => $fisicoDesde,
                'fase_resultante' => $fase,
                'nota' => ucfirst(implode('; ', [$historicoExistente ? 'ya tenía su registro histórico' : 'se crea su registro histórico', ...$notas])).'.',
            ];
        }

        return $filas;
    }

    /**
     * Aplica UNA fila del plan (idempotente: vuelve a comprobar que no exista
     * ya el candidato ni el hecho). Sin eventos ni avisos.
     *
     * @param  Fila  $fila
     * @return array{candidato_creado: bool, contrato_registrado: bool}
     */
    public function aplicar(array $fila): array
    {
        if ($fila['categoria'] !== self::UBICADO) {
            return ['candidato_creado' => false, 'contrato_registrado' => false];
        }

        return DB::transaction(function () use ($fila): array {
            $colaborador = Colaborador::query()->with('sucursalPrincipal:id,empresa_id')->lockForUpdate()->where('id', $fila['colaborador_id'])->first();

            if ($colaborador === null || $colaborador->fecha_ingreso === null) {
                return ['candidato_creado' => false, 'contrato_registrado' => false];
            }

            $creado = false;
            $registrado = false;

            if ($fila['crear_candidato'] && ! Candidato::withTrashed()->where('colaborador_id', $colaborador->id)->exists()) {
                // Mediodía: la fecha no cambia de día al convertir zonas horarias.
                $ingreso = CarbonImmutable::parse($colaborador->fecha_ingreso->toDateString().' 12:00:00');
                $candidato = new Candidato;
                $candidato->timestamps = false;
                $candidato->forceFill([
                    'nombre' => $colaborador->name,
                    'apellidos' => $colaborador->apellidos,
                    'empresa_id' => $colaborador->sucursalPrincipal?->empresa_id,
                    'sucursal_id' => $colaborador->sucursal_principal_id,
                    'departamento_id' => $colaborador->departamento_id,
                    'puesto_objetivo_id' => $colaborador->puesto_id,
                    'espontaneo' => false,
                    'historico' => true,
                    'estado' => EstadoCandidato::Contratado,
                    'etapa_maxima' => EstadoCandidato::Contratado->orden(),
                    'colaborador_id' => $colaborador->id,
                    'contratado_en' => $ingreso,
                    'observaciones' => 'Registro histórico: ya laboraba antes de PEOPLE (no viene de un proceso de reclutamiento del sistema). Creado por people:ubicar-historico-en-fases.',
                    'created_at' => $ingreso,
                    'updated_at' => $ingreso,
                ])->saveQuietly();
                $creado = true;

                $this->auditoria->registrar('candidato_historico_creado', $candidato, null, [
                    'colaborador_id' => $colaborador->id,
                    'fecha_ingreso' => $colaborador->fecha_ingreso->toDateString(),
                    'origen' => 'sistema',
                ]);
            }

            if ($fila['contrato_fisico_desde'] !== null && $colaborador->contrato_indeterminado_fisico_desde === null) {
                $colaborador->forceFill(['contrato_indeterminado_fisico_desde' => $fila['contrato_fisico_desde']])->saveQuietly();
                $registrado = true;

                $this->auditoria->registrar('contrato_indeterminado_fisico_registrado', $colaborador, null, [
                    'desde' => $fila['contrato_fisico_desde'],
                    'fin_periodo_prueba' => $fila['fin_periodo'],
                    'detalle' => 'Plantilla histórica: su contrato por tiempo indeterminado existe en papel (no lo generó PEOPLE).',
                    'origen' => 'sistema',
                ]);
            }

            return ['candidato_creado' => $creado, 'contrato_registrado' => $registrado];
        });
    }
}
