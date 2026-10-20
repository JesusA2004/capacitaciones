<?php

use App\Enums\EstadoUsuario;
use App\Models\Baja;
use App\Models\CausalRotacion;
use App\Models\Colaborador;
use App\Models\User;
use Database\Seeders\CausalesRotacionSeeder;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
| Las pantallas del módulo de bajas cargan con los datos que esperan (Inertia).
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $this->seed(CausalesRotacionSeeder::class);
    Storage::fake('nas');
    Notification::fake();

    $estructura = clEstructura();
    $this->rh = clUsuario('rh_admin', ['sucursal_principal_id' => $estructura['sucursal']->id]);
    $this->gerente = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $estructura['sucursal']->id]);
    $this->persona = Colaborador::factory()->create(['sucursal_principal_id' => $estructura['sucursal']->id, 'estatus' => EstadoUsuario::Activo, 'fecha_ingreso' => '2024-01-01']);
    User::factory()->create(['colaborador_id' => $this->persona->id])->assignRole('colaborador');
});

test('bandeja, solicitud, edición, expediente y tablero cargan', function () {
    $this->actingAs($this->gerente)->get(route('rh.bajas.create', ['colaborador' => $this->persona->id]))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('Rh/Bajas/Formulario')
            ->has('formas', 3)
            ->where('colaborador.id', $this->persona->id)
            ->has('documentos_catalogo', 13));

    $this->actingAs($this->gerente)->post(route('rh.bajas.store'), ['colaborador_id' => $this->persona->id, 'forma_salida' => 'RV'])->assertSessionHasNoErrors();
    $baja = Baja::query()->firstOrFail();

    $this->actingAs($this->gerente)->get(route('rh.bajas.edit', $baja))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('Rh/Bajas/Formulario')->where('baja.folio', $baja->folio));

    $this->actingAs($this->gerente)->put(route('rh.bajas.update', $baja), [
        'forma_salida' => 'RV',
        'causal_rotacion_id' => CausalRotacion::query()->where('clave', 'RV-01')->value('id'),
        'fecha_baja' => now()->subDay()->toDateString(),
        'recontratable' => 'si',
        'enviar' => true,
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->rh)->get(route('rh.bajas.index'))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('Rh/Bajas/Index')->where('filtros.estatus', 'en_revision_rh')->has('bajas.data', 1)->where('bajas.data.0.documentos_completos', false));

    $this->actingAs($this->rh)->get(route('rh.bajas.show', $baja))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('Rh/Bajas/Show')
            ->has('baja.documentos', 3)
            ->has('baja.bitacora')
            ->where('baja.acciones', fn ($acciones) => collect($acciones)->contains('cerrar') && collect($acciones)->contains('cambiar_causal')));

    $this->actingAs($this->rh)->get(route('reportes.rotacion.tablero.index'))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('Reportes/TableroRotacion')->has('tablero.tendencia', 12)->has('tablero.resumen.por_forma', 3));

    // Ciclo laboral: el atajo trae las formas con causales y los catálogos del expediente.
    $this->actingAs($this->rh)->get(route('rh.colaboradores.ciclo', $this->persona))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->has('opciones.causas', 3)->has('opciones.recontratable', 3)->has('opciones.limites_baja'));
});
