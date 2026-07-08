<?php

use App\Enums\EstadoWebhook;
use App\Enums\TipoSincronizacionReunion;
use App\Jobs\ProcesarWebhookZoomJob;
use App\Jobs\SincronizarSesionZoomJob;
use App\Models\Curso;
use App\Models\CursoModulo;
use App\Models\Leccion;
use App\Models\SesionEnVivo;
use App\Models\User;
use App\Models\WebhookRecibido;
use Illuminate\Support\Facades\Queue;

/**
 * Cubre qué hace ProcesarWebhookZoomJob con un webhook ya registrado
 * (firma válida, no duplicado): solo `meeting.ended` dispara
 * SincronizarSesionZoomJob, porque la asistencia se calcula con la Report
 * API y no con los datos del propio webhook. Ver
 * docs/AUDITORIA_CUMPLIMIENTO.md sección 2.
 */
beforeEach(function () {
    $curso = Curso::factory()->create();
    $modulo = CursoModulo::factory()->create(['curso_id' => $curso->id]);
    $leccion = Leccion::factory()->create(['curso_modulo_id' => $modulo->id, 'tipo' => 'sesion_en_vivo']);

    $this->sesion = SesionEnVivo::factory()->create([
        'leccion_id' => $leccion->id,
        'proveedor' => 'zoom',
        'id_reunion_externa' => '123456789',
        'creado_por' => User::factory()->create()->id,
    ]);
});

test('un webhook de meeting.ended encuentra la sesion por id externo y encola la sincronizacion', function () {
    Queue::fake();

    $webhook = WebhookRecibido::factory()->create([
        'tipo' => 'meeting.ended',
        'payload_normalizado' => [
            'event' => 'meeting.ended',
            'payload' => ['object' => ['id' => 123456789, 'uuid' => 'uuid-1']],
        ],
    ]);

    (new ProcesarWebhookZoomJob($webhook))->handle();

    expect($webhook->fresh())
        ->estado->toBe(EstadoWebhook::Procesado)
        ->procesado_en->not->toBeNull();

    Queue::assertPushed(
        SincronizarSesionZoomJob::class,
        fn ($job) => $job->sesion->is($this->sesion) && $job->tipo === TipoSincronizacionReunion::Webhook,
    );
});

test('un webhook de participant_joined no dispara sincronizacion pero queda marcado como procesado', function () {
    Queue::fake();

    $webhook = WebhookRecibido::factory()->create([
        'tipo' => 'meeting.participant_joined',
        'payload_normalizado' => [
            'event' => 'meeting.participant_joined',
            'payload' => ['object' => ['id' => 123456789, 'uuid' => 'uuid-1']],
        ],
    ]);

    (new ProcesarWebhookZoomJob($webhook))->handle();

    expect($webhook->fresh()->estado)->toBe(EstadoWebhook::Procesado);
    Queue::assertNotPushed(SincronizarSesionZoomJob::class);
});

test('un meeting.ended sin sesion asociada lanza una excepcion para que el job reintente', function () {
    $webhook = WebhookRecibido::factory()->create([
        'tipo' => 'meeting.ended',
        'payload_normalizado' => [
            'event' => 'meeting.ended',
            'payload' => ['object' => ['id' => 'no-existe', 'uuid' => 'uuid-x']],
        ],
    ]);

    $job = new ProcesarWebhookZoomJob($webhook);

    expect(fn () => $job->handle())->toThrow(RuntimeException::class);
});

test('failed() marca el webhook como error', function () {
    $webhook = WebhookRecibido::factory()->create(['tipo' => 'meeting.ended']);

    (new ProcesarWebhookZoomJob($webhook))->failed(new RuntimeException('boom'));

    expect($webhook->fresh())
        ->estado->toBe(EstadoWebhook::Error)
        ->error->toBe('boom');
});
