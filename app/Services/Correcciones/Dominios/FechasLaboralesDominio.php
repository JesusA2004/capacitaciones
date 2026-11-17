<?php

namespace App\Services\Correcciones\Dominios;

use App\Enums\ModoCorreccion;
use App\Enums\UnidadPeriodoPrueba;
use App\Models\Colaborador;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Colaboradores\FechasLaboralesService;
use App\Services\Expedientes\DatosExpedienteService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * «Fechas laborales» del expediente (docs/FECHAS_LABORALES.md): ingreso,
 * inicio de capacitación inicial, inducción, alta IMSS y periodo de prueba
 * específico. Pasa por el sistema universal de correcciones, así cada cambio
 * queda en el historial inmutable (quién, cuándo, antes/después, motivo
 * obligatorio) junto con las fechas CALCULADAS que se movieron (derivados).
 *
 * La fecha de ingreso se guarda por DatosExpedienteService (mismos bloqueos
 * que «Datos laborales»); lo demás y el recálculo de pendientes, por
 * FechasLaboralesService. Nunca toca un contrato: si queda desfasado, avisa.
 */
class FechasLaboralesDominio extends DominioColaborador
{
    public function __construct(
        AlcanceOrganizacionalService $alcance,
        private readonly FechasLaboralesService $fechas,
        private readonly DatosExpedienteService $datos,
    ) {
        parent::__construct($alcance);
    }

    public function clave(): string
    {
        return 'fechas_laborales';
    }

    public function puedeEscribir(User $actor, Model $sujeto): bool
    {
        return $sujeto instanceof Colaborador && $this->fechas->puedeEditar($actor, $sujeto);
    }

    protected function restriccion(Model $sujeto, string $campo): ?array
    {
        if ($campo !== 'fecha_ingreso' || ! $sujeto instanceof Colaborador) {
            return null;
        }

        $bloqueos = $this->datos->bloqueosFechaIngreso($sujeto);

        return $bloqueos === [] ? null : [
            ModoCorreccion::NoCorregible,
            sprintf('La fecha de ingreso no se corrige porque %s (antigüedad, recibos y finiquito ya dependen de ella; pendiente de validación por RH y Jurídico). Sí puedes mover el inicio de la capacitación inicial y el periodo de prueba.', implode(', ', $bloqueos)),
        ];
    }

    protected function opcionesDe(string $campo): array
    {
        if ($campo !== 'periodo_prueba_unidad') {
            return [];
        }

        return array_map(fn (UnidadPeriodoPrueba $u): array => ['valor' => $u->value, 'etiqueta' => $u->etiqueta()], UnidadPeriodoPrueba::cases());
    }

    public function normalizar(Model $sujeto, array $valores, array $opciones, User $actor): array
    {
        $resultado = parent::normalizar($sujeto, $valores, $opciones, $actor);

        if (! $sujeto instanceof Colaborador) {
            return $resultado;
        }

        // Periodo específico: cantidad y unidad van juntas (ambas vacías = hereda del puesto).
        $cantidad = array_key_exists('periodo_prueba_cantidad', $resultado) ? $resultado['periodo_prueba_cantidad'] : $this->comoTexto($sujeto->periodo_prueba_cantidad);
        $unidad = array_key_exists('periodo_prueba_unidad', $resultado) ? $resultado['periodo_prueba_unidad'] : $this->comoTexto($sujeto->periodo_prueba_unidad);

        if (($cantidad === null) !== ($unidad === null)) {
            throw ValidationException::withMessages([
                'valores.periodo_prueba_cantidad' => 'Captura la cantidad y la unidad (meses o días) del periodo específico, o deja ambas vacías para heredar el del puesto.',
            ]);
        }

        return $resultado;
    }

    public function derivados(Model $sujeto, array $valores): array
    {
        return $sujeto instanceof Colaborador ? $this->fechas->cambiosCalculados($sujeto, $valores) : [];
    }

    public function afectados(Model $sujeto, array $valores, array $opciones): array
    {
        if (! $sujeto instanceof Colaborador) {
            return [];
        }

        $afectados = $this->fechas->afectados($sujeto, $valores);
        $simulado = $this->fechas->simular($sujeto, $valores);

        if ($simulado->fecha_ingreso !== null && $simulado->periodo_prueba_inicio !== null
            && $simulado->periodo_prueba_inicio->lt($simulado->fecha_ingreso)) {
            $afectados[] = ['tipo' => 'fecha', 'etiqueta' => 'La capacitación inicial empieza antes del ingreso', 'detalle' => 'Revisa que sea correcto: normalmente empieza el mismo día del ingreso o después.', 'accion' => 'revisar'];
        }

        return $afectados;
    }

    public function aplicar(Model $sujeto, array $valores, string $motivo, array $opciones, User $actor): array
    {
        if (! $sujeto instanceof Colaborador) {
            throw ValidationException::withMessages(['valores' => 'Registro no válido.']);
        }

        if (array_key_exists('fecha_ingreso', $valores) && $valores['fecha_ingreso'] !== null) {
            $this->datos->actualizarLaborales($sujeto, ['fecha_ingreso' => $valores['fecha_ingreso'], 'motivo' => $motivo], $actor, 'fechas_laborales');
        }

        $resto = $valores;
        unset($resto['fecha_ingreso']);
        $this->fechas->guardar($sujeto->refresh(), $resto);

        return ['modo' => ModoCorreccion::Directo->value];
    }
}
