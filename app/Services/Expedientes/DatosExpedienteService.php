<?php

namespace App\Services\Expedientes;

use App\Models\Colaborador;
use App\Models\ColaboradorDatosMedicos;
use App\Models\Departamento;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Colaboradores\DomicilioService;
use App\Services\Compensacion\CompensacionService;
use App\Services\MovimientosLaborales\MovimientoLaboralService;
use App\Services\Organigrama\JefeDirectoService;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ÚNICO escritor de los datos personales/de contacto y laborales del
 * expediente de un colaborador. Lo usan la pestaña «Datos» del expediente
 * (Rh\ExpedienteController) y la edición rápida del Directorio
 * (DirectorioEdicionService): la misma regla para ambas pantallas.
 *
 * La autorización (permiso, alcance y «nadie edita lo suyo») la resuelve
 * quien llama (Form Request), nunca este service.
 */
class DatosExpedienteService
{
    /** Campos laborales estructurales; el sueldo va aparte por CompensacionService. */
    public const CAMPOS_LABORALES = ['sucursal_principal_id', 'departamento_id', 'puesto_id'];

    /** Datos médicos (cifrados en ColaboradorDatosMedicos, permiso expedientes.datos_medicos.ver). */
    public const CAMPOS_MEDICOS = ['condicion_medica', 'alergias'];

    /**
     * Datos CRÍTICOS: identifican a la persona ante IMSS/SAT o definen su
     * relación laboral. Cambiarlos desde el expediente exige motivo
     * (ActualizarDatosPersonalesRequest / ActualizarDatosLaboralesRequest).
     */
    public const CAMPOS_CRITICOS = [
        'name', 'apellidos', 'curp', 'rfc', 'nss', 'fecha_nacimiento', 'genero',
        'condicion_medica', 'alergias', 'sueldo_mensual',
    ];

    /** Bitácora (AuditoriaService) de la edición administrativa desde el expediente. */
    public const EVENTO_PERSONALES = 'expediente_datos_editados';

    public const EVENTO_LABORALES = 'expediente_datos_laborales_editados';

    /** Etiquetas de los campos que no están en DatosFaltantesService. */
    private const ETIQUETAS = [
        'name' => 'Nombre(s)',
        'apellidos' => 'Apellidos',
        'telefono_corporativo' => 'Teléfono corporativo',
        'correo_corporativo' => 'Correo corporativo',
        'correo_personal' => 'Correo personal',
        'contacto_emergencia_direccion' => 'Dirección del contacto de emergencia',
        'beneficiario_nombre' => 'Beneficiario',
        'beneficiario_parentesco' => 'Parentesco del beneficiario',
        'condicion_medica' => 'Condición médica',
        'alergias' => 'Alergias',
        'domicilio' => 'Domicilio (texto)',
        'sucursal_principal_id' => 'Sucursal',
        'departamento_id' => 'Departamento',
        'puesto_id' => 'Puesto',
        'sueldo_mensual' => 'Sueldo mensual',
    ];

    public function __construct(
        private readonly MovimientoLaboralService $movimientos,
        private readonly JefeDirectoService $jefes,
        private readonly CompensacionService $compensacion,
        private readonly AuditoriaService $auditoria,
        private readonly DomicilioService $domicilios,
    ) {}

    /**
     * Datos personales y de contacto ya validados (solo las llaves
     * presentes se escriben). NUNCA en silencio: cada dato que de verdad
     * cambia queda en la bitácora (activity_log, log «rh») con su valor
     * anterior, el nuevo, quién, cuándo, desde qué módulo y el motivo, y se
     * muestra en «Historial de cambios» del expediente
     * (HistorialCambiosDatosService). Los datos médicos van cifrados a
     * ColaboradorDatosMedicos y, en la bitácora, también cifrados.
     *
     * Devuelve los cambios aplicados (vacío si nada cambió).
     *
     * @param  array<string, mixed>  $datos
     * @return list<array{campo: string, etiqueta: string, antes: string|null, despues: string|null, critico: bool, confidencial: bool}>
     */
    public function actualizarPersonales(Colaborador $colaborador, array $datos, ?User $actor = null, ?string $motivo = null, string $modulo = 'expediente'): array
    {
        $medicos = array_intersect_key($datos, array_flip(self::CAMPOS_MEDICOS));
        $generales = array_diff_key($datos, array_flip(self::CAMPOS_MEDICOS));
        $cambios = $this->cambiosPersonales($colaborador, $datos);

        if ($cambios === []) {
            return [];
        }

        DB::transaction(function () use ($colaborador, $generales, $medicos, $actor): void {
            if ($generales !== []) {
                $colaborador->update($generales);
            }

            if ($medicos !== []) {
                ColaboradorDatosMedicos::query()->updateOrCreate(
                    ['colaborador_id' => $colaborador->id],
                    [...$medicos, 'fuente' => 'expediente', 'actualizado_por' => $actor?->id],
                );
                $colaborador->unsetRelation('datosMedicos');
            }
        });

        // La bitácora nunca tumba el guardado (AuditoriaService atrapa sus fallos).
        $this->auditoria->registrar(self::EVENTO_PERSONALES, $colaborador, $actor, [
            'modulo' => $modulo,
            'motivo' => $motivo,
            'cambios' => array_map(fn (array $c): array => $c['confidencial'] ? [
                ...$c,
                'antes' => $c['antes'] !== null ? Crypt::encryptString($c['antes']) : null,
                'despues' => $c['despues'] !== null ? Crypt::encryptString($c['despues']) : null,
            ] : $c, $cambios),
        ]);

        return $cambios;
    }

    /**
     * Qué cambiaría si se guardan estos datos: solo los campos que de
     * verdad difieren del expediente vigente (normalizados igual que al
     * guardar: CURP/RFC en mayúsculas, fechas Y-m-d, domicilio limpio).
     *
     * @param  array<string, mixed>  $datos
     * @return list<array{campo: string, etiqueta: string, antes: string|null, despues: string|null, critico: bool, confidencial: bool}>
     */
    public function cambiosPersonales(Colaborador $colaborador, array $datos): array
    {
        $cambios = [];

        foreach ($datos as $campo => $valor) {
            $campo = (string) $campo;

            // `domicilio` (texto) se recompone solo a partir del estructurado.
            if ($campo === 'domicilio' && array_intersect_key($datos, array_flip(DomicilioService::CAMPOS)) !== []) {
                continue;
            }

            $medico = in_array($campo, self::CAMPOS_MEDICOS, true);
            $antes = $this->comoTexto($medico ? $colaborador->datosMedicos?->getAttribute($campo) : $colaborador->getAttribute($campo));
            $despues = $this->comoTexto(in_array($campo, DomicilioService::CAMPOS, true) ? $this->domicilios->normalizar($campo, $valor) : $valor);

            if ($antes === $despues) {
                continue;
            }

            $cambios[] = [
                'campo' => $campo,
                'etiqueta' => self::etiquetaCampo($campo),
                'antes' => $antes,
                'despues' => $despues,
                'critico' => in_array($campo, self::CAMPOS_CRITICOS, true),
                'confidencial' => $medico,
            ];
        }

        return $cambios;
    }

    /**
     * ¿Algún dato crítico cambia? Entonces el motivo es obligatorio.
     *
     * @param  list<array{critico: bool}>  $cambios
     */
    public function cambiaCritico(array $cambios): bool
    {
        foreach ($cambios as $cambio) {
            if ($cambio['critico']) {
                return true;
            }
        }

        return false;
    }

    public static function etiquetaCampo(string $campo): string
    {
        return self::ETIQUETAS[$campo]
            ?? DatosFaltantesService::PERSONALES[$campo]
            ?? DomicilioService::ETIQUETAS[$campo]
            ?? DatosFaltantesService::LABORALES[$campo]
            ?? $campo;
    }

    /** Texto comparable de un valor (enum → value, fecha → Y-m-d, vacío → null). */
    private function comoTexto(mixed $valor): ?string
    {
        $texto = match (true) {
            $valor instanceof BackedEnum => (string) $valor->value,
            $valor instanceof DateTimeInterface => $valor->format('Y-m-d'),
            is_scalar($valor) => trim((string) $valor),
            default => '',
        };

        return $texto === '' ? null : $texto;
    }

    /**
     * Cambia sucursal (y con ella la empresa), departamento, puesto y,
     * opcionalmente, sueldo mensual — sin pasar por una vacante. Mismo
     * patrón snapshot → update → registrarCambioPuesto() que
     * VacanteController::cubrir(), aquí sin vacante.
     *
     * El sueldo solo se toca si la llave `sueldo_mensual` viene en $datos
     * (el formulario del expediente siempre la manda; el directorio no la
     * edita). Devuelve true si no se pudo asignar el sueldo por defecto
     * porque falta SALARIO_MINIMO_DIARIO (aviso para la pantalla).
     *
     * @param  array<string, mixed>  $datos  sucursal_principal_id?, departamento_id?, puesto_id?, sueldo_mensual?, motivo?
     */
    public function actualizarLaborales(Colaborador $colaborador, array $datos, User $actor, string $modulo = 'expediente'): bool
    {
        $antes = $this->movimientos->snapshot($colaborador);
        $legibleAntes = $this->laboralesLegibles($colaborador);
        $estructurales = array_intersect_key($datos, array_flip(self::CAMPOS_LABORALES));
        $motivo = is_string($datos['motivo'] ?? null) && $datos['motivo'] !== '' ? $datos['motivo'] : null;

        // Un campo de sueldo vacío NUNCA borra un sueldo que ya existía (el
        // middleware ConvertEmptyStringsToNull convierte "" en null). Solo se
        // completa con el salario mínimo cuando el colaborador NUNCA tuvo un
        // sueldo capturado. Si falta SALARIO_MINIMO_DIARIO no se inventa un
        // monto (config/nomina.php): se avisa y se guarda sin sueldo.
        $nuevoSueldoMensual = null;
        $avisoSueldoSinConfigurar = false;

        if (array_key_exists('sueldo_mensual', $datos)) {
            $sueldoCapturado = $datos['sueldo_mensual'];

            if (! empty($sueldoCapturado) && is_numeric($sueldoCapturado)) {
                $nuevoSueldoMensual = (float) $sueldoCapturado;
            } elseif ($colaborador->sueldo_mensual === null) {
                $salarioMinimoMensual = config('nomina.salario_minimo_mensual');

                if ($salarioMinimoMensual !== null) {
                    $nuevoSueldoMensual = (float) $salarioMinimoMensual;
                } else {
                    $avisoSueldoSinConfigurar = true;
                    Log::warning('DatosExpedienteService::actualizarLaborales(): SALARIO_MINIMO_DIARIO no está definida en el entorno — no se pudo asignar un sueldo por defecto.', [
                        'colaborador_id' => $colaborador->id,
                    ]);
                }
            }
        }

        DB::transaction(function () use ($colaborador, $estructurales, $antes, $actor, $motivo, $nuevoSueldoMensual): void {
            $this->aplicarEstructurales([['colaborador' => $colaborador, 'antes' => $antes, 'cambios' => $estructurales]], $actor, $motivo);

            // Sueldo canónico: CompensacionService sincroniza mensual + diario
            // + ledger. Solo si de verdad cambia el diario.
            if ($nuevoSueldoMensual !== null && $nuevoSueldoMensual > 0) {
                $diarioNuevo = $this->compensacion->diarioDesdeMensual($nuevoSueldoMensual);
                $diarioActual = $colaborador->salario_diario !== null ? (float) $colaborador->salario_diario : null;

                if ($diarioActual === null || abs($diarioActual - $diarioNuevo) > 0.001) {
                    $this->compensacion->actualizarSalario($colaborador, $diarioNuevo, null, $actor, [
                        'fuente' => 'datos_laborales',
                        'motivo' => $motivo ?? 'Actualización de sueldo desde Datos laborales.',
                    ]);
                }
            }
        });

        // Mismo historial que los datos personales: antes/después legibles
        // (nombres, no ids), quién, cuándo, módulo y motivo.
        $legibleDespues = $this->laboralesLegibles($colaborador->fresh() ?? $colaborador);
        $cambios = [];

        foreach ($legibleDespues as $campo => $despues) {
            if (($legibleAntes[$campo] ?? null) !== $despues) {
                $cambios[] = [
                    'campo' => $campo,
                    'etiqueta' => self::etiquetaCampo($campo),
                    'antes' => $legibleAntes[$campo] ?? null,
                    'despues' => $despues,
                    'critico' => in_array($campo, self::CAMPOS_CRITICOS, true),
                    'confidencial' => false,
                ];
            }
        }

        if ($cambios !== []) {
            $this->auditoria->registrar(self::EVENTO_LABORALES, $colaborador, $actor, [
                'modulo' => $modulo,
                'motivo' => $motivo,
                'cambios' => $cambios,
            ]);
        }

        return $avisoSueldoSinConfigurar;
    }

    /**
     * Sucursal, departamento, puesto y sueldo como los lee una persona.
     *
     * @return array<string, string|null>
     */
    private function laboralesLegibles(Colaborador $colaborador): array
    {
        return [
            'sucursal_principal_id' => $this->comoTexto($colaborador->sucursal_principal_id !== null ? Sucursal::query()->whereKey($colaborador->sucursal_principal_id)->value('nombre') : null),
            'departamento_id' => $this->comoTexto($colaborador->departamento_id !== null ? Departamento::query()->whereKey($colaborador->departamento_id)->value('nombre') : null),
            'puesto_id' => $this->comoTexto($colaborador->puesto_id !== null ? Puesto::query()->whereKey($colaborador->puesto_id)->value('nombre') : null),
            'sueldo_mensual' => $colaborador->sueldo_mensual !== null ? sprintf('$%s', number_format((float) $colaborador->sueldo_mensual, 2)) : null,
        ];
    }

    /**
     * Cambia el departamento de varios colaboradores con la MISMA regla que
     * la pestaña «Datos laborales» del expediente (snapshot → update →
     * jefe derivado del organigrama → movimiento laboral por persona), pero
     * recalculando el organigrama una sola vez para todo el lote. Lo usa
     * «Departamentos → mover personas» (MovimientoDepartamentoService).
     *
     * La autorización y el filtrado (alcance, «nadie se mueve a sí mismo»,
     * idempotencia) los resuelve quien llama.
     *
     * @param  iterable<Colaborador>  $colaboradores
     */
    public function cambiarDepartamentoEnLote(iterable $colaboradores, int $departamentoId, User $actor, ?string $motivo): void
    {
        $lote = [];

        foreach ($colaboradores as $colaborador) {
            $lote[] = [
                'colaborador' => $colaborador,
                'antes' => $this->movimientos->snapshot($colaborador),
                'cambios' => ['departamento_id' => $departamentoId],
            ];
        }

        if ($lote === []) {
            return;
        }

        DB::transaction(fn () => $this->aplicarEstructurales($lote, $actor, $motivo));
    }

    /**
     * Núcleo compartido de los cambios estructurales (sucursal, departamento,
     * puesto): escribe, recalcula el jefe derivado del organigrama UNA vez y
     * registra los movimientos laborales de cada persona contra su snapshot.
     * Debe llamarse dentro de una transacción.
     *
     * @param  list<array{colaborador: Colaborador, antes: array{empresa_id: int|null, sucursal_id: int|null, departamento_id: int|null, puesto_id: int|null, jefe_id: int|null, nivel_jerarquico: int|null, sueldo_mensual: string|null}, cambios: array<string, mixed>}>  $lote
     */
    private function aplicarEstructurales(array $lote, User $actor, ?string $motivo): void
    {
        foreach ($lote as $item) {
            $item['colaborador']->update($item['cambios']);
        }

        // El jefe sale del organigrama: se recalcula YA para que el
        // movimiento laboral registre el cambio de jefe.
        $this->jefes->sincronizar($actor);

        // El snapshot no cambió el sueldo: aquí solo salen los movimientos
        // estructurales (puesto/sucursal/depto/jefe).
        foreach ($lote as $item) {
            $this->movimientos->registrarCambioPuesto(
                $item['colaborador']->fresh() ?? $item['colaborador'],
                $item['antes'],
                $actor,
                $motivo,
            );
        }
    }
}
