<?php

namespace App\Services\DocumentosMaestros;

use App\Enums\TipoBaja;
use App\Enums\TipoSolicitudInterna;
use App\Models\Colaborador;
use App\Models\EvaluacionPeriodoPrueba;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Services\CierreLaboral\CierreLaboralService;
use App\Services\Contratos\ContratoLaboralService;
use App\Services\Nomina\PrestamoService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Vista previa de los documentos que un flujo GENERA SOLO al confirmarse
 * (regla: todo formato para un colaborador se puede ver antes de
 * generarlo). El registro del proceso todavía no existe, así que se arma
 * EN MEMORIA con los datos del formulario —mismas reglas con las que el
 * flujo lo creará— y se le pasa a DocumentoProcesoService::vistaPrevia().
 * Nunca se guarda nada: ni contrato, ni cierre, ni préstamo, ni documento.
 *
 *  - renovacion     (evaluación): contrato indeterminado de ContratoLaboralService::renovar().
 *  - no_renovacion  (evaluación): aviso de terminación + formato de evaluación NO acredita
 *                   del cierre que nace en EvaluacionPeriodoPruebaService::autorizar().
 *  - baja           (colaborador): renuncia / aviso de la causa de CierreLaboralService::solicitar().
 *  - prestamo       (solicitud):   contrato de crédito, pagaré y carta de retención de
 *                   PrestamoAutorizacionService::autorizar().
 *
 * La regla de negocio de cada flujo no cambia: sus documentos se siguen
 * generando al confirmar.
 */
class VistaPreviaFlujoService
{
    public const FLUJOS = ['renovacion', 'no_renovacion', 'baja', 'prestamo'];

    public function __construct(
        private readonly DocumentoProcesoService $documentos,
        private readonly ContratoLaboralService $contratos,
        private readonly CierreLaboralService $cierres,
        private readonly PrestamoService $prestamos,
    ) {}

    /**
     * Documentos que el flujo generaría al confirmar y que quien mira puede
     * previsualizar.
     *
     * @param  array<string, mixed>  $datos  Datos del formulario (tipo_baja, fecha_efectiva, motivo, monto_autorizado…).
     * @return list<array{clave: string, nombre: string}>
     */
    public function documentos(string $flujo, int $id, array $datos, User $actor): array
    {
        ['proceso' => $proceso, 'registro' => $registro] = $this->armar($flujo, $id, $datos, $actor);

        return array_map(
            fn (string $clave): array => ['clave' => $clave, 'nombre' => $this->documentos->nombreDocumento($clave)],
            $this->documentos->clavesPorGenerar($proceso, $registro, $actor),
        );
    }

    /**
     * PDF de vista previa de un documento del flujo (sin guardar nada).
     *
     * @param  array<string, mixed>  $datos
     * @return array{pdf: string, nombre: string, faltantes: int}
     */
    public function pdf(string $flujo, int $id, string $clave, array $datos, User $actor): array
    {
        ['proceso' => $proceso, 'registro' => $registro, 'manuales' => $manuales] = $this->armar($flujo, $id, $datos, $actor);

        // Permiso y alcance los exige vistaPrevia() (403); aquí solo que el
        // documento sea uno de los que este paso genera.
        if (! in_array($clave, $this->documentos->clavesPorGenerar($proceso, $registro, $actor, soloPermitidas: false), true)) {
            throw ValidationException::withMessages(['clave' => 'Ese documento no se genera en este paso.']);
        }

        return $this->documentos->vistaPrevia($proceso, $registro, $clave, $actor, $manuales);
    }

    /**
     * Registro EN MEMORIA del flujo + proceso documental + datos del acto
     * con los que el flujo genera sus documentos.
     *
     * @param  array<string, mixed>  $datos
     * @return array{proceso: string, registro: Model, manuales: array<string, string>}
     */
    private function armar(string $flujo, int $id, array $datos, User $actor): array
    {
        switch ($flujo) {
            case 'renovacion':
                $nuevo = $this->contratos->propuestaRenovacion($this->evaluacion($id)->contrato, $actor);

                // Mismos datos del contrato que ContratoLaboralService::prepararDocumentos().
                return ['proceso' => 'renovacion', 'registro' => $nuevo, 'manuales' => $this->contratos->variablesContrato($nuevo)];

            case 'no_renovacion':
                $evaluacion = $this->evaluacion($id);
                // La no renovación del periodo de prueba es una baja por fin de
                // contrato (catálogo de 3 tipos: renuncia / rescisión / fin de contrato).
                $cierre = $this->cierres->propuesta($evaluacion->colaborador, [...$datos, 'tipo_baja' => TipoBaja::FinContrato->value], $actor, $evaluacion);

                return ['proceso' => 'baja', 'registro' => $cierre, 'manuales' => []];

            case 'baja':
                $colaborador = Colaborador::query()->where('id', $id)->firstOrFail();

                if (TipoBaja::tryFrom((string) ($datos['tipo_baja'] ?? '')) === null) {
                    throw ValidationException::withMessages(['tipo_baja' => 'Elige la causa de la baja.']);
                }

                return ['proceso' => 'baja', 'registro' => $this->cierres->propuesta($colaborador, $datos, $actor), 'manuales' => []];

            case 'prestamo':
                $solicitud = SolicitudInterna::query()->where('id', $id)->firstOrFail();

                if ($solicitud->tipo !== TipoSolicitudInterna::PrestamoInterno) {
                    throw ValidationException::withMessages(['solicitud' => 'La solicitud no es de préstamo.']);
                }

                return ['proceso' => 'prestamo', 'registro' => $this->prestamos->propuestaDesdeSolicitud($solicitud, $datos, $actor), 'manuales' => []];

            default:
                abort(404);
        }
    }

    private function evaluacion(int $id): EvaluacionPeriodoPrueba
    {
        return EvaluacionPeriodoPrueba::query()->with(['contrato', 'colaborador'])->where('id', $id)->firstOrFail();
    }
}
