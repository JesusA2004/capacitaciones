<?php

namespace App\Http\Controllers\Rh;

use App\Enums\EstadoFlujoDocumento;
use App\Enums\TipoBaja;
use App\Http\Controllers\Controller;
use App\Models\CierreLaboral;
use App\Models\Colaborador;
use App\Models\EvaluacionPeriodoPrueba;
use App\Models\GeneratedDocument;
use App\Services\AlcanceOrganizacionalService;
use App\Services\CicloLaboral\CicloLaboralService;
use App\Services\CicloLaboral\OrganizacionJerarquiaService;
use App\Services\CierreLaboral\CierreLaboralService;
use App\Services\Contratos\EvaluacionPeriodoPruebaService;
use App\Services\Onboarding\OnboardingService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ficha del colaborador en su ciclo laboral (Contratación → Onboarding →
 * Periodo de prueba → Activo → Cierre). Todo el estado viene de
 * CicloLaboralService; aquí solo se arma la vista.
 */
class CicloColaboradorController extends Controller
{
    public function __construct(
        private readonly CicloLaboralService $ciclo,
        private readonly OnboardingService $onboarding,
        private readonly EvaluacionPeriodoPruebaService $evaluaciones,
        private readonly CierreLaboralService $cierres,
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly OrganizacionJerarquiaService $jerarquia,
    ) {}

    public function show(Request $request, Colaborador $colaborador): Response
    {
        $usuario = $request->user();
        $enCadena = $usuario->colaborador !== null && $this->jerarquia->estaEnCadenaDeMando($usuario->colaborador, $colaborador);

        abort_unless($this->alcance->alcanzaColaborador($usuario, $colaborador) || $enCadena, 403);

        $estado = $this->ciclo->obtenerEstado($colaborador, $usuario);
        $colaborador->loadMissing(['puesto:id,nombre', 'sucursalPrincipal:id,nombre']);
        $proceso = $this->onboarding->procesoActual($colaborador);
        $evaluacion = isset($estado['evaluacion_id']) ? EvaluacionPeriodoPrueba::query()->with(['contrato', 'colaborador'])->find($estado['evaluacion_id']) : null;
        $cierre = isset($estado['cierre_id']) ? CierreLaboral::query()->find($estado['cierre_id']) : null;
        $puedeOperarFisico = $usuario->can('documentos_laborales.operar_fisico');

        $documentos = GeneratedDocument::query()
            ->where('colaborador_id', $colaborador->id)
            ->where(fn ($q) => $q->whereNull('estado_flujo')->orWhere('estado_flujo', '!=', EstadoFlujoDocumento::Cancelado->value))
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->map(fn (GeneratedDocument $d) => [
                'id' => $d->id,
                'titulo' => $d->titulo,
                'clave' => $d->clave_plantilla,
                'estado' => $d->estado_flujo?->value,
                'estado_etiqueta' => $d->estado_flujo?->etiqueta(),
                'acciones' => $puedeOperarFisico ? self::pasosFisicos($d->estado_flujo) : [],
            ])
            ->values()
            ->all();

        return Inertia::render('Rh/Colaboradores/Ciclo', [
            'colaborador' => [
                'id' => $colaborador->id,
                'nombre' => $colaborador->nombreCompleto(),
                'numero_empleado' => $colaborador->numero_empleado,
                'puesto' => $colaborador->puesto?->nombre,
                'sucursal' => $colaborador->sucursalPrincipal?->nombre,
            ],
            'ciclo' => $estado,
            'onboarding' => $proceso !== null ? $this->onboarding->aArray($proceso, $usuario) : null,
            'documentos' => $documentos,
            'evaluacion' => $evaluacion !== null ? $this->evaluaciones->aArray($evaluacion) : null,
            'cierre' => $cierre !== null ? $this->cierres->aArray($cierre, true, $usuario) : null,
            'opciones' => [
                'causas' => array_values(array_map(
                    fn (TipoBaja $t) => ['value' => $t->value, 'etiqueta' => $t->etiqueta()],
                    array_filter(TipoBaja::cases(), fn (TipoBaja $t) => $usuario->can(CierreLaboralService::PERMISO_GESTIONAR) || in_array($t->value, (array) config('ciclo_laboral.cierre.causas_solicitables', []), true)),
                )),
                'criterios' => array_values(array_filter((array) config('contratos.criterios_evaluacion', []), 'is_string')),
            ],
        ]);
    }

    /**
     * Paso físico siguiente que el gerente/corporativo puede registrar.
     *
     * @return list<string>
     */
    public static function pasosFisicos(?EstadoFlujoDocumento $estado): array
    {
        return match ($estado) {
            EstadoFlujoDocumento::PendienteImpresion => ['imprimir'],
            EstadoFlujoDocumento::Impreso, EstadoFlujoDocumento::PendienteFirmaFisica => ['firma_fisica'],
            EstadoFlujoDocumento::FirmadoFisicamente => ['envio'],
            EstadoFlujoDocumento::EnviadoCorporativo => ['recepcion'],
            EstadoFlujoDocumento::RecibidoCorporativo, EstadoFlujoDocumento::Escaneado => ['archivar'],
            default => [],
        };
    }
}
