<?php

use App\Enums\FamiliaAdministrativa;
use App\Services\DocumentosAdministrativos\DatosDocumentoAdministrativo;
use App\Services\DocumentosAdministrativos\DisenoAdministrativoService;
use App\Services\DocumentosAdministrativos\DocumentoAdministrativoService;

test('la vista previa lleva marca de agua y el definitivo no', function () {
    $servicio = app(DocumentoAdministrativoService::class);
    $familia = FamiliaAdministrativa::Finiquito;
    $datos = app(DatosDocumentoAdministrativo::class)->ejemplo($familia);
    $diseno = app(DisenoAdministrativoService::class)->porDefecto($familia);

    expect($servicio->html($familia, [...$datos, 'marca_agua' => DocumentoAdministrativoService::MARCA_VISTA_PREVIA], $diseno))->toContain('NO VÁLIDA PARA FIRMA')
        ->and($servicio->html($familia, $datos, $diseno))->not->toContain('NO VÁLIDA PARA FIRMA');
});
