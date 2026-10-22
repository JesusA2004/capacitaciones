<?php

use App\Enums\CategoriaDocumento;
use App\Enums\EstadoDocumento;
use App\Enums\EstadoFlujoDocumento;
use App\Enums\EstadoVacante;
use App\Enums\TipoNodoComercial;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\GeneratedDocument;
use App\Models\NodoComercial;
use App\Models\Puesto;
use App\Models\Vacante;
use App\Services\Reclutamiento\CandidatoWorkflowService;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

/*
| Permisos granulares de Contratación y expediente (2026-10-22): el gerente
| (gerente_sucursal = gerente) ve a su gente y sus datos generales, aprueba
| la entrevista y sigue el flujo, y SOLO sube el contrato firmado — no ve,
| descarga ni genera contratos, ni descarga documentos del expediente. El
| contrato de un Gerente de Sucursal lo sube su Gerente Regional. RH
| conserva todo con los permisos generales.
*/

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesYPermisosSeeder::class);
    $this->seed(DocumentTypeSeeder::class);
    Storage::fake('nas');
    Notification::fake();

    $this->estructura = clEstructura();
    $this->sucursal = $this->estructura['sucursal'];
    $this->gestor = Puesto::factory()->create(['nombre' => 'Gestor', 'meses_periodo_prueba' => 2, 'grupo_documental' => 'gestor']);
    $this->puestoGerente = Puesto::factory()->create(['nombre' => 'Gerente de Sucursal', 'meses_periodo_prueba' => 3, 'grupo_documental' => 'gerente']);
    $this->puestoRegional = Puesto::factory()->create(['nombre' => 'Gerente Regional Q1']);

    // Matriz comercial: Región Q1 (puesto regional ligado) → zona de la sucursal.
    $matriz = NodoComercial::factory()->create(['tipo' => TipoNodoComercial::Matriz->value, 'nombre' => 'MATRIZ']);
    $region = NodoComercial::factory()->create(['tipo' => TipoNodoComercial::Region->value, 'nombre' => 'Región Q1', 'parent_id' => $matriz->id, 'puesto_id' => $this->puestoRegional->id]);
    NodoComercial::factory()->create(['tipo' => TipoNodoComercial::Zona->value, 'nombre' => 'CUERNAVACA', 'parent_id' => $region->id, 'sucursal_id' => $this->sucursal->id]);

    $base = ['estatus' => 'activo', 'sucursal_principal_id' => $this->sucursal->id];
    $this->rh = clUsuario('rh_admin', $base);
    $this->regional = clUsuario('gerente_regional', [...$base, 'puesto_id' => $this->puestoRegional->id]);
    $this->gerente = clUsuario('gerente_sucursal', [...$base, 'puesto_id' => $this->puestoGerente->id]);
    // «gerente y gerente de sucursal es lo mismo»: ambos roles iguales.
    $this->gerenteRol = clUsuario('gerente', [...$base, 'puesto_id' => $this->puestoGerente->id]);

    $this->empleado = Colaborador::factory()->create([...$base, 'puesto_id' => $this->gestor->id, 'fecha_ingreso' => now()->subDays(10)->toDateString()]);
    $this->otroGerente = Colaborador::factory()->create([...$base, 'puesto_id' => $this->puestoGerente->id, 'fecha_ingreso' => now()->subDays(10)->toDateString()]);
});

function pgArchivo(): UploadedFile
{
    return UploadedFile::fake()->create('contrato-firmado.pdf', 120, 'application/pdf');
}

function pgContratoGenerado(Colaborador $colaborador): GeneratedDocument
{
    $documento = GeneratedDocument::factory()->create([
        'colaborador_id' => $colaborador->id,
        'categoria' => CategoriaDocumento::Contratos->value,
        'estado_flujo' => EstadoFlujoDocumento::Generado->value,
        'clave_plantilla' => 'contrato_capacitacion',
        'path' => 'contratos/contrato.pdf',
        'original_name' => 'contrato.pdf',
        'mime' => 'application/pdf',
    ]);
    Storage::disk('nas')->put('contratos/contrato.pdf', '%PDF-1.4 prueba');

    return $documento;
}

// --- Defaults por rol -------------------------------------------------------

test('defaults: el gerente (gerente_sucursal y gerente) solo sube el firmado; RH tiene todas las acciones de contratos', function () {
    foreach ([$this->gerente, $this->gerenteRol, $this->regional] as $usuario) {
        expect($usuario->can('contratos.subir_firmado'))->toBeTrue()
            ->and($usuario->can('contratos.ver_documento'))->toBeFalse()
            ->and($usuario->can('contratos.descargar'))->toBeFalse()
            ->and($usuario->can('contratos.generar'))->toBeFalse()
            ->and($usuario->can('documentos.descargar'))->toBeFalse()
            ->and($usuario->can('candidatos.aprobar_entrevista'))->toBeTrue();
    }

    foreach (['contratos.ver_documento', 'contratos.descargar', 'contratos.generar', 'contratos.subir_firmado', 'documentos.descargar'] as $permiso) {
        expect($this->rh->can($permiso))->toBeTrue();
    }

    expect(Role::findByName('juridico')->hasPermissionTo('contratos.ver_documento'))->toBeTrue()
        ->and(Role::findByName('colaborador')->hasPermissionTo('contratos.subir_firmado'))->toBeFalse();
});

// --- Subir firmado ----------------------------------------------------------

test('el gerente sube el contrato firmado de su gente (web y API) y queda en revisión', function () {
    $this->actingAs($this->gerente)
        ->post(route('rh.contratos.subir-firmado', $this->empleado), ['clave' => 'contrato_capacitacion', 'archivo' => pgArchivo()])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $tipo = DocumentType::query()->where('clave', 'contrato')->firstOrFail();
    $documento = EmployeeDocument::query()->where('colaborador_id', $this->empleado->id)->where('document_type_id', $tipo->id)->firstOrFail();

    expect($documento->status)->toBe(EstadoDocumento::EnRevision);

    Sanctum::actingAs($this->gerenteRol);
    $this->postJson("/api/v1/rh/colaboradores/{$this->empleado->id}/contratos/firmado", ['clave' => 'contrato_confidencialidad', 'archivo' => pgArchivo()])
        ->assertCreated()
        ->assertJsonPath('data.tipo', 'carta_confidencialidad')
        ->assertJsonPath('data.estado', EstadoDocumento::EnRevision->value);
});

test('el contrato de un Gerente de Sucursal no lo sube otro gerente de sucursal: lo sube la Gerente Regional de su zona (o RH)', function () {
    $this->actingAs($this->gerente)
        ->post(route('rh.contratos.subir-firmado', $this->otroGerente), ['clave' => 'contrato_capacitacion', 'archivo' => pgArchivo()])
        ->assertForbidden();

    Sanctum::actingAs($this->gerenteRol);
    $this->postJson("/api/v1/rh/colaboradores/{$this->otroGerente->id}/contratos/firmado", ['clave' => 'contrato_capacitacion', 'archivo' => pgArchivo()])
        ->assertForbidden()
        ->assertJsonPath('message', 'El contrato de un Gerente de Sucursal lo sube la Gerente Regional de su zona (o RH).');

    $this->actingAs($this->regional)
        ->post(route('rh.contratos.subir-firmado', $this->otroGerente), ['clave' => 'contrato_capacitacion', 'archivo' => pgArchivo()])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->actingAs($this->rh)
        ->post(route('rh.contratos.subir-firmado', $this->otroGerente), ['clave' => 'contrato_no_competencia', 'archivo' => pgArchivo()])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(EmployeeDocument::query()->where('colaborador_id', $this->otroGerente->id)->count())->toBe(2);
});

test('nadie sube su propio contrato firmado con la acción del gerente, ni sin permiso', function () {
    $colaborador = clUsuario('colaborador', ['estatus' => 'activo', 'sucursal_principal_id' => $this->sucursal->id]);

    $this->actingAs($this->gerente)
        ->post(route('rh.contratos.subir-firmado', $this->gerente->colaborador_id), ['clave' => 'contrato_capacitacion', 'archivo' => pgArchivo()])
        ->assertForbidden();

    $this->actingAs($colaborador)
        ->post(route('rh.contratos.subir-firmado', $this->empleado), ['clave' => 'contrato_capacitacion', 'archivo' => pgArchivo()])
        ->assertForbidden();
});

// --- Ver / descargar / generar contratos ---------------------------------------

test('el gerente no ve, descarga ni genera contratos (web y API); RH sí', function () {
    $contrato = pgContratoGenerado($this->empleado);

    foreach ([$this->gerente, $this->gerenteRol] as $gerente) {
        $this->actingAs($gerente)->get(route('rh.documentos-laborales.descargar', $contrato))->assertForbidden();
        $this->actingAs($gerente)->get(route('rh.documentos-laborales.descargar', ['documento' => $contrato->id, 'descargar' => 1]))->assertForbidden();
        $this->actingAs($gerente)->get(route('rh.contratos.vista-previa', ['colaborador' => $this->empleado->id, 'clave' => 'contrato_capacitacion']))->assertForbidden();
        $this->actingAs($gerente)->post(route('rh.contratos.generar', $this->empleado))->assertForbidden();
        $this->actingAs($gerente)->post(route('rh.contratos.generar-todos'))->assertForbidden();

        Sanctum::actingAs($gerente);
        $this->getJson("/api/v1/rh/documentos-laborales/{$contrato->id}")->assertForbidden();
        $this->getJson("/api/v1/rh/documentos-laborales/{$contrato->id}/descargar")->assertForbidden();
    }

    $this->actingAs($this->rh)->get(route('rh.documentos-laborales.descargar', $contrato))->assertOk();
    $this->actingAs($this->rh)->get(route('rh.documentos-laborales.descargar', ['documento' => $contrato->id, 'descargar' => 1]))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="contrato.pdf"');

    Sanctum::actingAs($this->rh);
    $this->getJson("/api/v1/rh/documentos-laborales/{$contrato->id}/descargar")->assertOk();
});

test('la pantalla «Contratos» no entrega al gerente la URL del contrato y sí le ofrece subir el firmado', function () {
    pgContratoGenerado($this->empleado);

    $this->actingAs($this->gerente)
        ->get(route('rh.contratos.index'))
        ->assertOk()
        ->assertInertia(function ($pagina) {
            $filas = collect($pagina->toArray()['props']['filas']['data']);
            $empleado = $filas->firstWhere('id', $this->empleado->id);
            $otroGerente = $filas->firstWhere('id', $this->otroGerente->id);

            expect($pagina->toArray()['props']['puedeGenerar'])->toBeFalse()
                ->and($empleado['puede_subir_firmado'])->toBeTrue()
                ->and(collect($empleado['contratos'])->flatMap(fn ($e) => $e['documentos'])->pluck('descargar_url')->filter()->all())->toBe([])
                ->and($otroGerente['puede_subir_firmado'])->toBeFalse();
        });
});

// --- Documentos del expediente ---------------------------------------------

test('el gerente ve el expediente de su gente pero no descarga sus documentos (web y API)', function () {
    $tipo = DocumentType::query()->where('clave', 'contrato')->firstOrFail();
    Storage::disk('nas')->put('expedientes/ine.pdf', '%PDF-1.4');
    $documento = EmployeeDocument::factory()->create([
        'colaborador_id' => $this->empleado->id,
        'document_type_id' => $tipo->id,
        'path' => 'expedientes/ine.pdf',
        'original_name' => 'ine.pdf',
        'mime' => 'application/pdf',
    ]);

    $this->actingAs($this->gerente)->get(route('rh.expedientes.show', $this->empleado))->assertOk();
    $this->actingAs($this->gerente)->get(route('rh.documentos.descargar', $documento))->assertForbidden();
    $this->actingAs($this->gerente)->get(route('rh.expedientes.descargar-zip', $this->empleado))->assertForbidden();
    $this->actingAs($this->gerente)->get(route('rh.expedientes.alta-imss.show', $this->empleado))->assertForbidden();

    Sanctum::actingAs($this->gerente);
    $this->getJson("/api/v1/rh/expedientes/{$this->empleado->id}/documentos/{$documento->id}/ver")->assertForbidden();

    $this->actingAs($this->rh)->get(route('rh.documentos.descargar', $documento))->assertOk();
});

// --- Entrevista del candidato ------------------------------------------------

test('el gerente aprueba la entrevista (web y API); un rol con solo «aprobar entrevista» también; sin permiso, 403', function () {
    $reclutador = clUsuario('rh_auxiliar', ['estatus' => 'activo', 'sucursal_principal_id' => $this->sucursal->id]);
    $workflow = app(CandidatoWorkflowService::class);
    $nuevo = function () use ($workflow, $reclutador): Candidato {
        $vacante = Vacante::factory()->real()->create([
            'empresa_id' => $this->estructura['empresa']->id, 'sucursal_id' => $this->sucursal->id, 'puesto_id' => $this->gestor->id,
            'estado' => EstadoVacante::Abierta->value, 'plazas_requeridas' => 1, 'plazas_disponibles' => 1, 'plazas_cubiertas' => 0,
        ]);
        $candidato = $workflow->registrar([
            'empresa_id' => $this->estructura['empresa']->id, 'sucursal_id' => $this->sucursal->id,
            'departamento_id' => $this->estructura['departamento']->id, 'puesto_objetivo_id' => $this->gestor->id,
            'vacante_id' => $vacante->id, 'nombre' => 'Ana', 'apellidos' => 'Prueba '.uniqid(), 'telefono' => '5512345678',
            'correo' => uniqid().'@example.test', 'fuente' => 'facebook_grupos', 'gerente_involucrado_id' => $this->gerente->id,
        ], $reclutador);

        return $workflow->evaluarPerfil($candidato, $reclutador, true, null);
    };
    $entrevista = ['realizada_en' => now()->subHour()->toDateTimeString(), 'resultado' => 'viable', 'observaciones' => 'Buena entrevista.'];

    $web = $nuevo();
    $this->actingAs($this->gerente)->post(route('rh.candidatos.entrevista', $web), $entrevista)->assertRedirect()->assertSessionHasNoErrors();
    expect($web->refresh()->estado->value)->not->toBe('entrevista_pendiente');

    $api = $nuevo();
    Sanctum::actingAs($this->gerenteRol);
    $this->postJson("/api/v1/rh/candidatos/{$api->id}/entrevista", $entrevista)->assertOk();

    // Rol armado a mano solo con la acción específica (sin candidatos.evaluar).
    $soloEntrevista = Role::create(['name' => 'entrevistador_prueba', 'guard_name' => 'web']);
    $soloEntrevista->givePermissionTo(['candidatos.ver', 'candidatos.ver_sucursal', 'candidatos.aprobar_entrevista']);
    $entrevistador = clUsuario('entrevistador_prueba', ['estatus' => 'activo', 'sucursal_principal_id' => $this->sucursal->id]);
    $especifico = $nuevo();
    expect(collect($workflow->accionesPermitidas($especifico, $entrevistador))->pluck('clave'))->toContain('registrar_entrevista');
    $this->actingAs($entrevistador)->post(route('rh.candidatos.entrevista', $especifico), $entrevista)->assertRedirect()->assertSessionHasNoErrors();

    $sinPermiso = clUsuario('colaborador', ['estatus' => 'activo', 'sucursal_principal_id' => $this->sucursal->id]);
    $bloqueado = $nuevo();
    $this->actingAs($sinPermiso)->post(route('rh.candidatos.entrevista', $bloqueado), $entrevista)->assertForbidden();
});

test('el bootstrap móvil expone las acciones granulares para mostrar u ocultar botones', function () {
    Sanctum::actingAs($this->gerente);
    $respuesta = $this->getJson('/api/v1/mobile/bootstrap')->assertOk()->json();
    $acciones = data_get($respuesta, 'data.ciclo_laboral.acciones') ?? data_get($respuesta, 'ciclo_laboral.acciones');

    expect($acciones)->toBeArray()
        ->and($acciones['aprobar_entrevista'])->toBeTrue()
        ->and($acciones['subir_contrato_firmado'])->toBeTrue()
        ->and($acciones['ver_contratos'])->toBeFalse()
        ->and($acciones['descargar_contratos'])->toBeFalse()
        ->and($acciones['generar_contratos'])->toBeFalse()
        ->and($acciones['descargar_documentos_expediente'])->toBeFalse();
});
