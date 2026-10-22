<?php

use App\Enums\EstadoDocumento;
use App\Models\Colaborador;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\Sucursal;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Bandejas-lista de la Etapa 2 (contratación y expediente): rutas de
| routes/rh.php servidas por ContratacionController + ContratacionListaService.
*/

beforeEach(function () {
    // Las páginas Vue nuevas aún no están en el manifest de Vite (no se ha
    // corrido `npm run build`): sin esto, renderizar la vista Inertia lanza
    // ViteException. withoutVite() es el stub estándar de Laravel para
    // probar páginas Inertia sin un build previo.
    $this->withoutVite();
    $this->seed(RolesYPermisosSeeder::class);
    $this->seed(DocumentTypeSeeder::class);
    Storage::fake('nas');
    Notification::fake();
    $this->estructura = clEstructura();
    $this->rh = clUsuario('rh_admin');
});

function clPersonaContratacion(int $sucursalId): Colaborador
{
    return clColaboradorEnContratacion(['sucursal_principal_id' => $sucursalId]);
}

// --- 1 · Personas en contratación -----------------------------------------

test('Personas en contratación carga 200 con datos reales para quien tiene contratos.ver', function () {
    $persona = clPersonaContratacion($this->estructura['sucursal']->id);

    $this->actingAs($this->rh)
        ->get(route('rh.contratacion.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->component('Rh/Contratacion/Index')
            ->has('personas.data', 1)
            ->where('personas.data.0.id', $persona->id)
            ->has('personas.data.0.etapa')
            ->has('altasDigitales'));
});

test('Personas en contratación responde 403 sin permiso', function () {
    $sinPermiso = clUsuario('colaborador', ['sucursal_principal_id' => $this->estructura['sucursal']->id]);

    $this->actingAs($sinPermiso)
        ->get(route('rh.contratacion.index'))
        ->assertForbidden();
});

test('Personas en contratación respeta el alcance: un gerente de sucursal no ve otras', function () {
    $otra = Sucursal::factory()->create();
    $propia = clPersonaContratacion($this->estructura['sucursal']->id);
    clPersonaContratacion($otra->id);

    $gerente = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $this->estructura['sucursal']->id]);

    $this->actingAs($gerente)
        ->get(route('rh.contratacion.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->has('personas.data', 1)
            ->where('personas.data.0.id', $propia->id));
});

// --- 2 · Validación de documentos ------------------------------------------

test('Validación de documentos lista documentos por revisar para quien tiene documentos.revisar', function () {
    $persona = clPersonaContratacion($this->estructura['sucursal']->id);
    $tipo = DocumentType::query()->where('activo', true)->firstOrFail();

    $documento = EmployeeDocument::factory()->create([
        'user_id' => null,
        'colaborador_id' => $persona->id,
        'document_type_id' => $tipo->id,
        'status' => EstadoDocumento::EnRevision->value,
    ]);

    $this->actingAs($this->rh)
        ->get(route('rh.validacion-documentos.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->component('Rh/Contratacion/ValidacionDocumentos')
            ->has('documentos.data', 1)
            ->where('documentos.data.0.id', $documento->id)
            ->where('documentos.data.0.aprobar_url', "/rh/documentos/{$documento->id}/aprobar")
            // El visor centrado de la bandeja necesita saber qué archivo es
            // (PDF o imagen) para dibujarlo inline sin ir al expediente.
            ->where('documentos.data.0.archivo', 'documento.pdf')
            ->where('documentos.data.0.mime', 'application/pdf')
            ->where('documentos.data.0.descargar_url', "/rh/documentos/{$documento->id}/descargar")
            ->where('puedeAprobar', true));
});

test('Validación de documentos responde 403 sin documentos.revisar', function () {
    // gerente_sucursal tiene contratos.ver pero NO documentos.revisar.
    $gerente = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $this->estructura['sucursal']->id]);

    $this->actingAs($gerente)
        ->get(route('rh.validacion-documentos.index'))
        ->assertForbidden();
});

test('Validación de documentos: «Rechazar» solo se ofrece con documentos.rechazar', function () {
    $this->actingAs($this->rh)
        ->get(route('rh.validacion-documentos.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->where('puedeRechazar', true)
            ->where('puedeSolicitarCorreccion', true));

    // Revisor sin aprobar ni rechazar: puede pedir corrección (la Policy solo
    // exige documentos.revisar), pero no rechazar ni aprobar.
    $gerente = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $this->estructura['sucursal']->id]);
    $gerente->givePermissionTo('documentos.revisar');

    expect($gerente->can('documentos.rechazar'))->toBeFalse();

    $this->actingAs($gerente)
        ->get(route('rh.validacion-documentos.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->where('puedeAprobar', false)
            ->where('puedeRechazar', false)
            ->where('puedeSolicitarCorreccion', true));
});

test('Validación de documentos respeta el alcance del revisor', function () {
    $otra = Sucursal::factory()->create();
    $tipo = DocumentType::query()->where('activo', true)->firstOrFail();

    $propia = clPersonaContratacion($this->estructura['sucursal']->id);
    $ajena = clPersonaContratacion($otra->id);

    $docPropio = EmployeeDocument::factory()->create([
        'user_id' => null,
        'colaborador_id' => $propia->id,
        'document_type_id' => $tipo->id,
        'status' => EstadoDocumento::EnRevision->value,
    ]);
    EmployeeDocument::factory()->create([
        'user_id' => null,
        'colaborador_id' => $ajena->id,
        'document_type_id' => $tipo->id,
        'status' => EstadoDocumento::EnRevision->value,
    ]);

    $gerente = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $this->estructura['sucursal']->id]);
    $gerente->givePermissionTo('documentos.revisar');

    $this->actingAs($gerente)
        ->get(route('rh.validacion-documentos.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->has('documentos.data', 1)
            ->where('documentos.data.0.id', $docPropio->id));
});

// --- 3 · Contratos de ingreso ----------------------------------------------

test('Contratos de ingreso lista a las personas en contratación con su paquete', function () {
    $persona = clPersonaContratacion($this->estructura['sucursal']->id);

    $this->actingAs($this->rh)
        ->get(route('rh.contratos-ingreso.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->component('Rh/Contratacion/ContratosIngreso')
            ->has('contratos.data', 1)
            ->where('contratos.data.0.id', $persona->id)
            ->where('contratos.data.0.gestion_url', "/rh/colaboradores/{$persona->id}/ciclo")
            ->has('contratos.data.0.documentos'));

    // «Gestionar» abre una página Inertia (el Ciclo laboral), no el JSON
    // de /rh/documentos-proceso que esa página consume.
    $this->actingAs($this->rh)
        ->get("/rh/colaboradores/{$persona->id}/ciclo")
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Rh/Colaboradores/Ciclo'));
});

test('Contratos de ingreso responde 403 sin contratos.ver', function () {
    $sinPermiso = clUsuario('colaborador', ['sucursal_principal_id' => $this->estructura['sucursal']->id]);

    $this->actingAs($sinPermiso)
        ->get(route('rh.contratos-ingreso.index'))
        ->assertForbidden();
});

test('Contratos de ingreso respeta el alcance: un gerente de sucursal no ve otras', function () {
    $otra = Sucursal::factory()->create();
    $propia = clPersonaContratacion($this->estructura['sucursal']->id);
    clPersonaContratacion($otra->id);

    $gerente = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $this->estructura['sucursal']->id]);

    $this->actingAs($gerente)
        ->get(route('rh.contratos-ingreso.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->has('contratos.data', 1)
            ->where('contratos.data.0.id', $propia->id));
});
