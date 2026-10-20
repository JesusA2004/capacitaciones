<?php

namespace App\Http\Requests\CicloLaboral;

use App\Enums\TipoBaja;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Vista previa (GET, sin guardar nada) de un documento del proceso o de un
 * flujo que genera sus documentos al confirmar. La autorización real
 * (alcance + permiso de generar la clave) vive en DocumentoProcesoService.
 *
 * - clave: el documento a ver (obligatoria salvo en el listado del flujo).
 * - completar / manuales: lo capturado en el modal de datos faltantes; se
 *   aplica SOLO en memoria.
 * - tipo_baja, fecha_efectiva, motivo, monto_autorizado…: datos del
 *   formulario del flujo (baja, no renovación, préstamo) antes de confirmar;
 *   en el préstamo también lo que RH captura a mano del contrato de crédito
 *   (cargo_sin_iva, iva, monto_total_con_iva, numero_cheque, fechas de pago).
 */
class VistaPreviaDocumentoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'clave' => [$this->routeIs('*.previa.documentos') ? 'nullable' : 'required', 'string', 'max:80', 'regex:/^[a-z0-9_]+$/'],
            'proceso' => ['nullable', 'string', 'in:alta,renovacion,evaluacion,baja,negativa_firma,permiso,prestamo,activos,acta,expediente'],
            'completar' => ['nullable', 'array', 'max:30'],
            'completar.*' => ['nullable', 'string', 'max:191'],
            'manuales' => ['nullable', 'array', 'max:20'],
            'manuales.*' => ['nullable', 'string', 'max:191'],
            'tipo_baja' => ['nullable', Rule::enum(TipoBaja::class)],
            'causal_rotacion_id' => ['nullable', 'integer'],
            'fecha_efectiva' => ['nullable', 'date'],
            'motivo' => ['nullable', 'string', 'max:1000'],
            'monto_autorizado' => ['nullable', 'numeric', 'min:1', 'max:9999999'],
            'plazo_autorizado' => ['nullable', 'integer', 'min:1', 'max:520'],
            'periodicidad' => ['nullable', Rule::in(['semanal', 'quincenal', 'mensual'])],
            'pago_programado' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            // Préstamo: datos del contrato de crédito capturados a mano por RH.
            'cargo_sin_iva' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'iva' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'monto_total_con_iva' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'numero_cheque' => ['nullable', 'string', 'max:40'],
            'fecha_primer_descuento' => ['nullable', 'date'],
            'fecha_ultimo_pago' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function completar(): array
    {
        return array_filter((array) $this->validated('completar', []), fn (mixed $v): bool => $v !== null && $v !== '');
    }

    /**
     * @return array<string, string>
     */
    public function manuales(): array
    {
        return array_map('strval', array_filter((array) $this->validated('manuales', []), fn (mixed $v): bool => $v !== null && $v !== ''));
    }

    /**
     * Datos del formulario del flujo (solo los capturados).
     *
     * @return array<string, mixed>
     */
    public function datosFlujo(): array
    {
        return array_filter(
            $this->safe()->only(['tipo_baja', 'causal_rotacion_id', 'fecha_efectiva', 'motivo', 'monto_autorizado', 'plazo_autorizado', 'periodicidad', 'pago_programado', 'cargo_sin_iva', 'iva', 'monto_total_con_iva', 'numero_cheque', 'fecha_primer_descuento', 'fecha_ultimo_pago']),
            fn (mixed $v): bool => $v !== null && $v !== '',
        );
    }
}
