<?php

use App\Models\MobileAppRelease;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('nas');
});

test('la api de latest regresa la version android publicada con download_url', function () {
    $release = MobileAppRelease::factory()->publicada()->create(['version' => '3.1.0']);

    $respuesta = $this->getJson('/api/v1/app/releases/latest?platform=android')
        ->assertOk()
        ->assertJsonPath('data.version', '3.1.0');

    expect($respuesta->json('data.download_url'))->toContain('/app/descargar/android')
        ->and($respuesta->json('data.sha256'))->toBe($release->sha256);
});

test('la api de latest regresa data null cuando no hay version ios publicada', function () {
    $this->getJson('/api/v1/app/releases/latest?platform=ios')
        ->assertNotFound()
        ->assertJsonPath('data', null);
});

test('la api de config expone version minima por plataforma y features de cumpleanos/rh_mobile', function () {
    $respuesta = $this->getJson('/api/v1/app/config')
        ->assertOk()
        ->assertJsonStructure([
            'maintenance', 'minimum_version', 'latest_version', 'force_update',
            'minimum_android_version', 'minimum_android_build',
            'minimum_ios_version', 'minimum_ios_build',
            'features', 'download_url', 'update_url',
            'ios' => ['install_url', 'store_url'],
        ]);

    expect($respuesta->json('features.rh_mobile'))->toBeTrue()
        ->and($respuesta->json('features'))->toHaveKey('cumpleanos');
});
