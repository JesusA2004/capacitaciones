<?php

namespace App\Services\Tareas;

use App\Enums\EstadoCambioFoto;
use App\Enums\EstadoCandidato;
use App\Enums\EstadoCierreLaboral;
use App\Enums\EstadoEvaluacionPrueba;
use App\Enums\EstadoFiniquito;
use App\Enums\EstadoFlujoDocumento;
use App\Enums\EstadoIntervencionCandidato;
use App\Enums\EstadoLoteNomina;
use App\Models\CambioFotoPerfil;
use App\Models\Candidato;
use App\Models\CierreLaboral;
use App\Models\Colaborador;
use App\Models\EvaluacionPeriodoPrueba;
use App\Models\FiniquitoCalculo;
use App\Models\GeneratedDocument;
use App\Models\IntervencionCandidato;
use App\Models\NominaLote;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Expedientes\DatosFaltantesService;
use App\Services\Solicitudes\SolicitudesService;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * «Mis pendientes» como centro único: además de la bandeja de tareas del
 * ciclo laboral, un resumen de TODO lo que espera una acción del usuario
 * (solicitudes, cambios de foto, evaluaciones, candidatos, intervenciones,
 * documentos por imprimir/firmar, bajas, finiquitos, datos faltantes y
 * lotes de nómina por emitir). Cada tarjeta respeta el permiso y el alcance
 * organizacional del usuario (AlcanceOrganizacionalService) y lleva a la
 * pantalla donde se resuelve. Una tarjeta en cero no se muestra.
 */
class ResumenPendientesService
{
    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly SolicitudesService $solicitudes,
        private readonly DatosFaltantesService $datosFaltantes,
    ) {}

    /**
     * @return list<array{clave: string, titulo: string, descripcion: string, conteo: int, url: string, tono: string}>
     */
    public function atajos(User $usuario): array
    {
        $global = $this->alcance->tieneAlcanceGlobal($usuario);
        $colaboradores = fn () => $this->alcance->limitarColaboradoresPorAlcance(Colaborador::query(), $usuario)->select('id');
        $enAlcance = fn (Builder $q, string $columna = 'colaborador_id') => $global ? $q : $q->whereIn($columna, $colaboradores());

        $definiciones = [
            ['solicitudes', 'Solicitudes', 'Permisos, vacaciones y préstamos por revisar o autorizar.', 'primario', $usuario->can('solicitudes.revisar') || $usuario->can('solicitudes.aprobar'),
                fn () => (int) array_sum(array_column($this->solicitudes->resumenPorTipo($usuario), 'abiertas')), fn () => route('rh.solicitudes.index', ['todas' => 1])],
            ['cambios_foto', 'Cambios de foto', 'Fotos de perfil que esperan aprobación de RH.', 'neutro', $usuario->can('expedientes.revisar'),
                fn () => $enAlcance(CambioFotoPerfil::query()->where('estado', EstadoCambioFoto::Pendiente->value))->count(), fn () => route('rh.cambios-foto.index')],
            ['evaluaciones', 'Evaluaciones de capacitación', 'Por capturar, corregir o autorizar.', 'oro', $usuario->can('evaluaciones.capturar') || $usuario->can('evaluaciones.autorizar'),
                fn () => $this->evaluaciones($usuario), fn () => route('rh.evaluaciones.index')],
            ['candidatos', 'Candidatos en proceso', 'Filtro RH, entrevista, psicométricos, socioeconómico o contratación.', 'neutro', $usuario->can('candidatos.ver'),
                fn () => $this->alcance->limitarPorSucursal(Candidato::query()->whereIn('estado', array_map(fn (EstadoCandidato $e) => $e->value, array_filter(EstadoCandidato::cases(), fn (EstadoCandidato $e) => ! $e->esTerminal()))), $usuario)->count(), fn () => route('rh.candidatos.index')],
            ['intervenciones', 'Intervenciones', 'Rechazos de RH con intervención solicitada.', 'alerta', $usuario->can('candidatos.aprobar'),
                fn () => IntervencionCandidato::query()->where('estado', EstadoIntervencionCandidato::Pendiente->value)->whereIn('candidato_id', $this->alcance->limitarPorSucursal(Candidato::query(), $usuario)->select('id'))->count(), fn () => route('rh.candidatos.index')],
            ['documentos', 'Contratos y documentos', 'Por imprimir, firmar o archivar.', 'neutro', $usuario->can('documentos_laborales.operar_fisico'),
                fn () => $enAlcance(GeneratedDocument::query()->whereIn('estado_flujo', [EstadoFlujoDocumento::Generado->value, EstadoFlujoDocumento::PendienteImpresion->value, EstadoFlujoDocumento::PendienteFirmaFisica->value, EstadoFlujoDocumento::Impreso->value]))->count(), fn () => route('rh.expedientes.index')],
            ['bajas', 'Bajas en proceso', 'Cierres laborales abiertos.', 'alerta', $usuario->can('cierres.gestionar'),
                fn () => $enAlcance(CierreLaboral::query()->whereIn('estado', array_map(fn (EstadoCierreLaboral $e) => $e->value, EstadoCierreLaboral::abiertos())))->count(), fn () => route('rh.pendientes.index', ['etapa' => 'cierre'])],
            ['finiquitos', 'Finiquitos por revisar', 'Cálculos en borrador: revisa conceptos y ajustes.', 'oro', $usuario->can('cierres.gestionar'),
                fn () => $enAlcance(FiniquitoCalculo::query()->where('estado', EstadoFiniquito::Borrador->value))->count(), fn () => route('rh.solicitudes.index', ['tipo' => 'baja_colaborador'])],
            ['datos_faltantes', 'Datos faltantes', 'Colaboradores activos con datos contractuales incompletos.', 'alerta', $usuario->can('expedientes.revisar'),
                fn () => $this->datosFaltantes->conteoIncompletos($usuario), fn () => route('rh.expedientes.index', ['datos' => 'incompletos'])],
            ['nomina', 'Lotes de nómina', 'Preparados y sin emitir (revisa errores antes de publicar).', 'oro', $usuario->can('nomina.recibos.ver'),
                fn () => NominaLote::query()->where('estado', EstadoLoteNomina::Preparado->value)->when(! $global, fn (Builder $q) => $q->where('creado_por', $usuario->id))->count(), fn () => route('rh.nomina.lotes.index')],
        ];

        $atajos = [];

        foreach ($definiciones as [$clave, $titulo, $descripcion, $tono, $permitido, $conteo, $url]) {
            if (! $permitido) {
                continue;
            }

            try {
                $total = (int) $conteo();
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            if ($total > 0) {
                $atajos[] = ['clave' => $clave, 'titulo' => $titulo, 'descripcion' => $descripcion, 'conteo' => $total, 'url' => $url(), 'tono' => $tono];
            }
        }

        return $atajos;
    }

    private function evaluaciones(User $usuario): int
    {
        $abiertas = [EstadoEvaluacionPrueba::Pendiente->value, EstadoEvaluacionPrueba::Capturada->value, EstadoEvaluacionPrueba::Devuelta->value];
        $query = EvaluacionPeriodoPrueba::query()->whereIn('estado', $abiertas);

        if (! $usuario->can('evaluaciones.autorizar')) {
            return $query->where(fn (Builder $q) => $q
                ->where('evaluador_colaborador_id', $usuario->colaborador_id ?? 0)
                ->orWhereHas('colaborador', fn (Builder $c) => $c->where('jefe_id', $usuario->colaborador_id ?? 0)))->count();
        }

        return $this->alcance->tieneAlcanceGlobal($usuario)
            ? $query->count()
            : $query->whereIn('colaborador_id', $this->alcance->limitarColaboradoresPorAlcance(Colaborador::query(), $usuario)->select('id'))->count();
    }
}
