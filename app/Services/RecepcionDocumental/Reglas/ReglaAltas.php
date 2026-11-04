<?php

namespace App\Services\RecepcionDocumental\Reglas;

use App\Enums\EstadoContratoLaboral;
use App\Enums\EstadoFlujoDocumento;
use App\Enums\ReglaRecepcionDocumental;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\GeneratedDocument;
use App\Services\DocumentosMaestros\DocumentoProcesoService;
use App\Services\RecepcionDocumental\EsperadoRecepcion;
use App\Services\RecepcionDocumental\PeriodoRecepcion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * ALTAS: por cada colaborador cuya fecha_ingreso (DATE) cae en el mes, los
 * originales del PAQUETE DE ALTA de su puesto
 * (config/documentos_maestros.php → paquetes.alta.<modalidad>.<grupo>).
 *
 * Fuente de verdad = el paquete configurado + los GeneratedDocument reales
 * de su contrato inicial (la unión: si ya se generaron, se ligan; si aún no,
 * se espera el paquete de su puesto). Hoy el paquete solo incluye «No
 * competencia» para Gestor y Gerente, aunque el negocio pidió 3 originales
 * por alta para todos: se respeta la configuración (docs/RECEPCION_DOCUMENTAL_RH.md
 * → «No competencia por puesto»). Un puesto marcado «no requiere documentos
 * laborales» solo espera lo que realmente se haya generado.
 *
 * El contrato indeterminado de una RENOVACIÓN no cae aquí (ReglaContratoIndeterminado).
 */
class ReglaAltas implements ReglaRecepcion
{
    public function __construct(private readonly DocumentoProcesoService $documentos) {}

    public function regla(): ReglaRecepcionDocumental
    {
        return ReglaRecepcionDocumental::Altas;
    }

    public function esperados(PeriodoRecepcion $periodo): array
    {
        $colaboradores = Colaborador::query()
            ->whereBetween('fecha_ingreso', $periodo->rangoFechas())
            ->with(['puesto:id,nombre,grupo_documental,no_requiere_documentos_laborales', 'sucursalPrincipal:id,nombre'])
            ->orderBy('id')
            ->get();

        if ($colaboradores->isEmpty()) {
            return [];
        }

        $ids = $colaboradores->modelKeys();
        $contratos = ContratoLaboral::query()
            ->whereIn('colaborador_id', $ids)
            ->where('estado', '!=', EstadoContratoLaboral::Cancelado->value)
            ->orderBy('id')
            ->get(['id', 'colaborador_id', 'tipo', 'contrato_anterior_id', 'estado']);
        $iniciales = $contratos->whereNull('contrato_anterior_id')->groupBy('colaborador_id')->map(fn ($grupo) => $grupo->first());
        $renovaciones = $contratos->whereNotNull('contrato_anterior_id')->modelKeys();
        $morphContrato = (new ContratoLaboral)->getMorphClass();

        // Documentos reales vigentes (no cancelados) del paquete de alta.
        $documentos = GeneratedDocument::query()
            ->whereIn('colaborador_id', $ids)
            ->whereIn('clave_plantilla', self::clavesDeAlta())
            ->where(fn (Builder $q) => $q->whereNull('estado_flujo')->orWhere('estado_flujo', '!=', EstadoFlujoDocumento::Cancelado->value))
            ->when($renovaciones !== [], fn (Builder $q) => $q->whereNot(fn (Builder $r) => $r
                ->where('documentable_type', $morphContrato)
                ->whereIn('documentable_id', $renovaciones)))
            ->orderByDesc('id')
            ->get(['id', 'colaborador_id', 'clave_plantilla']);
        $porColaborador = $documentos->groupBy('colaborador_id');

        $esperados = [];
        $modalidadDefecto = (string) config('recepcion_documental.modalidad_alta_por_defecto', 'capacitacion_inicial');

        foreach ($colaboradores as $colaborador) {
            $inicial = $iniciales->get($colaborador->id);
            $modalidad = $inicial instanceof ContratoLaboral ? $inicial->tipo->value : $modalidadDefecto;
            $paquete = $colaborador->puesto?->no_requiere_documentos_laborales
                ? []
                : $this->documentos->clavesPaquete('alta', $modalidad, $colaborador);

            /** @var array<string, int> $reales clave => id del documento vigente más reciente */
            $reales = [];

            foreach ($porColaborador->get($colaborador->id, collect()) as $documento) {
                $clave = (string) $documento->clave_plantilla;
                $reales[$clave] ??= $documento->id;
            }

            $claves = array_values(array_unique([...$paquete, ...array_keys($reales)]));
            $fecha = $colaborador->fecha_ingreso instanceof Carbon ? $colaborador->fecha_ingreso->toDateString() : null;
            $descripcion = sprintf('Alta del %s%s', $colaborador->fecha_ingreso?->format('d/m/Y') ?? 'sin fecha', $colaborador->puesto !== null ? sprintf(' · %s', $colaborador->puesto->nombre) : '');

            foreach ($claves as $clave) {
                $esperados[] = EsperadoRecepcion::deColaborador(
                    $this->regla(),
                    $clave,
                    EsperadoRecepcion::etiquetaTipo($clave),
                    $colaborador,
                    $colaborador,
                    $fecha,
                    $descripcion,
                    $reales[$clave] ?? null,
                );
            }
        }

        return $esperados;
    }

    /**
     * Todas las claves que algún paquete de alta puede pedir.
     *
     * @return list<string>
     */
    public static function clavesDeAlta(): array
    {
        $claves = [];

        foreach ((array) config('documentos_maestros.paquetes.alta', []) as $modalidad) {
            foreach ((array) $modalidad as $paquete) {
                foreach ((array) $paquete as $clave) {
                    if (is_string($clave)) {
                        $claves[] = $clave;
                    }
                }
            }
        }

        return array_values(array_unique($claves));
    }
}
