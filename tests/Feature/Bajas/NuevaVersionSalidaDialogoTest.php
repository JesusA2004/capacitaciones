<?php

/*
| «Nueva versión» del documento de salida abría un campo en la tarjeta, sin
| cancelar y ocultando Descargar/Subir firmado: al pulsarlo parecía no pasar
| nada. Debe abrir un diálogo con motivo mínimo.
*/

test('Nueva versión del documento de salida abre un diálogo con motivo obligatorio', function () {
    $vue = file_get_contents(resource_path('js/components/bajas/GeneradorDocumentoSalida.vue'));

    expect($vue)->toContain(':open="pidiendoVersion"')
        ->and($vue)->toContain('motivo.trim().length < MINIMO_MOTIVO')
        ->and($vue)->not->toContain('<div v-if="pidiendoVersion"');
});
