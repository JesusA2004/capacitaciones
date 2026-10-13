<?php

use App\Models\DocumentTemplate;
use App\Services\Plantillas\PlantillaDocumentoService;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\PhpWord;

/*
| La detección de {{variables}} de una plantilla se cachea un día, pero un
| fallo de lectura NUNCA se cachea: si no, una variable requerida dejaría de
| bloquear la generación durante todo ese día por un error transitorio.
*/

test('un fallo al leer el DOCX no se cachea: la siguiente lectura sí detecta las variables', function () {
    Storage::fake('nas');
    $ruta = 'plantillas/'.uniqid('cache', true).'.docx';
    $plantilla = DocumentTemplate::factory()->create(['path' => $ruta]);
    $servicio = app(PlantillaDocumentoService::class);

    // El archivo todavía no está (NAS lento, archivo bloqueado…).
    expect($servicio->variablesEnPlantilla($plantilla))->toBe([]);

    $phpWord = new PhpWord;
    $phpWord->addSection()->addText('Hola {{nombre_completo}}, folio: {{folio_obra}}.');
    $temporal = sys_get_temp_dir().'/'.uniqid('plantilla', true).'.docx';
    $phpWord->save($temporal, 'Word2007');
    Storage::disk('nas')->put($ruta, (string) file_get_contents($temporal));
    unlink($temporal);

    expect($servicio->variablesEnPlantilla($plantilla))->toBe(['nombre_completo', 'folio_obra']);
});
