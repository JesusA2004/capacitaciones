<?php

namespace App\Services\RecepcionDocumental;

use App\Enums\EstadoRecepcionDocumento;
use App\Models\RecepcionDocumentalCorte;
use App\Models\RecepcionDocumentalRenglon;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Construye (materializa) los renglones esperados de un corte NO cerrado a
 * partir de las reglas activas. Idempotente: el índice único
 * (corte, regla, origen, tipo) impide duplicar y correrlo dos veces deja
 * exactamente lo mismo.
 *
 *  - Evento nuevo → renglón nuevo «Pendiente» con su snapshot.
 *  - Renglón existente → nunca se borra ni se cambia su snapshot; solo se
 *    liga a la versión digital vigente si cambió y, mientras siga
 *    PENDIENTE, se actualiza su cantidad esperada (lote semanal de listas
 *    de asistencia: altas y bajas de la semana).
 *  - Renglón PENDIENTE cuyo evento ya no devuelve la regla (cancelado o
 *    movido de mes) → «No aplica» con justificación automática.
 *  - Renglón que el sistema puso en «No aplica» y cuyo evento vuelve a
 *    aplicar → regresa a «Pendiente».
 *  - Reglas apagadas: sus renglones no se tocan.
 */
class MaterializadorCorteService
{
    public function __construct(
        private readonly CatalogoReglasRecepcion $catalogo,
        private readonly AuditoriaService $auditoria,
        private readonly EstadoCorteService $estados,
    ) {}

    /**
     * @return array{nuevos: int, ligados: int, no_aplica: int, reactivados: int, actualizados: int}
     */
    public function materializar(RecepcionDocumentalCorte $corte, ?User $actor = null): array
    {
        if ($corte->estaCerrado()) {
            throw ValidationException::withMessages(['corte' => 'El corte está cerrado: no se recalcula. Reábrelo con motivo si necesitas actualizarlo.']);
        }

        $periodo = PeriodoRecepcion::delCorte($corte);

        // Fuera de la transacción: solo lecturas acotadas al periodo.
        /** @var array<string, array<string, EsperadoRecepcion>> $porRegla */
        $porRegla = [];

        foreach ($this->catalogo->activas() as $regla) {
            $porRegla[$regla->regla()->value] = [];

            foreach ($regla->esperados($periodo) as $esperado) {
                $porRegla[$regla->regla()->value][$esperado->clave()] ??= $esperado;
            }
        }

        $conteo = DB::transaction(function () use ($corte, $porRegla, $periodo): array {
            $bloqueado = RecepcionDocumentalCorte::query()->lockForUpdate()->findOrFail($corte->id);

            if ($bloqueado->estaCerrado()) {
                throw ValidationException::withMessages(['corte' => 'El corte se cerró mientras se calculaba: no se recalcula.']);
            }

            $conteo = ['nuevos' => 0, 'ligados' => 0, 'no_aplica' => 0, 'reactivados' => 0, 'actualizados' => 0];
            $ahora = Carbon::now();

            foreach ($porRegla as $regla => $esperados) {
                $existentes = RecepcionDocumentalRenglon::query()
                    ->where('corte_id', $corte->id)
                    ->where('regla', $regla)
                    ->get()
                    ->keyBy(fn (RecepcionDocumentalRenglon $r): string => EsperadoRecepcion::claveDe($r->regla->value, $r->origen_type, $r->origen_id, $r->tipo_documento));

                $nuevos = [];

                foreach ($esperados as $clave => $esperado) {
                    $renglon = $existentes->get($clave);

                    if (! $renglon instanceof RecepcionDocumentalRenglon) {
                        $nuevos[] = $this->filaNueva($corte, $esperado, $ahora);

                        continue;
                    }

                    $cambios = [];

                    if ($esperado->generatedDocumentId !== null && $renglon->generated_document_id !== $esperado->generatedDocumentId) {
                        $cambios['generated_document_id'] = $esperado->generatedDocumentId;
                        $conteo['ligados']++;
                    }

                    if ($renglon->estado === EstadoRecepcionDocumento::NoAplica && $renglon->no_aplica_automatico) {
                        $cambios += ['estado' => EstadoRecepcionDocumento::Pendiente, 'justificacion' => null, 'no_aplica_automatico' => false];
                        $conteo['reactivados']++;
                    }

                    $cantidad = max(1, $esperado->cantidad);

                    if ($renglon->cantidad_esperada !== $cantidad && ($renglon->estado === EstadoRecepcionDocumento::Pendiente || isset($cambios['estado']))) {
                        $cambios += ['cantidad_esperada' => $cantidad, 'evento_descripcion' => mb_substr($esperado->descripcion, 0, 255)];
                        $conteo['actualizados']++;
                    }

                    if ($cambios !== []) {
                        $renglon->update($cambios);
                    }
                }

                foreach (array_chunk($nuevos, 500) as $lote) {
                    RecepcionDocumentalRenglon::query()->insert($lote);
                    $conteo['nuevos'] += count($lote);
                }

                $justificacion = sprintf('Automático: ya no se espera en %s — el evento de origen se canceló, cambió de mes o el documento dejó de pedirse en recepción (detectado el %s).', $periodo->etiqueta(), Carbon::now('America/Mexico_City')->format('d/m/Y'));

                foreach ($existentes as $clave => $renglon) {
                    if (! isset($esperados[$clave]) && $renglon->estado === EstadoRecepcionDocumento::Pendiente) {
                        $renglon->update(['estado' => EstadoRecepcionDocumento::NoAplica, 'justificacion' => $justificacion, 'no_aplica_automatico' => true]);
                        $conteo['no_aplica']++;
                    }
                }
            }

            return $conteo;
        });

        $corte->refresh();
        $corte->forceFill([
            'materializado_en' => Carbon::now(),
            'generado_en' => $corte->generado_en ?? Carbon::now(),
            'generado_por' => $corte->generado_por ?? $actor?->id,
        ])->save();
        $this->estados->recalcular($corte);

        if ($actor !== null || array_sum($conteo) > 0) {
            $this->auditoria->registrar('recepcion_documental_materializada', $corte, $actor, ['periodo' => $corte->periodo, ...$conteo]);
        }

        return $conteo;
    }

    /**
     * @return array<string, mixed>
     */
    private function filaNueva(RecepcionDocumentalCorte $corte, EsperadoRecepcion $e, Carbon $ahora): array
    {
        return [
            'corte_id' => $corte->id,
            'regla' => $e->regla->value,
            'tipo_documento' => mb_substr($e->tipoDocumento, 0, 60),
            'tipo_etiqueta' => mb_substr($e->tipoEtiqueta, 0, 160),
            'origen_type' => $e->origen->getMorphClass(),
            'origen_id' => (int) $e->origen->getKey(),
            'generated_document_id' => $e->generatedDocumentId,
            'colaborador_id' => $e->colaboradorId,
            'sucursal_id' => $e->sucursalId,
            'colaborador_nombre' => $e->colaboradorNombre !== null ? mb_substr($e->colaboradorNombre, 0, 200) : null,
            'numero_empleado' => $e->numeroEmpleado !== null ? mb_substr($e->numeroEmpleado, 0, 40) : null,
            'puesto_nombre' => $e->puestoNombre !== null ? mb_substr($e->puestoNombre, 0, 160) : null,
            'sucursal_nombre' => $e->sucursalNombre !== null ? mb_substr($e->sucursalNombre, 0, 160) : null,
            'fecha_evento' => $e->fechaEvento,
            'evento_descripcion' => mb_substr($e->descripcion, 0, 255),
            'cantidad_esperada' => max(1, $e->cantidad),
            'cantidad_recibida' => 0,
            'estado' => EstadoRecepcionDocumento::Pendiente->value,
            'no_aplica_automatico' => false,
            'incidencia' => false,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ];
    }
}
