<?php

namespace App\Services\Finiquitos;

use App\Enums\EstadoCierreLaboral;
use App\Enums\EstadoFiniquito;
use App\Enums\TipoFiniquito;
use App\Models\Baja;
use App\Models\CierreLaboral;
use App\Models\Colaborador;
use App\Models\FiniquitoCalculo;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\CierreLaboral\CierreLaboralService;
use App\Services\Colaboradores\FotoColaboradorService;
use App\Support\Paginacion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pestaña «Finiquitos» de Bajas (PDF Etapa 1, F1–F4): localiza y procesa cada
 * FiniquitoCalculo sin entrar al expediente. Es el MISMO registro que muestra
 * la baja: aquí solo se lista y se orquesta; montos y reglas siguen en
 * FiniquitoService y la entrega en CierreLaboralService.
 *
 * Presentación: Calculado (borrador) → Revisado (revisado o aprobado) →
 * Firmado → Pagado. «Aprobado» se conserva como subestado de los expedientes
 * que ya lo tienen.
 */
class FiniquitoConsultaService
{
    public const MODULO = 'finiquitos';

    /** @var array<string, list<EstadoFiniquito>> */
    private const ESTADOS_FLUJO = [
        'calculado' => [EstadoFiniquito::Borrador],
        'revisado' => [EstadoFiniquito::Revisado, EstadoFiniquito::Aprobado],
        'firmado' => [EstadoFiniquito::Firmado],
        'pagado' => [EstadoFiniquito::Pagado],
    ];

    public function __construct(
        private readonly FiniquitoService $finiquitos,
        private readonly CierreLaboralService $cierres,
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly FotoColaboradorService $fotos,
    ) {}

    /**
     * @param  array<string, mixed>  $filtros  busqueda, estado, sucursal_id, tipo, per_page
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function listado(User $usuario, array $filtros): LengthAwarePaginator
    {
        $estado = self::ESTADOS_FLUJO[(string) ($filtros['estado'] ?? '')] ?? null;
        $busqueda = trim((string) ($filtros['busqueda'] ?? ''));
        $tipo = TipoFiniquito::tryFrom((string) ($filtros['tipo'] ?? ''));

        return $this->base($usuario)
            ->with(['colaborador' => fn ($q) => $q->withTrashed()->with(['puesto:id,nombre', 'sucursalPrincipal:id,nombre']), 'solicitudInterna.causalRotacion'])
            ->when($estado !== null, fn (Builder $q) => $q->whereIn('estado', array_map(fn (EstadoFiniquito $e): string => $e->value, $estado ?? [])))
            ->when(is_numeric($filtros['sucursal_id'] ?? null), fn (Builder $q) => $q->whereIn('colaborador_id', Colaborador::query()->withTrashed()->where('sucursal_principal_id', (int) $filtros['sucursal_id'])->select('id')))
            ->when($busqueda !== '', fn (Builder $q) => $q->whereIn('colaborador_id', Colaborador::query()->withTrashed()->buscar($busqueda)->select('id')))
            ->when($tipo instanceof TipoFiniquito, fn (Builder $q) => $q->where(fn (Builder $w) => $this->filtrarTipo($w, $tipo ?? TipoFiniquito::Finiquito)))
            ->orderByRaw("case when estado = 'pagado' then 1 else 0 end")
            ->orderByDesc('id')
            ->paginate(Paginacion::porPagina(20, $filtros['per_page'] ?? null))
            ->withQueryString()
            ->through(fn (FiniquitoCalculo $f): array => $this->fila($f, $usuario));
    }

    /**
     * Tarjetas superiores: conteo por estatus y monto pendiente de pago.
     *
     * @return array{calculados: int, revisados: int, firmados: int, pagados: int, monto_pendiente: float}
     */
    public function resumen(User $usuario): array
    {
        $conteos = $this->base($usuario)->selectRaw('estado, count(*) as total')->groupBy('estado')->pluck('total', 'estado');
        $contar = fn (string $flujo): int => (int) array_sum(array_map(fn (EstadoFiniquito $e): int => (int) ($conteos[$e->value] ?? 0), self::ESTADOS_FLUJO[$flujo]));

        return [
            'calculados' => $contar('calculado'),
            'revisados' => $contar('revisado'),
            'firmados' => $contar('firmado'),
            'pagados' => $contar('pagado'),
            'monto_pendiente' => round((float) $this->base($usuario)->where('estado', '!=', EstadoFiniquito::Pagado->value)->sum('neto'), 2),
        ];
    }

    /**
     * Detalle editable de la pestaña: mismo desglose que el expediente.
     *
     * @return array<string, mixed>
     */
    public function detalle(FiniquitoCalculo $finiquito, User $usuario): array
    {
        $finiquito->loadMissing(['colaborador' => fn ($q) => $q->withTrashed()->with(['puesto:id,nombre', 'sucursalPrincipal:id,nombre']), 'solicitudInterna.causalRotacion', 'ajustes.usuario:id,name,apellidos']);
        $tipo = $this->finiquitos->tipoDe($finiquito);
        $causal = $finiquito->solicitudInterna->causalRotacion;
        $editable = $usuario->can('editarAjustes', $finiquito);

        return [
            ...$this->fila($finiquito, $usuario),
            'fecha_ingreso' => $finiquito->fecha_ingreso->toDateString(),
            'fecha_baja' => $finiquito->fecha_baja->toDateString(),
            'sueldo_diario' => (float) $finiquito->sueldo_diario,
            'total_percepciones' => (float) $finiquito->total_percepciones,
            'total_deducciones' => (float) $finiquito->total_deducciones,
            'desglose' => $this->finiquitos->desglose($finiquito),
            'historial_ajustes' => $this->finiquitos->historialAjustes($finiquito),
            'ultima_edicion' => $this->finiquitos->ultimaEdicion($finiquito),
            'causal_implica_indemnizacion' => $causal !== null && $causal->implica_indemnizacion,
            'advertencia_liquidacion' => $tipo === TipoFiniquito::Liquidacion
                ? 'La causal está marcada como «Implica indemnización»: se propone liquidación. Es una clasificación con revisión de RH/Jurídico según el caso; ajusta los conceptos con motivo si no aplica.'
                : null,
            'puede' => [
                'editar' => $editable,
                'recalcular' => $editable,
                'revisar' => $usuario->can('revisar', $finiquito),
                'cambiar_tipo' => $editable,
                'recibo' => $usuario->can('descargar', $finiquito),
                'subir_firmado' => $usuario->can('subirFirmado', $finiquito) && in_array($finiquito->estado, [EstadoFiniquito::Revisado, EstadoFiniquito::Aprobado], true),
                'registrar_pago' => $finiquito->estado === EstadoFiniquito::Firmado && $this->puedeRegistrarPago($finiquito, $usuario),
            ],
        ];
    }

    public function puedeRegistrarPago(FiniquitoCalculo $finiquito, User $usuario): bool
    {
        $cierre = $this->cierreDe($finiquito);

        return $cierre !== null ? $usuario->can('entregarFiniquito', $cierre) : $usuario->can('editarAjustes', $finiquito) || $usuario->can('finiquitos.calcular');
    }

    /**
     * «Registrar pago»: exige el finiquito firmado. Si tiene cierre abierto,
     * usa la entrega del cierre (congela el desglose y ejecuta la baja laboral),
     * para no dejar dos caminos; si no, la confirmación administrativa.
     *
     * @param  array{fecha: string, forma_pago: string|null, comentario: string|null}  $datos
     */
    public function registrarPago(FiniquitoCalculo $finiquito, User $actor, array $datos): FiniquitoCalculo
    {
        if ($finiquito->estado !== EstadoFiniquito::Firmado) {
            throw ValidationException::withMessages(['estado' => 'El finiquito debe estar firmado antes de registrar el pago.']);
        }

        $cierre = $this->cierreDe($finiquito);

        if ($cierre !== null && ! $cierre->estado->esFinal() && $cierre->estado !== EstadoCierreLaboral::ExpedienteCerrado) {
            $this->cierres->registrarEntregaFiniquito($cierre, $actor, $datos);

            return $finiquito->refresh();
        }

        return $this->finiquitos->confirmarPago($finiquito, $actor, (string) ($datos['forma_pago'] ?? 'Pago registrado'), Carbon::parse($datos['fecha']));
    }

    /**
     * Sube el recibo firmado y lo deja en estado Firmado.
     */
    public function subirFirmado(FiniquitoCalculo $finiquito, UploadedFile $archivo, User $actor): FiniquitoCalculo
    {
        return $this->finiquitos->registrarFirmado($finiquito, $archivo, $actor);
    }

    /**
     * Recibo PDF: vista previa mientras se calcula; desde «Revisado», el PDF
     * registrado en el expediente (se regenera si los montos cambiaron); una
     * vez firmado, el documento que vale.
     */
    public function recibo(FiniquitoCalculo $finiquito, User $actor): Response
    {
        if ($finiquito->estado === EstadoFiniquito::Borrador) {
            return response($this->finiquitos->vistaPreviaPdf($finiquito), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="recibo-finiquito-vista-previa.pdf"',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'no-store',
            ]);
        }

        if (in_array($finiquito->estado, [EstadoFiniquito::Revisado, EstadoFiniquito::Aprobado], true)) {
            $congelado = is_array($finiquito->snapshot) && isset($finiquito->snapshot['neto']) ? round((float) $finiquito->snapshot['neto'], 2) : null;

            if ($finiquito->documento_generado_path === null || $congelado !== round((float) $finiquito->neto, 2)) {
                $finiquito = $this->finiquitos->generarPdf($finiquito, $actor);
            }
        }

        if ($finiquito->documento_generado_path === null && $finiquito->documento_firmado_path === null) {
            return response($this->finiquitos->vistaPreviaPdf($finiquito), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="recibo-finiquito.pdf"',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'no-store',
            ]);
        }

        return $this->finiquitos->descargarPdf($finiquito);
    }

    /**
     * @return Builder<FiniquitoCalculo>
     */
    private function base(User $usuario): Builder
    {
        $query = FiniquitoCalculo::query();
        $this->alcance->limitarPorModulo($query, $usuario, self::MODULO, 'colaborador_id', true);

        return $query;
    }

    /**
     * El tipo guardado manda; los anteriores (NULL) se derivan igual que
     * FiniquitoService::tipoDe().
     *
     * @param  Builder<FiniquitoCalculo>  $query
     */
    private function filtrarTipo(Builder $query, TipoFiniquito $elegido): void
    {
        $liquidacion = $elegido === TipoFiniquito::Liquidacion;

        $query->where('tipo_documento', $elegido->value)
            ->orWhere(fn (Builder $w) => $w->whereNull('tipo_documento')->where(function (Builder $d) use ($liquidacion): void {
                if ($liquidacion) {
                    $d->where('indemnizacion', '>', 0)->orWhereHas('solicitudInterna.causalRotacion', fn (Builder $c) => $c->where('implica_indemnizacion', true));
                } else {
                    $d->where('indemnizacion', '<=', 0)->whereDoesntHave('solicitudInterna.causalRotacion', fn (Builder $c) => $c->where('implica_indemnizacion', true));
                }
            }));
    }

    /**
     * @return array<string, mixed>
     */
    private function fila(FiniquitoCalculo $finiquito, User $usuario): array
    {
        $colaborador = $finiquito->colaborador;
        $solicitud = $finiquito->solicitudInterna;
        $forma = $solicitud->tipo_baja?->forma();
        $tipo = $this->finiquitos->tipoDe($finiquito);
        $flujo = $this->estadoFlujo($finiquito->estado);
        $cierre = $this->cierreDe($finiquito);

        return [
            'id' => $finiquito->id,
            'colaborador' => $colaborador->nombreCompleto(),
            'foto_url' => $this->fotos->urlPara($colaborador, $usuario),
            'puesto' => $colaborador->puesto?->nombre,
            'sucursal' => $colaborador->sucursalPrincipal?->nombre,
            'forma' => $forma?->value,
            'forma_etiqueta' => $forma?->etiqueta(),
            'forma_token' => $forma?->token(),
            'causal' => $solicitud->causalRotacion?->etiquetaConClave(),
            'tipo' => $tipo->value,
            'tipo_etiqueta' => $tipo->etiqueta(),
            'monto' => (float) $finiquito->neto,
            'estado' => $finiquito->estado->value,
            'estado_flujo' => $flujo,
            'subestado' => $finiquito->estado === EstadoFiniquito::Aprobado ? 'Aprobado' : null,
            'fecha_pago' => $finiquito->pagado_en?->toDateString(),
            'referencia_pago' => $finiquito->referencia_pago,
            'baja_id' => $cierre !== null ? Baja::query()->where('cierre_laboral_id', $cierre->id)->value('id') : null,
        ];
    }

    private function estadoFlujo(EstadoFiniquito $estado): string
    {
        foreach (self::ESTADOS_FLUJO as $flujo => $estados) {
            if (in_array($estado, $estados, true)) {
                return $flujo;
            }
        }

        return 'calculado';
    }

    private function cierreDe(FiniquitoCalculo $finiquito): ?CierreLaboral
    {
        return CierreLaboral::query()->where('solicitud_interna_id', $finiquito->solicitud_interna_id)->latest('id')->first();
    }
}
