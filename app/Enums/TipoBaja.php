<?php

namespace App\Enums;

/**
 * Tipo de baja que guarda el CIERRE laboral (y su solicitud): SOLO tres, uno
 * por forma de salida del módulo de bajas (App\Enums\FormaSalida):
 *
 *   renuncia     ↔ RV Renuncia voluntaria
 *   fin_contrato ↔ NR No renovación (el valor se conserva por
 *                  compatibilidad con la API móvil y el historial)
 *   rescision    ↔ RE Rescisión
 *
 * El desglose («por qué se va») es la causal (App\Models\CausalRotacion,
 * claves RV-01…RE-05 de config/bajas.php). Los valores anteriores (despido,
 * no_renovacion, baja_inmediata…) se migraron a tipo + causal y su valor
 * original quedó en tipo_baja_original.
 *
 * Documentos que el sistema GENERA por tipo/causal
 * (config/documentos_maestros.php documentos_por_causa / documentos_por_causal):
 *  - Renuncia: carta de renuncia.
 *  - No renovación: NR-02 evaluación (NO acredita) + aviso de terminación;
 *    NR-04 aviso de terminación; el resto se adjunta (convenio/aviso).
 *  - Rescisión: ninguno (el aviso de rescisión se ADJUNTA a la baja).
 */
enum TipoBaja: string
{
    case Renuncia = 'renuncia';
    case Rescision = 'rescision';
    case FinContrato = 'fin_contrato';

    /**
     * Etiquetas de los valores anteriores, para mostrar historial (timeline,
     * auditoría) guardado antes de la migración a tres tipos.
     */
    private const ETIQUETAS_LEGADAS = [
        'despido' => 'Despido',
        'mutuo_acuerdo' => 'Mutuo acuerdo',
        'abandono' => 'Abandono de empleo',
        'no_renovacion' => 'No renovación del periodo de prueba',
        'bajo_desempeno' => 'Bajo desempeño',
        'baja_inmediata' => 'Baja inmediata (rescisión)',
        'otro' => 'Otro',
    ];

    public function etiqueta(): string
    {
        return $this->forma()->etiqueta();
    }

    public function descripcion(): string
    {
        return match ($this) {
            self::Renuncia => 'La persona decide irse. El sistema genera la carta de renuncia para imprimir y firmar.',
            self::Rescision => 'La empresa termina la relación por una falta grave de la persona (art. 47 LFT). El aviso de rescisión se adjunta a la baja.',
            self::FinContrato => 'La empresa decide no continuar, sin que exista una falta grave (bajo desempeño, periodo de prueba, término de contrato, reestructura…).',
        };
    }

    public function forma(): FormaSalida
    {
        return FormaSalida::desdeTipoBaja($this);
    }

    /**
     * Baja voluntaria (renuncia) frente a involuntaria (no renovación /
     * rescisión), para indicadores de rotación.
     */
    public function esVoluntaria(): bool
    {
        return $this === self::Renuncia;
    }

    /**
     * El finiquito estima indemnización (si la política está habilitada):
     * nunca en renuncia; en rescisión y no renovación sí, salvo que la causal
     * la excluya (faltas injustificadas o abandono, RE-01).
     */
    public function contemplaIndemnizacion(): bool
    {
        return $this !== self::Renuncia;
    }

    /**
     * Etiqueta de un valor guardado, sea de los tres tipos vigentes o de una
     * causa anterior a la migración (historial).
     */
    public static function etiquetaDeValor(string $valor): string
    {
        return self::tryFrom($valor)?->etiqueta() ?? self::ETIQUETAS_LEGADAS[$valor] ?? $valor;
    }

    /**
     * Tipo vigente que corresponde a un valor guardado (vigente o anterior).
     */
    public static function desdeValor(string $valor): ?self
    {
        $tipo = self::tryFrom($valor);

        if ($tipo !== null) {
            return $tipo;
        }

        $legado = config('bajas.tipos_baja_anteriores.'.$valor);

        return is_string($legado) ? self::tryFrom($legado) : null;
    }
}
