<?php

namespace App\Services\Expedientes;

use App\Enums\EstadoDocumento;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use Illuminate\Support\Collection;

/**
 * ÚNICA regla de avance del expediente (app del colaborador, detalle RH,
 * incorporación y alta). La app solo pinta lo que devuelve esto.
 *
 * - Denominador: documentos OBLIGATORIOS activos.
 * - Numerador: obligatorios cuya versión vigente está APROBADA. Nada más
 *   cuenta como completo: ni "en revisión", ni "cargado", ni un cambio
 *   solicitado/autorizado, ni rechazado/vencido/requiere corrección.
 * - porcentaje = floor(completos / total * 100): 10 de 11 = 90 (nunca se
 *   redondea hacia arriba) y solo es 100 cuando TODOS están completos.
 * - Sin obligatorios configurados: 0 %, `completo = true`,
 *   `sin_obligatorios = true` (la app muestra "Sin documentos
 *   obligatorios", no un 100 decorativo).
 */
final class ProgresoExpediente
{
    /** Esperando a RH. */
    private const EN_REVISION = [EstadoDocumento::Cargado, EstadoDocumento::EnRevision, EstadoDocumento::CambioSolicitado];

    /** Requieren que el colaborador corrija. */
    private const REQUIEREN_CORRECCION = [EstadoDocumento::Rechazado, EstadoDocumento::RequiereCorreccion, EstadoDocumento::Vencido];

    /**
     * @param  Collection<int, DocumentType>  $tipos  Tipos a considerar (se filtran los obligatorios).
     * @param  Collection<int, EmployeeDocument>  $vigentes  Documento vigente por document_type_id.
     * @return array{total_obligatorios: int, completos: int, faltantes: int, en_revision: int, rechazados: int, pendientes: int, porcentaje: int, completo: bool, sin_obligatorios: bool}
     */
    public static function calcular(Collection $tipos, Collection $vigentes): array
    {
        $requeridos = $tipos->filter(fn (DocumentType $tipo) => (bool) $tipo->requerido);

        $completos = 0;
        $faltantes = 0;
        $enRevision = 0;
        $rechazados = 0;

        foreach ($requeridos as $tipo) {
            $estado = self::estadoDe($vigentes->get($tipo->id));

            if ($estado === EstadoDocumento::Aprobado) {
                $completos++;
            } elseif (in_array($estado, self::EN_REVISION, true)) {
                $enRevision++;
            } elseif (in_array($estado, self::REQUIEREN_CORRECCION, true)) {
                $rechazados++;
            } else {
                // Pendiente, cambio autorizado o cualquier estado desconocido: falta.
                $faltantes++;
            }
        }

        $total = $requeridos->count();

        return [
            'total_obligatorios' => $total,
            'completos' => $completos,
            'faltantes' => $faltantes,
            'en_revision' => $enRevision,
            'rechazados' => $rechazados,
            // Todo lo que todavía no está aprobado.
            'pendientes' => $total - $completos,
            'porcentaje' => self::porcentaje($completos, $total),
            'completo' => $completos === $total,
            'sin_obligatorios' => $total === 0,
        ];
    }

    /** floor(completos / total * 100), acotado a 0..100; 0 si no hay total. */
    public static function porcentaje(int $completos, int $total): int
    {
        if ($total <= 0) {
            return 0;
        }

        return (int) max(0, min(100, intdiv(max(0, $completos) * 100, $total)));
    }

    public static function estadoDe(?EmployeeDocument $documento): EstadoDocumento
    {
        return $documento === null ? EstadoDocumento::Pendiente : $documento->status;
    }
}
