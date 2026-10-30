<?php

namespace App\Services\Incapacidades;

use App\Enums\EstatusIncapacidad;
use App\Enums\SecuenciaIncapacidad;
use App\Enums\TipoDocumentoIncapacidad;
use App\Enums\TipoIncapacidad;
use App\Models\Colaborador;
use App\Models\Incapacidad;
use App\Models\IncapacidadDocumento;
use App\Models\NominaRectificacion;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Colaboradores\BuscadorColaboradoresService;
use App\Services\Nomina\IncidenciasNominaService;
use App\Support\FechaMexico;
use App\Support\Paginacion;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Lecturas del módulo Incapacidades: listado, indicadores, cadena del riesgo,
 * línea de tiempo e impacto en nómina. El estatus se DERIVA aquí y solo aquí.
 */
class IncapacidadConsultaService
{
    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly IncidenciasNominaService $incidencias,
    ) {}

    public function estatus(Incapacidad $incapacidad, ?CarbonImmutable $hoy = null): EstatusIncapacidad
    {
        if ($incapacidad->estaCancelada()) {
            return EstatusIncapacidad::Cancelada;
        }

        if ($incapacidad->tipo === TipoIncapacidad::RiesgoTrabajo) {
            $raiz = $incapacidad->incapacidad_inicial_id !== null ? $incapacidad->inicial : $incapacidad;

            return $raiz?->riesgo_concluido_en !== null ? EstatusIncapacidad::Concluida : EstatusIncapacidad::Activa;
        }

        $hoy ??= $this->hoy();

        return $incapacidad->fecha_fin->toDateString() <= $hoy->toDateString() ? EstatusIncapacidad::Concluida : EstatusIncapacidad::Activa;
    }

    /**
     * @return Builder<Incapacidad>
     */
    public function visibles(User $usuario): Builder
    {
        $query = Incapacidad::query()->with(['colaborador:id,name,apellidos,numero_empleado,sucursal_principal_id,puesto_id,foto_path', 'colaborador.puesto:id,nombre', 'sucursal:id,nombre', 'inicial', 'documentos']);
        $this->alcance->limitarPorModulo($query, $usuario, 'incapacidades', 'colaborador_id', true);

        // Nadie consulta su propia información médica desde el módulo operativo.
        if ($usuario->colaborador_id !== null) {
            $query->where('colaborador_id', '!=', $usuario->colaborador_id);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return LengthAwarePaginator<int, Incapacidad>
     */
    public function listar(User $usuario, array $filtros): LengthAwarePaginator
    {
        [$desde, $hasta] = $this->rangoMes(isset($filtros['mes']) ? (string) $filtros['mes'] : null);

        return $this->filtrados($usuario, $desde, $hasta, $filtros)
            ->orderByDesc('fecha_inicio')
            ->orderByDesc('id')
            ->paginate(Paginacion::porPagina(20, isset($filtros['per_page']) ? (int) $filtros['per_page'] : null))
            ->withQueryString();
    }

    /**
     * Filas para Excel/PDF: mismos filtros que la pantalla.
     *
     * @param  array<string, mixed>  $filtros
     * @return array{titulo: string, columnas: array<int, string>, filas: array<int, array<int, string|int>>}
     */
    public function tablaExportable(User $usuario, array $filtros): array
    {
        [$desde, $hasta] = $this->rangoMes(isset($filtros['mes']) ? (string) $filtros['mes'] : null);
        $filas = $this->filtrados($usuario, $desde, $hasta, $filtros)
            ->orderBy('fecha_inicio')
            ->get()
            ->map(fn (Incapacidad $i): array => [
                $i->colaborador->nombreCompleto(),
                $i->sucursal->nombre ?? 'Sin sucursal',
                $i->tipo->etiqueta(),
                $i->subtipo_riesgo?->etiqueta() ?? '—',
                $i->secuencia->etiqueta(),
                $i->serie_folio_imss,
                $i->fecha_inicio->format('d/m/Y'),
                $i->dias,
                $i->fecha_fin->format('d/m/Y'),
                $this->estatus($i)->etiqueta(),
                implode(', ', $this->documentosPendientes($i)) ?: 'Completos',
            ])
            ->values()
            ->all();

        return [
            'titulo' => sprintf('Incapacidades del %s al %s', $desde->format('d/m/Y'), $hasta->format('d/m/Y')),
            'columnas' => ['Colaborador', 'Sucursal', 'Tipo', 'Subtipo', 'Inicial / subsecuente', 'Serie y folio IMSS', 'Inicio', 'Días', 'Término', 'Estatus', 'Documentos pendientes'],
            'filas' => $filas,
        ];
    }

    /**
     * Indicadores de la pág. 7.
     *
     * @return array{activas: int, dias_mes: int, riesgos_abiertos: int, documentos_pendientes: int}
     */
    public function indicadores(User $usuario, ?string $mes, ?string $sucursalId): array
    {
        [$desde, $hasta] = $this->rangoMes($mes);
        $base = fn (): Builder => $this->visibles($usuario)
            ->whereNull('cancelada_en')
            ->when($sucursalId, fn (Builder $q, $v) => $q->where('sucursal_id', (int) $v));

        $hoy = $this->hoy();
        $activas = $base()->whereDate('fecha_inicio', '<=', $hoy->toDateString())->get()
            ->filter(fn (Incapacidad $i): bool => $this->estatus($i, $hoy) === EstatusIncapacidad::Activa)
            ->count();

        $diasMes = $base()->whereDate('fecha_inicio', '<=', $hasta->toDateString())->whereDate('fecha_fin', '>=', $desde->toDateString())->get()
            ->sum(function (Incapacidad $i) use ($desde, $hasta): int {
                $a = max($i->fecha_inicio->toDateString(), $desde->toDateString());
                $b = min($i->fecha_fin->toDateString(), $hasta->toDateString());

                return (int) CarbonImmutable::parse($a)->diffInDays(CarbonImmutable::parse($b)) + 1;
            });

        $riesgos = $base()->where('tipo', TipoIncapacidad::RiesgoTrabajo->value)->where('secuencia', SecuenciaIncapacidad::Inicial->value)->get();

        return [
            'activas' => $activas,
            'dias_mes' => (int) $diasMes,
            'riesgos_abiertos' => $riesgos->whereNull('riesgo_concluido_en')->count(),
            'documentos_pendientes' => $riesgos->sum(fn (Incapacidad $i): int => count($this->documentosPendientes($i))),
        ];
    }

    /**
     * Días de incapacidad por persona en una quincena (pág. 7, «Impacto en
     * nómina»). Mismos números que el recibo: salen de IncidenciasNominaService.
     *
     * @return array<int, array{colaborador_id: int, nombre: string, sucursal: string|null, detalle: string, dias: int}>
     */
    public function impactoNomina(User $usuario, CarbonImmutable $desde, CarbonImmutable $hasta, ?string $sucursalId): array
    {
        $ids = $this->visibles($usuario)
            ->whereNull('cancelada_en')
            ->when($sucursalId, fn (Builder $q, $v) => $q->where('sucursal_id', (int) $v))
            ->whereDate('fecha_inicio', '<=', $hasta->toDateString())
            ->whereDate('fecha_fin', '>=', $desde->toDateString())
            ->pluck('colaborador_id')
            ->unique();

        return Colaborador::query()->withTrashed()->with('sucursalPrincipal:id,nombre')->whereIn('id', $ids)->get()
            ->map(function (Colaborador $colaborador) use ($desde, $hasta): array {
                $resumen = $this->incidencias->resumen($colaborador, $desde, $hasta);
                $detalle = collect($resumen['incidencias'])
                    ->where('tipo', 'incapacidad')
                    ->map(fn (array $i): string => sprintf('%s: %d día(s)', $i['referencia'], $i['dias_en_periodo']))
                    ->implode(' · ');

                return [
                    'colaborador_id' => $colaborador->id,
                    'nombre' => $colaborador->nombreCompleto(),
                    'sucursal' => $colaborador->sucursalPrincipal?->nombre,
                    'detalle' => $detalle,
                    'dias' => $resumen['dias_incapacidad'],
                ];
            })
            ->sortByDesc('dias')
            ->values()
            ->all();
    }

    /**
     * Recibos emitidos con diferencia por resolver.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rectificacionesPendientes(User $usuario): array
    {
        $query = NominaRectificacion::query()
            ->with(['recibo:id,folio,periodo_inicio,periodo_fin,dias_pagados,neto,estado,colaborador_id', 'colaborador:id,name,apellidos'])
            ->where('estado', 'pendiente')
            ->orderBy('id');
        $this->alcance->limitarPorModulo($query, $usuario, 'incapacidades', 'colaborador_id', true);

        return $query->limit(50)->get()->map(fn (NominaRectificacion $r): array => [
            'id' => $r->id,
            'colaborador' => $r->colaborador->nombreCompleto(),
            'folio' => $r->recibo->folio,
            'periodo' => sprintf('%s al %s', $r->recibo->periodo_inicio->format('d/m/Y'), $r->recibo->periodo_fin->format('d/m/Y')),
            'dias_antes' => $r->dias_pagados_antes,
            'dias_despues' => $r->dias_pagados_despues,
            'importe_despues' => $r->importe_sueldo_despues,
            'motivo' => $r->motivo,
        ])->all();
    }

    /**
     * Incapacidades iniciales de la persona que aún admiten subsecuente.
     *
     * @return array<int, array{id: int, tipo: string, subtipo_riesgo: string|null, etiqueta: string, ultimo_dia: string}>
     */
    public function inicialesAbiertas(Colaborador $colaborador): array
    {
        return Incapacidad::query()
            ->where('colaborador_id', $colaborador->id)
            ->where('secuencia', SecuenciaIncapacidad::Inicial->value)
            ->whereNull('cancelada_en')
            ->whereNull('riesgo_concluido_en')
            ->with('subsecuentes')
            ->orderByDesc('fecha_inicio')
            ->get()
            ->map(function (Incapacidad $i): array {
                $ultimo = $i->subsecuentes->whereNull('cancelada_en')->max(fn (Incapacidad $s): string => $s->fecha_fin->toDateString()) ?? $i->fecha_fin->toDateString();

                return [
                    'id' => $i->id,
                    'tipo' => $i->tipo->value,
                    'subtipo_riesgo' => $i->subtipo_riesgo?->value,
                    'etiqueta' => sprintf('%s · %s · %d días', $i->serie_folio_imss, $i->fecha_inicio->format('d/m/Y'), $i->dias),
                    'ultimo_dia' => $ultimo,
                ];
            })
            ->all();
    }

    /**
     * Expediente de una cadena (inicial + subsecuentes) en orden cronológico.
     *
     * @return array<string, mixed>
     */
    public function cadena(Incapacidad $raiz, User $usuario): array
    {
        $raiz->loadMissing(['colaborador.puesto', 'colaborador.sucursalPrincipal', 'sucursal', 'documentos.cargadoPor', 'registradoPor']);
        $miembros = $this->miembros($raiz);
        $vigentes = $miembros->filter(fn (Incapacidad $i): bool => ! $i->estaCancelada());
        $documentos = $miembros->flatMap(fn (Incapacidad $i) => $i->documentos);

        return [
            'id' => $raiz->id,
            'tipo' => $raiz->tipo->value,
            'tipo_etiqueta' => $raiz->tipo->etiqueta(),
            'subtipo_etiqueta' => $raiz->subtipo_riesgo?->etiqueta(),
            'cuenta_siniestralidad' => $raiz->subtipo_riesgo?->cuentaParaSiniestralidad(),
            'riesgo_folio' => $raiz->riesgo_folio,
            'descripcion_evento' => $raiz->descripcion_evento,
            'estatus' => $this->estatus($raiz)->value,
            'estatus_etiqueta' => $this->estatus($raiz)->etiqueta(),
            'dias_totales' => (int) $vigentes->sum('dias'),
            'observaciones' => $raiz->observaciones,
            'incapacidades' => $miembros->map(fn (Incapacidad $i): array => $this->aArray($i, $usuario))->values()->all(),
            'documentos' => $documentos->sortBy('id')->map(fn (IncapacidadDocumento $d): array => $this->documentoArray($d, $usuario))->values()->all(),
            'pendientes' => $this->documentosPendientes($raiz),
            'linea_de_tiempo' => $this->lineaDeTiempo($raiz, $miembros),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function aArray(Incapacidad $i, User $usuario): array
    {
        $estatus = $this->estatus($i);
        $hoy = $this->hoy()->toDateString();

        return [
            'id' => $i->id,
            'raiz_id' => $i->raizId(),
            'colaborador_id' => $i->colaborador_id,
            'colaborador' => $i->colaborador->nombreCompleto(),
            'sucursal' => $i->sucursal->nombre ?? null,
            'puesto' => $i->colaborador->puesto->nombre ?? null,
            'tipo' => $i->tipo->value,
            'tipo_etiqueta' => $i->tipo->etiqueta(),
            'subtipo_etiqueta' => $i->subtipo_riesgo?->etiqueta(),
            'secuencia' => $i->secuencia->value,
            'secuencia_etiqueta' => $i->secuencia->etiqueta(),
            'serie_folio_imss' => $i->serie_folio_imss,
            'fecha_inicio' => $i->fecha_inicio->toDateString(),
            'dias' => $i->dias,
            'fecha_fin' => $i->fecha_fin->toDateString(),
            'en_curso' => $i->fecha_inicio->toDateString() <= $hoy && $i->fecha_fin->toDateString() >= $hoy,
            'estatus' => $estatus->value,
            'estatus_etiqueta' => $estatus->etiqueta(),
            'documentos_resumen' => $this->documentosResumen($i),
            'motivo_cancelacion' => $i->motivo_cancelacion,
            'puede_corregir' => ! $i->estaCancelada() && $usuario->can('corregir', $i),
        ];
    }

    /**
     * @return list<array{etiqueta: string, cargado: bool}>
     */
    public function documentosResumen(Incapacidad $i): array
    {
        $raiz = $i->incapacidad_inicial_id !== null && $i->inicial !== null ? $i->inicial : $i;
        $resumen = [['etiqueta' => 'Certificado', 'cargado' => $this->tieneDocumento($i, TipoDocumentoIncapacidad::Certificado)]];

        if ($i->tipo === TipoIncapacidad::RiesgoTrabajo) {
            $resumen[] = ['etiqueta' => 'ST-7', 'cargado' => $this->tieneDocumento($raiz, TipoDocumentoIncapacidad::St7)];
            $resumen[] = ['etiqueta' => 'ST-2', 'cargado' => $this->tieneDocumento($raiz, TipoDocumentoIncapacidad::St2)];
        }

        return $resumen;
    }

    /**
     * Documentos obligatorios que faltan en el riesgo. ST-3 es opcional.
     *
     * @return list<string>
     */
    public function documentosPendientes(Incapacidad $i): array
    {
        if ($i->tipo !== TipoIncapacidad::RiesgoTrabajo || $i->estaCancelada()) {
            return [];
        }

        $raiz = $i->incapacidad_inicial_id !== null && $i->inicial !== null ? $i->inicial : $i;
        $pendientes = [];

        if (! $this->tieneDocumento($raiz, TipoDocumentoIncapacidad::St7)) {
            $pendientes[] = 'ST-7';
        }

        if (! $this->tieneDocumento($raiz, TipoDocumentoIncapacidad::St2)) {
            $pendientes[] = 'ST-2';
        }

        return $pendientes;
    }

    public function hoy(): CarbonImmutable
    {
        return CarbonImmutable::parse(FechaMexico::hoy());
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function rangoMes(?string $mes): array
    {
        $base = is_string($mes) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes) === 1
            ? CarbonImmutable::parse($mes.'-01')
            : $this->hoy()->startOfMonth();

        return [$base->startOfMonth()->startOfDay(), $base->endOfMonth()->startOfDay()];
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return Builder<Incapacidad>
     */
    private function filtrados(User $usuario, CarbonImmutable $desde, CarbonImmutable $hasta, array $filtros): Builder
    {
        $hoy = $this->hoy()->toDateString();
        $estatus = (string) ($filtros['estatus'] ?? '');

        return $this->visibles($usuario)
            ->whereDate('fecha_inicio', '<=', $hasta->toDateString())
            ->whereDate('fecha_fin', '>=', $desde->toDateString())
            ->when($filtros['tipo'] ?? null, fn (Builder $q, string $v) => $q->where('tipo', $v))
            ->when($filtros['sucursal_id'] ?? null, fn (Builder $q, int|string $v) => $q->where('sucursal_id', (int) $v))
            ->when($filtros['q'] ?? null, fn (Builder $q, string $v) => app(BuscadorColaboradoresService::class)->aplicarPorColumna($q, $v))
            ->when($estatus === EstatusIncapacidad::Cancelada->value, fn (Builder $q) => $q->whereNotNull('cancelada_en'), fn (Builder $q) => $q->whereNull('cancelada_en'))
            ->when($estatus === EstatusIncapacidad::Activa->value, fn (Builder $q) => $q->where(fn (Builder $w) => $this->condicionActiva($w, $hoy)))
            ->when($estatus === EstatusIncapacidad::Concluida->value, fn (Builder $q) => $q->where(fn (Builder $w) => $this->condicionConcluida($w, $hoy)));
    }

    /**
     * @param  Builder<Incapacidad>  $q
     */
    private function condicionActiva(Builder $q, string $hoy): void
    {
        $q->where(fn (Builder $n) => $n->where('tipo', '!=', TipoIncapacidad::RiesgoTrabajo->value)->whereDate('fecha_fin', '>', $hoy))
            ->orWhere(fn (Builder $r) => $r->where('tipo', TipoIncapacidad::RiesgoTrabajo->value)
                ->whereNull('riesgo_concluido_en')
                ->where(fn (Builder $s) => $s->whereNull('incapacidad_inicial_id')->orWhereHas('inicial', fn (Builder $i) => $i->whereNull('riesgo_concluido_en'))));
    }

    /**
     * @param  Builder<Incapacidad>  $q
     */
    private function condicionConcluida(Builder $q, string $hoy): void
    {
        $q->where(fn (Builder $n) => $n->where('tipo', '!=', TipoIncapacidad::RiesgoTrabajo->value)->whereDate('fecha_fin', '<=', $hoy))
            ->orWhere(fn (Builder $r) => $r->where('tipo', TipoIncapacidad::RiesgoTrabajo->value)
                ->where(fn (Builder $s) => $s->whereNotNull('riesgo_concluido_en')->orWhereHas('inicial', fn (Builder $i) => $i->whereNotNull('riesgo_concluido_en'))));
    }

    /**
     * @return Collection<int, Incapacidad>
     */
    private function miembros(Incapacidad $raiz): Collection
    {
        $subsecuentes = Incapacidad::query()
            ->with(['documentos.cargadoPor', 'colaborador.puesto', 'sucursal', 'inicial'])
            ->where('incapacidad_inicial_id', $raiz->id)
            ->orderBy('fecha_inicio')
            ->get();

        return collect([$raiz])->merge($subsecuentes)->values();
    }

    /**
     * @param  Collection<int, Incapacidad>  $miembros
     * @return list<array{fecha: string, titulo: string, detalle: string|null, clase: string, pendiente: bool}>
     */
    private function lineaDeTiempo(Incapacidad $raiz, Collection $miembros): array
    {
        $eventos = [];

        if ($raiz->tipo === TipoIncapacidad::RiesgoTrabajo) {
            $eventos[] = ['fecha' => ($raiz->fecha_evento ?? $raiz->fecha_inicio)->toDateString(), 'titulo' => sprintf('%s · apertura', $raiz->subtipo_riesgo?->etiqueta() ?? 'Riesgo de trabajo'), 'detalle' => $raiz->descripcion_evento ?: sprintf('Se abre el riesgo %s', (string) $raiz->riesgo_folio), 'clase' => 'evento', 'pendiente' => false];
        }

        foreach ($miembros as $i) {
            $eventos[] = [
                'fecha' => $i->fecha_inicio->toDateString(),
                'titulo' => sprintf('Incapacidad %s · %d días%s', mb_strtolower($i->secuencia->etiqueta()), $i->dias, $i->estaCancelada() ? ' (cancelada)' : ''),
                'detalle' => sprintf('Folio %s · %s al %s', $i->serie_folio_imss, $i->fecha_inicio->format('d/m/Y'), $i->fecha_fin->format('d/m/Y')),
                'clase' => $i->estaCancelada() ? 'cancelada' : 'incapacidad',
                'pendiente' => false,
            ];
        }

        foreach ($raiz->documentos->whereNull('reemplazado_en') as $d) {
            if ($d->tipo === TipoDocumentoIncapacidad::Certificado) {
                continue;
            }

            $eventos[] = ['fecha' => ($d->fecha_documento ?? $d->created_at)?->toDateString() ?? '', 'titulo' => sprintf('%s cargado', explode(' · ', $d->tipo->etiqueta())[0]), 'detalle' => explode(' · ', $d->tipo->etiqueta())[1] ?? null, 'clase' => 'documento', 'pendiente' => false];
        }

        usort($eventos, fn (array $a, array $b): int => $a['fecha'] <=> $b['fecha']);

        foreach ($this->documentosPendientes($raiz) as $falta) {
            $eventos[] = ['fecha' => '', 'titulo' => sprintf('%s pendiente', $falta), 'detalle' => $falta === 'ST-2' ? 'Al cargarlo, el riesgo pasa a Concluida.' : 'Aviso para calificar probable riesgo de trabajo.', 'clase' => 'pendiente', 'pendiente' => true];
        }

        return $eventos;
    }

    /**
     * @return array<string, mixed>
     */
    private function documentoArray(IncapacidadDocumento $d, User $usuario): array
    {
        return [
            'id' => $d->id,
            'tipo' => $d->tipo->value,
            'etiqueta' => $d->tipo->etiqueta(),
            'incapacidad_id' => $d->incapacidad_id,
            'nombre_original' => $d->nombre_original,
            'fecha_documento' => $d->fecha_documento?->toDateString(),
            'cargado_por' => $d->cargadoPor?->nombreCompleto(),
            'cargado_en' => $d->created_at?->toIso8601String(),
            'vigente' => $d->reemplazado_en === null,
            'motivo_reemplazo' => $d->motivo_reemplazo,
            'puede_descargar' => $usuario->can('incapacidades.descargar'),
        ];
    }

    private function tieneDocumento(Incapacidad $i, TipoDocumentoIncapacidad $tipo): bool
    {
        return $i->documentos->contains(fn (IncapacidadDocumento $d): bool => $d->tipo === $tipo && $d->reemplazado_en === null);
    }
}
