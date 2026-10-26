<?php

use App\Enums\EstadoCandidato;
use App\Enums\EstadoVacante;
use App\Enums\TipoTarea;
use App\Models\Candidato;
use App\Models\Puesto;
use App\Models\TareaRh;
use App\Models\Vacante;
use App\Services\Reclutamiento\CandidatoPresenter;
use App\Services\Reclutamiento\CandidatoWorkflowService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
| Recorrido del candidato con un permiso por paso (2026-10-26): «Ver» deja
| seguir todo el proceso, pero cada botón solo aparece (y el backend solo
| acepta) a quien tiene el permiso de ESE paso. Un gerente con solo
| «Registrar entrevista» la registra y ya no avanza los demás pasos; RH con
| el mismo permiso la registra en una contratación directa.
*/

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();

    $this->estructura = clEstructura();
    $this->sucursal = $this->estructura['sucursal'];
    $this->gestor = Puesto::factory()->create(['nombre' => 'Gestor', 'meses_periodo_prueba' => 2, 'grupo_documental' => 'gestor']);
    $base = ['estatus' => 'activo', 'sucursal_principal_id' => $this->sucursal->id];
    $this->rh = clUsuario('rh_admin', $base);

    // Gerente armado en «Roles y permisos» con SOLO ver + registrar entrevista.
    Role::create(['name' => 'gerente_solo_entrevista', 'guard_name' => 'web'])
        ->givePermissionTo(['candidatos.ver', 'candidatos.ver_sucursal', 'candidatos.aprobar_entrevista']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->gerente = clUsuario('gerente_solo_entrevista', $base);

    $this->workflow = app(CandidatoWorkflowService::class);
    $this->nuevo = function (): Candidato {
        $vacante = Vacante::factory()->real()->create([
            'empresa_id' => $this->estructura['empresa']->id, 'sucursal_id' => $this->sucursal->id, 'puesto_id' => $this->gestor->id,
            'estado' => EstadoVacante::Abierta->value, 'plazas_requeridas' => 1, 'plazas_disponibles' => 1, 'plazas_cubiertas' => 0,
        ]);
        $candidato = $this->workflow->registrar([
            'empresa_id' => $this->estructura['empresa']->id, 'sucursal_id' => $this->sucursal->id,
            'departamento_id' => $this->estructura['departamento']->id, 'puesto_objetivo_id' => $this->gestor->id,
            'vacante_id' => $vacante->id, 'nombre' => 'Ana', 'apellidos' => 'Prueba '.uniqid(), 'telefono' => '5512345678',
            'correo' => uniqid().'@example.test', 'fuente' => 'facebook_grupos', 'gerente_involucrado_id' => $this->gerente->id,
        ], $this->rh);

        return $this->workflow->evaluarPerfil($candidato, $this->rh, true, null);
    };
    $this->entrevista = ['realizada_en' => now()->subHour()->toDateTimeString(), 'resultado' => 'viable', 'observaciones' => 'Buena entrevista.'];
});

test('un gerente con solo «Registrar entrevista» la registra y después ya no puede avanzar el proceso', function () {
    $candidato = ($this->nuevo)();

    expect(collect($this->workflow->accionesPermitidas($candidato, $this->gerente))->pluck('clave')->all())->toBe(['registrar_entrevista']);

    $this->actingAs($this->gerente)->post(route('rh.candidatos.entrevista', $candidato), $this->entrevista)->assertRedirect()->assertSessionHasNoErrors();
    expect($candidato->refresh()->estado)->toBe(EstadoCandidato::PsicometricasPendientes);

    // Sigue viendo el recorrido, pero sin botones (ni cerrar el proceso).
    $this->actingAs($this->gerente)->get(route('rh.candidatos.show', $candidato))->assertOk();
    expect($this->workflow->accionesPermitidas($candidato, $this->gerente))->toBe([]);

    // RH lleva el candidato a la revisión del psicométrico.
    $this->workflow->enviarPsicometricas($candidato, $this->rh, 'https://pruebas.example.test/abc');
    $this->workflow->registrarResultadosPsicometricas($candidato->refresh(), $this->rh, 'Perfil adecuado.');
    expect($candidato->refresh()->estado)->toBe(EstadoCandidato::RevisionPsicometricas);

    // El gerente no tiene ese paso: el backend lo rechaza aunque mande la petición.
    $this->actingAs($this->gerente)
        ->post(route('rh.candidatos.psicometricas.revision', $candidato), ['viable' => true])
        ->assertForbidden();

    // Y el pendiente no se le asigna: va a quien tiene el permiso del paso.
    $tarea = TareaRh::query()->where('tipo', TipoTarea::CandidatoRevisionPsicometricas->value)->where('candidato_id', $candidato->id)->whereNull('resuelta_en')->first();
    expect($tarea)->not->toBeNull()
        ->and($tarea->asignado_user_id)->toBeNull()
        ->and($tarea->asignado_permiso)->toBe(CandidatoWorkflowService::PERMISO_REVISAR_PSICOMETRICAS)
        ->and($this->workflow->responsableActual($candidato)['rol'])->toBe('Reclutamiento');
});

test('RH con «Registrar entrevista» la registra en una contratación directa', function () {
    $candidato = ($this->nuevo)();

    expect(collect($this->workflow->accionesPermitidas($candidato, $this->rh))->pluck('clave'))->toContain('registrar_entrevista');
    $this->actingAs($this->rh)->post(route('rh.candidatos.entrevista', $candidato), $this->entrevista)->assertRedirect()->assertSessionHasNoErrors();
    expect($candidato->refresh()->estado)->toBe(EstadoCandidato::PsicometricasPendientes);
});

test('los roles sembrados conservan los pasos que cubrían los permisos generales', function () {
    $gerente = Role::findByName('gerente_sucursal');
    $rh = Role::findByName('rh_admin');

    foreach (CandidatoWorkflowService::PERMISOS_PASOS_GERENTE as $permiso) {
        expect($gerente->hasPermissionTo($permiso))->toBeTrue();
    }

    foreach (CandidatoWorkflowService::PERMISOS_PASOS as $permiso) {
        expect($rh->hasPermissionTo($permiso))->toBeTrue();
    }

    expect($gerente->hasPermissionTo('candidatos.revisar_perfil'))->toBeFalse()
        ->and($gerente->hasPermissionTo('candidatos.cerrar_proceso'))->toBeTrue();
});

test('el socioeconómico guarda el tiempo en la vivienda en años y meses', function () {
    $candidato = ($this->nuevo)();
    $this->workflow->registrarEntrevista($candidato, $this->rh, $this->entrevista);
    $this->workflow->enviarPsicometricas($candidato->refresh(), $this->rh, 'https://pruebas.example.test/abc');
    $this->workflow->registrarResultadosPsicometricas($candidato->refresh(), $this->rh, 'Perfil adecuado.');
    $this->workflow->revisarPsicometricas($candidato->refresh(), $this->rh, true, null);
    expect($candidato->refresh()->estado)->toBe(EstadoCandidato::SocioeconomicoPendiente);

    $datos = ['fecha_visita' => now()->toDateString(), 'direccion' => 'Calle 1, Cuernavaca'];

    $this->actingAs($this->rh)
        ->post(route('rh.candidatos.socioeconomico', $candidato), [...$datos, 'checklist' => ['arraigo_anios' => 3, 'arraigo_meses' => 12]])
        ->assertSessionHasErrors('checklist.arraigo_meses');

    $this->actingAs($this->rh)
        ->post(route('rh.candidatos.socioeconomico', $candidato), [...$datos, 'checklist' => ['vivienda_en_orden' => true, 'arraigo_anios' => 3, 'arraigo_meses' => 4]])
        ->assertRedirect()->assertSessionHasNoErrors();

    $detalle = app(CandidatoPresenter::class)->detalle($candidato->refresh());
    $criterios = collect($detalle['socioeconomicos'][0]['criterios'])->keyBy('clave');

    expect($criterios['arraigo']['valor'])->toBe('3 años 4 meses')
        ->and($criterios['vivienda_en_orden']['valor'])->toBe('Sí');
});

test('el tiempo en la vivienda se escribe bien en español', function (mixed $anios, mixed $meses, string $esperado) {
    expect(CandidatoPresenter::tiempoEnVivienda($anios, $meses))->toBe($esperado);
})->with([
    [3, 4, '3 años 4 meses'],
    [1, 0, '1 año'],
    [0, 1, '1 mes'],
    [0, 8, '8 meses'],
    [2.5, null, '2 años 6 meses'],
    [null, null, 'Menos de un mes'],
]);
