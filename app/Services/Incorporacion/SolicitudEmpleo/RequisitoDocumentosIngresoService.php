<?php

namespace App\Services\Incorporacion\SolicitudEmpleo;

use App\Enums\EstadoAltaColaborador;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\GeneratedDocument;
use App\Services\Expedientes\RequisitoDocumentoGeneradoService;

/**
 * Regla de negocio: la Solicitud de empleo y el Estudio socioeconómico deben
 * estar COMPLETOS y GENERADOS antes de generar el contrato de contratación
 * (paquete de alta). Punto único que consultan DocumentoProcesoService
 * (botones de la ficha y API) y ContratoLaboralService (generación
 * automática del paquete).
 *
 * No es retroactiva: solo aplica a personas en incorporación (pendiente de
 * documentos / en revisión / pendiente de contrato) cuyo contrato inicial
 * todavía no tiene ningún documento generado. Un alta ya contratada, una
 * renovación o un colaborador importado nunca se bloquean.
 */
class RequisitoDocumentosIngresoService
{
    public function __construct(private readonly PerfilIngresoService $perfil) {}

    /**
     * @return list<string>
     */
    public function claves(): array
    {
        return array_values(array_map('strval', (array) config('documentos_maestros.documentos_incorporacion', CatalogoSolicitudEmpleo::AMBOS)));
    }

    public function exigido(): bool
    {
        return (bool) config('incorporacion.documentos_ingreso.exigir_antes_de_contrato', true);
    }

    /**
     * ¿La regla aplica a este contrato?
     */
    public function aplicaAlContrato(ContratoLaboral $contrato): bool
    {
        if (! $this->exigido() || $contrato->contrato_anterior_id !== null) {
            return false;
        }

        $colaborador = Colaborador::query()->withTrashed()->where('id', $contrato->colaborador_id)->first();

        if ($colaborador === null || ! $this->enIncorporacion($colaborador)) {
            return false;
        }

        // Ya hay documentos del paquete (alta contratada antes de esta regla
        // o paquete en curso): nunca se bloquea retroactivamente.
        return ! GeneratedDocument::query()
            ->where('documentable_type', $contrato->getMorphClass())
            ->where('documentable_id', $contrato->id)
            ->exists();
    }

    public function enIncorporacion(Colaborador $colaborador): bool
    {
        return in_array($colaborador->estado_alta, [EstadoAltaColaborador::PendienteDocumentos, EstadoAltaColaborador::DocumentacionEnRevision, EstadoAltaColaborador::PendienteContrato], true);
    }

    /**
     * ¿Se muestra la sección «Solicitud de empleo y estudio socioeconómico»
     * en la ficha? Durante la contratación, o si ya se generó alguno.
     */
    public function aplicaSeccion(Colaborador $colaborador): bool
    {
        if ($colaborador->estado_alta?->enContratacion() ?? false) {
            return true;
        }

        return GeneratedDocument::query()
            ->where('documentable_type', $colaborador->getMorphClass())
            ->where('documentable_id', $colaborador->id)
            ->whereIn('clave_plantilla', $this->claves())
            ->exists();
    }

    /** Regla única de «generado vigente» (no cancelado): RequisitoDocumentoGeneradoService. */
    public function generado(Colaborador $colaborador, string $clave): ?GeneratedDocument
    {
        return app(RequisitoDocumentoGeneradoService::class)->generadoVigente($colaborador, $clave);
    }

    /**
     * Qué falta, en español y por documento («Solicitud de Empleo: faltan 2
     * datos (Escolaridad, Número de hijos)»).
     *
     * @return list<string>
     */
    public function pendientes(Colaborador $colaborador): array
    {
        $completitud = null;
        $pendientes = [];

        foreach ($this->claves() as $clave) {
            if ($this->generado($colaborador, $clave) !== null) {
                continue;
            }

            $completitud ??= $this->perfil->completitud($colaborador)['documentos'];
            $documento = $completitud[$clave] ?? null;
            $nombre = $documento['nombre'] ?? (CatalogoSolicitudEmpleo::DOCUMENTOS[$clave] ?? $clave);

            $pendientes[] = $documento !== null && ! $documento['completo']
                ? sprintf('%s: falta%s %d dato%s (%s)', $nombre, count($documento['faltantes']) === 1 ? '' : 'n', count($documento['faltantes']), count($documento['faltantes']) === 1 ? '' : 's', implode(', ', $documento['faltantes']))
                : sprintf('%s: datos completos, falta generarl%s', $nombre, (CatalogoSolicitudEmpleo::ARTICULOS[$clave] ?? 'la') === 'el' ? 'o' : 'a');
        }

        return $pendientes;
    }

    /**
     * Mensaje que bloquea el contrato, o null si puede generarse.
     */
    public function bloqueoContrato(ContratoLaboral $contrato): ?string
    {
        if (! $this->aplicaAlContrato($contrato)) {
            return null;
        }

        $colaborador = Colaborador::query()->withTrashed()->where('id', $contrato->colaborador_id)->firstOrFail();
        $pendientes = $this->pendientes($colaborador);

        if ($pendientes === []) {
            return null;
        }

        return sprintf('Antes del contrato se deben generar la Solicitud de Empleo y el Estudio Socioeconómico. Pendiente: %s.', implode('; ', $pendientes));
    }
}
