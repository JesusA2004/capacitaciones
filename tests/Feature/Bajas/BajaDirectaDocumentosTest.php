<?php

use App\Enums\EstatusBaja;
use App\Models\Baja;
use App\Models\CausalRotacion;
use App\Models\CierreLaboral;
use App\Models\Colaborador;
use App\Models\GeneratedDocument;
use App\Models\Puesto;
use App\Models\SolicitudInterna;
use App\Services\DocumentosMaestros\ImportadorFormatosJuridicosService;
use Database\Seeders\CausalesRotacionSeeder;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
| Baja directa con los formatos reales de Jurídico (docs/formatos_fuente):
| la vista previa del formulario (renuncia + finiquito) no escribe nada y,
| al registrar, la renuncia queda GENERADA (nunca se sube). Sin los
| originales, se omite.
*/

beforeEach(function () {
    if (! is_dir(base_path('docs/formatos_fuente')) || glob(base_path('docs/formatos_fuente').'/*.docx') === []) {
        $this->markTestSkipped('Sin originales jurídicos en docs/formatos_fuente (no se versionan).');
    }

    $this->seed(RolesYPermisosSeeder::class);
    $this->seed(CausalesRotacionSeeder::class);
    $this->disco = sprintf('nas_bajas_directas_%d', getmypid());
    config(['expedientes.disk' => $this->disco, 'documentos_maestros.disk' => $this->disco]);
    Storage::fake($this->disco);
    Notification::fake();
    dmMotorFielDePrueba();
    app(ImportadorFormatosJuridicosService::class)->importarCarpeta(base_path('docs/formatos_fuente'));

    $estructura = clEstructura();
    $estructura['sucursal']->update(['direccion' => 'Av. Juárez 10', 'ciudad' => 'Tula de Allende', 'estado' => 'Hidalgo', 'codigo_postal' => '42800']);
    $this->gerente = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $estructura['sucursal']->id]);
    $puesto = Puesto::factory()->create(['nombre' => 'Gestor de cobranza', 'grupo_documental' => 'gestor', 'meses_periodo_prueba' => 2]);
    $this->persona = clExpedienteCompleto(Colaborador::factory()->create([
        'name' => 'Adrián',
        'apellidos' => 'Díaz Mena',
        'sucursal_principal_id' => $estructura['sucursal']->id,
        'departamento_id' => $estructura['departamento']->id,
        'puesto_id' => $puesto->id,
        'genero' => 'masculino',
        'curp' => 'DIMA900510HHGZNR01',
        'rfc' => 'DIMA900510AB1',
        'nss' => '12345678901',
        'domicilio' => 'Calle Hidalgo 5, Col. Centro, Tula, Hidalgo',
        'fecha_ingreso' => '2025-01-06',
        'sueldo_mensual' => 9000,
    ]));
    $this->causal = CausalRotacion::query()->where('clave', 'RV-01')->firstOrFail();
});

afterEach(function () {
    if (isset($this->disco)) {
        (new Filesystem)->deleteDirectory(storage_path('framework/testing/disks/'.$this->disco));
    }
});

test('antes de registrar: la renuncia y el finiquito se ven sin crear baja, cierre, trámite ni documento; al registrar la renuncia queda generada', function () {
    $fecha = now()->subDay()->toDateString();
    $datosFlujo = http_build_query(['tipo_baja' => 'renuncia', 'causal_rotacion_id' => $this->causal->id, 'fecha_efectiva' => $fecha, 'motivo' => '']);

    $documentos = $this->actingAs($this->gerente)->getJson("/rh/documentos-proceso/previa/baja/{$this->persona->id}?{$datosFlujo}")->assertOk()->json('data');
    expect(array_column($documentos, 'clave'))->toBe(['carta_renuncia']);

    foreach ([$documentos[0]['url'], route('rh.bajas.previa.finiquito', ['colaborador_id' => $this->persona->id, 'forma_salida' => 'RV', 'causal_rotacion_id' => $this->causal->id, 'fecha_baja' => $fecha])] as $url) {
        $pdf = $this->actingAs($this->gerente)->get($url);
        $pdf->assertOk();
        expect(substr((string) $pdf->getContent(), 0, 4))->toBe('%PDF');
    }

    expect(Baja::query()->count())->toBe(0)
        ->and(CierreLaboral::query()->count())->toBe(0)
        ->and(SolicitudInterna::query()->count())->toBe(0)
        ->and(GeneratedDocument::query()->count())->toBe(0);

    $this->actingAs($this->gerente)->post(route('rh.bajas.store'), [
        'colaborador_id' => $this->persona->id,
        'forma_salida' => 'RV',
        'causal_rotacion_id' => $this->causal->id,
        'fecha_baja' => $fecha,
        'recontratable' => 'si',
    ])->assertSessionHasNoErrors();

    $baja = Baja::query()->firstOrFail();
    expect($baja->estatus)->toBe(EstatusBaja::EnRevisionRh)
        ->and(GeneratedDocument::query()->where('documentable_type', (new CierreLaboral)->getMorphClass())->where('documentable_id', $baja->cierre_laboral_id)->where('clave_plantilla', 'carta_renuncia')->exists())->toBeTrue();
});
