<?php

namespace App\Enums;

/**
 * Tipo de una baja de colaborador: SOLO tres. El desglose («motivo») es la
 * causal de rotación (App\Models\CausalRotacion), un subcatálogo por tipo
 * que RH administra desde Bajas y finiquitos.
 *
 * Los valores anteriores (despido, no_renovacion, baja_inmediata…) se
 * migraron a tipo + causal (config/causales_rotacion.php `legado`) y su
 * valor original quedó en tipo_baja_original.
 *
 * Documentos por tipo (config/documentos_maestros.php documentos_por_causa):
 *  - Renuncia: carta de renuncia generada por el sistema.
 *  - Rescisión: ninguno (se aplica la baja y sigue el finiquito).
 *  - Fin de contrato: formato de evaluación (NO acredita) + aviso de
 *    terminación / no renovación.
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
        return match ($this) {
            self::Renuncia => 'Renuncia',
            self::Rescision => 'Rescisión',
            self::FinContrato => 'Fin de contrato',
        };
    }

    public function descripcion(): string
    {
        return match ($this) {
            self::Renuncia => 'La persona deja el empleo por decisión propia. El sistema genera la carta de renuncia para imprimir y firmar.',
            self::Rescision => 'La empresa termina la relación por una causal (art. 47 LFT, desempeño…). No se genera documento: se aplica la baja y sigue el finiquito.',
            self::FinContrato => 'Termina el contrato o no se acredita el periodo de prueba. Se generan el formato de evaluación y el aviso de no renovación.',
        };
    }

    /**
     * Baja voluntaria (renuncia) frente a involuntaria (rescisión / fin de
     * contrato), para indicadores de rotación.
     */
    public function esVoluntaria(): bool
    {
        return $this === self::Renuncia;
    }

    /**
     * El finiquito estima indemnización (si la política está habilitada):
     * nunca en renuncia; en rescisión y fin de contrato sí, salvo que la
     * causal la excluya (abandono de trabajo).
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

        $legado = config('causales_rotacion.legado.'.$valor);

        return is_array($legado) && is_string($legado['tipo'] ?? null) ? self::tryFrom($legado['tipo']) : null;
    }
}
