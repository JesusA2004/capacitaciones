<?php

/*
|--------------------------------------------------------------------------
| Inducción Mr. Lana
|--------------------------------------------------------------------------
|
| El documento OFICIAL de la inducción es el PDF entregado por el negocio:
| "Presentar inducción" (RH) y la inducción del colaborador muestran
| literalmente sus páginas con pdf.js — nunca una reconstrucción en HTML.
| Ruta relativa a la raíz del proyecto (viaja con el repositorio) o
| absoluta. Se sirve solo por endpoint autenticado (InduccionPdfService).
|
*/

return [
    'pdf_oficial' => env('INDUCCION_PDF_OFICIAL', 'docs/onboarding/Induccion_Mr_Lana_Rediseno (5).pdf'),

    'titulo' => 'Inducción Mr. Lana',
];
