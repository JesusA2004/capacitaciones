<?php

use App\Enums\EstadoUsuario;
use App\Models\Baja;
use App\Models\CausalRotacion;
use App\Models\CierreLaboral;
use App\Models\Colaborador;
use App\Models\FiniquitoCalculo;
use App\Models\SolicitudInterna;
use App\Models\User;
use Database\Seeders\CausalesRotacionSeeder;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
| Las pantallas del módulo «Bajas» cargan con los datos que esperan
| (Inertia) y la vista previa del finiquito ANTES de registrar no escribe
| nada.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $this->seed(CausalesRotacionSeeder::class);
    Storage::fake('nas');
    Notification::fake();

    $estructura = clEstructura();
    $this->rh = clUsuario('rh_admin', ['sucursal_principal_id' => $estructura['sucursal']->id]);
    $this->gerente = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $estructura['sucursal']->id]);
    $this->persona = Colaborador::factory()->create(['sucursal_principal_id' => $estructura['sucursal']->id, 'estatus' => EstadoUsuario::Activo, 'fecha_ingreso' => '2024-01-01', 'sueldo_mensual' => 15000]);
    User::factory()->create(['colaborador_id' => $this->persona->id])->assignRole('colaborador');
});

test('registro, bandeja «Bajas», expediente y tablero cargan', function () {
    $this->actingAs($this->gerente)->get(route('rh.bajas.create', ['colaborador' => $this->persona->id]))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('Rh/Bajas/Formulario')
            ->has('formas', 3)
            ->where('colaborador.id', $this->persona->id)
            ->missing('baja')
            ->has('documentos_catalogo', 13));

    $this->actingAs($this->gerente)->post(route('rh.bajas.store'), [
        'colaborador_id' => $this->persona->id,
        'forma_salida' => 'RV',
        'causal_rotacion_id' => CausalRotacion::query()->where('clave', 'RV-01')->value('id'),
        'fecha_baja' => now()->subDay()->toDateString(),
        'recontratable' => 'si',
    ])->assertSessionHasNoErrors();
    $baja = Baja::query()->firstOrFail();

    $this->actingAs($this->rh)->get(route('rh.bajas.index'))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('Rh/Bajas/Index')
            ->where('filtros.estatus', 'abiertas')
            ->has('bajas.data', 1)
            ->where('bajas.data.0.documentos_completos', false)
            ->where('bajas.data.0.documentos_total', 3)
            ->where('resumen.en_tramite', 1)
            // Sin estatus del flujo anterior en el filtro.
            ->where('estatus', fn ($opciones) => ! collect($opciones)->pluck('value')->intersect(['borrador', 'rechazada'])->count()));

    $this->actingAs($this->rh)->get(route('rh.bajas.show', $baja))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('Rh/Bajas/Show')
            ->has('baja.documentos', 3)
            ->has('baja.bitacora')
            ->where('baja.acciones', fn ($acciones) => collect($acciones)->contains('cerrar') && collect($acciones)->contains('cambiar_causal') && ! collect($acciones)->contains('validar')));

    $this->actingAs($this->rh)->get(route('reportes.rotacion.tablero.index'))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('Reportes/TableroRotacion')->has('tablero.tendencia', 12)->has('tablero.resumen.por_forma', 3));
});

test('ciclo laboral: el «Iniciar baja» trae las formas con causales y los catálogos del registro', function () {
    $this->actingAs($this->rh)->get(route('rh.colaboradores.ciclo', $this->persona))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->has('opciones.causas', 3)->has('opciones.recontratable', 3)->has('opciones.limites_baja'));
});

test('vista previa del FINIQUITO antes de registrar: PDF inline, no-store, y no escribe nada', function () {
    $consulta = [
        'colaborador_id' => $this->persona->id,
        'forma_salida' => 'RV',
        'causal_rotacion_id' => CausalRotacion::query()->where('clave', 'RV-01')->value('id'),
        'fecha_baja' => now()->subDay()->toDateString(),
    ];

    $respuesta = $this->actingAs($this->gerente)->get(route('rh.bajas.previa.finiquito', $consulta));
    $respuesta->assertOk();

    expect($respuesta->headers->get('Content-Type'))->toStartWith('application/pdf')
        ->and($respuesta->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and($respuesta->headers->get('Cache-Control'))->toContain('no-store')
        ->and(substr((string) $respuesta->getContent(), 0, 4))->toBe('%PDF')
        ->and(Baja::query()->count())->toBe(0)
        ->and(CierreLaboral::query()->count())->toBe(0)
        ->and(SolicitudInterna::query()->count())->toBe(0)
        ->and(FiniquitoCalculo::query()->count())->toBe(0)
        ->and(User::query()->where('colaborador_id', $this->persona->id)->value('acceso_bloqueado_en'))->toBeNull();

    // Sin forma de salida no hay finiquito que mostrar; fuera de alcance, 403.
    $this->actingAs($this->gerente)->get(route('rh.bajas.previa.finiquito', ['colaborador_id' => $this->persona->id]))->assertSessionHasErrors('forma_salida');
    $this->actingAs(clUsuario('jefe_directo'))->get(route('rh.bajas.previa.finiquito', $consulta))->assertForbidden();
});
