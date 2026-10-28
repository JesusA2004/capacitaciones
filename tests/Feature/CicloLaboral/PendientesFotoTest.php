<?php

use App\Enums\TipoTarea;
use App\Models\Colaborador;
use App\Models\TareaRh;
use App\Services\Colaboradores\FotoColaboradorService;
use App\Services\Tareas\TareaService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Bandeja de Pendientes (web): cada tarjeta muestra la foto oficial del
| expediente de la persona (URL protegida, solo dentro del alcance de quien
| mira); sin foto, iniciales. Nunca viaja `foto_path`.
*/

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake(config('expedientes.disk'));
    Notification::fake();
    $this->estructura = clEstructura();
    $this->rh = clUsuario('rh_admin');
});

function pfColaborador(array $estructura, bool $conFoto): Colaborador
{
    $colaborador = Colaborador::factory()->create(['sucursal_principal_id' => $estructura['sucursal']->id]);

    if ($conFoto) {
        $ruta = sprintf('expedientes/%d/foto/perfil.jpg', $colaborador->id);
        Storage::disk(config('expedientes.disk'))->put($ruta, 'jpeg-falso');
        $colaborador->update(['foto_path' => $ruta]);
    }

    app(TareaService::class)->abrir(TipoTarea::ContratoPendiente, $colaborador, ['colaborador' => $colaborador, 'permiso' => 'documentos_laborales.generar']);

    return $colaborador;
}

/**
 * @return array<int, array<string, mixed>> por colaborador_id
 */
function pfTarjetas($test, $usuario): array
{
    $tarjetas = [];

    $test->actingAs($usuario)->get(route('rh.pendientes.index'))->assertOk()
        ->assertInertia(function (Assert $page) use (&$tarjetas) {
            $tarjetas = collect($page->toArray()['props']['tareas']['data'])
                ->filter(fn (array $t): bool => $t['colaborador'] !== null)
                ->keyBy(fn (array $t): int => $t['colaborador']['id'])
                ->all();

            return $page;
        });

    return $tarjetas;
}

test('cada pendiente trae la foto oficial de la persona, o null si no tiene', function () {
    $conFoto = pfColaborador($this->estructura, true);
    $sinFoto = pfColaborador($this->estructura, false);

    $tarjetas = pfTarjetas($this, $this->rh);

    expect($tarjetas[$conFoto->id]['foto_url'])->toBe(app(FotoColaboradorService::class)->url($conFoto))
        ->and($tarjetas[$sinFoto->id]['foto_url'])->toBeNull();
});

test('fuera del alcance del expediente no se entrega la foto', function () {
    $conFoto = pfColaborador($this->estructura, true);
    $tarea = TareaRh::query()->with('colaborador', 'candidato.colaborador')->where('colaborador_id', $conFoto->id)->firstOrFail();
    $ajeno = clUsuario('colaborador');

    expect(app(TareaService::class)->fotoUrl($tarea, $ajeno))->toBeNull();

    $this->actingAs($ajeno)->get(route('rh.expedientes.foto', $conFoto->id))->assertForbidden();
});

test('la bandeja nunca expone foto_path (ruta física del NAS)', function () {
    $conFoto = pfColaborador($this->estructura, true);

    $html = $this->actingAs($this->rh)->get(route('rh.pendientes.index'))->assertOk()->getContent();

    expect($html)->not->toContain('foto_path')
        ->and($html)->not->toContain((string) $conFoto->foto_path)
        ->and($html)->not->toContain(str_replace('/', '\\/', (string) $conFoto->foto_path));
});
