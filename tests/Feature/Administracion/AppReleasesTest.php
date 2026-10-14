<?php

use App\Models\MobileAppRelease;
use App\Models\User;
use App\Support\LimitesSubida;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
});

test('un admin con permiso puede subir una version apk', function () {
    $admin = User::factory()->create();
    $admin->assignRole('rh_admin');

    $archivo = UploadedFile::fake()->create('mr-lana-people.apk', 20_000);

    $this->actingAs($admin)
        ->post(route('administracion.app-releases.store'), [
            'apk' => $archivo,
            'version' => '1.2.0',
            'build_number' => '5',
            'changelog' => 'Mejoras iniciales.',
        ])
        ->assertRedirect();

    $release = MobileAppRelease::query()->where('version', '1.2.0')->firstOrFail();
    expect($release->platform->value)->toBe('android')
        ->and($release->is_published)->toBeFalse()
        ->and($release->sha256)->not->toBeNull()
        ->and($release->file_path)->not->toBeNull();

    Storage::disk('nas')->assertExists($release->file_path);
});

test('un usuario sin permiso no puede subir una version apk', function () {
    $colaborador = User::factory()->create();
    $colaborador->assignRole('colaborador');

    $archivo = UploadedFile::fake()->create('mr-lana-people.apk', 1000);

    $this->actingAs($colaborador)
        ->post(route('administracion.app-releases.store'), [
            'apk' => $archivo,
            'version' => '1.0.0',
        ])
        ->assertForbidden();
});

test('subir un archivo sin extension apk falla la validacion', function () {
    $admin = User::factory()->create();
    $admin->assignRole('rh_admin');

    $archivo = UploadedFile::fake()->create('app.zip', 1000);

    $this->actingAs($admin)
        ->post(route('administracion.app-releases.store'), [
            'apk' => $archivo,
            'version' => '1.0.0',
        ])
        ->assertSessionHasErrors('apk');
});

test('publicar una version la marca como latest y desmarca la anterior', function () {
    $admin = User::factory()->create();
    $admin->assignRole('rh_admin');

    $anterior = MobileAppRelease::factory()->publicada()->create(['version' => '1.0.0']);
    $nueva = MobileAppRelease::factory()->conArchivo()->create(['version' => '1.1.0']);

    $this->actingAs($admin)
        ->post(route('administracion.app-releases.publicar', $nueva))
        ->assertRedirect();

    expect($nueva->fresh()->is_published)->toBeTrue()
        ->and($nueva->fresh()->is_latest)->toBeTrue()
        ->and($anterior->fresh()->is_latest)->toBeFalse();
});

test('la pagina publica de descarga muestra la version publicada mas reciente', function () {
    MobileAppRelease::factory()->publicada()->create(['version' => '2.0.0', 'changelog' => 'Notas de la 2.0.0']);

    $this->get('/app')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('App/Index')
            ->where('downloadEnabled', true)
            ->where('latest.version', '2.0.0'));
});

test('la pagina publica de descarga informa cuando no hay version publicada', function () {
    $this->get('/app')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('latest', null));
});

test('una version no publicada no se puede descargar desde la ruta publica', function () {
    MobileAppRelease::factory()->conArchivo()->create(['is_published' => false, 'is_latest' => false]);

    $this->get('/app/descargar/android')->assertNotFound();
});

test('una version publicada si se puede descargar desde la ruta publica y el archivo pasa por el controller', function () {
    $release = MobileAppRelease::factory()->publicada()->create();
    Storage::disk('nas')->put($release->file_path, 'contenido-apk-de-prueba');

    $respuesta = $this->get('/app/descargar/android')->assertOk();

    expect($respuesta->headers->get('content-disposition'))->toContain($release->version)
        ->and($respuesta->headers->get('content-disposition'))->not->toContain(storage_path());
});

test('el listado admin de versiones nunca expone file_path al frontend', function () {
    $admin = User::factory()->create();
    $admin->assignRole('rh_admin');

    MobileAppRelease::factory()->conArchivo()->create();

    $respuesta = $this->actingAs($admin)->get(route('administracion.app-releases.index'))->assertOk();

    $respuesta->assertInertia(function ($page) {
        $releases = $page->toArray()['props']['releases'];
        foreach ($releases as $fila) {
            expect($fila)->not->toHaveKey('file_path');
        }
    });
});

test('un admin puede eliminar una version y su archivo', function () {
    $admin = User::factory()->create();
    $admin->assignRole('rh_admin');

    $release = MobileAppRelease::factory()->conArchivo()->create();
    Storage::disk('nas')->put($release->file_path, 'contenido-apk-de-prueba');

    $this->actingAs($admin)
        ->delete(route('administracion.app-releases.destroy', $release))
        ->assertRedirect();

    expect(MobileAppRelease::find($release->id))->toBeNull();
    Storage::disk('nas')->assertMissing($release->file_path);
});

/*
 * Error 413 al subir el APK: un APK > MOBILE_APK_MAX_MB (250 MB) debe recibir
 * la validación amigable de Laravel (Nginx 320M y PHP 300M/320M lo dejan
 * pasar), nunca la página cruda «413 Content Too Large». El tamaño se falsea
 * (UploadedFile::fake reporta KB sin escribir 251 MB a disco).
 */
test('un apk de mas de 250 MB recibe un mensaje de validacion amigable', function () {
    config(['mobile_releases.max_upload_mb' => 250]);
    $admin = User::factory()->create();
    $admin->assignRole('rh_admin');

    $this->actingAs($admin)
        ->post(route('administracion.app-releases.store'), [
            'apk' => UploadedFile::fake()->create('mr-lana-people.apk', 251 * 1024),
            'version' => '9.9.9',
        ])
        ->assertSessionHasErrors(['apk' => 'El APK supera el máximo permitido de 250 MB.']);

    expect(MobileAppRelease::query()->where('version', '9.9.9')->exists())->toBeFalse();
});

test('un apk de 132 MiB cabe dentro del limite de la aplicacion', function () {
    config(['mobile_releases.max_upload_mb' => 250]);
    $admin = User::factory()->create();
    $admin->assignRole('rh_admin');

    $this->actingAs($admin)
        ->post(route('administracion.app-releases.store'), [
            'apk' => UploadedFile::fake()->create('mr-lana-people.apk', intdiv(138_379_654, 1024)),
            'version' => '1.3.0',
        ])
        ->assertSessionHasNoErrors();

    expect(MobileAppRelease::query()->where('version', '1.3.0')->exists())->toBeTrue();
});

test('si PHP rechaza el cuerpo por post_max_size se muestra la pagina amigable, no la cruda', function () {
    config(['app.debug' => false]);
    $admin = User::factory()->create();
    $admin->assignRole('rh_admin');

    // Simula lo que hace ValidatePostSize cuando CONTENT_LENGTH supera el
    // post_max_size real de PHP-FPM.
    $this->actingAs($admin)
        ->call('POST', route('administracion.app-releases.store'), [], [], [], ['CONTENT_LENGTH' => (string) (2 * 1024 * 1024 * 1024 * 1024)])
        ->assertStatus(413)
        ->assertInertia(fn ($page) => $page
            ->component('Error')
            ->where('status', 413)
            ->where('mensaje', fn (string $mensaje) => str_contains($mensaje, 'post_max_size')));
});

test('el panel de versiones muestra los limites reales de PHP y el limite efectivo', function () {
    $admin = User::factory()->create();
    $admin->assignRole('rh_admin');

    $this->actingAs($admin)
        ->get(route('administracion.app-releases.index'))
        ->assertInertia(fn ($page) => $page
            ->has('limitesServidor.post_max_size')
            ->has('limitesServidor.upload_max_filesize')
            ->where('limiteEfectivoMb', LimitesSubida::efectivoMb((int) config('mobile_releases.max_upload_mb'))));
});

test('LimitesSubida interpreta los valores de php.ini', function () {
    expect(LimitesSubida::bytes('320M'))->toBe(320 * 1024 * 1024)
        ->and(LimitesSubida::bytes('1G'))->toBe(1024 * 1024 * 1024)
        ->and(LimitesSubida::bytes('512K'))->toBe(512 * 1024)
        ->and(LimitesSubida::bytes('0'))->toBeNull();
});
