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
 *
 * Los requisitos que PEOPLE GENERA (Solicitud de empleo, Estudio
 * socioeconómico: RequisitoDocumentoGeneradoService) NUNCA son pendientes
 * por subir: no bloquean ni cuentan para la carta compromiso. Se listan
 * aparte en generadosPorSistema() para presentarlos sin botón de subir.
 */
class DocumentosPendientesService
{
    /** Estados de la versión vigente que todavía no cuentan como «subido». */
    public const ESTADOS_FALTANTES = ['pendiente', 'rechazado', 'requiere_correccion', 'vencido', 'cambio_autorizado'];

    public function __construct(
        private readonly ExpedienteService $expediente,
        private readonly RequisitoDocumentoGeneradoService $generados,
    ) {}

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
     * Documentos exigibles que la persona todavía no sube (sin los que genera PEOPLE).
     *
     * @return list<array{document_type_id: int, clave: string, nombre: string, descripcion: string|null, estado: string, estado_etiqueta: string}>
     */
    public function pendientes(Colaborador $colaborador): array
    {
        $vigentes = $this->expediente->documentosVigentes($colaborador);
        $pendientes = [];

        foreach ($this->tiposExigibles() as $tipo) {
            if ($this->generados->esGenerable($tipo)) {
                continue;
            }

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

    /**
     * Requisitos exigibles que PEOPLE genera con los datos de la persona, con
     * su presentación («Lo prepara Recursos Humanos», «Generado con tus
     * datos»…). `$registroAbierto`: la persona sigue en su registro de la app
     * (el formato sale de su paso «Solicitud de empleo»).
     *
     * @return list<array{document_type_id: int, clave: string, nombre: string, estado: string, cumplido: bool, titulo: string, mensaje: string, captura: string}>
     */
    public function generadosPorSistema(Colaborador $colaborador, bool $registroAbierto = false): array
    {
        $tipos = $this->tiposExigibles()->filter(fn (DocumentType $tipo): bool => $this->generados->esGenerable($tipo));

        if ($tipos->isEmpty()) {
            return [];
        }

        $vigentes = $this->expediente->documentosVigentes($colaborador);
        $generados = $this->generados->generadosDe($colaborador);
        $lista = [];

        foreach ($tipos as $tipo) {
            $presentacion = $this->generados->presentacion($tipo, $vigentes->get($tipo->id), $generados, $registroAbierto);

            $lista[] = [
                'document_type_id' => $tipo->id,
                'clave' => $tipo->clave,
                'nombre' => $tipo->nombre,
                'estado' => $presentacion['estado'],
                'cumplido' => $presentacion['cumplido'],
                'titulo' => $presentacion['titulo'],
                'mensaje' => $presentacion['mensaje'],
                'captura' => $presentacion['captura'],
            ];
        }

        return $lista;
    }

    private function estadoDe(?EmployeeDocument $documento): EstadoDocumento
    {
        return $documento === null ? EstadoDocumento::Pendiente : $documento->status;
    }
}
