<?php

use App\Models\Puesto;
use Database\Seeders\DepartamentoSeeder;
use Database\Seeders\PuestoJerarquiaSeeder;
use Database\Seeders\PuestoSeeder;

test('el seeder de jerarquía arma un solo árbol con Dirección General como raíz', function () {
    $this->seed(DepartamentoSeeder::class);
    $this->seed(PuestoSeeder::class);
    $this->seed(PuestoJerarquiaSeeder::class);

    $direccionGeneral = Puesto::where('nombre', 'Dirección General')->firstOrFail();

    expect($direccionGeneral->puesto_superior_id)->toBeNull()
        ->and($direccionGeneral->nivel_jerarquico)->toBe(1);

    $ramas = ['Director comercial', 'Gerente de Recursos Humanos', 'Gerente de Contabilidad', 'Gerente de Sistemas', 'Responsable administrativo/regional'];

    foreach ($ramas as $nombre) {
        $puesto = Puesto::where('nombre', $nombre)->firstOrFail();

        expect($puesto->puesto_superior_id)
            ->toBe($direccionGeneral->id, "{$nombre} debería reportar a Dirección General");
    }

    // La rama comercial completa sigue conectada por debajo de Director comercial.
    $directorComercial = Puesto::where('nombre', 'Director comercial')->firstOrFail();
    $gestorVolante = Puesto::where('nombre', 'Gestor volante')->firstOrFail();

    $actual = $gestorVolante;
    $cadena = [];

    while ($actual !== null) {
        $cadena[] = $actual->nombre;
        $actual = $actual->puesto_superior_id ? Puesto::find($actual->puesto_superior_id) : null;
    }

    expect($cadena)->toContain('Gestor volante', 'Gestor fijo', 'Subgerente', 'Gerente', 'Gerente regional', 'Director comercial', 'Dirección General')
        ->and($directorComercial->puesto_superior_id)->toBe($direccionGeneral->id);
});

test('correr el seeder dos veces no duplica puestos ni rompe la jerarquía', function () {
    $this->seed(DepartamentoSeeder::class);
    $this->seed(PuestoSeeder::class);
    $this->seed(PuestoJerarquiaSeeder::class);
    $this->seed(PuestoJerarquiaSeeder::class);

    expect(Puesto::where('nombre', 'Dirección General')->count())->toBe(1)
        ->and(Puesto::where('nombre', 'Director comercial')->count())->toBe(1);
});
