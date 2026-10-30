<?php

use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\Puesto;
use App\Models\Sucursal;
use Database\Seeders\RolesYPermisosSeeder;

/*
| Directorio de la empresa: lo ve todo colaborador autenticado, acotado al
| alcance del módulo «Directorio» (DirectorioAlcanceTest).
|
| Propuesta de mejoras Etapa 1 (RH, 2026-10-29): el Directorio lista a TODAS
| las personas activas de la plantilla —incluidos los gestores—, aunque no
| tengan correo ni teléfono. Quien no tiene contacto aparece con la bandera
| `datos_por_completar` (solo visible para RH), nunca se oculta ni se inventa
| un correo. La web recibe la lista completa para organizarla por puesto, por
| área y por sucursal.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $this->corporativo = Sucursal::factory()->create(['nombre' => 'Corporativo', 'activo' => true, 'es_corporativo' => true]);
    $this->tula = Sucursal::factory()->create(['nombre' => 'Tula', 'activo' => true, 'es_corporativo' => false]);
    $this->gerente = Puesto::factory()->create(['nombre' => 'Gerente de Sucursal', 'grupo_documental' => 'gerente', 'nivel_jerarquico' => 4, 'activo' => true]);
    $this->gestor = Puesto::factory()->create(['nombre' => 'Gestor', 'grupo_documental' => 'gestor', 'nivel_jerarquico' => 6, 'activo' => true]);
    $sistemas = Departamento::factory()->create(['nombre' => 'Sistemas']);
    $this->responsableSistemas = Puesto::factory()->create(['nombre' => 'Responsable de Sistemas', 'grupo_documental' => 'administrativo_confianza', 'departamento_id' => $sistemas->id, 'nivel_jerarquico' => 2, 'activo' => true]);
});

test('el directorio muestra a TODA la plantilla activa, incluidos gestores', function () {
    Colaborador::factory()->create(['name' => 'María', 'apellidos' => 'López', 'puesto_id' => $this->gerente->id, 'sucursal_principal_id' => $this->tula->id, 'estatus' => 'activo', 'correo_corporativo' => 'maria.lopez@mrlana.test', 'telefono_corporativo' => '5550003333']);
    Colaborador::factory()->create(['name' => 'Pedro', 'apellidos' => 'Ramírez', 'puesto_id' => $this->gestor->id, 'sucursal_principal_id' => $this->tula->id, 'estatus' => 'activo', 'correo_corporativo' => null, 'telefono_corporativo' => null]);
    Colaborador::factory()->create(['name' => 'Ana', 'apellidos' => 'Martínez', 'puesto_id' => $this->responsableSistemas->id, 'sucursal_principal_id' => $this->corporativo->id, 'estatus' => 'activo']);

    $this->actingAs(clUsuario('rh_admin'))->get(route('directorio.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('colaboradores', function ($data) {
            $filas = collect($data)->keyBy('nombre');

            return $filas->has('Pedro Ramírez')
                // El gestor sin contacto aparece marcado como «datos por completar».
                && $filas['Pedro Ramírez']['datos_por_completar'] === true
                && $filas['María López']['correo_corporativo'] === 'maria.lopez@mrlana.test'
                && $filas['María López']['datos_por_completar'] === false
                && $filas['María López']['area'] === 'Gerentes de sucursal'
                && $filas['Ana Martínez']['area'] === 'Sistemas'
                && $filas['Ana Martínez']['es_corporativo'] === true;
        }));
});

test('Corporativo + Sucursales es igual al total de personas activas', function () {
    Colaborador::factory()->count(2)->create(['puesto_id' => $this->responsableSistemas->id, 'sucursal_principal_id' => $this->corporativo->id, 'estatus' => 'activo']);
    Colaborador::factory()->count(2)->create(['puesto_id' => $this->gerente->id, 'sucursal_principal_id' => $this->tula->id, 'estatus' => 'activo']);
    $gestor = Colaborador::factory()->create(['name' => 'Pedro', 'apellidos' => 'Gestor', 'puesto_id' => $this->gestor->id, 'sucursal_principal_id' => $this->tula->id, 'estatus' => 'activo']);
    // Una persona inactiva NO cuenta.
    $inactivo = Colaborador::factory()->create(['name' => 'Baja', 'apellidos' => 'Pasada', 'puesto_id' => $this->gerente->id, 'sucursal_principal_id' => $this->tula->id, 'estatus' => 'inactivo']);

    $this->actingAs(clUsuario('rh_admin'))->get(route('directorio.index'))->assertOk()
        ->assertInertia(function ($page) use ($gestor, $inactivo) {
            $data = collect($page->toArray()['props']['colaboradores']);
            $corporativo = $data->where('es_corporativo', true)->count();
            $sucursales = $data->where('es_corporativo', false)->count();

            // Invariante de la propuesta: Corporativo + Sucursales = activas.
            expect($corporativo + $sucursales)->toBe($data->count())
                // El gestor activo aparece; el inactivo nunca.
                ->and($data->firstWhere('id', $gestor->id))->not->toBeNull()
                ->and($data->firstWhere('id', $inactivo->id))->toBeNull()
                ->and($corporativo)->toBeGreaterThanOrEqual(2);

            return true;
        });
});

test('la API móvil también lista a los gestores de la sucursal', function () {
    Colaborador::factory()->create(['name' => 'Pedro', 'apellidos' => 'Ramírez', 'puesto_id' => $this->gestor->id, 'estatus' => 'activo', 'sucursal_principal_id' => $this->tula->id]);
    Colaborador::factory()->create(['name' => 'María', 'apellidos' => 'López', 'puesto_id' => $this->gerente->id, 'estatus' => 'activo', 'sucursal_principal_id' => $this->tula->id]);
    $usuario = clUsuario('colaborador', ['sucursal_principal_id' => $this->tula->id]);

    $nombres = collect($this->actingAs($usuario, 'sanctum')->getJson('/api/v1/directorio?per_page=50')->assertOk()->json('data'))->pluck('nombre');

    expect($nombres)->toContain('María López')->toContain('Pedro Ramírez');
});

test('la bandera datos_por_completar solo se expone a quien ve todos los contactos', function () {
    Colaborador::factory()->create(['name' => 'Pedro', 'apellidos' => 'Ramírez', 'puesto_id' => $this->gestor->id, 'estatus' => 'activo', 'sucursal_principal_id' => $this->tula->id, 'correo_corporativo' => null, 'telefono_corporativo' => null]);
    $usuario = clUsuario('colaborador', ['sucursal_principal_id' => $this->tula->id]);

    $fila = collect($this->actingAs($usuario, 'sanctum')->getJson('/api/v1/directorio?per_page=50')->assertOk()->json('data'))
        ->firstWhere('nombre', 'Pedro Ramírez');

    // El operativo no distingue quién tiene contacto: la bandera va nula.
    expect($fila['datos_por_completar'])->toBeNull();
});

test('un colaborador sin correo corporativo aparece en el directorio sin correo inventado', function () {
    $colaborador = Colaborador::factory()->create(['estatus' => 'activo', 'puesto_id' => $this->gerente->id, 'correo_corporativo' => null, 'sucursal_principal_id' => $this->tula->id]);

    $this->actingAs(clUsuario('colaborador', ['sucursal_principal_id' => $this->tula->id]))->get(route('directorio.index'))
        ->assertInertia(fn ($page) => $page->where(
            'colaboradores',
            fn ($data) => collect($data)->firstWhere('id', $colaborador->id)['correo_corporativo'] === null,
        ));
});
