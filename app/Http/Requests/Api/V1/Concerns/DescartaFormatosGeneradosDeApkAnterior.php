<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Models\DocumentType;
use App\Services\Expedientes\RequisitoDocumentoGeneradoService;
use App\Services\Incorporacion\SolicitudEmpleo\SolicitudEmpleoRegistroService;

/**
 * Compatibilidad con la APK instalada (sin X-People-Solicitud-Empleo): su
 * paso de documentos no distingue los formatos que genera PEOPLE (Solicitud
 * de empleo, Estudio socioeconómico — RequisitoDocumentoGeneradoService) y
 * solo los deja pasar marcándolos «No tengo este documento». Esas marcas se
 * descartan: nunca son faltantes ni van en una carta compromiso, y
 * rechazarlas dejaba a la persona sin poder terminar su registro. A la app
 * que sí implementa la solicitud (manda la cabecera) no se le filtra nada:
 * el servicio sigue respondiendo 422 si llegara a mandarlas.
 */
trait DescartaFormatosGeneradosDeApkAnterior
{
    /**
     * @param  list<int>  $ids  document_type_id marcados «No tengo».
     * @return list<int>
     */
    protected function sinFormatosGeneradosDeApkAnterior(array $ids): array
    {
        if ($ids === [] || $this->header(SolicitudEmpleoRegistroService::CABECERA_CLIENTE) === '1') {
            return $ids;
        }

        $generables = array_keys(app(RequisitoDocumentoGeneradoService::class)->tiposGenerables());

        if ($generables === []) {
            return $ids;
        }

        $idsGenerables = DocumentType::query()->whereIn('clave', $generables)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        return array_values(array_diff($ids, $idsGenerables));
    }
}
