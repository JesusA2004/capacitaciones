<?php

use App\Models\HeadcountTarget;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Services\Headcount\PuestosPlantillaService;
use Illuminate\Support\Facades\DB;

/*
| El Tablero consultaba una vez por eslabón de la jerarquía y otra por puesto
| (404 consultas con el demo). El costo no debe crecer con el catálogo.
*/

test('resolver la plaza de muchos puestos no consulta por puesto', function () {
    $sucursal = Sucursal::factory()->create();
    $puestos = Puesto::factory()->count(8)->create();
    $puestos->each(fn (Puesto $p) => HeadcountTarget::factory()->create([
        'puesto_id' => $p->id, 'sucursal_id' => $sucursal->id, 'plantilla_autorizada' => 1,
    ]));
    $servicio = app(PuestosPlantillaService::class);

    DB::enableQueryLog();
    $puestos->each(fn (Puesto $p) => expect($servicio->sucursalDePlaza($p->id, 999))->toBe($sucursal->id));

    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(2);
});
