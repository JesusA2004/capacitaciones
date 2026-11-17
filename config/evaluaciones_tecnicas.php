<?php

/*
|--------------------------------------------------------------------------
| Evaluaciones técnicas por puesto («Seguimiento N»)
|--------------------------------------------------------------------------
|
| docs/EVALUACIONES_TECNICAS.md. El calendario (días, formulario, quién
| contesta, mínima) se configura por puesto en la web; aquí solo las reglas
| del programador automático (`evaluaciones-tecnicas:programar`).
*/

return [

    // Ventana hacia atrás: el programador genera una evaluación cuyo día ya
    // pasó solo si pasó hace a lo más estos días. Así, al configurar un
    // puesto por primera vez, a quien entró hace años no le llueven
    // evaluaciones viejas (sí a quien está a mitad de su calendario).
    'dias_gracia_generacion' => (int) env('EVALUACIONES_TECNICAS_DIAS_GRACIA', 15),

    // «Próximas a vencer» en el tablero: pendientes cuya fecha límite cae
    // dentro de estos días.
    'dias_alerta_vencimiento' => (int) env('EVALUACIONES_TECNICAS_DIAS_ALERTA', 3),

];
