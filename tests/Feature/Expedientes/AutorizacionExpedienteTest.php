<?php

use App\Models\Colaborador;
use App\Models\EmployeeDocument;
use App\Models\Sucursal;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
 * Auditoría de autorización del expediente: baja administrativa, foto,
 * revisión de documentos (web = API) y alcance de la API móvil.
 */

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
});

function usuarioEnSucursal(Sucursal $sucursal, string $rol): User
{
    $usuario = User::factory()->for(Colaborador::factory()->state(['sucursal_principal_id' => $sucursal->id]), 'colaborador')->create();
    $usuario->assignRole($rol);

    return $usuario;
}

test('sistemas no puede dar de baja a un colaborador desde el expediente', function () {
    $sistemas = User::factory()->create();
    $sistemas->assignRole('sistemas');
    $colaborador = Colaborador::factory()->create();

    $this->actingAs($sistemas)
        ->delete(route('rh.expedientes.dar-de-baja', $colaborador))
        ->assertForbidden();

    expect($colaborador->fresh()->trashed())->toBeFalse();
});

test('rh_admin da de baja desde el expediente y ve la acción separada de la gestión de acceso', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    // Contraste: un rol que SÍ consulta el expediente (lectura global) pero NO
    // puede dar de baja. `auditor` es solo lectura; `sistemas` ya no sirve de
    // contraste aquí porque, por mínimo privilegio (2026-10-16), tampoco ve el
    // expediente con PII (recibiría 403, no 200).
    $auditor = User::factory()->create();
    $auditor->assignRole('auditor');
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');

    $this->actingAs($auditor)
        ->get(route('rh.expedientes.show', $colaborador->colaborador_id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('puedeDarDeBaja', false));

    $this->actingAs($rh)
        ->get(route('rh.expedientes.show', $colaborador->colaborador_id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('puedeDarDeBaja', true));

    $this->actingAs($rh)
        ->delete(route('rh.expedientes.dar-de-baja', $colaborador->colaborador_id))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Colaborador::withTrashed()->findOrFail($colaborador->colaborador_id)->trashed())->toBeTrue();
});

test('la baja administrativa respeta el alcance del expediente', function () {
    $sucursalA = Sucursal::factory()->create();
    $sucursalB = Sucursal::factory()->create();
    $gerente = usuarioEnSucursal($sucursalA, 'gerente_sucursal');
    $gerente->givePermissionTo('cierres.ejecutar_baja');
    $ajeno = Colaborador::factory()->create(['sucursal_principal_id' => $sucursalB->id]);

    $this->actingAs($gerente)
        ->delete(route('rh.expedientes.dar-de-baja', $ajeno))
        ->assertForbidden();

    expect($ajeno->fresh()->trashed())->toBeFalse();
});

test('el colaborador no cambia su foto por la ruta de RH: usa la suya con aprobación', function () {
    Storage::fake(config('expedientes.disk'));
    $usuario = User::factory()->create();
    $usuario->assignRole('colaborador');

    $this->actingAs($usuario)
        ->post(route('rh.expedientes.foto.store', $usuario->colaborador_id), ['foto' => UploadedFile::fake()->image('yo.jpg', 400, 400)])
        ->assertForbidden();

    expect($usuario->colaborador->fresh()->foto_path)->toBeNull();
});

test('aprobar un documento en la web usa el servicio y notifica al colaborador', function () {
    Http::fake();
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    $colaborador = User::factory()->create();
    $documento = EmployeeDocument::factory()->create(['user_id' => $colaborador->id, 'status' => 'en_revision']);

    $this->actingAs($rh)
        ->post(route('rh.documentos.aprobar', $documento))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($documento->fresh()->status->value)->toBe('aprobado')
        ->and($documento->fresh()->reviewed_by)->toBe($rh->id)
        ->and($colaborador->fresh()->notifications()->count())->toBe(1);
});

test('pedir corrección en la web deja el documento en corrección y notifica al colaborador', function () {
    Http::fake();
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    $colaborador = User::factory()->create();
    $documento = EmployeeDocument::factory()->create(['user_id' => $colaborador->id, 'status' => 'en_revision']);

    $this->actingAs($rh)
        ->post(route('rh.documentos.solicitar-correccion', $documento), ['comments' => 'Foto borrosa'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($documento->fresh()->status->value)->toBe('requiere_correccion')
        ->and($documento->fresh()->comments)->toBe('Foto borrosa')
        ->and($colaborador->fresh()->notifications()->count())->toBe(1);
});

test('el permiso móvil de aprobar documentos también vale en la web y el web en la API', function () {
    Http::fake();
    $colaborador = User::factory()->create();

    $soloApp = User::factory()->create();
    $soloApp->assignRole('colaborador');
    $soloApp->givePermissionTo(['rh.expedientes.documentos.aprobar', 'expedientes.ver_todos']);
    $documentoWeb = EmployeeDocument::factory()->create(['user_id' => $colaborador->id, 'status' => 'en_revision']);

    $this->actingAs($soloApp)
        ->post(route('rh.documentos.aprobar', $documentoWeb))
        ->assertRedirect();
    expect($documentoWeb->fresh()->status->value)->toBe('aprobado');

    $soloWeb = User::factory()->create();
    $soloWeb->assignRole('colaborador');
    $soloWeb->givePermissionTo(['documentos.revisar', 'documentos.aprobar', 'expedientes.ver_todos']);
    $documentoApp = EmployeeDocument::factory()->create(['user_id' => $colaborador->id, 'status' => 'en_revision']);

    $this->withHeaders(['Authorization' => 'Bearer '.$soloWeb->createToken('test')->plainTextToken])
        ->postJson("/api/v1/rh/documentos/{$documentoApp->id}/aprobar")
        ->assertOk()
        ->assertJsonPath('data.estado', 'aprobado');
});

test('nadie aprueba su propio documento, ni por la web ni por la API', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    $documento = EmployeeDocument::factory()->create(['user_id' => $rh->id, 'status' => 'en_revision']);

    $this->actingAs($rh)->post(route('rh.documentos.aprobar', $documento))->assertForbidden();
    $this->withHeaders(['Authorization' => 'Bearer '.$rh->createToken('test')->plainTextToken])
        ->postJson("/api/v1/rh/documentos/{$documento->id}/aprobar")
        ->assertForbidden();

    expect($documento->fresh()->status->value)->toBe('en_revision');
});

test('la API de expedientes usa el mismo alcance que la web', function () {
    $sucursalA = Sucursal::factory()->create();
    $sucursalB = Sucursal::factory()->create();
    $gerente = usuarioEnSucursal($sucursalA, 'gerente_sucursal');
    $propio = Colaborador::factory()->create(['sucursal_principal_id' => $sucursalA->id]);
    $ajeno = Colaborador::factory()->create(['sucursal_principal_id' => $sucursalB->id]);
    $token = 'Bearer '.$gerente->createToken('test')->plainTextToken;

    // Sin permisos rh.expedientes.*: entra con el criterio web (expedientes.ver_sucursal).
    $ids = collect($this->withHeaders(['Authorization' => $token])
        ->getJson('/api/v1/rh/expedientes')
        ->assertOk()
        ->json('data'))->pluck('id');

    expect($ids)->toContain($propio->id)->not->toContain($ajeno->id);

    $this->withHeaders(['Authorization' => $token])->getJson("/api/v1/rh/expedientes/{$propio->id}")->assertOk();
    $this->withHeaders(['Authorization' => $token])->getJson("/api/v1/rh/expedientes/{$ajeno->id}")->assertForbidden();

    // La web, mismo resultado.
    $this->actingAs($gerente)->get(route('rh.expedientes.show', $ajeno))->assertForbidden();
});

test('rh_admin conserva el acceso a la API de expedientes', function () {
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    $colaborador = Colaborador::factory()->create();
    $token = 'Bearer '.$rh->createToken('test')->plainTextToken;

    $this->withHeaders(['Authorization' => $token])->getJson('/api/v1/rh/expedientes')->assertOk();
    $this->withHeaders(['Authorization' => $token])->getJson("/api/v1/rh/expedientes/{$colaborador->id}")->assertOk();
});

test('un colaborador sin permisos no entra a la API de expedientes de RH', function () {
    $usuario = User::factory()->create();
    $usuario->assignRole('colaborador');

    $this->withHeaders(['Authorization' => 'Bearer '.$usuario->createToken('test')->plainTextToken])
        ->getJson('/api/v1/rh/expedientes')
        ->assertForbidden();
});

test('editar datos laborales exige alcance sobre el expediente', function () {
    $sucursalA = Sucursal::factory()->create();
    $sucursalB = Sucursal::factory()->create();
    $gerente = usuarioEnSucursal($sucursalA, 'gerente_sucursal');
    $gerente->givePermissionTo('expedientes.editar');
    $ajeno = Colaborador::factory()->create(['sucursal_principal_id' => $sucursalB->id]);
    $propio = Colaborador::factory()->create(['sucursal_principal_id' => $sucursalA->id]);

    $this->actingAs($gerente)
        ->put(route('rh.expedientes.datos-laborales.update', $ajeno), ['sucursal_principal_id' => $sucursalA->id])
        ->assertForbidden();

    expect($ajeno->fresh()->sucursal_principal_id)->toBe($sucursalB->id);

    $this->actingAs($gerente)
        ->put(route('rh.expedientes.datos-laborales.update', $propio), ['sucursal_principal_id' => $sucursalA->id])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
});
