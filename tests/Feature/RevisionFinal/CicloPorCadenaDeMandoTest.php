<?php

use App\Models\Colaborador;
use App\Models\Sucursal;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

/*
| En E2E un Gerente de Córdoba abrió el ciclo de una persona de Cuernavaca. No es fuga:
| OrganigramaDemoSeeder le da la cobertura de Cuernavaca, así que es jefe del Subgerente
| de allá y este de la persona (cadena 22 → 14 → 4). Sin ese vínculo, otra sucursal: 403.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Notification::fake();
    $this->cordoba = Sucursal::factory()->create();
    $this->cuernavaca = Sucursal::factory()->create();
    $this->gerente = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $this->cordoba->id]);
    $this->intermedio = Colaborador::factory()->create(['sucursal_principal_id' => $this->cuernavaca->id, 'estatus' => 'activo']);
    $this->persona = Colaborador::factory()->create(['sucursal_principal_id' => $this->cuernavaca->id, 'estatus' => 'activo', 'jefe_id' => $this->intermedio->id]);
});

test('un gerente de otra sucursal sin vínculo de mando no abre el ciclo, en web ni en API', function () {
    $this->actingAs($this->gerente)->get("/rh/colaboradores/{$this->persona->id}/ciclo")->assertForbidden();

    Sanctum::actingAs($this->gerente);
    $this->getJson("/api/v1/rh/colaboradores/{$this->persona->id}/ciclo")->assertForbidden();
});

test('quien está en la cadena de mando sí lo abre, aunque su sucursal sea otra', function () {
    $this->intermedio->update(['jefe_id' => $this->gerente->colaborador_id]);

    $this->actingAs($this->gerente)->get("/rh/colaboradores/{$this->persona->id}/ciclo")->assertOk();

    Sanctum::actingAs($this->gerente);
    $this->getJson("/api/v1/rh/colaboradores/{$this->persona->id}/ciclo")->assertOk();
});
