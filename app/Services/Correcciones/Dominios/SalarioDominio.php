<?php

namespace App\Services\Correcciones\Dominios;

use App\Enums\EstadoContratoLaboral;
use App\Enums\EstadoFiniquito;
use App\Enums\EstadoReciboNomina;
use App\Enums\ModoCorreccion;
use App\Enums\TipoCambioSalario;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\FiniquitoCalculo;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Compensacion\CompensacionService;
use App\Services\Nomina\IncidenciasNominaService;
use App\Services\Salarios\AccesoSueldoService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Sueldo mensual (y diario derivado). Delega en CompensacionService; el recibo
 * emitido y el contrato firmado nunca cambian: se reportan como afectados.
 */
class SalarioDominio extends DominioBase
{
    public function __construct(
        private readonly CompensacionService $compensacion,
        private readonly IncidenciasNominaService $incidencias,
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly AccesoSueldoService $acceso,
    ) {}

    public function clave(): string
    {
        return 'salario';
    }

    public function sujeto(int $id, bool $bloquear = false): ?Model
    {
        return Colaborador::query()->whereKey($id)->when($bloquear, fn ($q) => $q->lockForUpdate())->first();
    }

    public function etiquetaSujeto(Model $sujeto): string
    {
        return $sujeto instanceof Colaborador ? $sujeto->nombreCompleto() : '';
    }

    public function colaboradorDe(Model $sujeto): ?Colaborador
    {
        return $sujeto instanceof Colaborador ? $sujeto : null;
    }

    public function puedeLeer(User $actor, Model $sujeto): bool
    {
        return $sujeto instanceof Colaborador && $this->acceso->puedeVer($actor)
            && $this->alcance->puedeVerExpediente($actor, $sujeto);
    }

    public function puedeEscribir(User $actor, Model $sujeto): bool
    {
        return $sujeto instanceof Colaborador && ! $this->esPropio($actor, $sujeto)
            && $actor->can('salarios.editar') && $this->puedeLeer($actor, $sujeto);
    }

    public function tiposDeCambio(Model $sujeto): array
    {
        $tieneSueldo = $sujeto instanceof Colaborador && $this->compensacion->mensualDe($sujeto) !== null;

        if (! $tieneSueldo) {
            return [['valor' => TipoCambioSalario::PrimeraCaptura->value, 'etiqueta' => TipoCambioSalario::PrimeraCaptura->etiqueta(), 'descripcion' => 'Todavía no tiene sueldo: no es un cambio.']];
        }

        return [
            ['valor' => TipoCambioSalario::Correccion->value, 'etiqueta' => TipoCambioSalario::Correccion->etiqueta(), 'descripcion' => 'El sueldo vigente es un error de captura. Rige desde que ese valor entró en vigor.'],
            ['valor' => TipoCambioSalario::Aumento->value, 'etiqueta' => TipoCambioSalario::Aumento->etiqueta(), 'descripcion' => 'Sueldo mayor a partir de hoy. La historia anterior sigue siendo válida.'],
            ['valor' => TipoCambioSalario::AjusteConVigencia->value, 'etiqueta' => TipoCambioSalario::AjusteConVigencia->etiqueta(), 'descripcion' => 'Aplica desde una fecha pasada o de hoy.'],
        ];
    }

    public function normalizar(Model $sujeto, array $valores, array $opciones, User $actor): array
    {
        $resultado = parent::normalizar($sujeto, $valores, $opciones, $actor);

        if (! $sujeto instanceof Colaborador || ! isset($resultado['sueldo_mensual'])) {
            return $resultado;
        }

        // Reglas del acto (lanza si no aplica); no escribe nada.
        $this->compensacion->fechaEfectivaDe($sujeto, (float) $resultado['sueldo_mensual'], $this->tipo($opciones), $opciones['vigente_desde'] ?? null);

        return $resultado;
    }

    public function derivados(Model $sujeto, array $valores): array
    {
        if (! isset($valores['sueldo_mensual'])) {
            return [];
        }

        return [[
            'campo' => 'salario_diario',
            'etiqueta' => 'Salario diario (derivado)',
            'antes' => $this->comoTexto($sujeto->getAttribute('salario_diario')),
            'despues' => number_format($this->compensacion->diarioDesdeMensual((float) $valores['sueldo_mensual']), 2, '.', ''),
            'confidencial' => true,
        ]];
    }

    public function afectados(Model $sujeto, array $valores, array $opciones): array
    {
        if (! $sujeto instanceof Colaborador || ! isset($valores['sueldo_mensual'])) {
            return [];
        }

        $mensual = (float) $valores['sueldo_mensual'];
        $tipo = $this->tipo($opciones);
        $afectados = [];

        foreach ($this->compensacion->contratosVigentesFirmados($sujeto) as $contrato) {
            $afectados[] = [
                'tipo' => 'contrato',
                'etiqueta' => sprintf('Contrato %s (firmado)', $contrato->tipo->etiqueta()),
                'detalle' => 'Conserva el sueldo firmado. No se genera un anexo automático.',
                'accion' => 'requiere_modificacion_contractual',
            ];
        }

        $firmados = array_column($afectados, 'etiqueta');
        $sinFirmar = ContratoLaboral::query()->where('colaborador_id', $sujeto->id)->where('estado', EstadoContratoLaboral::Vigente->value)->count() - count($firmados);

        if ($sinFirmar > 0) {
            $afectados[] = ['tipo' => 'contrato', 'etiqueta' => sprintf('%d contrato(s) vigente(s) sin firmar', $sinFirmar), 'detalle' => 'Su registro toma el sueldo nuevo. Si ya tienen documento, regenéralo.', 'accion' => 'se_actualiza'];
        }

        if ($tipo->afectaPeriodosPasados()) {
            $desde = $this->compensacion->fechaEfectivaDe($sujeto, $mensual, $tipo, $opciones['vigente_desde'] ?? null);

            foreach ($this->incidencias->recibosAfectadosPorSueldo($sujeto, $desde, $mensual) as $fila) {
                $recibo = $fila['recibo'];
                $emitido = $recibo->estado === EstadoReciboNomina::Emitido;
                $afectados[] = [
                    'tipo' => 'recibo',
                    'etiqueta' => sprintf('Recibo %s (%s – %s)', (string) $recibo->folio, $recibo->periodo_inicio->format('d/m/Y'), $recibo->periodo_fin->format('d/m/Y')),
                    'detalle' => sprintf('Sueldo del periodo: %s → %s. %s', $this->dinero($fila['importe_actual']), $this->dinero($fila['importe_nuevo']), $emitido ? 'El emitido no cambia: queda una rectificación pendiente.' : 'El preparado se ajusta.'),
                    'accion' => $emitido ? 'requiere_rectificacion' : 'se_actualiza',
                ];
            }
        } else {
            $afectados[] = ['tipo' => 'recibo', 'etiqueta' => 'Recibos existentes', 'detalle' => 'No cambian: los periodos anteriores conservan el sueldo con el que se calcularon.', 'accion' => 'intacto'];
        }

        foreach (FiniquitoCalculo::query()->where('colaborador_id', $sujeto->id)->get() as $finiquito) {
            $cerrado = in_array($finiquito->estado, [EstadoFiniquito::Firmado, EstadoFiniquito::Pagado], true);
            $afectados[] = [
                'tipo' => 'finiquito',
                'etiqueta' => sprintf('Finiquito (%s)', $finiquito->estado->etiqueta()),
                'detalle' => $cerrado ? 'Firmado o pagado: no cambia.' : 'No se recalcula solo: revísalo y recalcúlalo desde su panel.',
                'accion' => $cerrado ? 'intacto' : 'revisar',
            ];
        }

        return $afectados;
    }

    public function aplicar(Model $sujeto, array $valores, string $motivo, array $opciones, User $actor): array
    {
        if (! $sujeto instanceof Colaborador || ! isset($valores['sueldo_mensual'])) {
            throw ValidationException::withMessages(['valores' => 'Captura el sueldo mensual.']);
        }

        $tipo = $this->tipo($opciones);
        $historial = $this->compensacion->cambiarSueldo($sujeto, (float) $valores['sueldo_mensual'], $tipo, $motivo, $actor, $opciones['vigente_desde'] ?? null);
        $desde = $historial->fecha_efectiva->toDateString();

        if ($tipo->afectaPeriodosPasados()) {
            $this->incidencias->sincronizarSueldo($sujeto->refresh(), $desde, $motivo, $actor);
        }

        return ['modo' => ModoCorreccion::ConVigencia->value, 'tipo' => $tipo->value, 'vigente_desde' => $desde, 'resultado' => $historial];
    }

    protected function esVisible(Model $sujeto, string $campo, User $actor): bool
    {
        return $this->acceso->puedeVer($actor);
    }

    /**
     * @param  array{tipo?: string|null, vigente_desde?: string|null}  $opciones
     */
    private function tipo(array $opciones): TipoCambioSalario
    {
        $tipo = TipoCambioSalario::tryFrom((string) ($opciones['tipo'] ?? ''));

        if ($tipo === null || $tipo === TipoCambioSalario::Importacion) {
            throw ValidationException::withMessages(['tipo' => 'Indica qué tipo de cambio de sueldo es.']);
        }

        return $tipo;
    }
}
