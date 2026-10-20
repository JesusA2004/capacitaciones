<?php

namespace App\Services\Bajas;

use App\Enums\EntregaAvisoRescision;
use App\Enums\FormaSalida;
use App\Models\Baja;
use App\Support\FechaMexico;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * Reglas de negocio de la captura de una baja (especificación de RH):
 *
 *  RN-01 forma y causal obligatorias, lista cerrada.
 *  RN-02 la causal debe ser de la forma elegida.
 *  RN-04 comentario obligatorio (mínimo 20) en RV-05 y en todas las RE.
 *  RN-07 en RE: fecha en que se conoció la falta, forma y fecha de entrega
 *        del aviso; > 30 días entre conocimiento y baja → advertencia de
 *        prescripción (art. 517 LFT).
 *  RN-08 aviso ante el Tribunal: entrega ≤ 5 días hábiles después de la
 *        baja; si no, se avisa a Jurídico.
 *  RN-10 fecha de baja ≥ fecha de ingreso y ≤ 15 días en el futuro.
 *
 * Las mismas reglas aplican al enviar (gerente) y al validar/cerrar (RH),
 * porque el atajo del ciclo laboral y la API móvil pueden crear la baja con
 * datos pendientes.
 */
class BajaReglasService
{
    /**
     * Errores que impiden enviar / validar / cerrar (campo => mensaje).
     *
     * @return array<string, string>
     */
    public function errores(Baja $baja): array
    {
        $errores = [];
        $forma = $baja->forma_salida;
        $causal = $baja->causal;

        if ($forma === null) {
            $errores['forma_salida'] = 'Elige cómo se va la persona: renuncia voluntaria, no renovación o rescisión.';
        }

        if ($causal === null) {
            $errores['causal_rotacion_id'] = 'Elige por qué se va (la causal).';
        } elseif ($forma !== null && $causal->forma() !== $forma) {
            $errores['causal_rotacion_id'] = sprintf('«%s» no es una causal de %s.', $causal->nombre, mb_strtolower($forma->etiqueta()));
        } elseif ($causal->legado || ! $causal->activo) {
            $errores['causal_rotacion_id'] = sprintf('La causal «%s» ya no está activa: elige otra.', $causal->nombre);
        }

        $errorFecha = $this->errorFechaBaja($baja);

        if ($errorFecha !== null) {
            $errores['fecha_baja'] = $errorFecha;
        }

        if ($baja->recontratable === null) {
            $errores['recontratable'] = 'Indica si la persona es recontratable (Sí, No o Con reservas).';
        }

        if ($this->comentarioObligatorio($baja) && mb_strlen(trim((string) $baja->comentarios)) < $this->comentarioMinimo()) {
            $errores['comentarios'] = sprintf('El comentario es obligatorio en esta causal: describe lo que pasó (mínimo %d caracteres).', $this->comentarioMinimo());
        }

        if ($forma === FormaSalida::Rescision) {
            if ($baja->fecha_conocimiento_falta === null) {
                $errores['fecha_conocimiento_falta'] = 'Indica la fecha en que se conoció la falta.';
            }

            if ($baja->entrega_aviso_rescision === null) {
                $errores['entrega_aviso_rescision'] = 'Indica cómo se entregó el aviso de rescisión (personal o ante el Tribunal).';
            }

            if ($baja->fecha_entrega_aviso === null) {
                $errores['fecha_entrega_aviso'] = 'Indica la fecha de entrega del aviso de rescisión.';
            }
        }

        return $errores;
    }

    /**
     * @throws ValidationException
     */
    public function exigirCompleta(Baja $baja): void
    {
        $errores = $this->errores($baja);

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }
    }

    /**
     * RN-10 (también al guardar el borrador, para avisar de inmediato).
     */
    public function errorFechaBaja(Baja $baja): ?string
    {
        if ($baja->fecha_baja === null) {
            return 'Indica el último día laborado.';
        }

        if ($baja->fecha_ingreso !== null && $baja->fecha_baja->lt($baja->fecha_ingreso)) {
            return sprintf('El último día laborado no puede ser anterior a la fecha de ingreso (%s).', $baja->fecha_ingreso->format('d/m/Y'));
        }

        $limite = FechaMexico::inicioDeHoy()->addDays((int) config('bajas.reglas.dias_futuro_maximo', 15));

        if ($baja->fecha_baja->gt($limite)) {
            return sprintf('El último día laborado no puede ser mayor a %d días en el futuro (hasta el %s).', (int) config('bajas.reglas.dias_futuro_maximo', 15), $limite->format('d/m/Y'));
        }

        return null;
    }

    public function comentarioObligatorio(Baja $baja): bool
    {
        return $baja->forma_salida === FormaSalida::Rescision || ($baja->causal !== null && $baja->causal->comentario_obligatorio);
    }

    public function comentarioMinimo(): int
    {
        return (int) config('bajas.reglas.comentario_minimo', 20);
    }

    /**
     * RN-07: puede haber prescrito el derecho a rescindir.
     */
    public function posiblePrescripcion(Baja $baja): bool
    {
        return $baja->forma_salida === FormaSalida::Rescision
            && $baja->fecha_conocimiento_falta !== null
            && $baja->fecha_baja !== null
            && $baja->fecha_conocimiento_falta->diffInDays($baja->fecha_baja, false) > (int) config('bajas.reglas.dias_prescripcion_rescision', 30);
    }

    /**
     * RN-08: aviso ante el Tribunal entregado después de 5 días hábiles.
     */
    public function avisoTribunalFueraDePlazo(Baja $baja): bool
    {
        if ($baja->forma_salida !== FormaSalida::Rescision || $baja->entrega_aviso_rescision !== EntregaAvisoRescision::AnteTribunal || $baja->fecha_baja === null || $baja->fecha_entrega_aviso === null) {
            return false;
        }

        return $baja->fecha_entrega_aviso->gt($this->limiteAvisoTribunal($baja->fecha_baja));
    }

    public function limiteAvisoTribunal(CarbonInterface $fechaBaja): CarbonInterface
    {
        return $fechaBaja->copy()->startOfDay()->addWeekdays((int) config('bajas.reglas.dias_habiles_aviso_tribunal', 5));
    }

    public function rotacionTemprana(Baja $baja): bool
    {
        return $baja->antiguedad_dias !== null && $baja->antiguedad_dias < (int) config('bajas.reglas.dias_rotacion_temprana', 90);
    }

    /**
     * Advertencias que no impiden avanzar (se muestran en la pantalla y en el
     * expediente).
     *
     * @return list<array{clave: string, mensaje: string}>
     */
    public function advertencias(Baja $baja): array
    {
        $avisos = [];

        if ($this->posiblePrescripcion($baja)) {
            $avisos[] = ['clave' => 'prescripcion', 'mensaje' => 'Puede haber prescrito el derecho a rescindir (art. 517 LFT). Consulte a Jurídico.'];
        }

        if ($this->avisoTribunalFueraDePlazo($baja) && $baja->fecha_baja !== null) {
            $avisos[] = ['clave' => 'tribunal', 'mensaje' => sprintf('El aviso ante el Tribunal se entregó después de %d días hábiles de la baja (límite %s). Se avisa a Jurídico.', (int) config('bajas.reglas.dias_habiles_aviso_tribunal', 5), $this->limiteAvisoTribunal($baja->fecha_baja)->format('d/m/Y'))];
        }

        if ($this->rotacionTemprana($baja)) {
            $avisos[] = ['clave' => 'rotacion_temprana', 'mensaje' => sprintf('Rotación temprana: la persona tenía %d días de antigüedad (menos de %d).', (int) $baja->antiguedad_dias, (int) config('bajas.reglas.dias_rotacion_temprana', 90))];
        }

        if ($baja->causal !== null && $baja->causal->alerta !== null) {
            $avisos[] = ['clave' => 'alerta_causal', 'mensaje' => $baja->causal->alerta];
        }

        if ($baja->causal_migrada_de !== null) {
            $avisos[] = ['clave' => 'causal_migrada', 'mensaje' => sprintf('Causal migrada del catálogo anterior («%s»): RH debe revisarla.', $baja->causal_migrada_de)];
        }

        return $avisos;
    }
}
