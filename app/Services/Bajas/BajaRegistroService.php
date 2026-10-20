<?php

namespace App\Services\Bajas;

use App\Enums\AccionBitacoraBaja;
use App\Enums\EntregaAvisoRescision;
use App\Enums\EstatusBaja;
use App\Enums\FormaSalida;
use App\Enums\OrigenBaja;
use App\Enums\Recontratable;
use App\Models\Baja;
use App\Models\BajaBitacora;
use App\Models\CierreLaboral;
use App\Models\Colaborador;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Piezas de bajo nivel del expediente de la baja, sin dependencias de otros
 * servicios del ciclo laboral (lo usan BajaService, CierreLaboralService y
 * la migración): folio BAJ-AAAA-NNNN, copia de los datos del colaborador,
 * antigüedad y bitácora (RN-13).
 */
class BajaRegistroService
{
    /**
     * Siguiente folio del año (BAJ-2026-0001). Quien llama debe estar dentro
     * de una transacción; el índice único de folio evita duplicados.
     */
    public function siguienteFolio(int $anio): string
    {
        $prefijo = sprintf('%s-%04d-', (string) config('bajas.folio_prefijo', 'BAJ'), $anio);
        $ultimo = Baja::query()->where('folio', 'like', $prefijo.'%')->lockForUpdate()->orderByDesc('folio')->value('folio');
        $numero = is_string($ultimo) ? ((int) Str::afterLast($ultimo, '-')) + 1 : 1;

        return sprintf('%s%04d', $prefijo, $numero);
    }

    /**
     * Baja nueva (sin guardar) con los datos del colaborador COPIADOS al
     * momento (nombre, número, sucursal, puesto, departamento, ingreso).
     *
     * @param  array<string, mixed>  $atributos
     */
    public function nueva(Colaborador $colaborador, array $atributos, ?User $actor, OrigenBaja $origen = OrigenBaja::Sistema): Baja
    {
        $baja = $this->preparar($colaborador, $atributos, $actor, $origen);
        $baja->folio = $this->siguienteFolio((int) now()->year);

        return $baja;
    }

    /**
     * Igual que nueva() pero SIN folio (no toca la base): para validar la
     * captura completa antes de abrir la transacción del registro.
     *
     * @param  array<string, mixed>  $atributos
     */
    public function preparar(Colaborador $colaborador, array $atributos, ?User $actor, OrigenBaja $origen = OrigenBaja::Sistema): Baja
    {
        $baja = new Baja([
            'colaborador_id' => $colaborador->id,
            'nombre_colaborador' => Str::limit($colaborador->nombreCompleto(), 200, ''),
            'numero_empleado' => $colaborador->numero_empleado,
            'sucursal_id' => $colaborador->sucursal_principal_id,
            'puesto_id' => $colaborador->puesto_id,
            'departamento_id' => $colaborador->departamento_id,
            'fecha_ingreso' => $colaborador->fecha_ingreso?->toDateString(),
            'origen' => $origen,
            'estatus' => EstatusBaja::EnRevisionRh,
            'es_rotacion' => true,
            'creado_por' => $actor?->id,
            ...$atributos,
        ]);
        $this->recalcularAntiguedad($baja);

        return $baja;
    }

    public function recalcularAntiguedad(Baja $baja): void
    {
        $baja->antiguedad_dias = $baja->fecha_ingreso !== null && $baja->fecha_baja !== null
            ? (int) $baja->fecha_ingreso->diffInDays($baja->fecha_baja, false)
            : null;
    }

    /**
     * RN-09: en rescisión el valor por defecto es «No».
     */
    public function recontratablePorDefecto(?FormaSalida $forma): ?Recontratable
    {
        return $forma === FormaSalida::Rescision ? Recontratable::No : null;
    }

    public function bitacora(Baja $baja, ?User $usuario, AccionBitacoraBaja $accion, ?string $campo = null, ?string $anterior = null, ?string $nuevo = null, ?string $motivo = null): BajaBitacora
    {
        return BajaBitacora::query()->create([
            'baja_id' => $baja->id,
            'user_id' => $usuario?->id,
            'accion' => $accion,
            'campo' => $campo,
            'valor_anterior' => $anterior,
            'valor_nuevo' => $nuevo,
            'motivo' => $motivo,
        ]);
    }

    /**
     * RN-11: solo una baja abierta por colaborador.
     */
    public function exigirSinBajaAbierta(Colaborador $colaborador, ?int $excepto = null): void
    {
        $abierta = Baja::query()
            ->where('colaborador_id', $colaborador->id)
            ->whereIn('estatus', EstatusBaja::valoresAbiertos())
            ->when($excepto !== null, fn ($q) => $q->where('id', '!=', $excepto))
            ->first(['id', 'folio', 'estatus']);

        if ($abierta !== null) {
            throw ValidationException::withMessages(['colaborador_id' => sprintf('%s ya tiene una baja abierta (%s, %s). Solo puede haber una a la vez.', $colaborador->nombreCompleto(), $abierta->folio, $abierta->estatus->etiqueta())]);
        }
    }

    /**
     * Liga el cierre laboral recién aplicado con su expediente de baja: la
     * baja registrada en el módulo de Bajas (si viene) o una nueva «En
     * trámite» (o «En revisión Jurídico» si la causal lo requiere) para el
     * atajo de la API móvil y la no renovación desde la evaluación. Corre
     * dentro de la transacción del cierre.
     *
     * @param  array<string, mixed>  $datos  recontratable, comentarios/motivo, fecha_conocimiento_falta, entrega_aviso_rescision, fecha_entrega_aviso.
     */
    public function ligarCierre(CierreLaboral $cierre, User $actor, ?Baja $baja, array $datos, string $aplicadaPor): Baja
    {
        if ($baja !== null) {
            $baja->update([
                'cierre_laboral_id' => $cierre->id,
                'acceso_suspendido_en' => $cierre->acceso_suspendido_en ?? $baja->acceso_suspendido_en,
            ]);

            return $baja;
        }

        $existente = Baja::query()->where('cierre_laboral_id', $cierre->id)->first();

        if ($existente !== null) {
            return $existente;
        }

        $colaborador = $cierre->colaborador;
        $forma = FormaSalida::desdeTipoBaja($cierre->tipo_baja);
        $esRescision = $forma === FormaSalida::Rescision;
        $texto = trim((string) ($datos['comentarios'] ?? $datos['motivo'] ?? ''));
        $fechaOpcional = fn (string $campo): ?string => is_string($datos[$campo] ?? null) && $datos[$campo] !== '' ? $datos[$campo] : null;

        $baja = $this->nueva($colaborador, [
            'cierre_laboral_id' => $cierre->id,
            'fecha_baja' => $cierre->fecha_efectiva->toDateString(),
            'forma_salida' => $forma,
            'causal_rotacion_id' => $cierre->causal_rotacion_id,
            'comentarios' => $texto !== '' ? Str::limit($texto, (int) config('bajas.reglas.comentario_maximo', 500), '') : null,
            'recontratable' => Recontratable::tryFrom((string) ($datos['recontratable'] ?? '')) ?? $this->recontratablePorDefecto($forma),
            'fecha_conocimiento_falta' => $esRescision ? $fechaOpcional('fecha_conocimiento_falta') : null,
            'entrega_aviso_rescision' => $esRescision ? EntregaAvisoRescision::tryFrom((string) ($datos['entrega_aviso_rescision'] ?? '')) : null,
            'fecha_entrega_aviso' => $esRescision ? $fechaOpcional('fecha_entrega_aviso') : null,
            'estatus' => $cierre->causalRotacion?->requiere_juridico === true ? EstatusBaja::EnRevisionJuridico : EstatusBaja::EnRevisionRh,
            'solicitado_por' => $actor->id,
            'enviado_en' => now(),
            'acceso_suspendido_en' => $cierre->acceso_suspendido_en,
        ], $actor);
        $baja->save();

        $this->bitacora($baja, $actor, AccionBitacoraBaja::Registro, 'estatus', null, $baja->estatus->etiqueta(), sprintf('Baja aplicada por %s (cierre #%d).', $aplicadaPor, $cierre->id));

        return $baja;
    }

    /**
     * El cierre laboral se canceló (la baja no procede): su expediente de
     * baja abierto queda «No procede».
     */
    public function alCancelarCierre(CierreLaboral $cierre, User $actor, string $motivo): void
    {
        $baja = Baja::query()->where('cierre_laboral_id', $cierre->id)->whereIn('estatus', EstatusBaja::valoresAbiertos())->first();

        if ($baja === null) {
            return;
        }

        $anterior = $baja->estatus->etiqueta();
        $baja->update(['estatus' => EstatusBaja::Cancelada, 'cancelado_por' => $actor->id, 'cancelado_en' => now(), 'motivo_cancelacion' => $motivo]);
        $this->bitacora($baja, $actor, AccionBitacoraBaja::Cancelo, 'estatus', $anterior, EstatusBaja::Cancelada->etiqueta(), $motivo);
    }

    public static function fecha(?CarbonInterface $fecha): ?string
    {
        return $fecha?->toDateString();
    }
}
