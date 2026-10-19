<?php

namespace App\Services\Reportes;

use App\Enums\EstadoDocumento;
use App\Enums\EstadoSolicitudInterna;
use App\Models\EmployeeDocument;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Services\Expedientes\ExpedienteService;
use App\Services\Vacaciones\VacacionesService;
use Illuminate\Support\Collection;

/**
 * Inicio personal del colaborador (Dashboard/Colaborador.vue): el único
 * método que sigue vivo de este servicio. El tablero operativo de RH
 * (Dashboard/Global.vue y Dashboard/Sucursal.vue) se alimenta por completo
 * desde TableroRhService / HeadcountService / RotacionConsultaService, que
 * son la fuente canónica; las antiguas tarjetas/gráficas y el KPI de
 * rotación "en vivo" (fórmula no canónica) de este servicio se retiraron por
 * decisión del negocio (ver tests/Feature/Dashboard/DashboardGraficasTest y
 * DashboardFuenteCanonicaTest).
 */
class MetricasRhDashboardService
{
    public function __construct(
        private readonly ExpedienteService $expediente,
        private readonly VacacionesService $vacaciones,
    ) {}

    /**
     * @return array{miExpediente: array{porcentaje: float, pendientes: int}, misDocumentosPendientes: array<int, array{id: int, colaborador: string|null, tipo: string, status: string, creado_en: string|null}>, avisosPendientes: array{disponible: bool}, misVacaciones: array{dias_disponibles: int}, misSolicitudes: array{pendientes: int}}
     */
    public function colaborador(User $usuario): array
    {
        $colaborador = $usuario->colaborador;
        $resumen = $colaborador !== null
            ? $this->expediente->resumenCompletitud($colaborador)
            : ['porcentaje' => 0.0, 'requeridos_total' => 0, 'requeridos_aprobados' => 0, 'pendientes' => 0, 'rechazados' => 0];

        return [
            'miExpediente' => [
                'porcentaje' => $resumen['porcentaje'],
                'pendientes' => $resumen['pendientes'] + $resumen['rechazados'],
            ],
            'misDocumentosPendientes' => $this->documentosPendientesRevision(collect([$usuario->colaborador_id]), soloPropios: true),
            'misVacaciones' => ['dias_disponibles' => $this->vacaciones->saldo($usuario)['dias_disponibles']],
            'misSolicitudes' => [
                'pendientes' => SolicitudInterna::query()
                    ->where('user_id', $usuario->id)
                    ->whereIn('estado', [
                        EstadoSolicitudInterna::Enviada->value,
                        EstadoSolicitudInterna::EnRevision->value,
                        EstadoSolicitudInterna::RequiereCorreccion->value,
                    ])
                    ->count(),
            ],
            'avisosPendientes' => ['disponible' => false],
        ];
    }

    /**
     * @return array<int, EstadoDocumento>
     */
    private function estadosPendientes(): array
    {
        return [EstadoDocumento::Pendiente, EstadoDocumento::EnRevision, EstadoDocumento::RequiereCorreccion];
    }

    /**
     * Array plano (no Collection): el status del enum vuelve un tipo union
     * literal que no puede declararse de forma estable como Collection<...>.
     *
     * @param  Collection<int, int>  $idsVisibles
     * @return array<int, array{id: int, colaborador: string|null, tipo: string, status: string, creado_en: string|null}>
     */
    private function documentosPendientesRevision(Collection $idsVisibles, bool $soloPropios = false): array
    {
        return EmployeeDocument::query()
            ->whereIn('colaborador_id', $idsVisibles)
            ->whereIn('status', $this->estadosPendientes())
            ->with(['colaborador:id,name,apellidos', 'tipo:id,nombre'])
            ->orderByDesc('created_at')
            ->limit($soloPropios ? 10 : 6)
            ->get()
            ->map(fn (EmployeeDocument $doc) => [
                'id' => $doc->id,
                'colaborador' => $soloPropios ? null : trim(($doc->colaborador->name ?? '').' '.($doc->colaborador->apellidos ?? '')),
                'tipo' => $doc->tipo->nombre ?? '—',
                'status' => (string) $doc->status->value,
                'creado_en' => $doc->created_at?->toDateString(),
            ])
            ->values()
            ->all();
    }
}
