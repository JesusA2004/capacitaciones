<?php

use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\Puesto;
use App\Models\Sucursal;
use Database\Seeders\RolesYPermisosSeeder;

/*
| Directorio de la empresa: lo ve todo colaborador autenticado, acotado al
| alcance del módulo «Directorio» (DirectorioAlcanceTest).
| Los gestores NO aparecen (no tienen correo corporativo ni son contacto de
| comunicación interna, config/directorio.php). La web recibe la lista
| completa para organizarla por puesto, por área y por sucursal; una fila sin
| correo se muestra igual, nunca se inventa uno.
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

test('el directorio no muestra gestores y sí al personal de sucursal y corporativo', function () {
    Colaborador::factory()->create(['name' => 'María', 'apellidos' => 'López', 'puesto_id' => $this->gerente->id, 'sucursal_principal_id' => $this->tula->id, 'estatus' => 'activo', 'correo_corporativo' => 'maria.lopez@mrlana.test']);
    Colaborador::factory()->create(['name' => 'Pedro', 'apellidos' => 'Ramírez', 'puesto_id' => $this->gestor->id, 'sucursal_principal_id' => $this->tula->id, 'estatus' => 'activo']);
    Colaborador::factory()->create(['name' => 'Ana', 'apellidos' => 'Martínez', 'puesto_id' => $this->responsableSistemas->id, 'sucursal_principal_id' => $this->corporativo->id, 'estatus' => 'activo']);

    // Quien tiene permiso de ver todos los contactos (RH); el operativo solo
    // ve el de su jefe directo (DirectorioPrivacidadTest).
    $this->actingAs(clUsuario('rh_admin'))->get(route('directorio.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('colaboradores', function ($data) {
            $filas = collect($data)->keyBy('nombre');

            return ! $filas->has('Pedro Ramírez')
                && $filas['María López']['correo_corporativo'] === 'maria.lopez@mrlana.test'
                // En sucursal el área es el puesto; en Corporativo, el departamento.
                && $filas['María López']['area'] === 'Gerentes de sucursal'
                && $filas['María López']['sucursal'] === 'Tula'
                && $filas['Ana Martínez']['area'] === 'Sistemas'
                && $filas['Ana Martínez']['es_corporativo'] === true
                && $filas['Ana Martínez']['nivel'] === 2;
        }));
});

test('la API móvil tampoco lista gestores', function () {
    // Misma sucursal: el personal de sucursal solo ve la suya (alcance del módulo).
    Colaborador::factory()->create(['name' => 'Pedro', 'apellidos' => 'Ramírez', 'puesto_id' => $this->gestor->id, 'estatus' => 'activo', 'sucursal_principal_id' => $this->tula->id]);
    Colaborador::factory()->create(['name' => 'María', 'apellidos' => 'López', 'puesto_id' => $this->gerente->id, 'estatus' => 'activo', 'sucursal_principal_id' => $this->tula->id]);
    $usuario = clUsuario('colaborador', ['sucursal_principal_id' => $this->tula->id]);

    $nombres = collect($this->actingAs($usuario, 'sanctum')->getJson('/api/v1/directorio?per_page=50')->assertOk()->json('data'))->pluck('nombre');

    expect($nombres)->toContain('María López')->not->toContain('Pedro Ramírez');
});

test('un colaborador sin correo corporativo aparece en el directorio sin correo inventado', function () {
    $colaborador = Colaborador::factory()->create(['estatus' => 'activo', 'puesto_id' => $this->gerente->id, 'correo_corporativo' => null, 'sucursal_principal_id' => $this->tula->id]);

    $this->actingAs(clUsuario('colaborador', ['sucursal_principal_id' => $this->tula->id]))->get(route('directorio.index'))
        ->assertInertia(fn ($page) => $page->where(
            'colaboradores',
            fn ($data) => collect($data)->firstWhere('id', $colaborador->id)['correo_corporativo'] === null,
        ));
});
