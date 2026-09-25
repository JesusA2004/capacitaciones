<?php

use App\Models\Colaborador;
use App\Models\Puesto;
use Database\Seeders\DepartamentoSeeder;
use Database\Seeders\PuestoJerarquiaSeeder;

function superiorDe(string $nombre): ?string
{
    $puesto = Puesto::where('nombre', $nombre)->firstOrFail();

    return $puesto->puesto_superior_id !== null
        ? Puesto::whereKey($puesto->puesto_superior_id)->value('nombre')
        : null;
}

test('el seeder deja la estructura de puestos definida por dirección', function () {
    $this->seed(DepartamentoSeeder::class);
    $this->seed(PuestoJerarquiaSeeder::class);

    $estructura = [
        'Dirección General' => null,
        'Asistente de Dirección General' => 'Dirección General',
        'Director comercial' => 'Dirección General',
        'Asistente de Dirección Comercial' => 'Director comercial',
        'Gerente de Sistemas' => 'Director comercial',
        'Monitorista' => 'Gerente de Sistemas',
        'Gerente de Mesa de Control' => 'Director comercial',
        'Analista de Mesa de Control' => 'Gerente de Mesa de Control',
        'Gerente de Recursos Humanos' => 'Director comercial',
        'Administración de Personal' => 'Gerente de Recursos Humanos',
        'Reclutamiento' => 'Gerente de Recursos Humanos',
        'Gerente de Contraloría' => 'Director comercial',
        'Gerente regional' => 'Director comercial',
        'Gerente de Sucursal' => 'Gerente regional',
        'Subgerente' => 'Gerente de Sucursal',
        'Gestor fijo' => 'Subgerente',
        'Gestor grupal' => 'Subgerente',
        'Gestor volante' => 'Gestor fijo',
        'Gerente administrativo regional' => 'Director comercial',
        'Coordinadora' => 'Gerente administrativo regional',
    ];

    foreach ($estructura as $puesto => $superior) {
        expect(superiorDe($puesto))->toBe($superior, "«{$puesto}» debería reportar a «{$superior}»");
    }

    expect(Puesto::count())->toBe(count($estructura));

    // Las gerencias corporativas y la división comercial están al mismo nivel.
    expect(Puesto::whereIn('nombre', ['Gerente de Sistemas', 'Gerente de Mesa de Control', 'Gerente de Recursos Humanos', 'Gerente de Contraloría', 'Gerente regional', 'Gerente administrativo regional', 'Asistente de Dirección Comercial'])
        ->pluck('nivel_jerarquico')->unique()->all())->toBe([3]);
});

test('los puestos de la estructura anterior se renombran o se retiran sin perder a quien los usa', function () {
    $this->seed(DepartamentoSeeder::class);

    // Estructura anterior: un puesto renombrado con gente, uno retirado sin
    // uso y uno retirado que todavía tiene un colaborador.
    $contabilidad = Puesto::factory()->create(['nombre' => 'Gerente de Contabilidad']);
    $sinUso = Puesto::factory()->create(['nombre' => 'Analista de Nómina']);
    $enUso = Puesto::factory()->create(['nombre' => 'Soporte Técnico']);
    $contador = Colaborador::factory()->create(['puesto_id' => $contabilidad->id]);
    $tecnico = Colaborador::factory()->create(['puesto_id' => $enUso->id]);

    $this->seed(PuestoJerarquiaSeeder::class);

    // Renombrado: mismo registro, misma gente.
    expect($contador->refresh()->puesto_id)->toBe($contabilidad->id)
        ->and($contabilidad->refresh()->nombre)->toBe('Gerente de Contraloría');

    // Sin uso: se elimina (borrado suave).
    expect(Puesto::find($sinUso->id))->toBeNull();

    // En uso: queda inactivo y fuera del árbol; su colaborador no pierde el puesto.
    $enUso->refresh();
    expect($enUso->activo)->toBeFalse()
        ->and($enUso->puesto_superior_id)->toBeNull()
        ->and($tecnico->refresh()->puesto_id)->toBe($enUso->id);
});

test('correr el seeder dos veces no duplica puestos ni rompe la jerarquía', function () {
    $this->seed(DepartamentoSeeder::class);
    $this->seed(PuestoJerarquiaSeeder::class);
    $total = Puesto::count();

    $this->seed(PuestoJerarquiaSeeder::class);

    expect(Puesto::count())->toBe($total)
        ->and(superiorDe('Gestor volante'))->toBe('Gestor fijo');
});
