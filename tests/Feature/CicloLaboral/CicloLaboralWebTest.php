<?php

use App\Enums\EstadoAltaColaborador;
use App\Enums\EstadoReingreso;
use App\Enums\EstadoUsuario;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\GeneratedDocument;
use App\Models\Reingreso;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\CicloLaboral\CicloLaboralService;
use App\Services\CicloLaboral\ReingresoService;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;

/*
| Pantallas web (Inertia) del ciclo laboral: rutas de routes/ciclo-laboral.php
| que ya usan las páginas Vue. Mismos services que la API v1.
*/

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-01 10:00:00', 'America/Mexico_City'));
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();
    $this->estructura = clEstructura();
    $this->rh = clUsuario('rh_admin');
    $this->colaborador = Colaborador::factory()->create(['sucursal_principal_id' => $this->estructura['sucursal']->id]);
});

afterEach(fn () => Carbon::setTestNow());

test('las pantallas del ciclo laboral responden para RH', function () {
    $this->actingAs($this->rh);

    $this->get(route('rh.pendientes.index'))->assertOk()->assertInertia(fn (Assert $p) => $p->component('Rh/Pendientes/Index'));
    $this->get(route('rh.colaboradores.ciclo', $this->colaborador))->assertOk()->assertInertia(fn (Assert $p) => $p->component('Rh/Colaboradores/Ciclo')->where('colaborador.id', $this->colaborador->id));
    $this->get(route('rh.reingresos.index'))->assertOk()->assertInertia(fn (Assert $p) => $p->component('Rh/Reingresos/Index'));
    $this->get(route('rh.onboarding.configuracion'))->assertOk()->assertInertia(fn (Assert $p) => $p->component('Rh/Onboarding/Configuracion'));
});

test('un colaborador ve su propio proceso y no las pantallas de RH', function () {
    $usuario = clUsuario('colaborador', ['sucursal_principal_id' => $this->estructura['sucursal']->id, 'estatus' => EstadoUsuario::Activo]);
    $this->actingAs($usuario);

    $this->get(route('portal.mi-proceso'))->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Portal/MiProceso')
        ->has('pendientes')
        ->has('lecciones')
        ->missing('ciclo'));

    $this->get(route('portal.index'))->assertOk()->assertInertia(fn (Assert $p) => $p->has('pendientes.pendientes'));

    $this->get(route('rh.colaboradores.ciclo', $this->colaborador))->assertForbidden();
    $this->get(route('rh.reingresos.index'))->assertForbidden();
});

test('el original físico se controla desde la web pidiendo paquetería, guía y fechas reales, y se escanea antes de archivar', function () {
    clPlantilla('contrato_indeterminado', ['requiere_impresion' => true, 'requiere_firma_fisica' => true, 'requiere_huella' => true]);

    Sanctum::actingAs($this->rh);
    $id = $this->postJson("/api/v1/rh/colaboradores/{$this->colaborador->id}/documentos-laborales", ['clave' => 'contrato_indeterminado'])->assertCreated()->json('data.id');

    $this->actingAs($this->rh);
    $this->post(route('rh.documentos-laborales.imprimir', $id))->assertSessionHasNoErrors();
    $this->post(route('rh.documentos-laborales.firmaFisica', $id), ['huella_registrada' => false])->assertSessionHasErrors('huella_registrada');
    $this->post(route('rh.documentos-laborales.firmaFisica', $id), ['huella_registrada' => true, 'fecha' => '2026-09-29'])->assertSessionHasNoErrors();

    // Sin paquetería ni guía no hay envío.
    $this->post(route('rh.documentos-laborales.envio', $id), [])->assertSessionHasErrors(['paqueteria', 'numero_guia']);
    $this->post(route('rh.documentos-laborales.envio', $id), ['paqueteria' => 'Estafeta', 'numero_guia' => 'GU-1', 'fecha' => '2026-09-30'])->assertSessionHasNoErrors();

    // Nunca una fecha futura.
    $this->post(route('rh.documentos-laborales.recepcion', $id), ['fecha' => '2026-10-05'])->assertSessionHasErrors('fecha');
    $this->post(route('rh.documentos-laborales.recepcion', $id), ['fecha' => '2026-10-01'])->assertSessionHasNoErrors();

    $documento = GeneratedDocument::query()->with('seguimientoFisico')->findOrFail($id);
    expect($documento->seguimientoFisico?->numero_guia)->toBe('GU-1')
        ->and($documento->seguimientoFisico?->firmado_fisico_en?->toDateString())->toBe('2026-09-29')
        ->and($documento->seguimientoFisico?->enviado_en?->toDateString())->toBe('2026-09-30')
        ->and($documento->seguimientoFisico?->recibido_por)->toBe($this->rh->id);

    // La pantalla ofrece escanear (no archivar) un original recibido.
    $this->get(route('rh.colaboradores.ciclo', $this->colaborador))->assertInertia(fn (Assert $p) => $p
        ->where('documentos.0.acciones', ['escaneo']));

    $this->post(route('rh.documentos-laborales.archivar', $id))->assertSessionHasErrors();
    $this->post(route('rh.documentos-laborales.escaneo', $id), ['archivo' => clArchivoPdf('firmado.pdf')])->assertSessionHasNoErrors();
    $this->post(route('rh.documentos-laborales.archivar', $id))->assertSessionHasNoErrors();

    expect($documento->refresh()->estado_flujo?->value)->toBe('archivado');
});

test('solo RH decide un reingreso; quien solo lo solicita recibe 403', function () {
    $gerente = clUsuario('gerente', ['sucursal_principal_id' => $this->estructura['sucursal']->id]);
    $exColaborador = Colaborador::factory()->create([
        'sucursal_principal_id' => $this->estructura['sucursal']->id,
        'estatus' => EstadoUsuario::Inactivo,
        'estado_alta' => EstadoAltaColaborador::Baja,
        'curp' => 'REIW900101HDFPRN01',
    ]);
    $exColaborador->delete();

    $this->actingAs($gerente)
        ->post(route('rh.reingresos.store'), ['colaborador_id' => $exColaborador->id, 'motivo' => 'Buen desempeño previo.'])
        ->assertRedirect(route('rh.reingresos.index'));

    $reingreso = Reingreso::query()->where('colaborador_id', $exColaborador->id)->firstOrFail();

    $this->actingAs($gerente)->post(route('rh.reingresos.decidir', $reingreso), ['viable' => true])->assertForbidden();
    expect($reingreso->refresh()->estado)->toBe(EstadoReingreso::RevisionRh);

    $this->actingAs($this->rh)->post(route('rh.reingresos.decidir', $reingreso), ['viable' => true, 'comentario' => 'Viable.'])->assertSessionHasNoErrors();
    expect($reingreso->refresh()->estado)->toBe(EstadoReingreso::EnContratacion)
        ->and(Colaborador::query()->whereKey($exColaborador->id)->exists())->toBeTrue()
        ->and(Colaborador::withTrashed()->where('curp', $exColaborador->curp)->count())->toBe(1);
});

test('un gerente fuera del alcance no puede solicitar el reingreso de otra sucursal', function () {
    $otra = Sucursal::factory()->create();
    $gerente = clUsuario('gerente', ['sucursal_principal_id' => $otra->id]);
    $exColaborador = Colaborador::factory()->create([
        'sucursal_principal_id' => $this->estructura['sucursal']->id,
        'estatus' => EstadoUsuario::Inactivo,
        'estado_alta' => EstadoAltaColaborador::Baja,
    ]);
    $exColaborador->delete();

    expect(fn () => app(ReingresoService::class)->solicitar(Colaborador::withTrashed()->findOrFail($exColaborador->id), ['motivo' => 'x'], $gerente))
        ->toThrow(AuthorizationException::class);
});

test('el dashboard operativo integra el tablero con embudo por hito máximo y filtros acotados al alcance', function () {
    $sucursal = $this->estructura['sucursal'];
    Candidato::factory()->create(['sucursal_id' => $sucursal->id, 'estado' => 'contratado', 'etapa_maxima' => 11, 'contratado_en' => now()]);
    Candidato::factory()->create(['sucursal_id' => $sucursal->id, 'estado' => 'entrevista_pendiente', 'etapa_maxima' => 2]);

    $this->actingAs($this->rh)
        ->get(route('dashboard', ['tablero_sucursal_id' => $sucursal->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->has('tablero.summary')
            ->where('tablero.filters.sucursal_id', $sucursal->id)
            ->where('tablero.recruitment_funnel.0.total', 2)
            // El contratado también cuenta como que llegó a entrevista y referencias.
            ->where('tablero.recruitment_funnel.5.total', 1)
            ->where('tablero.recruitment_funnel.6.total', 1));

    $gerente = clUsuario('gerente', ['sucursal_principal_id' => $sucursal->id]);
    $otra = Sucursal::factory()->create();

    // Una sucursal fuera de su alcance se ignora (no se filtra a datos ajenos).
    $this->actingAs($gerente)
        ->get(route('dashboard', ['tablero_sucursal_id' => $otra->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('tablero.filters.sucursal_id', null));
});

test('en Mi espacio la persona en ingreso solo ve lo que le toca, sin nombres de etapas', function () {
    $this->seed(DocumentTypeSeeder::class);
    $persona = clColaboradorEnContratacion(['sucursal_principal_id' => $this->estructura['sucursal']->id]);
    $usuario = User::factory()->create(['colaborador_id' => $persona->id]);

    $datos = app(CicloLaboralService::class)->misPendientes($persona, $usuario);

    expect(collect($datos['pendientes'])->pluck('clave'))->toContain('subir_documentos')
        ->and($datos['todo_listo'])->toBeFalse()
        ->and($datos['documentos']['faltantes'])->toBeGreaterThan(0);

    $texto = mb_strtolower((string) json_encode($datos, JSON_UNESCAPED_UNICODE));
    expect($texto)->not->toContain('onboarding')->not->toContain('periodo de prueba')->not->toContain('contratación');
});
