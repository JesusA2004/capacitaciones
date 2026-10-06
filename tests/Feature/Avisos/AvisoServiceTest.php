<?php

use App\Enums\AlcanceAviso;
use App\Enums\EstadoUsuario;
use App\Jobs\SendExpoPushJob;
use App\Models\Aviso;
use App\Models\Colaborador;
use App\Models\MobileDevice;
use App\Models\User;
use App\Services\Avisos\AvisoService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
| Avisos de RH: mensaje + imagen, a toda la empresa o a un colaborador.
| El push se encola por dispositivo (App\Jobs\SendExpoPushJob), nunca se
| llama a Expo directo; aquí solo se prueba que se encola para quien
| corresponde, no el envío real a Expo.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Queue::fake();
});

test('un aviso "a toda la empresa" guarda su imagen y encola push SOLO para colaboradores activos con dispositivo', function () {
    $activo = Colaborador::factory()->create(['estatus' => EstadoUsuario::Activo->value]);
    $usuarioActivo = User::factory()->create(['colaborador_id' => $activo->id]);
    MobileDevice::factory()->create(['user_id' => $usuarioActivo->id, 'push_token' => 'ExponentPushToken[activo]']);

    $inactivo = Colaborador::factory()->create(['estatus' => EstadoUsuario::Inactivo->value]);
    $usuarioInactivo = User::factory()->create(['colaborador_id' => $inactivo->id]);
    MobileDevice::factory()->create(['user_id' => $usuarioInactivo->id, 'push_token' => 'ExponentPushToken[inactivo]']);

    $actor = User::factory()->create();

    $aviso = app(AvisoService::class)->crear(
        ['titulo' => 'Cambio de horario', 'mensaje' => 'La caja cierra 1 hora antes este viernes.', 'alcance' => AlcanceAviso::Todos->value],
        UploadedFile::fake()->image('aviso.jpg', 400, 200),
        $actor,
    );

    expect($aviso->alcance)->toBe(AlcanceAviso::Todos)
        ->and($aviso->tieneImagen())->toBeTrue()
        ->and(Storage::disk('nas')->exists((string) $aviso->imagen_path))->toBeTrue();

    Queue::assertPushed(SendExpoPushJob::class, fn (SendExpoPushJob $job): bool => $job->token === 'ExponentPushToken[activo]');
    Queue::assertNotPushed(SendExpoPushJob::class, fn (SendExpoPushJob $job): bool => $job->token === 'ExponentPushToken[inactivo]');
});

test('un aviso a UN colaborador exige a quién y nunca llega a los demás', function () {
    $actor = User::factory()->create();

    expect(fn () => app(AvisoService::class)->crear(
        ['titulo' => 'Recordatorio', 'mensaje' => 'Falta tu CURP en el expediente.', 'alcance' => AlcanceAviso::Colaborador->value],
        null,
        $actor,
    ))->toThrow(ValidationException::class);

    $destino = Colaborador::factory()->create();
    $otro = Colaborador::factory()->create();

    $aviso = app(AvisoService::class)->crear(
        ['titulo' => 'Recordatorio', 'mensaje' => 'Falta tu CURP.', 'alcance' => AlcanceAviso::Colaborador->value, 'colaborador_objetivo_id' => $destino->id],
        null,
        $actor,
    );

    $paraDestino = app(AvisoService::class)->paraColaborador($destino);
    $paraOtro = app(AvisoService::class)->paraColaborador($otro);

    expect($paraDestino->pluck('id'))->toContain($aviso->id)
        ->and($paraOtro->pluck('id'))->not->toContain($aviso->id);
});

test('paraColaborador(): incluye los de "todos" y los propios, y marcarLeido() solo crea la fila de lectura al abrirlo', function () {
    $colaborador = Colaborador::factory()->create();
    $usuario = User::factory()->create(['colaborador_id' => $colaborador->id]);
    $actor = User::factory()->create();

    $general = Aviso::factory()->create(['alcance' => AlcanceAviso::Todos]);

    $pagina = app(AvisoService::class)->paraColaborador($colaborador);
    $item = $pagina->firstWhere('id', $general->id);

    expect($item)->not->toBeNull()
        ->and($item['leido'])->toBeFalse();

    app(AvisoService::class)->marcarLeido($general, $usuario);

    $despues = app(AvisoService::class)->paraColaborador($colaborador)->firstWhere('id', $general->id);
    expect($despues['leido'])->toBeTrue()
        ->and($general->lecturas()->count())->toBe(1);

    // Idempotente: abrirlo otra vez no duplica la fila.
    app(AvisoService::class)->marcarLeido($general, $usuario);
    expect($general->lecturas()->count())->toBe(1);
});
