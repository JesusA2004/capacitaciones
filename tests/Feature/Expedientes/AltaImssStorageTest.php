<?php

use App\Enums\EstadoUsuario;
use App\Models\Colaborador;
use App\Models\Sucursal;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

/*
 * Almacenamiento del acuse de alta IMSS: dónde queda el archivo, qué ve (y
 * qué NO ve) el frontend, quién puede subirlo/verlo y que reemplazarlo no
 * borra el anterior. Complementa AltaImssTest.php (que cubre el indicador
 * naranja→verde, estatus_imss/fecha y la ausencia de pendientes).
 */

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake(config('expedientes.disk'));
});

function aiDisco()
{
    return Storage::disk(config('expedientes.disk'));
}

test('RH con alcance sube un PDF y una imagen (PNG) y ambos quedan en el disco del expediente', function () {
    $rh = clUsuario('rh_admin');
    $persona = Colaborador::factory()->create(['estatus' => EstadoUsuario::Activo->value]);

    $this->actingAs($rh)->post(route('rh.expedientes.alta-imss.store', $persona), [
        'archivo' => UploadedFile::fake()->create('acuse.pdf', 20, 'application/pdf'),
    ])->assertSessionHasNoErrors();

    $pathPdf = $persona->refresh()->alta_imss_path;
    expect($pathPdf)->not->toBeNull()
        ->and(strtolower(pathinfo($pathPdf, PATHINFO_EXTENSION)))->toBe('pdf');
    aiDisco()->assertExists($pathPdf);

    // El acuse también se acepta como imagen (JPG/PNG), no solo PDF.
    $this->actingAs($rh)->post(route('rh.expedientes.alta-imss.store', $persona), [
        'archivo' => UploadedFile::fake()->image('acuse.png', 10, 10),
    ])->assertSessionHasNoErrors();

    $pathPng = $persona->refresh()->alta_imss_path;
    expect(strtolower(pathinfo($pathPng, PATHINFO_EXTENSION)))->toBe('png');
    aiDisco()->assertExists($pathPng);
});

test('el payload del expediente no expone la ruta física: trae la URL de la ruta nombrada show', function () {
    $rh = clUsuario('rh_admin');
    $persona = Colaborador::factory()->create(['estatus' => EstadoUsuario::Activo->value]);

    $this->actingAs($rh)->post(route('rh.expedientes.alta-imss.store', $persona), [
        'archivo' => UploadedFile::fake()->create('acuse.pdf', 20, 'application/pdf'),
    ])->assertSessionHasNoErrors();

    $path = (string) $persona->refresh()->alta_imss_path;

    $colaborador = $this->actingAs($rh)->get(route('rh.expedientes.show', $persona))
        ->assertOk()->viewData('page')['props']['colaborador'];

    expect($colaborador['imss_alta_archivo']['url'])->toBe(route('rh.expedientes.alta-imss.show', $persona->id))
        // La ruta física del NAS nunca sale al cliente; solo la URL protegida.
        ->and(json_encode($colaborador))->not->toContain($path);
});

test('un RH fuera de alcance (otra sucursal) no puede subir ni ver el acuse (403)', function () {
    $sucursalA = Sucursal::factory()->create();
    $sucursalB = Sucursal::factory()->create();
    $persona = Colaborador::factory()->create(['estatus' => EstadoUsuario::Activo->value, 'sucursal_principal_id' => $sucursalA->id]);

    // Un RH global sube el acuse para que exista algo que intentar ver.
    $this->actingAs(clUsuario('rh_admin'))->post(route('rh.expedientes.alta-imss.store', $persona), [
        'archivo' => UploadedFile::fake()->create('acuse.pdf', 20, 'application/pdf'),
    ])->assertSessionHasNoErrors();

    // RH con permiso de edición pero alcance limitado a SU sucursal (B).
    $rol = Role::query()->create(['name' => 'rh_sucursal_prueba', 'guard_name' => 'web']);
    $rol->givePermissionTo(['expedientes.ver', 'expedientes.ver_sucursal', 'expedientes.editar']);
    $rhAjeno = clUsuario($rol->name, ['sucursal_principal_id' => $sucursalB->id]);

    $this->actingAs($rhAjeno)->post(route('rh.expedientes.alta-imss.store', $persona), [
        'archivo' => UploadedFile::fake()->create('otro.pdf', 20, 'application/pdf'),
    ])->assertForbidden();

    // El endpoint show también exige puedeVerExpediente (alcance), no solo login.
    $this->actingAs($rhAjeno)->get(route('rh.expedientes.alta-imss.show', $persona))->assertForbidden();
});

test('el propio colaborador no puede subir su alta IMSS (403)', function () {
    $colaborador = Colaborador::factory()->create(['estatus' => EstadoUsuario::Activo->value]);
    $usuario = User::factory()->create(['colaborador_id' => $colaborador->id]);
    $usuario->assignRole('colaborador');

    $this->actingAs($usuario)->post(route('rh.expedientes.alta-imss.store', $colaborador), [
        'archivo' => UploadedFile::fake()->create('acuse.pdf', 20, 'application/pdf'),
    ])->assertForbidden();
});

test('reemplazar el acuse conserva el anterior con sufijo (2) en el mismo expediente', function () {
    $rh = clUsuario('rh_admin');
    $persona = Colaborador::factory()->create(['estatus' => EstadoUsuario::Activo->value]);

    $this->actingAs($rh)->post(route('rh.expedientes.alta-imss.store', $persona), [
        'archivo' => UploadedFile::fake()->create('acuse.pdf', 20, 'application/pdf'),
    ])->assertSessionHasNoErrors();
    $primero = (string) $persona->refresh()->alta_imss_path;

    $this->actingAs($rh)->post(route('rh.expedientes.alta-imss.store', $persona), [
        'archivo' => UploadedFile::fake()->create('acuse.pdf', 20, 'application/pdf'),
    ])->assertSessionHasNoErrors();
    $segundo = (string) $persona->refresh()->alta_imss_path;

    expect($segundo)->not->toBe($primero);
    // El anterior NO se borra; el nuevo lleva el sufijo (2).
    aiDisco()->assertExists($primero);
    aiDisco()->assertExists($segundo);
    expect(basename($segundo))->toContain('(2)');

    $carpeta = dirname($primero);
    $acuses = collect(aiDisco()->files($carpeta))->filter(fn (string $f): bool => str_contains(basename($f), 'Acuse alta IMSS'));
    expect($acuses)->toHaveCount(2);
});

test('al registrar el acuse queda auditoría alta_imss_registrada', function () {
    $rh = clUsuario('rh_admin');
    $persona = Colaborador::factory()->create(['estatus' => EstadoUsuario::Activo->value]);

    $this->actingAs($rh)->post(route('rh.expedientes.alta-imss.store', $persona), [
        'archivo' => UploadedFile::fake()->create('acuse.pdf', 20, 'application/pdf'),
    ])->assertSessionHasNoErrors();

    expect(Activity::query()->where('event', 'alta_imss_registrada')->exists())->toBeTrue();
});
