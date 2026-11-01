<?php

/*
| Encabezado del expediente: máximo dos acciones secundarias a la vista; el resto
| vive en «Más acciones». Generar responsiva e Iniciar baja dejan de competir.
*/

test('Iniciar baja y Generar responsiva viven en Más acciones', function () {
    $detalle = file_get_contents(resource_path('js/components/Rh/ExpedienteDetalle.vue'));
    $pagina = file_get_contents(resource_path('js/pages/Rh/Expedientes/Show.vue'));

    expect($detalle)->toContain('<slot name="acciones-menu" />')
        ->and($pagina)->toContain('#acciones-menu')
        ->and($detalle)->not->toMatch('/<Button[^>]*>\s*<Link[^>]*solicitar_baja/');
});
