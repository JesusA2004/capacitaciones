<?php

use App\Models\AltaDigital;
use App\Models\CambioFotoPerfil;
use App\Models\Colaborador;
use App\Models\User;
use App\Services\Colaboradores\FotoColaboradorService;
use App\Services\Incorporacion\IncorporacionInvitacionService;
use App\Services\Incorporacion\RegistroAppService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * «Fotografía para expediente» = foto de perfil (FotoColaboradorService,
 * docs/FOTO_EXPEDIENTE.md): obligatoria en el registro de app, reemplazo
 * directo mientras el registro está abierto, normalizada también desde el
 * alta pública, y visible a otros solo por alcance/jerarquía (sin IDOR).
 */

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
});

/** @return array{usuario: User, headers: array<string, string>} */
function feRegistrarPorQr(): array
{
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $colaborador = clColaboradorEnContratacion();
    ['token' => $tokenQr] = app(IncorporacionInvitacionService::class)->crear(['colaborador_id' => $colaborador->id], $rh);

    $token = (string) test()->postJson("/api/v1/incorporacion/invitaciones/{$tokenQr}/registrar", [
        'name' => 'Ana',
        'apellidos' => 'Torres',
        'password' => 'Capacitacion2026!',
        'password_confirmation' => 'Capacitacion2026!',
    ])->assertCreated()->json('token');

    return [
        'usuario' => User::query()->where('colaborador_id', $colaborador->id)->firstOrFail(),
        'headers' => ['Authorization' => 'Bearer '.$token, RegistroAppService::CABECERA_CLIENTE => '1', 'Accept' => 'application/json'],
    ];
}

/** Colaborador con una foto (bytes cualquiera) ya guardada en el NAS. */
function feConFoto(array $atributos = []): Colaborador
{
    $colaborador = Colaborador::factory()->create(['estatus' => 'activo', ...$atributos]);
    $ruta = sprintf('expedientes/%d/foto/perfil.jpg', $colaborador->id);
    Storage::disk(config('expedientes.disk'))->put($ruta, 'jpeg-falso');
    $colaborador->update(['foto_path' => $ruta]);

    return $colaborador;
}

test('el estado del registro trae el bloque foto y el documento de fotografías queda cubierto', function () {
    ['headers' => $headers] = feRegistrarPorQr();

    test()->withHeaders($headers)->getJson('/api/v1/colaborador/registro')
        ->assertOk()
        ->assertJsonPath('data.foto.obligatoria', true)
        ->assertJsonPath('data.foto.completo', false)
        ->assertJsonPath('data.foto.url', null)
        ->assertJsonPath('data.foto.pendiente_revision', false)
        ->assertJsonPath('data.puede_finalizar', false);

    test()->withHeaders($headers)->postJson('/api/v1/colaborador/registro/finalizar')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['foto' => 'Agrega tu fotografía para expediente.']);
});

test('durante el registro la foto se reemplaza directo; ya terminado, el cambio queda como propuesta', function () {
    ['usuario' => $usuario, 'headers' => $headers] = feRegistrarPorQr();

    test()->withHeaders($headers)->post('/api/v1/colaborador/foto', ['foto' => UploadedFile::fake()->image('a.jpg', 600, 600)])
        ->assertOk()->assertJsonPath('resultado', 'oficial');
    $primera = $usuario->colaborador->refresh()->foto_path;

    test()->travel(2)->seconds();

    // Registro abierto: corrige su foto directo (sin propuesta para RH).
    test()->withHeaders($headers)->post('/api/v1/colaborador/foto', ['foto' => UploadedFile::fake()->image('b.jpg', 600, 600)])
        ->assertOk()->assertJsonPath('resultado', 'oficial')->assertJsonPath('foto.puede_subir_directo', true);
    $segunda = $usuario->colaborador->refresh()->foto_path;

    expect($segunda)->not->toBe($primera)
        ->and(CambioFotoPerfil::query()->count())->toBe(0)
        ->and(Storage::disk(config('expedientes.disk'))->exists($primera))->toBeTrue();

    // Cerrado el registro, la regla de siempre: propuesta pendiente de RH.
    $invitacion = app(RegistroAppService::class)->invitacionDe($usuario);
    $invitacion->forceFill(['metadata' => [...($invitacion->metadata ?? []), 'registro_app_completado_en' => now()->toIso8601String()]])->save();
    test()->travel(2)->seconds();

    test()->withHeaders($headers)->post('/api/v1/colaborador/foto', ['foto' => UploadedFile::fake()->image('c.jpg', 600, 600)])
        ->assertStatus(202)->assertJsonPath('resultado', 'pendiente');

    expect($usuario->colaborador->refresh()->foto_path)->toBe($segunda)
        ->and(CambioFotoPerfil::query()->where('estado', 'pendiente')->count())->toBe(1);
});

test('la foto del alta pública se normaliza al subirla y al convertir el alta queda como foto oficial 800x800', function () {
    $alta = AltaDigital::factory()->create(['estado' => 'en_captura', 'token_expira_en' => now()->addDays(3)]);

    // PNG vertical: se recorta en cuadro y se guarda como JPEG.
    $this->post(route('alta-publica.foto', $alta->token), ['foto' => UploadedFile::fake()->image('yo.png', 900, 1600)])
        ->assertSessionHasNoErrors();

    $alta->refresh();
    $bytesAlta = (string) Storage::disk(config('altas.disk'))->get((string) $alta->foto_path);
    expect(getimagesizefromstring($bytesAlta))->toMatchArray([0 => 800, 1 => 800, 'mime' => 'image/jpeg']);

    // Una foto cruda (no normalizada) de un alta anterior también se
    // normaliza al convertir: nunca se copia tal cual.
    $crudo = UploadedFile::fake()->image('vieja.jpg', 1200, 700);
    Storage::disk(config('altas.disk'))->put((string) $alta->foto_path, (string) file_get_contents($crudo->getRealPath()));
    $alta->update(['estado' => 'en_revision_rh']);

    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');
    $this->actingAs($rh)->post(route('rh.altas.aprobar', $alta))->assertSessionHasNoErrors();

    $colaborador = Colaborador::query()->findOrFail($alta->refresh()->colaborador_id);
    $bytes = (string) Storage::disk(config('expedientes.disk'))->get((string) $colaborador->foto_path);

    expect(getimagesizefromstring($bytes))->toMatchArray([0 => 800, 1 => 800, 'mime' => 'image/jpeg']);
});

test('un archivo que no es imagen en el alta pública se rechaza con mensaje claro', function () {
    $alta = AltaDigital::factory()->create(['estado' => 'en_captura', 'token_expira_en' => now()->addDays(3)]);

    $this->post(route('alta-publica.foto', $alta->token), ['foto' => UploadedFile::fake()->createWithContent('yo.jpg', 'no soy una imagen')])
        ->assertSessionHasErrors('foto');

    expect($alta->refresh()->foto_path)->toBeNull();
});

test('la ruta de foto para RH respeta el alcance: 200 dentro, 403 fuera', function () {
    $persona = feConFoto();
    $rh = clUsuario('rh_admin');
    $comun = clUsuario('colaborador');

    $this->actingAs($rh, 'sanctum')->get(route('api.v1.rh.colaboradores.foto', $persona))->assertOk();
    $this->actingAs($comun, 'sanctum')->get(route('api.v1.rh.colaboradores.foto', $persona))->assertForbidden();

    // urlRhApi apunta a esta ruta (ya no a la de cumpleaños).
    expect(app(FotoColaboradorService::class)->urlRhApi($persona))->toStartWith(route('api.v1.rh.colaboradores.foto', $persona->id));
});

test('un colaborador común no ve la foto de un compañero, pero sí la de su jefe directo y su equipo', function () {
    $jefe = feConFoto();
    $companero = feConFoto();
    $yo = clUsuario('colaborador', ['jefe_id' => $jefe->id, 'estatus' => 'activo']);
    $subordinado = feConFoto(['jefe_id' => $yo->colaborador_id]);

    $this->actingAs($yo, 'sanctum')->get(route('api.v1.colaboradores.foto', $companero))->assertForbidden();
    $this->actingAs($yo, 'sanctum')->get(route('api.v1.colaboradores.foto', $jefe))->assertOk();
    $this->actingAs($yo, 'sanctum')->get(route('api.v1.colaboradores.foto', $subordinado))->assertOk();

    $fotos = app(FotoColaboradorService::class);
    expect($fotos->urlApiPara($companero, $yo))->toBeNull()
        ->and($fotos->urlApiPara($jefe, $yo))->toStartWith(route('api.v1.colaboradores.foto', $jefe->id));
});

test('el directorio y la lista de colaboradores RH de la API traen foto_url abrible con Bearer', function () {
    $persona = feConFoto(['name' => 'Fotogénica', 'apellidos' => 'Prueba']);
    $rh = clUsuario('rh_admin');

    $fila = collect($this->actingAs($rh, 'sanctum')->getJson('/api/v1/directorio?per_page=50')->assertOk()->json('data'))
        ->firstWhere('id', $persona->id);
    expect($fila)->not->toBeNull()
        ->and($fila['foto_url'])->toStartWith(route('api.v1.colaboradores.foto', $persona->id));

    $filaRh = collect($this->actingAs($rh, 'sanctum')->getJson('/api/v1/rh/colaboradores?per_page=100')->assertOk()->json('data'))
        ->firstWhere('id', $persona->id);
    expect($filaRh)->not->toBeNull()
        ->and($filaRh['foto_url'])->toStartWith(route('api.v1.colaboradores.foto', $persona->id));

    $this->actingAs($rh, 'sanctum')->getJson("/api/v1/rh/colaboradores/{$persona->id}")
        ->assertOk()
        ->assertJsonPath('data.foto_url', app(FotoColaboradorService::class)->urlRhApi($persona));

    // La URL abre con el token de quien la recibió.
    $this->actingAs($rh, 'sanctum')->get((string) $fila['foto_url'])->assertOk();

    // Un colaborador común ve a su compañero en el directorio, sin foto.
    $comun = clUsuario('colaborador');
    $filaComun = collect($this->actingAs($comun, 'sanctum')->getJson('/api/v1/directorio?per_page=50')->assertOk()->json('data'))
        ->firstWhere('id', $persona->id);
    expect($filaComun === null || $filaComun['foto_url'] === null)->toBeTrue();
});
