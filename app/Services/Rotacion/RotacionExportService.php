<?php

namespace App\Services\Rotacion;

use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Support\FechaMexico;

/**
 * Datos del módulo de Rotación para la pantalla y TODAS las exportaciones
 * (Excel, resumen ejecutivo y reporte detallado): una sola llamada por
 * petición, así el archivo nunca trae otra cifra que la pantalla con los
 * mismos filtros.
 */
class RotacionExportService
{
    /** @var array<string, array<string, mixed>> */
    private array $cache = [];

    public function __construct(
        private readonly RotacionAnalisisService $analisis,
        private readonly HallazgosRotacionService $hallazgos,
        private readonly RotacionMotorService $motor,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function datos(User $usuario, FiltrosRotacion $f): array
    {
        $clave = $usuario->id.'|'.json_encode($f->aArray()).'|'.$f->sucursales->sort()->implode(',');

        return $this->cache[$clave] ??= (function () use ($usuario, $f): array {
            $resumen = $this->analisis->resumen($f);
            $causales = $this->analisis->causales($f);
            $calidad = $this->analisis->calidad($f);

            return [
                'alcance' => $this->alcance($f),
                'resumen' => $resumen,
                'tendencia' => $this->analisis->tendencia($f),
                'causales' => $causales,
                'antiguedad' => $this->analisis->antiguedad($f),
                'sucursales' => $this->analisis->sucursales($f),
                'matriz' => $this->analisis->matriz($f),
                'calidad' => $calidad,
                'hallazgos' => $this->hallazgos->hallazgos($usuario, $f, $resumen, $causales, $calidad),
            ];
        })();
    }

    /**
     * Encabezado de los reportes: qué se consultó y con qué corte.
     *
     * @return array<string, mixed>
     */
    private function alcance(FiltrosRotacion $f): array
    {
        $nombre = fn (?int $id, string $modelo): ?string => $id === null ? null : (string) $modelo::query()->whereKey($id)->value('nombre');

        return [
            'empresa' => $nombre($f->empresaId, Empresa::class) ?? ($f->empresaId === null ? 'Todas las empresas autorizadas' : null),
            'sucursal' => $nombre($f->sucursalId, Sucursal::class) ?? sprintf('%d sucursal(es) autorizada(s)', $f->sucursales->count()),
            'puesto' => $nombre($f->puestoId, Puesto::class),
            'area' => $nombre($f->departamentoId, Departamento::class),
            'clasificacion' => $f->clasificacion?->etiqueta(),
            'causal' => $f->causalAnaliticaId !== null ? ($this->motor->analiticas()->get($f->causalAnaliticaId)->nombre ?? null) : null,
            'antiguedad' => $f->rango?->etiqueta(),
            'fuente' => match ($f->fuente) {
                'sistema' => 'Solo sistema',
                'historico' => 'Solo histórico',
                default => 'Todas las fuentes',
            },
            'desde' => $f->desde->toDateString(),
            'hasta' => $f->hasta->toDateString(),
            'corte' => $f->hasta->toDateString(),
            'corte_parcial' => $f->hasta->isSameDay(FechaMexico::inicioDeHoyInmutable()) && ! $f->hasta->isLastOfMonth(),
            'generado' => now()->format('Y-m-d H:i'),
            'filtros' => $f->aArray(),
        ];
    }
}
