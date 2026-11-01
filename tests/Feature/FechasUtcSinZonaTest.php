<?php

/*
| `toDateTimeString()` manda UTC sin zona. formatearFechaHora lo tomaba por hora
| local: Bajas mostraba «9:20 p.m.» a las 3:20 p.m. en Ciudad de México.
*/

test('formatearFechaHora interpreta como UTC la fecha de Laravel sin zona', function () {
    $ts = file_get_contents(resource_path('js/lib/fechas.ts'));

    expect($ts)->toContain("`\${valor.replace(' ', 'T')}Z`");
});
