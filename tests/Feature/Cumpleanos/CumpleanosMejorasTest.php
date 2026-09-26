<?php

use App\Models\BirthdayPhrase;
use App\Models\Colaborador;
use App\Models\User;
use Database\Seeders\BirthdayPhraseSeeder;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $this->seed(BirthdayPhraseSeeder::class);
    Storage::fake('nas');
});

test('el filtro de colaborador acota los listados y el combobox recibe el universo completo sin ese filtro', function () {
    $admin = User::factory()->create();
    $admin->assignRole('rh_admin');

    $buscado = Colaborador::factory()->create(['fecha_nacimiento' => '1990-03-10', 'name' => 'Ana Buscada']);
    $otro = Colaborador::factory()->create(['fecha_nacimiento' => '1990-03-11', 'name' => 'Luis Otro']);

    $this->actingAs($admin)
        ->get(route('rh.cumpleanos.index', ['mes' => 3, 'colaborador_id' => $buscado->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Rh/Cumpleanos/Index')
            ->where('delMes', fn ($delMes) => collect($delMes)->pluck('colaborador_id')->contains($buscado->id)
                && ! collect($delMes)->pluck('colaborador_id')->contains($otro->id))
        );

    $this->actingAs($admin)
        ->get(route('rh.cumpleanos.index', ['mes' => 3]))
        ->assertInertia(fn ($page) => $page
            ->where('opciones.colaboradores', fn ($colaboradores) => collect($colaboradores)->pluck('id')->contains($buscado->id)
                && collect($colaboradores)->pluck('id')->contains($otro->id))
        );
});

test('la configuracion del fondo se puede subir, ver y eliminar solo con permiso', function () {
    $admin = User::factory()->create();
    $admin->assignRole('rh_admin');

    $sinPermiso = User::factory()->create();
    $sinPermiso->assignRole('colaborador');

    $this->actingAs($sinPermiso)->get(route('rh.cumpleanos.configuracion.index'))->assertForbidden();

    $this->actingAs($admin)
        ->get(route('rh.cumpleanos.configuracion.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('tieneFondo', false)->where('fondoUrl', null));

    $archivo = UploadedFile::fake()->image('fondo.png', 1080, 1350);

    $this->actingAs($admin)
        ->post(route('rh.cumpleanos.configuracion.fondo.actualizar'), ['fondo' => $archivo])
        ->assertRedirect();

    $this->actingAs($admin)
        ->get(route('rh.cumpleanos.configuracion.index'))
        ->assertInertia(fn ($page) => $page->where('tieneFondo', true));

    $this->actingAs($admin)->get(route('rh.cumpleanos.configuracion.fondo.ver'))->assertOk();

    $this->actingAs($admin)->delete(route('rh.cumpleanos.configuracion.fondo.eliminar'))->assertRedirect();

    $this->actingAs($admin)
        ->get(route('rh.cumpleanos.configuracion.index'))
        ->assertInertia(fn ($page) => $page->where('tieneFondo', false));
});

test('el fondo personalizado se usa como capa base de la tarjeta generada', function () {
    $admin = User::factory()->create();
    $admin->assignRole('rh_admin');

    $colaborador = Colaborador::factory()->create(['fecha_nacimiento' => now()->subYears(30)]);

    $archivo = UploadedFile::fake()->image('fondo.png', 1080, 1350);
    $this->actingAs($admin)->post(route('rh.cumpleanos.configuracion.fondo.actualizar'), ['fondo' => $archivo]);

    $this->actingAs($admin)
        ->get(route('rh.cumpleanos.felicitacion', $colaborador))
        ->assertOk();
});

test('previsualizar una frase no guarda nada y confirmar-frase si persiste el cambio', function () {
    $admin = User::factory()->create();
    $admin->assignRole('rh_admin');

    $colaborador = Colaborador::factory()->create(['fecha_nacimiento' => now()->subYears(28)]);

    // Genera la felicitacion del dia (idempotente) para tener un punto de partida.
    $this->actingAs($admin)->get(route('rh.cumpleanos.felicitacion', $colaborador))->assertOk();

    $fraseCatalogo = BirthdayPhrase::query()->activas()->first();

    $respuestaPreview = $this->actingAs($admin)->post(
        route('rh.cumpleanos.felicitacion.previsualizar', $colaborador),
        ['frase' => 'Una frase de prueba que no se debe guardar'],
    );
    $respuestaPreview->assertOk();
    expect($respuestaPreview->headers->get('Content-Type'))->toBe('image/png');

    $this->assertDatabaseMissing('birthday_greetings', [
        'user_id' => $colaborador->id,
        'frase' => 'Una frase de prueba que no se debe guardar',
    ]);

    $this->actingAs($admin)
        ->post(route('rh.cumpleanos.felicitacion.confirmar-frase', $colaborador), [
            'frase' => 'Frase confirmada de verdad',
            'birthday_phrase_id' => $fraseCatalogo->id,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('birthday_greetings', [
        'colaborador_id' => $colaborador->id,
        'frase' => 'Frase confirmada de verdad',
        'birthday_phrase_id' => $fraseCatalogo->id,
    ]);
});
