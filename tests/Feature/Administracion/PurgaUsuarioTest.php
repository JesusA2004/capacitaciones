<?php

use App\Models\Colaborador;
use App\Models\MobileDevice;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Support\ReferenciasUsuario;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Activity;

/*
| «Eliminar definitivamente» (solo super_admin): revisa dependencias, pide
| confirmación escrita, purga en una transacción sin dejar bitácora y nunca
| toca el expediente del colaborador. No es «Revocar acceso».
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $this->super = clUsuario('super_admin');
    $this->rh = clUsuario('rh_admin');
    $this->persona = Colaborador::factory()->create();
    $this->cuenta = User::factory()->create(['username' => 'cuenta.prueba', 'colaborador_id' => $this->persona->id]);
    $this->cuenta->assignRole('colaborador');
});

test('el mapa de referencias a users coincide con las llaves foráneas reales de las migraciones', function () {
    $reales = [];

    foreach (Schema::getTableListing(schemaQualified: false) as $tabla) {
        foreach (Schema::getForeignKeys($tabla) as $fk) {
            if ($fk['foreign_table'] === 'users') {
                $accion = strtolower((string) $fk['on_delete']);
                $reales[] = [$tabla, $fk['columns'][0], $accion === 'cascade' ? 'cascade' : ($accion === 'set null' ? 'set_null' : 'restrict')];
            }
        }
    }

    $ordenar = fn (array $l) => collect($l)->map(fn ($r) => implode('|', $r))->sort()->values()->all();
    expect($ordenar(ReferenciasUsuario::COLUMNAS))->toBe($ordenar($reales));
});

test('solo super_admin ve y usa la eliminación definitiva', function () {
    $this->actingAs($this->rh)->get(route('administracion.usuarios.index'))->assertInertia(fn ($p) => $p->where('puedePurgar', false));
    $this->actingAs($this->super)->get(route('administracion.usuarios.index'))->assertInertia(fn ($p) => $p->where('puedePurgar', true));

    $this->actingAs($this->rh)->getJson(route('administracion.usuarios.purga.revisar', $this->cuenta))->assertForbidden();
    $this->actingAs($this->rh)->delete(route('administracion.usuarios.purga.eliminar', $this->cuenta), ['confirmacion' => 'ELIMINAR']);
    expect(User::withTrashed()->whereKey($this->cuenta->id)->exists())->toBeTrue();
});

test('pide escribir ELIMINAR o el usuario exacto; purga sin bitácora y conserva el expediente', function () {
    MobileDevice::factory()->for($this->cuenta, 'usuario')->create();
    $this->cuenta->createToken('app');

    $revision = $this->actingAs($this->super)->getJson(route('administracion.usuarios.purga.revisar', $this->cuenta))->assertOk()->json('data');
    expect($revision['puede'])->toBeTrue()->and($revision['confirmacion'])->toBe('cuenta.prueba');

    $this->actingAs($this->super)->delete(route('administracion.usuarios.purga.eliminar', $this->cuenta), ['confirmacion' => 'eliminar'])
        ->assertSessionHasErrors('confirmacion');
    expect(User::withTrashed()->whereKey($this->cuenta->id)->exists())->toBeTrue();

    $actividadAntes = Activity::query()->count();
    $this->actingAs($this->super)->delete(route('administracion.usuarios.purga.eliminar', $this->cuenta), ['confirmacion' => 'cuenta.prueba'])
        ->assertSessionHasNoErrors()->assertRedirect(route('administracion.usuarios.index'));

    expect(User::withTrashed()->whereKey($this->cuenta->id)->exists())->toBeFalse()
        ->and(DB::table('mobile_devices')->where('user_id', $this->cuenta->id)->count())->toBe(0)
        ->and(DB::table('personal_access_tokens')->where('tokenable_id', $this->cuenta->id)->count())->toBe(0)
        ->and(DB::table('model_has_roles')->where('model_id', $this->cuenta->id)->where('model_type', $this->cuenta->getMorphClass())->count())->toBe(0)
        ->and(Activity::query()->count())->toBe($actividadAntes)
        ->and(Colaborador::query()->whereKey($this->persona->id)->exists())->toBeTrue();
});

test('con historial de negocio no se purga: explica qué lo impide', function () {
    SolicitudInterna::factory()->create(['user_id' => $this->cuenta->id, 'colaborador_id' => $this->persona->id]);

    $revision = $this->actingAs($this->super)->getJson(route('administracion.usuarios.purga.revisar', $this->cuenta))->assertOk()->json('data');
    expect($revision['puede'])->toBeFalse()
        ->and(implode(' ', $revision['bloqueos']))->toContain('Solicitudes: 1 registro');

    $this->actingAs($this->super)->delete(route('administracion.usuarios.purga.eliminar', $this->cuenta), ['confirmacion' => 'ELIMINAR'])
        ->assertSessionHasErrors('confirmacion');
    expect(User::withTrashed()->whereKey($this->cuenta->id)->exists())->toBeTrue();
});

test('las referencias de autoría se desvinculan y nadie puede purgarse a sí mismo', function () {
    $otro = Colaborador::factory()->create();
    $solicitud = SolicitudInterna::factory()->create(['user_id' => $this->rh->id, 'colaborador_id' => $otro->id, 'revisado_por' => $this->cuenta->id]);

    $this->actingAs($this->super)->delete(route('administracion.usuarios.purga.eliminar', $this->cuenta), ['confirmacion' => 'ELIMINAR'])
        ->assertSessionHasNoErrors();
    expect($solicitud->refresh()->revisado_por)->toBeNull();

    $this->actingAs($this->super)->getJson(route('administracion.usuarios.purga.revisar', $this->super))->assertForbidden();
});
