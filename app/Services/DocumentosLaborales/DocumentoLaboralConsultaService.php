<?php

namespace App\Services\DocumentosLaborales;

use App\Enums\EstadoFlujoDocumento;
use App\Models\Colaborador;
use App\Models\GeneratedDocument;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Consultas de documentos laborales emitidos por el motor documental
 * (solo los que tienen estado_flujo; los DOCX legacy de Rh\FormatoController
 * siguen en su propio módulo). Siempre acotadas por alcance organizacional.
 */
class DocumentoLaboralConsultaService
{
    public function __construct(private readonly AlcanceOrganizacionalService $alcance) {}

    /**
     * @param  array{etapa?: string|null, estado?: string|null, colaborador_id?: int|string|null, clave?: string|null, categoria?: string|null, per_page?: int|string|null}  $filtros
     * @return LengthAwarePaginator<int, GeneratedDocument>
     */
    public function listar(User $usuario, array $filtros = []): LengthAwarePaginator
    {
        return $this->query($usuario, $filtros)
            ->with(['colaborador:id,name,apellidos,numero_empleado,sucursal_principal_id', 'seguimientoFisico'])
            ->orderByDesc('id')
            ->paginate(max(1, min(100, (int) ($filtros['per_page'] ?? 20))));
    }

    /**
     * Conteo por etapa del control físico: pendientes de imprimir, firma del
     * colaborador, firma física, enviar, recibir y escanear.
     *
     * @return array<string, int>
     */
    public function conteosPendientes(User $usuario): array
    {
        $conteos = [];

        foreach (FlujoDocumentalService::etapasPendientes() as $etapa => $estados) {
            $conteos[$etapa] = $this->query($usuario, [])
                ->whereIn('estado_flujo', array_map(fn (EstadoFlujoDocumento $e) => $e->value, $estados))
                ->count();
        }

        return $conteos;
    }

    /**
     * @param  array{etapa?: string|null, estado?: string|null, colaborador_id?: int|string|null, clave?: string|null, categoria?: string|null}  $filtros
     * @return Builder<GeneratedDocument>
     */
    private function query(User $usuario, array $filtros): Builder
    {
        $query = GeneratedDocument::query()->whereNotNull('estado_flujo');

        if (! $this->alcance->tieneAlcanceGlobal($usuario)) {
            $query->whereIn('colaborador_id', $this->alcance->limitarColaboradoresPorAlcance(Colaborador::query()->withTrashed(), $usuario)->select('id'));
        }

        $etapa = $filtros['etapa'] ?? null;
        $etapas = FlujoDocumentalService::etapasPendientes();

        return $query
            ->when($etapa !== null && isset($etapas[$etapa]), fn (Builder $q) => $q->whereIn('estado_flujo', array_map(fn (EstadoFlujoDocumento $e) => $e->value, $etapas[(string) $etapa])))
            ->when($filtros['estado'] ?? null, fn (Builder $q, string $v) => $q->where('estado_flujo', $v))
            ->when($filtros['colaborador_id'] ?? null, fn (Builder $q, int|string $v) => $q->where('colaborador_id', (int) $v))
            ->when($filtros['clave'] ?? null, fn (Builder $q, string $v) => $q->where('clave_plantilla', $v))
            ->when($filtros['categoria'] ?? null, fn (Builder $q, string $v) => $q->where('categoria', $v));
    }

    /**
     * Documentos propios del colaborador (lo que ve en la app): nunca
     * borradores ni cancelados.
     *
     * @return LengthAwarePaginator<int, GeneratedDocument>
     */
    public function delColaborador(Colaborador $colaborador, ?string $estado = null): LengthAwarePaginator
    {
        return GeneratedDocument::query()
            ->where('colaborador_id', $colaborador->id)
            ->whereNotNull('estado_flujo')
            ->whereNotIn('estado_flujo', [EstadoFlujoDocumento::Borrador->value, EstadoFlujoDocumento::Cancelado->value])
            ->when($estado === 'pendientes_firma', fn (Builder $q) => $q->where('estado_flujo', EstadoFlujoDocumento::PendienteFirmaColaborador->value))
            ->orderByDesc('id')
            ->paginate(20);
    }

    /**
     * @return array<string, mixed>
     */
    public function aArray(GeneratedDocument $documento, bool $detalle = false): array
    {
        $datos = [
            'id' => $documento->id,
            'titulo' => $documento->titulo ?? $documento->original_name,
            'clave_plantilla' => $documento->clave_plantilla,
            'version_plantilla' => $documento->version_plantilla,
            'categoria' => $documento->categoria?->value,
            'estado' => $documento->estado_flujo?->value,
            'estado_etiqueta' => $documento->estado_flujo?->etiqueta(),
            'requiere_firma_digital' => $documento->requiere_firma_digital,
            'requiere_impresion' => $documento->requiere_impresion,
            'requiere_firma_fisica' => $documento->requiere_firma_fisica,
            'requiere_huella' => $documento->requiere_huella,
            'requiere_testigos' => $documento->requiere_testigos,
            'checksum' => $documento->checksum,
            'firmado_digital_en' => $documento->firmado_digital_en?->toIso8601String(),
            'generado_en' => $documento->created_at?->toIso8601String(),
            'related_type' => $documento->documentable_type !== null ? class_basename($documento->documentable_type) : null,
            'related_id' => $documento->documentable_id,
            'colaborador' => $documento->colaborador !== null ? [
                'id' => $documento->colaborador->id,
                'nombre' => $documento->colaborador->nombreCompleto(),
                'numero_empleado' => $documento->colaborador->numero_empleado,
            ] : null,
        ];

        $seguimiento = $documento->seguimientoFisico;

        $datos['original_fisico'] = $seguimiento === null ? null : [
            'impreso_en' => $seguimiento->impreso_en?->toIso8601String(),
            'impreso_por' => $seguimiento->impreso_por,
            'firmado_fisico_en' => $seguimiento->firmado_fisico_en?->toIso8601String(),
            'huella_registrada' => $seguimiento->huella_registrada,
            'testigos' => $seguimiento->testigos,
            'sucursal_origen_id' => $seguimiento->sucursal_origen_id,
            'enviado_en' => $seguimiento->enviado_en?->toIso8601String(),
            'enviado_por' => $seguimiento->enviado_por,
            'paqueteria' => $seguimiento->paqueteria,
            'numero_guia' => $seguimiento->numero_guia,
            'tiene_comprobante' => $seguimiento->comprobante_path !== null,
            'recibido_en' => $seguimiento->recibido_en?->toIso8601String(),
            'recibido_por' => $seguimiento->recibido_por,
            'escaneado_en' => $seguimiento->escaneado_en?->toIso8601String(),
            'escaneado_documento_id' => $documento->signed_document_id,
        ];

        if ($detalle) {
            $datos['payload'] = $documento->payload;
            $datos['eventos'] = $documento->eventos()->with('usuario:id,name,apellidos')->get()->map(fn ($evento) => [
                'accion' => $evento->accion,
                'estado_anterior' => $evento->estado_anterior,
                'estado_nuevo' => $evento->estado_nuevo,
                'usuario' => $evento->usuario !== null ? trim($evento->usuario->name.' '.$evento->usuario->apellidos) : null,
                'ip' => $evento->ip,
                'observaciones' => $evento->observaciones,
                'fecha' => $evento->created_at?->toIso8601String(),
            ])->all();
        }

        return $datos;
    }
}
