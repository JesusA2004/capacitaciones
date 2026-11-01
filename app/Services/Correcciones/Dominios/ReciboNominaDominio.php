<?php

namespace App\Services\Correcciones\Dominios;

use App\Enums\EstadoReciboNomina;
use App\Enums\ModoCorreccion;
use App\Enums\TipoConceptoNomina;
use App\Models\Colaborador;
use App\Models\ReciboNomina;
use App\Models\User;
use App\Services\Nomina\IncidenciasNominaService;
use App\Services\Nomina\ReciboNominaService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Incidencias y conceptos de un recibo. Preparado: corrección directa. Emitido:
 * solo rectificación pendiente, el original nunca cambia. Cancelado: nada.
 */
class ReciboNominaDominio extends DominioBase
{
    public function __construct(
        private readonly ReciboNominaService $recibos,
        private readonly IncidenciasNominaService $incidencias,
    ) {}

    public function clave(): string
    {
        return 'recibo_nomina';
    }

    public function sujeto(int $id, bool $bloquear = false): ?Model
    {
        return ReciboNomina::query()->whereKey($id)->when($bloquear, fn ($q) => $q->lockForUpdate())->first();
    }

    public function etiquetaSujeto(Model $sujeto): string
    {
        return $sujeto instanceof ReciboNomina ? sprintf('Recibo %s', (string) $sujeto->folio) : '';
    }

    public function colaboradorDe(Model $sujeto): ?Colaborador
    {
        return $sujeto instanceof ReciboNomina ? $sujeto->colaborador()->withTrashed()->first() : null;
    }

    public function puedeLeer(User $actor, Model $sujeto): bool
    {
        return $actor->can('ver', $sujeto) && ! $this->esPropio($actor, $this->colaboradorDe($sujeto));
    }

    public function puedeEscribir(User $actor, Model $sujeto): bool
    {
        return ! $this->esPropio($actor, $this->colaboradorDe($sujeto)) && $actor->can('editar', $sujeto);
    }

    protected function definiciones(Model $sujeto): array
    {
        $definiciones = parent::definiciones($sujeto);

        if (! $sujeto instanceof ReciboNomina) {
            return $definiciones;
        }

        foreach ($this->recibos->conceptosEditables($sujeto) as $indice => $concepto) {
            $definiciones[sprintf('concepto_%d', $indice)] = [
                'etiqueta' => sprintf('%s (%s)', $concepto['concepto'], $concepto['tipo'] === TipoConceptoNomina::Percepcion->value ? 'percepción' : 'deducción'),
                'tipo' => 'moneda',
                'modo' => ModoCorreccion::SoloRectificacion->value,
                'permiso' => 'nomina.recibos.crear',
                'reglas' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
            ];
        }

        return $definiciones;
    }

    protected function valor(Model $sujeto, string $campo): mixed
    {
        if ($sujeto instanceof ReciboNomina && str_starts_with($campo, 'concepto_')) {
            $concepto = $this->recibos->conceptosEditables($sujeto)[(int) substr($campo, 9)] ?? null;

            return $concepto !== null ? number_format($concepto['importe'], 2, '.', '') : null;
        }

        return parent::valor($sujeto, $campo);
    }

    protected function restriccion(Model $sujeto, string $campo): ?array
    {
        if (! $sujeto instanceof ReciboNomina) {
            return null;
        }

        if ($sujeto->estado === EstadoReciboNomina::Cancelado) {
            return [ModoCorreccion::NoCorregible, 'Este recibo está cancelado: se conserva como quedó.'];
        }

        if ($sujeto->rectificado_por_id !== null) {
            return [ModoCorreccion::NoCorregible, 'Este recibo ya se rectificó: corrige el recibo vigente.'];
        }

        if ($sujeto->estado === EstadoReciboNomina::Borrador) {
            return [ModoCorreccion::Directo, ''];
        }

        if ($campo === 'observaciones') {
            return [ModoCorreccion::NoCorregible, 'Las observaciones de un recibo emitido no se editan: el motivo queda en la rectificación.'];
        }

        return [ModoCorreccion::SoloRectificacion, 'El recibo ya está emitido: se crea una rectificación pendiente. Al aplicarla se emite un recibo nuevo enlazado y el original conserva folio, conceptos y PDF.'];
    }

    public function normalizar(Model $sujeto, array $valores, array $opciones, User $actor): array
    {
        $reglasDinamicas = $this->definiciones($sujeto);
        $resultado = [];

        foreach ($valores as $campo => $valor) {
            if (! isset($reglasDinamicas[$campo])) {
                continue;
            }

            if (str_starts_with($campo, 'concepto_') && (! is_numeric($valor) || (float) $valor < 0)) {
                throw ValidationException::withMessages(['valores.'.$campo => 'El importe debe ser un número mayor o igual a cero.']);
            }

            $resultado[$campo] = str_starts_with($campo, 'concepto_')
                ? number_format((float) $valor, 2, '.', '')
                : null;
        }

        return [...$resultado, ...parent::normalizar($sujeto, array_filter($valores, fn (string $c): bool => ! str_starts_with($c, 'concepto_'), ARRAY_FILTER_USE_KEY), $opciones, $actor)];
    }

    public function derivados(Model $sujeto, array $valores): array
    {
        if (! $sujeto instanceof ReciboNomina || array_filter(array_keys($valores), fn (string $c): bool => str_starts_with($c, 'concepto_')) === []) {
            return [];
        }

        $conceptos = $this->conceptosCon($sujeto, $valores);
        $neto = 0.0;

        foreach ($conceptos as $concepto) {
            $neto += $concepto['tipo'] === TipoConceptoNomina::Percepcion->value ? (float) $concepto['importe'] : -(float) $concepto['importe'];
        }

        return [['campo' => 'neto', 'etiqueta' => 'Neto del recibo', 'antes' => (string) $sujeto->neto, 'despues' => number_format($neto, 2, '.', ''), 'confidencial' => false]];
    }

    public function afectados(Model $sujeto, array $valores, array $opciones): array
    {
        $emitido = $sujeto instanceof ReciboNomina && $sujeto->estado === EstadoReciboNomina::Emitido;

        return [[
            'tipo' => 'recibo',
            'etiqueta' => $this->etiquetaSujeto($sujeto),
            'detalle' => $emitido
                ? 'No cambia. Queda una rectificación pendiente en Incapacidades e incidencias; al aplicarla se emite un recibo nuevo.'
                : 'Se ajusta el recibo preparado.',
            'accion' => $emitido ? 'requiere_rectificacion' : 'se_actualiza',
        ]];
    }

    public function aplicar(Model $sujeto, array $valores, string $motivo, array $opciones, User $actor): array
    {
        if (! $sujeto instanceof ReciboNomina) {
            throw ValidationException::withMessages(['valores' => 'Registro no válido.']);
        }

        $conceptos = $this->conceptosCon($sujeto, $valores);
        $dias = [
            'dias_pagados' => (float) ($valores['dias_pagados'] ?? $sujeto->dias_pagados ?? $this->incidencias->diasBaseDe($sujeto)),
            'dias_falta' => (float) ($valores['dias_falta'] ?? $sujeto->dias_falta ?? 0),
            'dias_incapacidad' => (float) ($valores['dias_incapacidad'] ?? $sujeto->dias_incapacidad ?? 0),
        ];

        if ($sujeto->estado === EstadoReciboNomina::Emitido) {
            $sueldo = 0.0;

            foreach ($conceptos as $concepto) {
                if ($concepto['tipo'] === TipoConceptoNomina::Percepcion->value && (($concepto['clave'] ?? null) === '001' || str_starts_with(mb_strtolower($concepto['concepto']), 'sueldo'))) {
                    $sueldo = (float) $concepto['importe'];
                    break;
                }
            }

            $rectificacion = $this->incidencias->proponerRectificacion($sujeto, $conceptos, [...$dias, 'importe_sueldo' => $sueldo > 0 ? $sueldo : (float) $sujeto->sueldo_base], $motivo, $actor);

            return ['modo' => ModoCorreccion::SoloRectificacion->value, 'resultado' => $rectificacion];
        }

        // Solo los días capturados: los no tocados conservan su valor.
        $sujeto->update(array_intersect_key($dias, $valores));
        $this->recibos->actualizar($sujeto, [
            'conceptos' => $conceptos,
            ...array_intersect_key($valores, ['observaciones' => true]),
        ], $actor);

        return ['modo' => ModoCorreccion::Directo->value];
    }

    /**
     * @param  array<string, string|null>  $valores
     * @return list<array{tipo: string, clave?: string|null, concepto: string, cantidad: float, importe: float, observaciones: string|null}>
     */
    private function conceptosCon(ReciboNomina $recibo, array $valores): array
    {
        $conceptos = $this->recibos->conceptosEditables($recibo);

        foreach ($conceptos as $indice => $concepto) {
            $clave = sprintf('concepto_%d', $indice);

            if (array_key_exists($clave, $valores)) {
                $conceptos[$indice]['importe'] = (float) $valores[$clave];
            }
        }

        return $conceptos;
    }
}
