<?php

use App\Enums\CategoriaNotificacion;
use App\Enums\TipoNodoComercial;
use App\Models\Colaborador;
use App\Models\NodoComercial;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Services\Configuracion\WorkflowRoutingService;
use App\Services\Notificaciones\Aviso;
use App\Services\Notificaciones\NotificacionEventosService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
| Precisión de alcances (2026-10-26):
|  - Directorio: el personal de sucursal (gestores, colaboradores, gerentes)
|    solo ve a su sucursal (+ su jefe directo); el Regional, su región; RH,
|    Sistemas, Dirección y Corporativo, toda la empresa.
|  - Avisos de solicitudes/permisos: llegan según el alcance del módulo
|    «Solicitudes» del rol en Roles y permisos (gerente → su sucursal).
|  - Avisos por sucursal: quien tiene alcance amplio silencia sucursales.
*/

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesYPermisosSeeder::class);
    Notification::fake();

    $this->corporativo = Sucursal::factory()->create(['nombre' => 'Corporativo', 'activo' => true, 'es_corporativo' => true]);
    $this->tula = Sucursal::factory()->create(['nombre' => 'Tula', 'activo' => true, 'es_corporativo' => false]);
    $this->huichapan = Sucursal::factory()->create(['nombre' => 'Huichapan', 'activo' => true, 'es_corporativo' => false]);
    $this->cuernavaca = Sucursal::factory()->create(['nombre' => 'Cuernavaca', 'activo' => true, 'es_corporativo' => false]);

    // Región Q1 = Tula + Huichapan; Cuernavaca está en otra región.
    $matriz = NodoComercial::factory()->create(['tipo' => TipoNodoComercial::Matriz->value, 'nombre' => 'MATRIZ']);
    $q1 = NodoComercial::factory()->create(['tipo' => TipoNodoComercial::Region->value, 'nombre' => 'Región Q1', 'parent_id' => $matriz->id]);
    $q2 = NodoComercial::factory()->create(['tipo' => TipoNodoComercial::Region->value, 'nombre' => 'Región Q2', 'parent_id' => $matriz->id]);
    NodoComercial::factory()->create(['tipo' => TipoNodoComercial::Zona->value, 'nombre' => 'TULA', 'parent_id' => $q1->id, 'sucursal_id' => $this->tula->id]);
    NodoComercial::factory()->create(['tipo' => TipoNodoComercial::Zona->value, 'nombre' => 'HUICHAPAN', 'parent_id' => $q1->id, 'sucursal_id' => $this->huichapan->id]);
    NodoComercial::factory()->create(['tipo' => TipoNodoComercial::Zona->value, 'nombre' => 'CUERNAVACA', 'parent_id' => $q2->id, 'sucursal_id' => $this->cuernavaca->id]);

    $this->puesto = Puesto::factory()->create(['nombre' => 'Coordinadora de Sucursal', 'grupo_documental' => 'administrativo', 'activo' => true]);
    $persona = fn (string $nombre, Sucursal $sucursal, array $extra = []): Colaborador => Colaborador::factory()->create([
        'name' => $nombre, 'apellidos' => 'Prueba', 'estatus' => 'activo', 'puesto_id' => $this->puesto->id, 'sucursal_principal_id' => $sucursal->id, ...$extra,
    ]);

    $this->jefeEnCuernavaca = $persona('Jefa', $this->cuernavaca);
    $this->deTula = $persona('Toño', $this->tula);
    $this->deHuichapan = $persona('Hugo', $this->huichapan);
    $this->deCuernavaca = $persona('Carla', $this->cuernavaca);
    $this->deCorporativo = $persona('Corina', $this->corporativo);

    $this->ver = fn ($usuario): array => collect($this->actingAs($usuario)->get(route('directorio.index'))->assertOk()->viewData('page')['props']['colaboradores'])
        ->pluck('nombre')->all();
});

test('el personal de sucursal solo ve a su sucursal y a su jefe directo en el directorio (web y API)', function () {
    $usuario = clUsuario('colaborador', ['estatus' => 'activo', 'sucursal_principal_id' => $this->tula->id, 'jefe_id' => $this->jefeEnCuernavaca->id]);

    $nombres = ($this->ver)($usuario);
    expect($nombres)->toContain('Toño Prueba', 'Jefa Prueba')
        ->not->toContain('Hugo Prueba', 'Carla Prueba', 'Corina Prueba');

    $api = collect($this->actingAs($usuario, 'sanctum')->getJson('/api/v1/directorio?per_page=50')->assertOk()->json('data'))->pluck('nombre');
    expect($api)->toContain('Toño Prueba')->not->toContain('Carla Prueba');

    $sucursales = collect($this->actingAs($usuario, 'sanctum')->getJson('/api/v1/directorio/opciones')->assertOk()->json('data.sucursales'))->pluck('nombre');
    expect($sucursales->all())->toBe(['Tula']);
});

test('el gerente ve su sucursal, el regional su región y RH / Corporativo toda la empresa', function () {
    $gerente = clUsuario('gerente_sucursal', ['estatus' => 'activo', 'sucursal_principal_id' => $this->tula->id]);
    expect(($this->ver)($gerente))->toContain('Toño Prueba')->not->toContain('Hugo Prueba', 'Carla Prueba');

    $regional = clUsuario('gerente_regional', ['estatus' => 'activo', 'sucursal_principal_id' => $this->tula->id]);
    expect(($this->ver)($regional))->toContain('Toño Prueba', 'Hugo Prueba')->not->toContain('Carla Prueba');

    $rh = clUsuario('rh_admin', ['estatus' => 'activo', 'sucursal_principal_id' => $this->tula->id]);
    expect(($this->ver)($rh))->toContain('Toño Prueba', 'Hugo Prueba', 'Carla Prueba', 'Corina Prueba');

    $corporativo = clUsuario('colaborador', ['estatus' => 'activo', 'sucursal_principal_id' => $this->corporativo->id]);
    expect(($this->ver)($corporativo))->toContain('Toño Prueba', 'Carla Prueba');
});

test('los avisos de solicitudes llegan según el alcance del módulo «Solicitudes» del rol', function () {
    $routing = app(WorkflowRoutingService::class);
    $destinatarios = fn (Colaborador $sujeto): array => $routing->resolver('solicitud_creada', $sujeto)->pluck('usuario.id')->all();

    $gerenteTula = clUsuario('gerente_sucursal', ['estatus' => 'activo', 'sucursal_principal_id' => $this->tula->id]);
    $rh = clUsuario('rh_admin', ['estatus' => 'activo', 'sucursal_principal_id' => $this->corporativo->id]);

    expect($destinatarios($this->deTula))->toContain($gerenteTula->id, $rh->id)
        ->and($destinatarios($this->deCuernavaca))->toContain($rh->id)->not->toContain($gerenteTula->id);

    // Roles y permisos manda: si a RH se le deja «Solicitudes → Sucursal»,
    // ya no recibe los de otras sucursales aunque su rol sea global.
    $rol = Role::findByName('rh_admin');
    $rol->revokePermissionTo('solicitudes.ver_todos');
    $rol->givePermissionTo('solicitudes.ver_sucursal');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $rh->refresh();

    // (Con un gerente en Cuernavaca: si la regla no encontrara a NADIE, el
    // respaldo de la regla avisa a RH para que el aviso nunca se pierda.)
    $gerenteCuernavaca = clUsuario('gerente_sucursal', ['estatus' => 'activo', 'sucursal_principal_id' => $this->cuernavaca->id]);

    expect($destinatarios($this->deCuernavaca))->toContain($gerenteCuernavaca->id)->not->toContain($rh->id, $gerenteTula->id)
        ->and($destinatarios($this->deCorporativo))->toContain($rh->id);
});

test('quien tiene alcance amplio silencia sucursales: deja de recibir sus avisos, nunca sus confirmaciones', function () {
    $rh = clUsuario('rh_admin', ['estatus' => 'activo', 'sucursal_principal_id' => $this->corporativo->id]);

    $this->actingAs($rh)->get(route('avisos-sucursal.edit'))->assertOk();
    $this->actingAs($rh)->put(route('avisos-sucursal.update'), ['sucursales' => [$this->cuernavaca->id]])->assertRedirect()->assertSessionHasNoErrors();
    expect($rh->sucursalesSilenciadas()->pluck('sucursales.id')->all())->toBe([$this->cuernavaca->id]);

    $eventos = app(NotificacionEventosService::class);
    $aviso = fn (Colaborador $sujeto): Aviso => new Aviso('prueba_alcance', 'prueba_alcance', 'Aviso de prueba', 'Mensaje', $sujeto, 'revisar', 'media', [], uniqid());

    expect($eventos->entregar($rh, CategoriaNotificacion::Pendiente, $aviso($this->deCuernavaca)))->toBeFalse()
        ->and($eventos->entregar($rh, CategoriaNotificacion::Pendiente, $aviso($this->deTula)))->toBeTrue()
        ->and($eventos->entregar($rh, CategoriaNotificacion::Confirmacion, $aviso($this->deCuernavaca)))->toBeTrue();

    // Su propio equipo directo en esa sucursal le sigue llegando.
    $suEquipo = Colaborador::factory()->create(['estatus' => 'activo', 'sucursal_principal_id' => $this->cuernavaca->id, 'jefe_id' => $rh->colaborador_id]);
    expect($eventos->entregar($rh, CategoriaNotificacion::Pendiente, $aviso($suEquipo)))->toBeTrue();

    // Lista vacía = recibir de todas.
    $this->actingAs($rh)->put(route('avisos-sucursal.update'), ['sucursales' => []])->assertRedirect();
    expect($eventos->entregar($rh, CategoriaNotificacion::Pendiente, $aviso($this->deCuernavaca)))->toBeTrue();
});

test('sin el permiso no se configura ni se silencia nada', function () {
    $gerente = clUsuario('gerente_sucursal', ['estatus' => 'activo', 'sucursal_principal_id' => $this->tula->id]);

    $this->actingAs($gerente)->get(route('avisos-sucursal.edit'))->assertForbidden();
    $this->actingAs($gerente)->put(route('avisos-sucursal.update'), ['sucursales' => [$this->tula->id]])->assertForbidden();

    $gerente->sucursalesSilenciadas()->sync([$this->tula->id]);
    $aviso = new Aviso('prueba_alcance', 'prueba_alcance', 'Aviso', 'Mensaje', $this->deTula, 'revisar', 'media', [], uniqid());
    expect(app(NotificacionEventosService::class)->entregar($gerente, CategoriaNotificacion::Pendiente, $aviso))->toBeTrue();
});
