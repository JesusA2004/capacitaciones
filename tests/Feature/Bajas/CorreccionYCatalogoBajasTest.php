<?php

use App\Enums\EstadoFiniquito;
use App\Enums\EstadoUsuario;
use App\Models\Baja;
use App\Models\BajaBitacora;
use App\Models\BajaDocumentoEdicion;
use App\Models\CausalGrupo;
use App\Models\CausalRotacion;
use App\Models\Colaborador;
use App\Models\FiniquitoCalculo;
use App\Models\GeneratedDocument;
use App\Services\Bajas\BajaDocumentoSalidaService;
use App\Services\DocumentosAdministrativos\DocumentoAdministrativoService;
use App\Services\DocumentosMaestros\DocumentoProcesoService;
use App\Services\Finiquitos\FiniquitoService;
use Database\Seeders\CausalesRotacionSeeder;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Expediente único de baja + finiquito, corrección de documentos e importes con
| vista previa que no guarda, y catálogo administrable con subcatálogos.
*/

beforeEach(function () {
    clRelojMediodiaMexico();
    $this->withoutVite();
    $this->seed(RolesYPermisosSeeder::class);
    $this->seed(CausalesRotacionSeeder::class);
    Storage::fake('nas');
    Notification::fake();

    $estructura = clEstructura();
    $sucursal = $estructura['sucursal'];
    $this->rh = clUsuario('rh_admin', ['sucursal_principal_id' => $sucursal->id]);
    $this->gerente = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $sucursal->id]);
    $this->persona = Colaborador::factory()->create([
        'sucursal_principal_id' => $sucursal->id,
        'puesto_id' => $estructura['puesto']->id,
        'jefe_id' => $this->gerente->colaborador_id,
        'estatus' => EstadoUsuario::Activo,
        'fecha_ingreso' => now()->subYears(3)->toDateString(),
        'sueldo_mensual' => 12000,
    ]);
});

function correccionBaja(object $test, string $clave): Baja
{
    $causal = CausalRotacion::query()->where('clave', $clave)->firstOrFail();

    $test->actingAs($test->rh)->post(route('rh.bajas.store'), [
        'colaborador_id' => $test->persona->id,
        'forma_salida' => $causal->forma()->value,
        'causal_rotacion_id' => $causal->id,
        'fecha_baja' => now()->subDay()->toDateString(),
        'recontratable' => 'si',
        'comentarios' => 'Se documentó el caso con el reporte de la sucursal.',
    ])->assertRedirect();

    return Baja::query()->where('colaborador_id', $test->persona->id)->latest('id')->firstOrFail();
}

function correccionFiniquito(Baja $baja): FiniquitoCalculo
{
    return FiniquitoCalculo::query()->where('solicitud_interna_id', $baja->cierre->solicitud_interna_id)->firstOrFail();
}

test('el expediente muestra el mismo finiquito que la pestaña', function () {
    $baja = correccionBaja($this, 'NR-02');
    $finiquito = correccionFiniquito($baja);

    $this->actingAs($this->rh)->get(route('rh.bajas.show', $baja))->assertInertia(fn (Assert $page) => $page
        ->component('Rh/Bajas/Show')
        ->where('finiquito.id', $finiquito->id)
        ->has('finiquito.desglose')
        ->where('finiquito.puede.editar', true));

    $this->actingAs($this->rh)->get(route('rh.bajas.finiquitos.index', ['finiquito' => $finiquito->id]))->assertInertia(fn (Assert $page) => $page
        ->where('seleccionado.id', $finiquito->id));
});

test('quien no ve finiquitos no los recibe en el expediente', function () {
    $baja = correccionBaja($this, 'NR-02');

    $this->actingAs($this->gerente)->get(route('rh.bajas.show', $baja))->assertInertia(fn (Assert $page) => $page
        ->where('finiquito', null));
});

test('corregir contenido: la vista previa no guarda y la confirmación deja versión, motivo y usuario', function () {
    $baja = correccionBaja($this, 'NR-02');
    $capturados = [];
    $real = app(DocumentoAdministrativoService::class);
    $espia = Mockery::mock($real)->makePartial();
    $espia->shouldReceive('renderizarVigente')->andReturnUsing(function ($familia, array $datos) use (&$capturados, $real) {
        $capturados[] = $datos;

        return $real->renderizarVigente($familia, $datos);
    });
    $this->app->instance(DocumentoAdministrativoService::class, $espia);

    $campos = $this->actingAs($this->rh)->getJson(route('rh.bajas.salida.campos', $baja))->assertOk()->json('campos');
    expect(array_column($campos, 'clave'))->toBe(['lugar_fecha', 'cuerpo', 'texto_adicional']);

    $this->actingAs($this->rh)->postJson(route('rh.bajas.salida.vista-previa-editada', $baja), ['campos' => ['cuerpo' => 'Texto corregido por RH.']])
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');

    expect(end($capturados)['cuerpo'])->toBe('Texto corregido por RH.')
        ->and(BajaDocumentoEdicion::query()->count())->toBe(0)
        ->and(GeneratedDocument::query()->where('clave_plantilla', 'convenio_terminacion')->count())->toBe(0);

    // Sin motivo no se guarda una corrección.
    $this->actingAs($this->rh)->post(route('rh.bajas.salida.generar', $baja), ['campos' => ['cuerpo' => 'Texto corregido por RH.']])->assertSessionHasErrors('motivo');

    $this->actingAs($this->rh)->post(route('rh.bajas.salida.generar', $baja), ['campos' => ['cuerpo' => 'Texto corregido por RH.', 'campo_inventado' => 'x'], 'motivo' => 'Estaba mal la redacción'])->assertSessionHasNoErrors();

    $edicion = BajaDocumentoEdicion::query()->sole();
    expect($edicion->campos)->toBe(['cuerpo' => 'Texto corregido por RH.'])
        ->and($edicion->user_id)->toBe($this->rh->id)
        ->and($edicion->generated_document_id)->not->toBeNull()
        ->and(BajaBitacora::query()->where('baja_id', $baja->id)->where('accion', 'corrigio_documento')->count())->toBe(1);

    // Una versión posterior sin cambios conserva la corrección.
    $this->actingAs($this->rh)->post(route('rh.bajas.salida.generar', $baja), ['motivo' => 'Se reimprime'])->assertSessionHasNoErrors();
    expect(BajaDocumentoEdicion::query()->count())->toBe(1)
        ->and(GeneratedDocument::query()->where('clave_plantilla', 'convenio_terminacion')->count())->toBe(2);

    $panel = app(BajaDocumentoSalidaService::class)->panel($baja->refresh(), $this->rh);
    expect($panel['ediciones'])->toHaveCount(1)->and($panel['ediciones'][0]['campos'])->toBe(['Texto principal']);
});

test('corregir la carta de renuncia pasa los datos al documento maestro y protege los de la empresa', function () {
    $baja = correccionBaja($this, 'RV-01');
    $proceso = $this->partialMock(DocumentoProcesoService::class);
    $proceso->shouldReceive('camposDelDocumento')->andReturn(['fecha_renuncia' => '01/10/2026', 'representante_legal' => 'Otra Persona']);
    $proceso->shouldReceive('vistaPrevia')->withArgs(fn ($p, $r, $clave, $actor, array $manuales = []) => $manuales === ['fecha_renuncia' => '02/10/2026'])->once()->andReturn(['pdf' => '%PDF-falso', 'nombre' => 'Carta', 'faltantes' => 0]);

    $campos = $this->actingAs($this->rh)->getJson(route('rh.bajas.salida.campos', $baja))->assertOk()->json('campos');
    expect(array_column($campos, 'clave'))->toBe(['fecha_renuncia']);

    $this->actingAs($this->rh)->postJson(route('rh.bajas.salida.vista-previa-editada', $baja), ['campos' => ['fecha_renuncia' => '02/10/2026', 'representante_legal' => 'Intento']])->assertOk();
});

test('no se corrige un documento firmado ni sin permiso', function () {
    $baja = correccionBaja($this, 'NR-02');
    $this->actingAs($this->rh)->post(route('rh.bajas.salida.generar', $baja))->assertSessionHasNoErrors();
    GeneratedDocument::query()->where('clave_plantilla', 'convenio_terminacion')->update(['estado_flujo' => 'firmado_fisicamente']);

    $this->actingAs($this->rh)->post(route('rh.bajas.salida.generar', $baja), ['campos' => ['cuerpo' => 'Cambio tardío'], 'motivo' => 'Quiero cambiarlo'])->assertSessionHasErrors('documento');
    expect(BajaDocumentoEdicion::query()->count())->toBe(0);

    $otro = clUsuario('colaborador');
    $this->actingAs($otro)->getJson(route('rh.bajas.salida.campos', $baja))->assertForbidden();
    $this->actingAs($otro)->postJson(route('rh.bajas.salida.vista-previa-editada', $baja), ['campos' => []])->assertForbidden();
});

test('corregir importes: la vista previa no guarda y la confirmación recalcula con bitácora', function () {
    $baja = correccionBaja($this, 'NR-02');
    $finiquito = correccionFiniquito($baja);
    $netoAntes = (float) $finiquito->neto;
    $concepto = 'prima_vacacional';
    $importeAntes = (float) collect(app(FiniquitoService::class)->desglose($finiquito))->firstWhere('concepto_clave', $concepto)['importe'];
    $nuevo = round($importeAntes + 100, 2);
    $cuerpo = ['conceptos' => [['concepto_clave' => $concepto, 'importe' => $nuevo]], 'nuevos' => [['concepto' => 'Bono de permanencia', 'tipo' => 'percepcion', 'importe' => 50]]];

    $this->actingAs($this->rh)->postJson(route('rh.bajas.finiquitos.vista-previa', $finiquito), $cuerpo)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect((float) $finiquito->refresh()->neto)->toBe($netoAntes)->and($finiquito->ajustes()->count())->toBe(0)->and($finiquito->conceptos()->count())->toBe(0);

    $this->actingAs($this->rh)->post(route('rh.bajas.finiquitos.corregir', $finiquito), $cuerpo)->assertSessionHasErrors('motivo');

    $this->actingAs($this->rh)->post(route('rh.bajas.finiquitos.corregir', $finiquito), [...$cuerpo, 'motivo' => 'Faltaba considerar días'])->assertSessionHasNoErrors();

    $finiquito->refresh();
    expect((float) $finiquito->neto)->toBe(round($netoAntes + 150, 2))
        ->and($finiquito->estado)->toBe(EstadoFiniquito::Borrador)
        ->and($finiquito->ajustes()->sole()->motivo)->toBe('Faltaba considerar días')
        ->and((float) $finiquito->ajustes()->sole()->valor_calculado)->toBe($importeAntes)
        ->and(BajaBitacora::query()->where('baja_id', $baja->id)->where('accion', 'corrigio_finiquito')->count())->toBe(1);

    // Sin cambios reales no escribe nada.
    $this->actingAs($this->rh)->post(route('rh.bajas.finiquitos.corregir', $finiquito), ['conceptos' => [['concepto_clave' => $concepto, 'importe' => $nuevo]], 'motivo' => 'Sin cambios reales'])->assertSessionHasErrors('cambios');
});

test('un finiquito firmado no se corrige ni con vista previa; importes negativos y conceptos ajenos se rechazan', function () {
    $baja = correccionBaja($this, 'NR-02');
    $finiquito = correccionFiniquito($baja);

    $this->actingAs($this->rh)->post(route('rh.bajas.finiquitos.corregir', $finiquito), ['conceptos' => [['concepto_clave' => 'prima_vacacional', 'importe' => -1]], 'motivo' => 'Prueba negativa'])->assertSessionHasErrors();
    $this->actingAs($this->rh)->post(route('rh.bajas.finiquitos.corregir', $finiquito), ['manuales' => [['id' => 999999, 'importe' => 1]], 'motivo' => 'Concepto de otro'])->assertSessionHasErrors('cambios');

    $finiquito->update(['estado' => EstadoFiniquito::Firmado->value]);

    $this->actingAs($this->rh)->post(route('rh.bajas.finiquitos.corregir', $finiquito), ['conceptos' => [['concepto_clave' => 'prima_vacacional', 'importe' => 1]], 'motivo' => 'Después de firmar'])->assertForbidden();
    $this->actingAs($this->rh)->postJson(route('rh.bajas.finiquitos.vista-previa', $finiquito), ['conceptos' => []])->assertForbidden();
});

test('catálogo: RH agrega subcatálogo y causal en Otras terminaciones; desactivar deja de ofrecerlas sin borrar', function () {
    $this->actingAs($this->rh)->post(route('rh.causales-rotacion.grupos.store'), ['forma_salida' => 'OT', 'nombre' => 'Pensión'])->assertSessionHasNoErrors();
    $grupo = CausalGrupo::query()->sole();

    $this->actingAs($this->rh)->post(route('rh.causales-rotacion.grupos.store'), ['forma_salida' => 'OT', 'nombre' => 'Pensión'])->assertSessionHasErrors('nombre');

    $this->actingAs($this->rh)->post(route('rh.causales-rotacion.store'), [
        'tipo' => 'otra_terminacion',
        'nombre' => 'Pensión por cesantía',
        'causal_grupo_id' => $grupo->id,
        'documento_salida' => 'convenio_terminacion',
        'implica_indemnizacion' => false,
    ])->assertSessionHasNoErrors();

    $causal = CausalRotacion::query()->where('nombre', 'Pensión por cesantía')->sole();
    expect($causal->causal_grupo_id)->toBe($grupo->id)
        ->and($causal->clave)->toStartWith('OT-')
        ->and(CausalRotacion::query()->activas()->where('id', $causal->id)->exists())->toBeTrue();

    $this->actingAs($this->rh)->get(route('rh.bajas.causales'))->assertInertia(fn (Assert $page) => $page
        ->component('Rh/Bajas/Causales')
        ->has('grupos', 1)
        ->where('historial.0.nombre', 'Pensión por cesantía')
        ->has('documentos_por_forma.OT', 2));

    $this->actingAs($this->rh)->patch(route('rh.causales-rotacion.grupos.activo', $grupo), ['activo' => false])->assertSessionHasNoErrors();
    expect(CausalRotacion::query()->activas()->where('id', $causal->id)->exists())->toBeFalse()
        ->and(CausalRotacion::query()->where('id', $causal->id)->exists())->toBeTrue();
});

test('catálogo: el subcatálogo y el documento deben ser de la forma; sin permiso no se administra', function () {
    $grupo = CausalGrupo::query()->create(['forma_salida' => 'OT', 'nombre' => 'Enfermedad']);

    $this->actingAs($this->rh)->post(route('rh.causales-rotacion.store'), ['tipo' => 'renuncia', 'nombre' => 'Causal cruzada', 'causal_grupo_id' => $grupo->id])->assertSessionHasErrors('causal_grupo_id');
    $this->actingAs($this->rh)->post(route('rh.causales-rotacion.store'), ['tipo' => 'renuncia', 'nombre' => 'Causal cruzada', 'documento_salida' => 'convenio_terminacion'])->assertSessionHasErrors('documento_salida');

    $this->actingAs($this->gerente)->post(route('rh.causales-rotacion.grupos.store'), ['forma_salida' => 'OT', 'nombre' => 'Sin permiso'])->assertForbidden();
    $this->actingAs($this->gerente)->put(route('rh.causales-rotacion.grupos.update', $grupo), ['nombre' => 'Sin permiso'])->assertForbidden();
    $this->actingAs($this->gerente)->patch(route('rh.causales-rotacion.grupos.activo', $grupo), ['activo' => false])->assertForbidden();
});

test('una causal de OT que genera convenio produce ese documento en la baja', function () {
    $causal = CausalRotacion::query()->where('clave', 'OT-03')->firstOrFail();
    $causal->update(['documento_salida' => 'convenio_terminacion']);
    $baja = correccionBaja($this, 'OT-03');

    expect(app(BajaDocumentoSalidaService::class)->panel($baja, $this->rh)['documento']['clave'])->toBe('convenio_terminacion');
});
