<?php

namespace App\Services\RecepcionDocumental\Reglas;

use App\Enums\EstadoFlujoDocumento;
use App\Enums\ReglaRecepcionDocumental;
use App\Models\GeneratedDocument;
use App\Models\Prestamo;
use App\Services\RecepcionDocumental\EsperadoRecepcion;
use App\Services\RecepcionDocumental\PeriodoRecepcion;
use Illuminate\Database\Eloquent\Builder;

/**
 * PRÉSTAMOS: por cada préstamo AUTORIZADO en el mes (autorizado_en, UTC →
 * hora de México), los originales de config/documentos_maestros.php →
 * documentos_prestamo (contrato de crédito, pagaré, carta de retención),
 * ligados a su GeneratedDocument cuando ya se generaron. Un préstamo
 * cancelado deja de esperarse.
 */
class ReglaPrestamos implements ReglaRecepcion
{
    public function regla(): ReglaRecepcionDocumental
    {
        return ReglaRecepcionDocumental::Prestamos;
    }

    public function esperados(PeriodoRecepcion $periodo): array
    {
        $claves = array_values(array_filter((array) config('documentos_maestros.documentos_prestamo', []), 'is_string'));

        if ($claves === []) {
            return [];
        }

        $prestamos = Prestamo::query()
            ->whereBetween('autorizado_en', $periodo->rangoUtc())
            ->where('estado', '!=', 'cancelado')
            ->with(['colaborador' => fn ($q) => $q->withTrashed()->with(['puesto:id,nombre', 'sucursalPrincipal:id,nombre'])])
            ->orderBy('id')
            ->get();

        if ($prestamos->isEmpty()) {
            return [];
        }

        $documentos = GeneratedDocument::query()
            ->where('documentable_type', (new Prestamo)->getMorphClass())
            ->whereIn('documentable_id', $prestamos->modelKeys())
            ->whereIn('clave_plantilla', $claves)
            ->where(fn (Builder $q) => $q->whereNull('estado_flujo')->orWhere('estado_flujo', '!=', EstadoFlujoDocumento::Cancelado->value))
            ->orderByDesc('id')
            ->get(['id', 'documentable_id', 'clave_plantilla']);

        /** @var array<string, int> $porClave «prestamo|clave» => id vigente */
        $porClave = [];

        foreach ($documentos as $documento) {
            $porClave[sprintf('%d|%s', (int) $documento->documentable_id, (string) $documento->clave_plantilla)] ??= $documento->id;
        }

        $esperados = [];

        foreach ($prestamos as $prestamo) {
            $directos = ['prestamo_contrato' => $prestamo->contrato_documento_id, 'prestamo_pagare' => $prestamo->pagare_documento_id];
            $descripcion = sprintf(
                'Préstamo de $%s autorizado el %s',
                number_format((float) $prestamo->monto_original, 2),
                $prestamo->autorizado_en?->setTimezone('America/Mexico_City')->format('d/m/Y') ?? '—',
            );

            foreach ($claves as $clave) {
                $documentoId = $porClave[sprintf('%d|%s', $prestamo->id, $clave)] ?? $directos[$clave] ?? null;

                $esperados[] = EsperadoRecepcion::deColaborador(
                    $this->regla(),
                    $clave,
                    EsperadoRecepcion::etiquetaTipo($clave),
                    $prestamo,
                    $prestamo->colaborador,
                    PeriodoRecepcion::fechaMexico($prestamo->autorizado_en),
                    $descripcion,
                    is_int($documentoId) ? $documentoId : null,
                );
            }
        }

        return $esperados;
    }
}
