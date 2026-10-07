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

test('el seeder deja la estructura confirmada por dirección (y conserva los puestos fuera de ella)', function () {
    $this->seed(DepartamentoSeeder::class);
    $this->seed(PuestoJerarquiaSeeder::class);

    $estructura = [
        // Estructura confirmada (2026-10-06): Sistemas, RH y Contraloría
        // reportan directo a Dirección General, al mismo nivel que Comercial.
        'Dirección General' => null,
        'Dirección Comercial' => 'Dirección General',
        'Asistente de Dirección Comercial' => 'Dirección Comercial',
        'Responsable de Sistemas' => 'Dirección General',
        'Monitorista' => 'Responsable de Sistemas',
        'Gerencia de Recursos Humanos' => 'Dirección General',
        'Administración de Personal' => 'Gerencia de Recursos Humanos',
        'Reclutamiento' => 'Gerencia de Recursos Humanos',
        'Coordinadora Regional' => 'Dirección Comercial',
        'Coordinadora de Sucursal' => 'Coordinadora Regional',
        'Gerente Regional Q1' => 'Dirección Comercial',
        'Gerente Regional Q3' => 'Dirección Comercial',
        'Gerente de Sucursal' => 'Gerente Regional Q1', // de referencia: se resuelve por región
        'Subgerente' => 'Gerente de Sucursal',
        'Gestor' => 'Subgerente',
        'Gestor Volante' => 'Subgerente',
        // Mesa de Control sigue bajo Comercial; Contraloría es área directa.
        'Gerente de Mesa de Control' => 'Dirección Comercial',
        'Analista de Mesa de Control' => 'Gerente de Mesa de Control',
        'Gerente de Contraloría' => 'Dirección General',
        'Auditora' => 'Gerente de Contraloría',
        'Tesorero' => 'Gerente de Contraloría',
        'Contador' => 'Gerente de Contraloría',
        // Fuera de la estructura confirmada: se conservan sin cambios.
        'Asistente de Dirección General' => 'Dirección General',
        'Gestor grupal' => 'Subgerente',
    ];

    foreach ($estructura as $puesto => $superior) {
        expect(superiorDe($puesto))->toBe($superior, "«{$puesto}» debería reportar a «{$superior}»");
    }

    expect(Puesto::count())->toBe(count($estructura));

    // Nombres anteriores que no deben reaparecer; Q2 no existe.
    expect(Puesto::whereIn('nombre', ['Gestor fijo', 'Gerente administrativo regional', 'Director comercial', 'Gerente de Sistemas', 'Gerente regional', 'Gerente Regional Q2'])->exists())->toBeFalse();
});

test('Dirección General es la única raíz y Dirección Comercial cuelga de ella', function () {
    $this->seed(DepartamentoSeeder::class);
    $this->seed(PuestoJerarquiaSeeder::class);

    expect(Puesto::whereNull('puesto_superior_id')->where('activo', true)->pluck('nombre')->all())->toBe(['Dirección General'])
        ->and(superiorDe('Dirección Comercial'))->toBe('Dirección General');
});

test('Gerencia de RH tiene exactamente sus 2 áreas y Sistemas solo al Monitorista', function () {
    $this->seed(DepartamentoSeeder::class);
    $this->seed(PuestoJerarquiaSeeder::class);

    $hijosDe = fn (string $nombre) => Puesto::where('puesto_superior_id', Puesto::where('nombre', $nombre)->value('id'))->orderBy('nombre')->pluck('nombre')->all();

    expect($hijosDe('Gerencia de Recursos Humanos'))->toBe(['Administración de Personal', 'Reclutamiento'])
        ->and($hijosDe('Responsable de Sistemas'))->toBe(['Monitorista'])
        ->and($hijosDe('Coordinadora Regional'))->toBe(['Coordinadora de Sucursal'])
        ->and(Puesto::where('nombre', 'like', 'Asistente de Dirección Comercial%')->count())->toBe(1);
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
        ->and(superiorDe('Gestor Volante'))->toBe('Subgerente');
});

test('renombra los puestos obsoletos conservando su id y su gente', function () {
    $this->seed(DepartamentoSeeder::class);

    $gestorFijo = Puesto::factory()->create(['nombre' => 'Gestor fijo']);
    $administrativoRegional = Puesto::factory()->create(['nombre' => 'Gerente administrativo regional']);
    $gestor = Colaborador::factory()->create(['puesto_id' => $gestorFijo->id]);

    $this->seed(PuestoJerarquiaSeeder::class);

    expect($gestorFijo->refresh()->nombre)->toBe('Gestor')
        ->and($gestorFijo->requiere_ruta)->toBeTrue()
        ->and($gestor->refresh()->puesto_id)->toBe($gestorFijo->id)
        ->and($administrativoRegional->refresh()->nombre)->toBe('Coordinadora Regional')
        ->and(Puesto::where('nombre', 'Gestor')->count())->toBe(1);
});

test('solo el Gestor tiene ruta de cobro', function () {
    $this->seed(DepartamentoSeeder::class);
    $this->seed(PuestoJerarquiaSeeder::class);

    expect(Puesto::where('requiere_ruta', true)->pluck('nombre')->all())->toBe(['Gestor']);
});

test('renombra a los nombres confirmados conservando id y gente (incluso cambios solo de mayúsculas)', function () {
    $this->seed(DepartamentoSeeder::class);

    $director = Puesto::factory()->create(['nombre' => 'Director comercial']);
    $volante = Puesto::factory()->create(['nombre' => 'Gestor volante']);
    $persona = Colaborador::factory()->create(['puesto_id' => $volante->id]);

    $this->seed(PuestoJerarquiaSeeder::class);

    expect($director->refresh()->nombre)->toBe('Dirección Comercial')
        ->and($volante->refresh()->nombre)->toBe('Gestor Volante')
        ->and($persona->refresh()->puesto_id)->toBe($volante->id);
});

test('el Gerente regional anterior con ocupante NO se adivina como Q1: se conserva y se reporta', function () {
    $this->seed(DepartamentoSeeder::class);

    $anterior = Puesto::factory()->create(['nombre' => 'Gerente regional']);
    $persona = Colaborador::factory()->create(['puesto_id' => $anterior->id]);

    $this->seed(PuestoJerarquiaSeeder::class);

    expect($anterior->refresh()->nombre)->toBe('Gerente regional')
        ->and($persona->refresh()->puesto_id)->toBe($anterior->id)
        ->and(Puesto::where('nombre', 'Gerente Regional Q1')->exists())->toBeTrue()
        ->and(Puesto::where('nombre', 'Gerente Regional Q3')->exists())->toBeTrue();
});
