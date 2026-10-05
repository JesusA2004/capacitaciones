<?php

use App\Enums\EstadoValidacionVisual;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\DocumentTemplate;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\DocumentosMaestros\CatalogoMaestrosService;
use App\Services\DocumentosMaestros\CoberturaDocumentalService;
use App\Services\DocumentosMaestros\ImportadorFormatosJuridicosService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

/*
| Cobertura documental por puesto (decisión explícita: grupo o "no requiere
| documentos" con motivo) y Administración → Documentos maestros (KPIs,
| detalle, carga con QA, prueba con colaborador por búsqueda, comparativo
| ORIGINAL vs GENERADO, permisos e IDOR).
*/

function caImportar(array $familias): void
{
    $catalogo = app(CatalogoMaestrosService::class);

    foreach (glob(base_path('docs/formatos_fuente').'/*') ?: [] as $ruta) {
        foreach ($catalogo->todasPorHash((string) hash_file('sha256', $ruta)) as $fuente) {
            if (in_array($fuente['familia'], $familias, true)) {
                app(ImportadorFormatosJuridicosService::class)->registrarVersion($fuente['familia'], $fuente['version'], (string) file_get_contents($ruta), [basename($ruta)], $fuente, null);
            }
        }
    }
}

function caHayOriginales(): bool
{
    return is_dir(base_path('docs/formatos_fuente')) && glob(base_path('docs/formatos_fuente').'/*.docx') !== [];
}

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();
    $this->motorFiel = dmMotorFielDePrueba();
    $this->rh = clUsuario('rh_admin');
});

test('decisión documental del puesto: grupo, o "no requiere" con motivo; nunca ambos ni ninguno', function () {
    $puesto = Puesto::factory()->create(['grupo_documental' => 'gestor']);
    $servicio = app(CoberturaDocumentalService::class);

    expect(fn () => $servicio->decidir($puesto, ['grupo_documental' => null, 'no_requiere_documentos_laborales' => false], $this->rh))->toThrow(ValidationException::class)
        ->and(fn () => $servicio->decidir($puesto, ['grupo_documental' => 'gestor', 'no_requiere_documentos_laborales' => true, 'motivo_sin_documentos' => 'Asesor externo honorarios'], $this->rh))->toThrow(ValidationException::class)
        ->and(fn () => $servicio->decidir($puesto, ['no_requiere_documentos_laborales' => true, 'motivo_sin_documentos' => 'corto'], $this->rh))->toThrow(ValidationException::class)
        ->and(fn () => $servicio->decidir($puesto, ['grupo_documental' => 'inventado', 'no_requiere_documentos_laborales' => false], $this->rh))->toThrow(ValidationException::class);

    $servicio->decidir($puesto, ['no_requiere_documentos_laborales' => true, 'motivo_sin_documentos' => 'Asesor externo por honorarios'], $this->rh);
    expect($puesto->refresh()->grupo_documental)->toBeNull()
        ->and($puesto->no_requiere_documentos_laborales)->toBeTrue();
});

test('el valor inicial por nombre (grupos_por_puesto) solo siembra puestos SIN decisión; la BD manda después', function () {
    $gestor = Puesto::factory()->create(['nombre' => 'Gestor', 'grupo_documental' => null]);
    $excluido = Puesto::factory()->create(['nombre' => 'Gestor Volante', 'grupo_documental' => null, 'no_requiere_documentos_laborales' => true, 'motivo_sin_documentos' => 'Decisión de RH']);
    $cambiado = Puesto::factory()->create(['nombre' => 'Subgerente', 'grupo_documental' => 'gerente']);

    app(CoberturaDocumentalService::class)->aplicarGruposIniciales();

    expect($gestor->refresh()->grupo_documental)->toBe('gestor')
        ->and($excluido->refresh()->grupo_documental)->toBeNull()
        ->and($cambiado->refresh()->grupo_documental)->toBe('gerente');
});

test('Parámetros de RH: quitarle el grupo documental a un puesto sin decidir "no requiere" se rechaza (no queda ambiguo en silencio)', function () {
    $puesto = Puesto::factory()->create(['grupo_documental' => 'gestor', 'activo' => true]);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $this->actingAs($admin)
        ->from('/administracion/configuracion/parametros-rh')
        ->put(route('administracion.configuracion.parametros-rh.puestos.update', $puesto), ['meses_periodo_prueba' => 2, 'grupo_documental' => null])
        ->assertSessionHasErrors('grupo_documental');

    expect($puesto->refresh()->grupo_documental)->toBe('gestor');
});

test('cobertura: completo, incompleto (falta el formato), sin decisión y excluido; el KPI cuenta los que requieren atención', function () {
    if (! caHayOriginales()) {
        $this->markTestSkipped('Sin originales jurídicos en docs/formatos_fuente.');
    }

    caImportar(['contrato_capacitacion.gestor', 'contrato_indeterminado.gestor', 'contrato_confidencialidad.gestor', 'contrato_no_competencia.gestor', 'contrato_confidencialidad.general']);
    Puesto::query()->update(['activo' => false]);
    $gestor = Puesto::factory()->create(['nombre' => 'Gestor', 'grupo_documental' => 'gestor', 'activo' => true]);
    $sistemas = Puesto::factory()->create(['nombre' => 'Sistemas', 'grupo_documental' => null, 'activo' => true]);
    $gerente = Puesto::factory()->create(['nombre' => 'Gerente', 'grupo_documental' => 'gerente', 'activo' => true]);
    $externo = Puesto::factory()->create(['nombre' => 'Asesor', 'activo' => true, 'no_requiere_documentos_laborales' => true, 'motivo_sin_documentos' => 'Honorarios']);

    $reporte = app(CoberturaDocumentalService::class)->reporte();
    $fila = fn (Puesto $p) => collect($reporte['puestos'])->firstWhere('id', $p->id);

    expect($fila($gestor)['estado'])->toBe('completo')
        ->and($fila($gestor)['celdas']['capacitacion']['estado'])->toBe('ok')
        ->and($fila($sistemas)['estado'])->toBe('sin_decision')
        ->and($fila($sistemas)['celdas']['confidencialidad']['estado'])->toBe('general')
        ->and($fila($gerente)['estado'])->toBe('incompleto')
        ->and($fila($gerente)['faltantes'])->toContain('Capacitación inicial (sin formato)')
        ->and($fila($externo)['estado'])->toBe('excluido')
        ->and($reporte['resumen'])->toMatchArray(['completos' => 1, 'incompletos' => 1, 'sin_decision' => 1, 'excluidos' => 1])
        ->and(app(CoberturaDocumentalService::class)->puestosConProblema())->toBe(2);

    // Un formato cargado pero sin diseño validado también es incompleto.
    DocumentTemplate::query()->where('familia', 'contrato_capacitacion.gestor')->update(['visual_validation_status' => EstadoValidacionVisual::Fallida->value]);
    expect(collect(app(CoberturaDocumentalService::class)->reporte()['puestos'])->firstWhere('id', $gestor->id)['celdas']['capacitacion']['estado'])->toBe('sin_validar');
});

test('pantallas de administración: RH administrador entra; gerente y colaborador reciben 403 (también a cobertura y a la prueba)', function () {
    $this->actingAs($this->rh)->get('/rh/documentos-maestros')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->component('Rh/DocumentosMaestros/Index')->has('kpis.formatos_activos')->has('masters'));
    $this->actingAs($this->rh)->get('/rh/documentos-maestros/cobertura')->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->component('Rh/DocumentosMaestros/Cobertura')->has('cobertura.resumen'));

    foreach (['gerente_sucursal', 'colaborador'] as $rol) {
        $usuario = clUsuario($rol);
        $this->actingAs($usuario)->get('/rh/documentos-maestros')->assertForbidden();
        $this->actingAs($usuario)->get('/rh/documentos-maestros/cobertura')->assertForbidden();
        $this->actingAs($usuario)->getJson('/rh/documentos-maestros/colaboradores?q=a')->assertForbidden();
    }
});

test('decidir la cobertura de un puesto desde la pantalla (con auditoría)', function () {
    $puesto = Puesto::factory()->create(['grupo_documental' => null, 'activo' => true]);

    $this->actingAs($this->rh)->put("/rh/documentos-maestros/cobertura/puestos/{$puesto->id}", ['grupo_documental' => 'coordinadora', 'no_requiere_documentos_laborales' => false])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($puesto->refresh()->grupo_documental)->toBe('coordinadora');
});

test('buscar colaborador para probar: por nombre o número de empleado, sin capturar IDs a mano', function () {
    $juan = Colaborador::factory()->create(['name' => 'Juan', 'apellidos' => 'Pérez López', 'numero_empleado' => 'EMP-0012']);
    Colaborador::factory()->create(['name' => 'María', 'apellidos' => 'Gómez']);

    $porNombre = $this->actingAs($this->rh)->getJson('/rh/documentos-maestros/colaboradores?q=juan perez')->assertOk()->json('data');
    $porNumero = $this->actingAs($this->rh)->getJson('/rh/documentos-maestros/colaboradores?q=EMP-0012')->assertOk()->json('data');

    expect(array_column($porNombre, 'id'))->toBe([$juan->id])
        ->and(array_column($porNumero, 'id'))->toBe([$juan->id])
        ->and($porNombre[0])->toHaveKeys(['nombre', 'numero_empleado', 'puesto', 'sucursal']);
});

test('probar con colaborador: resumen comparativo (páginas, fidelidad, campos, desbordes, fuentes, domicilio y representante) y ambos PDF', function () {
    if (! caHayOriginales()) {
        $this->markTestSkipped('Sin originales jurídicos en docs/formatos_fuente.');
    }

    caImportar(['formato_permiso.general', 'contrato_capacitacion.gestor']);
    $sucursal = Sucursal::factory()->create(['direccion' => 'Av. Morelos 120']);
    $colaborador = Colaborador::factory()->create(['name' => 'Juan', 'apellidos' => 'Pérez', 'sucursal_principal_id' => $sucursal->id, 'puesto_id' => Puesto::factory()->create(['grupo_documental' => 'gestor'])->id]);
    ContratoLaboral::query()->create(['colaborador_id' => $colaborador->id, 'tipo' => 'capacitacion_inicial', 'fecha_inicio' => '2026-10-05', 'fecha_fin' => '2026-12-04', 'estado' => 'vigente', 'sueldo_mensual' => 15000]);
    $master = DocumentTemplate::query()->where('familia', 'contrato_capacitacion.gestor')->firstOrFail();

    $resumen = $this->actingAs($this->rh)->postJson("/rh/documentos-maestros/{$master->id}/probar", ['colaborador_id' => $colaborador->id])->assertOk()->json('data');

    expect($resumen)->toHaveKeys(['paginas', 'fidelidad', 'campos', 'desbordes', 'fuentes', 'patron', 'url_resultado', 'url_original'])
        ->and($resumen['patron']['representante']['fuente'])->toBe('predeterminado')
        ->and($resumen['patron']['domicilio']['fuente'])->toBeIn(['fiscal', 'predeterminado', 'sucursal'])
        ->and($resumen['campos']['total'])->toBeGreaterThan(5);

    $this->actingAs($this->rh)->get($resumen['url_resultado'])->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $permiso = DocumentTemplate::query()->where('familia', 'formato_permiso.general')->firstOrFail();
    $this->actingAs($this->rh)->get("/rh/documentos-maestros/{$permiso->id}/original-pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');

    // Nada se guardó en el expediente.
    expect(Storage::disk('nas')->allFiles('expedientes'))->toBe([]);
});

test('cargar una versión nueva: original intacto (v1 se conserva), QA al cargar y queda inactiva hasta activarla; el detalle muestra bloqueos', function () {
    if (! caHayOriginales()) {
        $this->markTestSkipped('Sin originales jurídicos en docs/formatos_fuente.');
    }

    caImportar(['carta_renuncia.general']);
    $v1 = DocumentTemplate::query()->where('familia', 'carta_renuncia.general')->firstOrFail();
    $bytes = (string) file_get_contents(collect(glob(base_path('docs/formatos_fuente').'/*RENUNCIA*'))->first());
    // Un original distinto (otro hash) → v2: se simula con una copia modificada en metadatos del zip.
    $ruta = tempnam(sys_get_temp_dir(), 'ren').'.docx';
    file_put_contents($ruta, $bytes);
    $zip = new ZipArchive;
    $zip->open($ruta);
    $zip->setArchiveComment('v2 de Jurídico');
    $zip->close();

    $respuesta = $this->actingAs($this->rh)->post('/rh/documentos-maestros/familia/carta_renuncia.general/versiones', [
        'archivo' => new UploadedFile($ruta, 'RENUNCIA v2.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true),
    ], ['Accept' => 'application/json'])->assertCreated();

    expect($respuesta->json('data.version'))->toBe(2)
        ->and($respuesta->json('data.activo'))->toBeFalse()
        ->and($respuesta->json('data.calidad.estado'))->toBe('passed')
        ->and($respuesta->json('data.activacion.puede'))->toBeTrue()
        ->and(count($respuesta->json('data.versiones')))->toBe(2)
        ->and(Storage::disk('nas')->exists((string) $v1->refresh()->original_path))->toBeTrue()
        ->and($v1->activo)->toBeTrue();

    $this->actingAs($this->rh)->postJson('/rh/documentos-maestros/'.$respuesta->json('data.id').'/activar')->assertOk()->assertJsonPath('data.activo', true);
    expect($v1->refresh()->activo)->toBeFalse();
});
