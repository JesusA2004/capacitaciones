<?php

use App\Models\User;
use Database\Seeders\DepartamentoSeeder;
use Database\Seeders\EmpresaSeeder;
use Database\Seeders\PuestoSeeder;
use Database\Seeders\RolesYPermisosSeeder;
use Database\Seeders\SucursalSeeder;
use Database\Seeders\UsuarioDemoSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

test('sin filtros el panel devuelve los cumpleanos del mes actual', function () {
    $admin = User::factory()->create();
    $admin->assignRole('rh_admin');

    $hoy = Carbon::today();

    $festejado = User::factory()->create([
        'fecha_nacimiento' => $hoy->copy()->subYears(29),
        'name' => 'Festejado Del Mes',
    ]);

    $this->actingAs($admin)
        ->get(route('rh.cumpleanos.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Rh/Cumpleanos/Index')
            ->where('mes', $hoy->month)
            ->where('delMes', fn ($delMes) => collect($delMes)->pluck('id')->contains($festejado->id))
        );
});

test('el selector de colaborador incluye activos sin fecha de nacimiento', function () {
    $admin = User::factory()->create();
    $admin->assignRole('rh_admin');

    $sinFecha = User::factory()->create(['fecha_nacimiento' => null, 'name' => 'Sin Fecha Capturada']);

    $this->actingAs($admin)
        ->get(route('rh.cumpleanos.index'))
        ->assertInertia(fn ($page) => $page
            ->where('opciones.colaboradores', fn ($colaboradores) => collect($colaboradores)->pluck('id')->contains($sinFecha->id))
        );
});

test('los colaboradores activos sin fecha de nacimiento se cuentan y se listan aparte', function () {
    $admin = User::factory()->create();
    $admin->assignRole('rh_admin');

    $sinFecha = User::factory()->create(['fecha_nacimiento' => null, 'name' => 'Falta Su Fecha']);
    $conFecha = User::factory()->create(['fecha_nacimiento' => now()->subYears(30)]);

    $this->actingAs($admin)
        ->get(route('rh.cumpleanos.index'))
        ->assertInertia(fn ($page) => $page
            ->where('sinFechaNacimiento', fn ($lista) => collect($lista)->pluck('id')->contains($sinFecha->id)
                && ! collect($lista)->pluck('id')->contains($conFecha->id))
        );
});

test('limpiar filtros vuelve a traer el universo completo de datos reales', function () {
    $admin = User::factory()->create();
    $admin->assignRole('rh_admin');

    $marzo = User::factory()->create(['fecha_nacimiento' => '1990-03-05', 'name' => 'Nacido En Marzo']);
    $abril = User::factory()->create(['fecha_nacimiento' => '1990-04-05', 'name' => 'Nacido En Abril']);

    $this->actingAs($admin)
        ->get(route('rh.cumpleanos.index', ['mes' => 3, 'busqueda' => 'Marzo']))
        ->assertInertia(fn ($page) => $page
            ->where('delMes', fn ($delMes) => collect($delMes)->pluck('id')->contains($marzo->id)
                && ! collect($delMes)->pluck('id')->contains($abril->id))
        );

    // "Limpiar filtros" es una navegacion sin busqueda/colaborador_id (mismo
    // mes elegido): debe volver a traer todos los cumpleanos de ese mes, no
    // quedarse con el resultado acotado anterior.
    $this->actingAs($admin)
        ->get(route('rh.cumpleanos.index', ['mes' => 3]))
        ->assertInertia(fn ($page) => $page
            ->where('delMes', fn ($delMes) => collect($delMes)->pluck('id')->contains($marzo->id)));
});

test('los seeders de desarrollo dejan cumpleanos visibles en el mes actual', function () {
    $this->seed(EmpresaSeeder::class);
    $this->seed(SucursalSeeder::class);
    $this->seed(DepartamentoSeeder::class);
    $this->seed(PuestoSeeder::class);
    $this->seed(UsuarioDemoSeeder::class);

    $conCumpleanosEsteMes = User::query()
        ->whereNotNull('fecha_nacimiento')
        ->whereMonth('fecha_nacimiento', now()->month)
        ->count();

    expect($conCumpleanosEsteMes)->toBeGreaterThan(0);
});
