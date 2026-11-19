<?php

namespace App\Services\RecepcionDocumental;

use App\Enums\EstadoRecepcionDocumento;
use App\Models\RecepcionDocumentalCatalogo;
use App\Models\RecepcionDocumentalCorte;
use App\Models\RecepcionDocumentalRenglon;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Construye (materializa) los renglones esperados de un corte NO cerrado a
 * partir del catálogo configurable. Idempotente: el índice único
 * (corte, regla, origen, tipo) impide duplicar y correrlo dos veces deja
 * exactamente lo mismo.
 *
 *  - Evento nuevo → renglón nuevo «Pendiente» con su snapshot.
 *  - Renglón existente → nunca se borra ni se cambia su snapshot de
 *    persona; solo se liga a la versión digital vigente si cambió y,
 *    mientras siga PENDIENTE, se actualizan su cantidad esperada (copias,
 *    lote semanal, colaboradores activos) y el nombre del documento.
 *  - Renglón PENDIENTE cuyo evento ya no devuelve la regla (cancelado,
 *    movido de mes, documento o entrada quitados del catálogo) → «No aplica»
 *    con justificación automática que dice por qué.
 *  - Renglón que el sistema puso en «No aplica» y vuelve a esperarse →
 *    regresa a «Pendiente».
 *  - Lo RECIBIDO, observado o no recibido nunca se toca: si la nueva
 *    configuración ya no lo espera, o espera otra cantidad, se reporta como
 *    conflicto (planificar() → conflictos) para que RH lo vea, nunca en
 *    silencio.
 *
 * planificar() es puro (solo lee): lo usan la materialización real y la
 * simulación del recálculo antes de guardar un cambio del catálogo.
 *
 * @phpstan-type Calculo array{esperados: array<string, array<string, EsperadoRecepcion>>, apagadas: array<string, string>, agregadas: list<RecepcionDocumentalCatalogo>}
 * @phpstan-type Conflicto array{renglon_id: int, tipo: string, sucursal: string, documento: string, persona: string|null, estado: string, mensaje: string}
 * @phpstan-type Plan array{nuevos: list<EsperadoRecepcion>, cambios: array<int, array<string, mixed>>, conteo: array{nuevos: int, ligados: int, no_aplica: int, reactivados: int, actualizados: int}, conflictos: list<Conflicto>, renglones: Collection<int, RecepcionDocumentalRenglon>}
 */
class MaterializadorCorteService
{
    /** @var list<Conflicto> */
    private array $ultimosConflictos = [];

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
        $calculo = $this->calcular($periodo, $this->catalogo->entradas());

        $plan = DB::transaction(function () use ($corte, $calculo, $periodo): array {
            $bloqueado = RecepcionDocumentalCorte::query()->lockForUpdate()->findOrFail($corte->id);

            if ($bloqueado->estaCerrado()) {
                throw ValidationException::withMessages(['corte' => 'El corte se cerró mientras se calculaba: no se recalcula.']);
            }

            $plan = $this->planificar($corte, $calculo, $periodo);

            foreach ($plan['cambios'] as $id => $cambios) {
                $renglon = $plan['renglones']->get($id);

                if ($renglon instanceof RecepcionDocumentalRenglon) {
                    $renglon->update($cambios);
                }
            }

            $ahora = Carbon::now();

            foreach (array_chunk(array_map(fn (EsperadoRecepcion $e): array => $this->filaNueva($corte, $e, $ahora), $plan['nuevos']), 500) as $lote) {
                RecepcionDocumentalRenglon::query()->insert($lote);
            }

            return $plan;
        });

        $conteo = $plan['conteo'];
        $this->ultimosConflictos = $plan['conflictos'];

        $corte->refresh();
        $corte->forceFill([
            'materializado_en' => Carbon::now(),
            'generado_en' => $corte->generado_en ?? Carbon::now(),
            'generado_por' => $corte->generado_por ?? $actor?->id,
        ])->save();
        $this->estados->recalcular($corte);

        if ($actor !== null || array_sum($conteo) > 0) {
            $this->auditoria->registrar('recepcion_documental_materializada', $corte, $actor, ['periodo' => $corte->periodo, ...$conteo, 'conflictos' => count($plan['conflictos'])]);
        }

        return $conteo;
    }

    /**
     * Conflictos de la última materialización (lo recibido que la
     * configuración ya no espera, o espera en otra cantidad).
     *
     * @return list<Conflicto>
     */
    public function ultimosConflictos(): array
    {
        return $this->ultimosConflictos;
    }

    /**
     * Lo esperado en el periodo con las entradas dadas (guardadas o en
     * borrador), agrupado por BLOQUE (la regla del renglón).
     *
     * @param  list<ReglaConfigurada>  $configuradas
     * @return Calculo
     */
    public function calcular(PeriodoRecepcion $periodo, array $configuradas): array
    {
        $esperados = [];
        $apagadas = [];
        $agregadas = [];

        foreach ($configuradas as $configurada) {
            $entrada = $configurada->entrada;

            if ($entrada->esPersonalizada()) {
                $agregadas[] = $entrada;
            }

            if (! $configurada->activa()) {
                if (! $entrada->esPersonalizada()) {
                    $apagadas[$entrada->regla->value] = $entrada->nombre;
                }

                continue;
            }

            if ($periodo->esFuturo()) {
                continue;
            }

            foreach ($configurada->esperados($periodo) as $esperado) {
                $esperados[$esperado->regla->value][$esperado->clave()] ??= $esperado;
            }
        }

        return ['esperados' => $esperados, 'apagadas' => $apagadas, 'agregadas' => $agregadas];
    }

    /**
     * Qué cambiaría en el corte con lo esperado calculado. Solo lee.
     *
     * @param  Calculo  $calculo
     * @return Plan
     */
    public function planificar(?RecepcionDocumentalCorte $corte, array $calculo, PeriodoRecepcion $periodo): array
    {
        $renglones = $corte !== null && $corte->exists
            ? RecepcionDocumentalRenglon::query()->where('corte_id', $corte->id)->get()->keyBy('id')
            : new Collection;
        $porRegla = $renglones->groupBy(fn (RecepcionDocumentalRenglon $r): string => $r->regla->value);
        $bloques = array_values(array_unique([...array_keys($calculo['esperados']), ...$porRegla->keys()->map(fn (mixed $k): string => (string) $k)->all()]));
        $hoy = Carbon::now('America/Mexico_City')->format('d/m/Y');

        $nuevos = [];
        $cambios = [];
        $conflictos = [];
        $conteo = ['nuevos' => 0, 'ligados' => 0, 'no_aplica' => 0, 'reactivados' => 0, 'actualizados' => 0];

        foreach ($bloques as $regla) {
            $esperados = $calculo['esperados'][$regla] ?? [];
            $existentes = $porRegla->get($regla, new Collection)
                ->keyBy(fn (RecepcionDocumentalRenglon $r): string => EsperadoRecepcion::claveDe($r->regla->value, $r->origen_type, $r->origen_id, $r->tipo_documento));

            foreach ($esperados as $clave => $esperado) {
                $renglon = $existentes->get($clave);

                if (! $renglon instanceof RecepcionDocumentalRenglon) {
                    $nuevos[] = $esperado;
                    $conteo['nuevos']++;

                    continue;
                }

                $c = [];

                if ($esperado->generatedDocumentId !== null && $renglon->generated_document_id !== $esperado->generatedDocumentId) {
                    $c['generated_document_id'] = $esperado->generatedDocumentId;
                    $conteo['ligados']++;
                }

                if ($renglon->estado === EstadoRecepcionDocumento::NoAplica && $renglon->no_aplica_automatico) {
                    $c += ['estado' => EstadoRecepcionDocumento::Pendiente, 'justificacion' => null, 'no_aplica_automatico' => false];
                    $conteo['reactivados']++;
                }

                $cantidad = max(1, $esperado->cantidad);
                $editable = $renglon->estado === EstadoRecepcionDocumento::Pendiente || isset($c['estado']);
                $etiqueta = mb_substr($esperado->tipoEtiqueta, 0, 160);

                if ($editable && ($renglon->cantidad_esperada !== $cantidad || $renglon->tipo_etiqueta !== $etiqueta)) {
                    $c += ['cantidad_esperada' => $cantidad, 'tipo_etiqueta' => $etiqueta, 'evento_descripcion' => mb_substr($esperado->descripcion, 0, 255)];
                    $conteo['actualizados']++;
                }

                if (! $editable && $renglon->estado !== EstadoRecepcionDocumento::NoAplica && $renglon->cantidad_esperada !== $cantidad) {
                    $conflictos[] = $this->conflicto($renglon, 'cantidad_distinta', sprintf(
                        'Ya está marcado como «%s» con %d papel(es); la configuración ahora espera %d. Se conserva lo marcado: corrígelo a mano si hace falta.',
                        $renglon->estado->etiqueta(), $renglon->cantidad_esperada, $cantidad,
                    ));
                }

                if ($renglon->estado === EstadoRecepcionDocumento::NoAplica && ! $renglon->no_aplica_automatico) {
                    $conflictos[] = $this->conflicto($renglon, 'no_aplica_manual', 'Se marcó a mano como «No aplica» y la configuración lo vuelve a esperar. Se conserva la decisión manual.');
                }

                if ($c !== []) {
                    $cambios[$renglon->id] = $c;
                }
            }

            foreach ($existentes as $clave => $renglon) {
                if (isset($esperados[$clave])) {
                    continue;
                }

                if ($renglon->estado === EstadoRecepcionDocumento::Pendiente) {
                    $cambios[$renglon->id] = ['estado' => EstadoRecepcionDocumento::NoAplica, 'justificacion' => $this->justificacion($renglon, $calculo, $periodo, $hoy), 'no_aplica_automatico' => true];
                    $conteo['no_aplica']++;
                } elseif ($renglon->estado !== EstadoRecepcionDocumento::NoAplica) {
                    $conflictos[] = $this->conflicto($renglon, 'ya_no_se_espera', sprintf(
                        'Ya está marcado como «%s», pero con la configuración actual ya no se espera. Se conserva tal cual (con su evidencia).',
                        $renglon->estado->etiqueta(),
                    ));
                }
            }
        }

        return ['nuevos' => $nuevos, 'cambios' => $cambios, 'conteo' => $conteo, 'conflictos' => $conflictos, 'renglones' => $renglones];
    }

    /**
     * Por qué un renglón pendiente pasa solo a «No aplica».
     *
     * @param  Calculo  $calculo
     */
    private function justificacion(RecepcionDocumentalRenglon $r, array $calculo, PeriodoRecepcion $periodo, string $hoy): string
    {
        if (str_starts_with($r->tipo_documento, 'personalizado.')) {
            $duena = null;

            foreach ($calculo['agregadas'] as $entrada) {
                if ($entrada->esDuenaDeTipo($r->tipo_documento)) {
                    $duena = $entrada;

                    break;
                }
            }

            if ($duena === null) {
                return sprintf('Automático: «%s» se quitó del catálogo de lo que se recibe; ya no se espera en %s (detectado el %s).', $r->tipo_etiqueta, $periodo->etiqueta(), $hoy);
            }

            if (! $duena->activa) {
                return sprintf('Automático: «%s» se desactivó en el catálogo de lo que se recibe; ya no se espera en %s (detectado el %s). Si se vuelve a activar, regresa a «Pendiente».', $duena->nombre, $periodo->etiqueta(), $hoy);
            }

            return sprintf('Automático: con la configuración de «%s» en el catálogo ya no se espera en %s (cambió su cálculo, sus sucursales o sus áreas; detectado el %s).', $duena->nombre, $periodo->etiqueta(), $hoy);
        }

        if (isset($calculo['apagadas'][$r->regla->value])) {
            return sprintf('Automático: «%s» se desactivó en el catálogo de lo que se recibe; ya no se espera en %s (detectado el %s). Si se vuelve a activar, regresa a «Pendiente».', $calculo['apagadas'][$r->regla->value], $periodo->etiqueta(), $hoy);
        }

        return sprintf('Automático: ya no se espera en %s — el evento de origen se canceló, cambió de mes o el documento dejó de pedirse en recepción (detectado el %s).', $periodo->etiqueta(), $hoy);
    }

    /**
     * @return Conflicto
     */
    private function conflicto(RecepcionDocumentalRenglon $r, string $tipo, string $mensaje): array
    {
        return [
            'renglon_id' => $r->id,
            'tipo' => $tipo,
            'sucursal' => $r->sucursal_nombre ?? 'Sin sucursal',
            'documento' => $r->tipo_etiqueta,
            'persona' => $r->colaborador_nombre,
            'estado' => $r->estado->etiqueta(),
            'mensaje' => $mensaje,
        ];
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
