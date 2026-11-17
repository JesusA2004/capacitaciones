<?php

use App\Services\Solicitudes\SolicitudesService;

/*
| Texto único (web y API) sobre el documento oficial generado al aprobar una
| solicitud: SolicitudesService::avisoDocumentoOficial().
*/

function aoServicioCon(?array $resultado): SolicitudesService
{
    $servicio = app(SolicitudesService::class);
    (new ReflectionProperty($servicio, 'ultimoResultadoDocumentoOficial'))->setValue($servicio, $resultado);

    return $servicio;
}

test('sin documento oficial aplicable no hay aviso; con él dice si se generó o por qué no', function () {
    expect(aoServicioCon(null)->avisoDocumentoOficial())->toBeNull()
        ->and(aoServicioCon(['generacion' => null, 'aplica' => false, 'motivo_error' => null])->avisoDocumentoOficial())->toBeNull()
        ->and(aoServicioCon(['generacion' => null, 'aplica' => true, 'motivo_error' => null])->avisoDocumentoOficial())
        ->toBe(['error' => false, 'texto' => 'Documento oficial generado correctamente.'])
        ->and(aoServicioCon(['generacion' => null, 'aplica' => true, 'motivo_error' => 'falta la plantilla'])->avisoDocumentoOficial())
        ->toBe(['error' => true, 'texto' => 'Documento oficial NO generado: falta la plantilla.']);
});
