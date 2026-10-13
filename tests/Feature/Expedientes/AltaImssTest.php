<?php

use App\Enums\EstadoUsuario;
use App\Enums\EstatusImss;
use App\Models\Colaborador;
use App\Models\TareaRh;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * Acuse de alta IMSS: al concluir el alta RH sube el archivo. Indicador
 * verde (registrado) o naranja (falta) en el expediente y en la lista — sin
 * abrir ningún pendiente en la bandeja.
 */

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake(config('expedientes.disk'));
});

test('activo sin acuse = naranja; RH sube el acuse = verde, con IMSS y fecha de alta, sin pendientes', function () {
    $rh = User::factory()->create(['colaborador_id' => Colaborador::factory()->create()->id]);
    $rh->assignRole('rh_admin');
    $persona = Colaborador::factory()->create(['estatus' => EstadoUsuario::Activo->value, 'fecha_alta_imss' => null]);

    $this->actingAs($rh)->get(route('rh.expedientes.show', $persona))
        ->assertInertia(fn ($page) => $page->where('colaborador.imss_alta', 'pendiente')->where('colaborador.puede_subir_imss', true));

    $tareasAntes = TareaRh::query()->count();

    $this->actingAs($rh)->post(route('rh.expedientes.alta-imss.store', $persona), [
        'archivo' => UploadedFile::fake()->create('acuse.pdf', 30, 'application/pdf'),
    ])->assertSessionHasNoErrors();

    $persona->refresh();
    expect($persona->alta_imss_path)->not->toBeNull()
        ->and($persona->estatus_imss)->toBe(EstatusImss::ConImss)
        ->and($persona->fecha_alta_imss)->not->toBeNull()
        ->and(TareaRh::query()->count())->toBe($tareasAntes);

    $this->actingAs($rh)->get(route('rh.expedientes.show', $persona))
        ->assertInertia(fn ($page) => $page->where('colaborador.imss_alta', 'registrada'));
    $this->actingAs($rh)->get(route('rh.expedientes.alta-imss.show', $persona))->assertOk();
});

test('quien no edita expedientes no puede subir el acuse', function () {
    $colaborador = User::factory()->create(['colaborador_id' => Colaborador::factory()->create()->id]);
    $colaborador->assignRole('colaborador');
    $persona = Colaborador::factory()->create(['estatus' => EstadoUsuario::Activo->value]);

    $this->actingAs($colaborador)->post(route('rh.expedientes.alta-imss.store', $persona), [
        'archivo' => UploadedFile::fake()->create('acuse.pdf', 30, 'application/pdf'),
    ])->assertForbidden();
});
