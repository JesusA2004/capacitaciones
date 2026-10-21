<?php

namespace App\Services\Expedientes;

use App\Enums\EstadoDocumento;
use App\Models\Colaborador;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use Illuminate\Support\Collection;

/**
 * REGLA ÚNICA de «documentos que el colaborador debe subir él mismo»: los
 * tipos requeridos, activos y que aplican al alta (document_types.aplica_alta).
 * Nunca incluye los que genera RH (contrato, confidencialidad, no
 * competencia). La usan el bloqueo de la app por documentos faltantes, la
 * carta compromiso y el payload de /me.
 *
 * Un documento sigue «pendiente» si no hay versión vigente o si la vigente
 * está en ESTADOS_FALTANTES (subido o en revisión ya cuenta; no hace falta
 * la aprobación de RH). Misma semántica que el registro en la app
 * (RegistroAppService).
 */
class DocumentosPendientesService
{
    /** Estados de la versión vigente que todavía no cuentan como «subido». */
    public const ESTADOS_FALTANTES = ['pendiente', 'rechazado', 'requiere_correccion', 'vencido', 'cambio_autorizado'];

    public function __construct(private readonly ExpedienteService $expediente) {}

    /**
     * @return Collection<int, DocumentType>
     */
    public function tiposExigibles(): Collection
    {
        return DocumentType::query()
            ->where('activo', true)
            ->where('requerido', true)
            ->where('aplica_alta', true)
            ->orderBy('orden')
            ->orderBy('id')
            ->get();
    }

    /**
     * Documentos exigibles que la persona todavía no sube.
     *
     * @return list<array{document_type_id: int, clave: string, nombre: string, descripcion: string|null, estado: string, estado_etiqueta: string}>
     */
    public function pendientes(Colaborador $colaborador): array
    {
        $vigentes = $this->expediente->documentosVigentes($colaborador);
        $pendientes = [];

        foreach ($this->tiposExigibles() as $tipo) {
            $estado = $this->estadoDe($vigentes->get($tipo->id));

            if (! in_array($estado->value, self::ESTADOS_FALTANTES, true)) {
                continue;
            }

            $pendientes[] = [
                'document_type_id' => $tipo->id,
                'clave' => $tipo->clave,
                'nombre' => $tipo->nombre,
                'descripcion' => $tipo->descripcion,
                'estado' => $estado->value,
                'estado_etiqueta' => $estado->etiqueta(),
            ];
        }

        return $pendientes;
    }

    /**
     * @return list<int>
     */
    public function idsPendientes(Colaborador $colaborador): array
    {
        return array_column($this->pendientes($colaborador), 'document_type_id');
    }

    private function estadoDe(?EmployeeDocument $documento): EstadoDocumento
    {
        return $documento === null ? EstadoDocumento::Pendiente : $documento->status;
    }
}
