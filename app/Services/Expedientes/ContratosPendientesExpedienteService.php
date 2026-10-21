<?php

namespace App\Services\Expedientes;

use App\Enums\EstadoContratoCorrespondiente;
use App\Models\Colaborador;
use App\Models\GeneratedDocument;
use App\Services\Contratos\RegularizacionContratosService;
use App\Services\DocumentosLaborales\FirmaDocumentalService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Parte de RH en la contratación: con los documentos del colaborador ya
 * completos, lo que falta son los contratos (contrato, confidencialidad, no
 * competencia), que genera RH y se firman en papel. El listado de
 * expedientes lo muestra como un aviso informativo, distinto de los
 * «documento(s) pendiente(s)» que sí debe subir la persona.
 *
 * Usa el MISMO plan que la tarjeta «Contratación» del expediente
 * (RegularizacionContratosService::plan): generados/total coinciden con su
 * «Por generar 0/3».
 */
class ContratosPendientesExpedienteService
{
    public function __construct(
        private readonly RegularizacionContratosService $contratos,
        private readonly FirmaDocumentalService $firmas,
    ) {}

    /**
     * null si la persona no está en contratación o ya no le falta nada de
     * los contratos.
     *
     * @return array{estado: 'por_generar'|'por_firmar', etiqueta: string, listos: int, total: int}|null
     */
    public function resumen(Colaborador $colaborador): ?array
    {
        if ($colaborador->estado_alta?->enContratacion() !== true) {
            return null;
        }

        try {
            // Instancia limpia: el listado cargó el puesto con columnas
            // parciales y el plan necesita meses_periodo_prueba / grupo_documental.
            $plan = $this->contratos->plan($colaborador->fresh() ?? $colaborador);
        } catch (Throwable $e) {
            Log::warning('ContratosPendientesExpedienteService: no se pudo calcular el plan de contratos.', ['colaborador_id' => $colaborador->id, 'error' => $e->getMessage()]);

            return ['estado' => 'por_generar', 'etiqueta' => 'Contratos pendientes de RH', 'listos' => 0, 'total' => 0];
        }

        if ($plan['estado'] === EstadoContratoCorrespondiente::NoAplica->value) {
            return null;
        }

        $documentos = [];

        foreach ($plan['contratos'] as $etapa) {
            foreach ($etapa['documentos'] as $documento) {
                $documentos[] = $documento;
            }
        }

        $total = count($documentos);
        $idsGenerados = [];

        foreach ($documentos as $documento) {
            if ($documento['estado'] === EstadoContratoCorrespondiente::Generado->value && $documento['documento_id'] !== null) {
                $idsGenerados[] = $documento['documento_id'];
            }
        }

        $generados = count($idsGenerados);

        if ($total === 0 || $generados < $total) {
            // Sin documentos calculables (p. ej. falta la fecha de ingreso):
            // sigue siendo trabajo de RH, sin conteo.
            $etiqueta = $total > 0 ? sprintf('Contratos por generar (%d/%d)', $generados, $total) : 'Contratos por generar';

            return ['estado' => 'por_generar', 'etiqueta' => $etiqueta, 'listos' => $generados, 'total' => $total];
        }

        $firmados = GeneratedDocument::query()
            ->whereIn('id', $idsGenerados)
            ->get()
            ->filter(fn (GeneratedDocument $documento): bool => $this->firmas->documentoConcluidoSegunFlujo($documento))
            ->count();

        if ($firmados < $total) {
            return ['estado' => 'por_firmar', 'etiqueta' => sprintf('Contratos por firmar (%d/%d)', $firmados, $total), 'listos' => $firmados, 'total' => $total];
        }

        return null;
    }
}
