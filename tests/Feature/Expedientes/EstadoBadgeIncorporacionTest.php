<?php

/*
| Sin esta entrada, EstadoBadge derivaba la etiqueta de la clave y mostraba
| «En incorporacion», sin acento, en el encabezado del expediente.
*/

test('EstadoBadge muestra «En incorporación» con acento', function () {
    expect(file_get_contents(resource_path('js/components/Common/EstadoBadge.vue')))
        ->toContain("en_incorporacion: { variante: 'info', etiqueta: 'En incorporación' }");
});
