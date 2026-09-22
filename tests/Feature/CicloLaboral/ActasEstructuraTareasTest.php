<?php

use App\Enums\EstadoCandidato;
use App\Enums\TipoTarea;
use App\Models\CampanaReclutamiento;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\HeadcountTarget;
use App\Models\Puesto;
use App\Models\TareaRh;
use App\Models\User;
use App\Services\Tareas\TareaService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();
    $this->rh = clUsuario('rh_admin');
    $this->estructura = clEstructura();
});

test('actas: captura, anexos, formato por motor documental, negativa a firmar y cierre; el implicado no puede verla', function () {
    Sanctum::actingAs($this->rh);
    clPlantilla('acta_hechos', ['contenido_html' => '<h1>{{tipo_acta}} {{folio_acta}}</h1><p>{{hechos_acta}}</p><p>Testigos: {{testigos_acta}}</p>', 'requiere_testigos' => true]);
    $colaborador = Colaborador::factory()->create(['sucursal_principal_id' => $this->estructura['sucursal']->id]);

    $acta = $this->postJson("/api/v1/rh/colaboradores/{$colaborador->id}/actas", [
        'tipo' => 'hechos',
        'fecha' => now()->toDateString(),
        'hora' => '09:30',
        'lugar' => 'Sucursal Cuernavaca',
        'hechos' => 'Faltante de mercancía en inventario.',
        'testigos' => [['nombre' => 'Pedro López', 'puesto' => 'Supervisor']],
        'declaraciones' => [['persona' => 'Colaborador', 'declaracion' => 'Desconoce el faltante.']],
    ])->assertCreated()->assertJsonPath('data.estado', 'borrador')->json('data');

    expect($acta['folio'])->toStartWith('ACT-');

    $this->post("/api/v1/rh/actas/{$acta['id']}/anexos", ['archivo' => clArchivoPdf('evidencia.pdf'), 'descripcion' => 'Conteo'], ['Accept' => 'application/json'])
        ->assertCreated()->assertJsonCount(1, 'data.anexos');

    $documento = $this->postJson("/api/v1/rh/actas/{$acta['id']}/documento")->assertCreated()->json('data');
    expect($documento['payload']['testigos_acta'])->toBe('Pedro López (Supervisor)')
        ->and($documento['categoria'])->toBe('actas');

    // Generada ya no admite edición de hechos; los cambios van como seguimiento.
    $this->patchJson("/api/v1/rh/actas/{$acta['id']}", ['hechos' => 'otro'])->assertUnprocessable();
    $this->postJson("/api/v1/rh/actas/{$acta['id']}/negativa-firma", ['motivo' => 'Se negó a firmar ante testigos.'])->assertOk()->assertJsonPath('data.negativa_firma', true);
    $this->postJson("/api/v1/rh/actas/{$acta['id']}/cerrar")->assertOk()->assertJsonPath('data.estado', 'cerrada');

    $implicado = User::factory()->create(['colaborador_id' => $colaborador->id]);
    $implicado->assignRole('colaborador');
    Sanctum::actingAs($implicado);
    $this->getJson("/api/v1/rh/actas/{$acta['id']}")->assertForbidden();
});

test('plantilla autorizada vs activa: autorizados, activos, vacantes, excedentes y cobertura calculados', function () {
    Sanctum::actingAs($this->rh);
    $sucursal = $this->estructura['sucursal'];
    $puestoA = $this->estructura['puesto'];
    $puestoB = Puesto::factory()->create();

    HeadcountTarget::factory()->create(['sucursal_id' => $sucursal->id, 'puesto_id' => $puestoA->id, 'plantilla_autorizada' => 3]);
    HeadcountTarget::factory()->create(['sucursal_id' => $sucursal->id, 'puesto_id' => $puestoB->id, 'plantilla_autorizada' => 1]);
    Colaborador::factory()->count(1)->create(['sucursal_principal_id' => $sucursal->id, 'puesto_id' => $puestoA->id]);
    Colaborador::factory()->count(2)->create(['sucursal_principal_id' => $sucursal->id, 'puesto_id' => $puestoB->id]);
    Colaborador::factory()->create(['sucursal_principal_id' => $sucursal->id, 'puesto_id' => $puestoB->id, 'estatus' => 'inactivo']);

    $totales = $this->getJson('/api/v1/rh/plantilla/cobertura')->assertOk()->json('data.totales');

    // El excedente de B no "tapa" la vacante de A.
    expect($totales['autorizados'])->toBe(4)
        ->and($totales['activos'])->toBe(3)
        ->and($totales['vacantes'])->toBe(2)
        ->and($totales['excedentes'])->toBe(1)
        ->and($totales['cobertura'])->toEqual(50);
});

test('indicadores de RH se calculan en backend (rotación, embudo, costo por contratación, contratos por vencer)', function () {
    Sanctum::actingAs($this->rh);
    $sucursal = $this->estructura['sucursal'];
    Colaborador::factory()->count(4)->create(['sucursal_principal_id' => $sucursal->id]);

    Candidato::factory()->create(['sucursal_id' => $sucursal->id, 'estado' => EstadoCandidato::Entrevista->value]);
    Candidato::factory()->create(['sucursal_id' => $sucursal->id, 'estado' => EstadoCandidato::Contratado->value, 'contratado_en' => now(), 'created_at' => now()->subDays(10)]);
    CampanaReclutamiento::query()->create(['canal' => 'meta', 'mes' => (int) now()->format('n'), 'anio' => (int) now()->format('Y'), 'monto' => 3000, 'sucursal_id' => $sucursal->id]);
    ContratoLaboral::factory()->create(['colaborador_id' => Colaborador::factory()->create(['sucursal_principal_id' => $sucursal->id])->id, 'fecha_fin' => now()->addDays(5)->toDateString()]);

    $datos = $this->getJson('/api/v1/rh/indicadores?sucursal_id='.$sucursal->id)->assertOk()->json('data');

    expect($datos['plantilla_activa'])->toBe(5)
        ->and($datos['contratados_periodo'])->toBe(1)
        ->and($datos['inversion_reclutamiento'])->toEqual(3000)
        ->and($datos['costo_por_contratacion'])->toEqual(3000)
        ->and($datos['tiempo_contratacion_dias']['candidatos'])->toEqual(10)
        ->and($datos['contratos_por_vencer']['total'])->toBe(1)
        ->and(collect($datos['embudo_candidatos'])->firstWhere('estado', 'entrevista')['total'])->toBe(1);

    Sanctum::actingAs(clUsuario('colaborador'));
    $this->getJson('/api/v1/rh/indicadores')->assertForbidden();
});

test('jerarquía: jefe, gerente, subordinados y organigrama por sucursal', function () {
    Sanctum::actingAs($this->rh);
    $sucursal = $this->estructura['sucursal'];
    $gerente = Colaborador::factory()->create(['sucursal_principal_id' => $sucursal->id, 'name' => 'Gerente']);
    $jefe = Colaborador::factory()->create(['sucursal_principal_id' => $sucursal->id, 'jefe_id' => $gerente->id, 'name' => 'Jefe']);
    $colaborador = Colaborador::factory()->create(['sucursal_principal_id' => $sucursal->id, 'jefe_id' => $jefe->id, 'name' => 'Colaborador']);

    $this->getJson("/api/v1/rh/colaboradores/{$colaborador->id}/jerarquia")
        ->assertOk()
        ->assertJsonPath('data.jefe_inmediato.id', $jefe->id)
        ->assertJsonPath('data.gerente.id', $gerente->id);

    $this->getJson("/api/v1/rh/colaboradores/{$jefe->id}/jerarquia")->assertJsonPath('data.subordinados_directos.0.id', $colaborador->id);

    $arbol = $this->getJson("/api/v1/rh/organigrama?sucursal_id={$sucursal->id}")->assertOk()->json('data');
    $raiz = collect($arbol)->firstWhere('id', $gerente->id);
    expect($raiz['subordinados'][0]['id'])->toBe($jefe->id)
        ->and($raiz['subordinados'][0]['subordinados'][0]['id'])->toBe($colaborador->id);
});

test('bandeja de tareas: cada usuario ve lo suyo, sin duplicados, y solo el destinatario la resuelve', function () {
    $colaborador = Colaborador::factory()->create();
    $servicio = app(TareaService::class);

    $servicio->abrir(TipoTarea::ContratoPendiente, $colaborador, ['colaborador' => $colaborador, 'permiso' => 'documentos_laborales.generar']);
    $servicio->abrir(TipoTarea::ContratoPendiente, $colaborador, ['colaborador' => $colaborador, 'permiso' => 'documentos_laborales.generar']);
    expect(TareaRh::query()->count())->toBe(1);

    Sanctum::actingAs($this->rh);
    $tarea = $this->getJson('/api/v1/tareas')->assertOk()
        ->assertJsonPath('meta.conteos.abiertas', 1)
        ->assertJsonPath('data.0.related_type', 'Colaborador')
        ->assertJsonPath('data.0.related_id', $colaborador->id)
        ->json('data.0');

    $ajeno = clUsuario('colaborador');
    Sanctum::actingAs($ajeno);
    $this->getJson('/api/v1/tareas')->assertOk()->assertJsonCount(0, 'data');
    $this->postJson("/api/v1/tareas/{$tarea['id']}/resolver")->assertForbidden();

    Sanctum::actingAs($this->rh);
    $this->postJson("/api/v1/tareas/{$tarea['id']}/leer")->assertOk()->assertJsonPath('data.read_at', fn ($v) => $v !== null);
    $this->postJson("/api/v1/tareas/{$tarea['id']}/resolver")->assertOk();

    // Una vez resuelta, el mismo pendiente puede volver a abrirse si vuelve a hacer falta.
    $servicio->abrir(TipoTarea::ContratoPendiente, $colaborador, ['colaborador' => $colaborador, 'permiso' => 'documentos_laborales.generar']);
    expect(TareaRh::query()->count())->toBe(2);
});
