<?php

namespace App\Services\Correcciones\Dominios;

use App\Enums\EstadoContratoLaboral;
use App\Enums\ModoCorreccion;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\Departamento;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Expedientes\DatosExpedienteService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Fecha de ingreso, puesto, sucursal y departamento: delega en
 * DatosExpedienteService (jefe del organigrama, movimiento laboral).
 */
class LaboralDominio extends DominioColaborador
{
    public function __construct(AlcanceOrganizacionalService $alcance, private readonly DatosExpedienteService $datos)
    {
        parent::__construct($alcance);
    }

    public function clave(): string
    {
        return 'laboral';
    }

    protected function restriccion(Model $sujeto, string $campo): ?array
    {
        if ($campo !== 'fecha_ingreso' || ! $sujeto instanceof Colaborador) {
            return null;
        }

        $bloqueos = $this->datos->bloqueosFechaIngreso($sujeto);

        return $bloqueos === [] ? null : [
            ModoCorreccion::NoCorregible,
            sprintf('No se corrige aquí porque %s. Si cambia, contratos y antigüedad cambian también: requiere revisión de RH y Jurídico (pendiente de validación).', implode(', ', $bloqueos)),
        ];
    }

    protected function opcionesDe(string $campo): array
    {
        $modelo = match ($campo) {
            'puesto_id' => Puesto::query(),
            'sucursal_principal_id' => Sucursal::query(),
            'departamento_id' => Departamento::query(),
            default => null,
        };

        if ($modelo === null) {
            return [];
        }

        $opciones = [];

        foreach ($modelo->orderBy('nombre')->get(['id', 'nombre']) as $fila) {
            $opciones[] = ['valor' => (string) $fila->getKey(), 'etiqueta' => (string) $fila->getAttribute('nombre')];
        }

        return $opciones;
    }

    public function normalizar(Model $sujeto, array $valores, array $opciones, User $actor): array
    {
        $resultado = parent::normalizar($sujeto, $valores, $opciones, $actor);

        // No se mueve a nadie a una sucursal fuera del alcance de quien corrige.
        if (isset($resultado['sucursal_principal_id']) && ! $this->alcance->tieneAlcanceGlobal($actor)
            && ! $this->alcance->sucursalesVisiblesIds($actor)->contains((int) $resultado['sucursal_principal_id'])) {
            throw ValidationException::withMessages(['valores.sucursal_principal_id' => 'Esa sucursal está fuera de tu alcance.']);
        }

        return $resultado;
    }

    public function afectados(Model $sujeto, array $valores, array $opciones): array
    {
        $afectados = [];

        if (array_intersect_key($valores, array_flip(DatosExpedienteService::CAMPOS_LABORALES)) !== []) {
            $afectados[] = ['tipo' => 'organigrama', 'etiqueta' => 'Jefe directo y organigrama', 'detalle' => 'El jefe se recalcula según el puesto y la sucursal. Revisa headcount y vacantes.', 'accion' => 'se_actualiza'];
            $afectados[] = ['tipo' => 'documento', 'etiqueta' => 'Contratos y recibos emitidos', 'detalle' => 'Conservan el puesto y la sucursal con los que se firmaron o emitieron.', 'accion' => 'intacto'];
        }

        if (array_key_exists('fecha_ingreso', $valores)) {
            $vigentes = ContratoLaboral::query()->where('colaborador_id', $sujeto->getKey())->where('estado', EstadoContratoLaboral::Vigente->value)->count();

            if ($vigentes > 0) {
                $afectados[] = ['tipo' => 'contrato', 'etiqueta' => sprintf('%d contrato(s) vigente(s) sin firmar', $vigentes), 'detalle' => 'Sus fechas NO cambian solas: corrígelas en «Datos de contratación» y revisa el periodo de prueba.', 'accion' => 'revisar'];
            }
        }

        return $afectados;
    }

    public function aplicar(Model $sujeto, array $valores, string $motivo, array $opciones, User $actor): array
    {
        if (! $sujeto instanceof Colaborador) {
            throw ValidationException::withMessages(['valores' => 'Registro no válido.']);
        }

        $this->datos->actualizarLaborales($sujeto, [...array_map(fn (?string $v): ?int => is_numeric($v) ? (int) $v : null, array_intersect_key($valores, array_flip(DatosExpedienteService::CAMPOS_LABORALES))), ...array_intersect_key($valores, ['fecha_ingreso' => true]), 'motivo' => $motivo], $actor, 'correcciones');

        return ['modo' => ModoCorreccion::Directo->value];
    }
}
