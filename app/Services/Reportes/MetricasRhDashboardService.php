<?php

namespace App\Services\Reportes;

use App\Enums\EstadoDocumento;
use App\Enums\EstadoUsuario;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Expedientes\ExpedienteService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Metricas del dashboard RH (reemplaza al dashboard de cumplimiento de
 * capacitacion en App\Services\Reportes\MetricasDashboardService, que se
 * deja intacta y sin usar: capacitacion no se elimino, ver
 * docs/CAPACITACION_PROXIMAMENTE.md). Los tres metodos publicos devuelven
 * exactamente lo que consumen Dashboard/Global.vue, Dashboard/Sucursal.vue y
 * Dashboard/Colaborador.vue.
 *
 * Altas/vacaciones/solicitudes todavia no tienen tablas propias (llegan en
 * checkpoints siguientes, ver docs/PORTAL_RH.md): sus tarjetas se devuelven
 * con `disponible: false` en vez de inventar un numero, para que el
 * frontend muestre "Próximamente" en lugar de una cifra falsa.
 */
class MetricasRhDashboardService
{
    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly ExpedienteService $expediente,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function global(User $usuario): array
    {
        return $this->paraAlcance($usuario);
    }

    /**
     * @return array<string, mixed>
     */
    public function sucursal(User $usuario): array
    {
        return $this->paraAlcance($usuario);
    }

    /**
     * @return array<string, mixed>
     */
    private function paraAlcance(User $usuario): array
    {
        $colaboradoresVisibles = $this->alcance->limitarUsuariosPorAlcance(User::query(), $usuario)
            ->with(['sucursalPrincipal:id,nombre,empresa_id', 'sucursalPrincipal.empresa:id,nombre', 'departamento:id,nombre', 'puesto:id,nombre'])
            ->get();

        $idsVisibles = $colaboradoresVisibles->pluck('id');

        $documentos = EmployeeDocument::query()->whereIn('user_id', $idsVisibles)->get(['id', 'user_id', 'status', 'document_type_id', 'created_at']);

        [$expedientesCompletos, $expedientesIncompletos] = $this->contarExpedientes($colaboradoresVisibles);

        return [
            'cards' => [
                'colaboradores_activos' => $colaboradoresVisibles->where('estatus', EstadoUsuario::Activo)->count(),
                'altas_en_proceso' => ['valor' => 0, 'disponible' => false],
                'bajas_del_mes' => $this->bajasDelMes($idsVisibles),
                'expedientes_completos' => $expedientesCompletos,
                'expedientes_incompletos' => $expedientesIncompletos,
                'documentos_pendientes' => $documentos->whereIn('status', $this->estadosPendientes())->count(),
                'solicitudes_pendientes' => ['valor' => 0, 'disponible' => false],
                'vacaciones_pendientes' => ['valor' => 0, 'disponible' => false],
            ],
            'graficas' => [
                'colaboradoresPorEmpresa' => $this->agruparPor($colaboradoresVisibles, function (User $u) {
                    $sucursal = $u->sucursalPrincipal;

                    return $sucursal === null || $sucursal->empresa === null ? 'Sin empresa' : $sucursal->empresa->nombre;
                }),
                'colaboradoresPorSucursal' => $this->agruparPor($colaboradoresVisibles, fn (User $u) => $u->sucursalPrincipal === null ? 'Sin sucursal' : $u->sucursalPrincipal->nombre),
                'colaboradoresPorDepartamento' => $this->agruparPor($colaboradoresVisibles, fn (User $u) => $u->departamento === null ? 'Sin departamento' : $u->departamento->nombre),
                'colaboradoresPorPuesto' => $this->agruparPor($colaboradoresVisibles, fn (User $u) => $u->puesto === null ? 'Sin puesto' : $u->puesto->nombre),
                'expedientesEstado' => [
                    ['clave' => 'completos', 'etiqueta' => 'Completos', 'valor' => $expedientesCompletos],
                    ['clave' => 'incompletos', 'etiqueta' => 'Incompletos', 'valor' => $expedientesIncompletos],
                ],
                'documentosPorEstado' => collect(EstadoDocumento::cases())
                    ->map(fn (EstadoDocumento $estado) => [
                        'clave' => $estado->value,
                        'etiqueta' => $estado->etiqueta(),
                        'valor' => $documentos->where('status', $estado)->count(),
                    ])
                    ->filter(fn (array $fila) => $fila['valor'] > 0)
                    ->values(),
            ],
            'proximosAniversarios' => $this->proximosAniversarios($colaboradoresVisibles),
            'documentosPendientesRevision' => $this->documentosPendientesRevision($idsVisibles),
            'alertas' => $this->alertas($expedientesIncompletos, $documentos),
        ];
    }

    /**
     * @return array{miExpediente: array{porcentaje: float, pendientes: int}, misDocumentosPendientes: array<int, array{id: int, colaborador: string|null, tipo: string, status: string, creado_en: string|null}>, avisosPendientes: array{disponible: bool}, misVacaciones: array{disponible: bool}, misSolicitudes: array{disponible: bool}}
     */
    public function colaborador(User $usuario): array
    {
        $resumen = $this->expediente->resumenCompletitud($usuario);

        return [
            'miExpediente' => [
                'porcentaje' => $resumen['porcentaje'],
                'pendientes' => $resumen['pendientes'] + $resumen['rechazados'],
            ],
            'misDocumentosPendientes' => $this->documentosPendientesRevision(collect([$usuario->id]), soloPropios: true),
            'misVacaciones' => ['disponible' => false],
            'misSolicitudes' => ['disponible' => false],
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
     * @param  Collection<int, User>  $colaboradores
     * @return array{0: int, 1: int}
     */
    private function contarExpedientes(Collection $colaboradores): array
    {
        $completos = 0;
        $incompletos = 0;

        foreach ($colaboradores as $colaborador) {
            $resumen = $this->expediente->resumenCompletitud($colaborador);

            if ($resumen['requeridos_total'] > 0 && $resumen['porcentaje'] >= 100.0) {
                $completos++;
            } else {
                $incompletos++;
            }
        }

        return [$completos, $incompletos];
    }

    /**
     * @param  Collection<int, int>  $idsVisibles
     */
    private function bajasDelMes(Collection $idsVisibles): int
    {
        return User::onlyTrashed()
            ->whereIn('id', $idsVisibles)
            ->whereBetween('deleted_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->count();
    }

    /**
     * @param  Collection<int, User>  $colaboradores
     * @param  callable(User): string  $clasificador
     * @return Collection<int, array{etiqueta: string, valor: int}>
     */
    private function agruparPor(Collection $colaboradores, callable $clasificador): Collection
    {
        return $colaboradores
            ->groupBy($clasificador)
            ->map(fn (Collection $grupo, string $etiqueta) => ['etiqueta' => $etiqueta, 'valor' => $grupo->count()])
            ->sortByDesc('valor')
            ->values();
    }

    /**
     * Colaboradores cuyo aniversario laboral (mismo dia/mes de fecha_ingreso)
     * cae dentro de los proximos 30 dias, ordenados por cercania.
     *
     * Se devuelve un array plano (no Collection): los generics de Collection
     * no son covariantes en PHPStan, y esta forma exacta (tras el filter que
     * acota "dias" a un rango) no puede declararse de forma estable como
     * Collection<...>. Ver https://phpstan.org/blog/whats-up-with-template-covariant.
     *
     * @param  Collection<int, User>  $colaboradores
     * @return array<int, array{id: int, nombre: string, fecha: string, dias: int, anios: int}>
     */
    private function proximosAniversarios(Collection $colaboradores): array
    {
        $hoy = now()->startOfDay();

        return $colaboradores
            ->filter(fn (User $u) => $u->fecha_ingreso !== null)
            ->map(function (User $u) use ($hoy) {
                $proximo = Carbon::parse($u->fecha_ingreso)->year($hoy->year);

                if ($proximo->lt($hoy)) {
                    $proximo = $proximo->addYear();
                }

                return [
                    'id' => $u->id,
                    'nombre' => $u->nombreCompleto(),
                    'fecha' => $proximo->toDateString(),
                    'dias' => (int) $hoy->diffInDays($proximo),
                    'anios' => $proximo->year - Carbon::parse($u->fecha_ingreso)->year,
                ];
            })
            ->filter(fn (array $item) => $item['dias'] <= 30)
            ->sortBy('dias')
            ->take(8)
            ->values()
            ->all();
    }

    /**
     * Array plano por el mismo motivo que proximosAniversarios(): el status
     * del enum vuelve un tipo union literal que no puede declararse de forma
     * estable como Collection<...>.
     *
     * @param  Collection<int, int>  $idsVisibles
     * @return array<int, array{id: int, colaborador: string|null, tipo: string, status: string, creado_en: string|null}>
     */
    private function documentosPendientesRevision(Collection $idsVisibles, bool $soloPropios = false): array
    {
        return EmployeeDocument::query()
            ->whereIn('user_id', $idsVisibles)
            ->whereIn('status', $this->estadosPendientes())
            ->with(['usuario:id,name,apellidos', 'tipo:id,nombre'])
            ->orderByDesc('created_at')
            ->limit($soloPropios ? 10 : 6)
            ->get()
            ->map(fn (EmployeeDocument $doc) => [
                'id' => $doc->id,
                'colaborador' => $soloPropios ? null : trim(($doc->usuario->name ?? '').' '.($doc->usuario->apellidos ?? '')),
                'tipo' => $doc->tipo->nombre ?? '—',
                'status' => (string) $doc->status->value,
                'creado_en' => $doc->created_at?->toDateString(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, EmployeeDocument>  $documentos
     * @return Collection<int, array{tono: string, mensaje: string}>
     */
    private function alertas(int $expedientesIncompletos, Collection $documentos): Collection
    {
        $alertas = collect();

        if ($expedientesIncompletos > 0) {
            $alertas->push([
                'tono' => 'warning',
                'mensaje' => "{$expedientesIncompletos} expediente(s) incompleto(s) requieren seguimiento.",
            ]);
        }

        $rechazados = $documentos->where('status', EstadoDocumento::Rechazado)->count();

        if ($rechazados > 0) {
            $alertas->push([
                'tono' => 'danger',
                'mensaje' => "{$rechazados} documento(s) rechazado(s) pendientes de que el colaborador vuelva a subirlos.",
            ]);
        }

        $enRevision = $documentos->where('status', EstadoDocumento::EnRevision)->count();

        if ($enRevision > 0) {
            $alertas->push([
                'tono' => 'info',
                'mensaje' => "{$enRevision} documento(s) esperando revisión de RH.",
            ]);
        }

        return $alertas;
    }
}
