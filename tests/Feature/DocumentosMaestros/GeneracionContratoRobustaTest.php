<?php

use App\Enums\TipoContratacion;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\GeneratedDocument;
use App\Models\Puesto;
use App\Models\User;
use App\Services\DocumentosMaestros\ImportadorFormatosJuridicosService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
| Generar el contrato de capacitación inicial desde la ruta WEB real
| (POST /rh/documentos-proceso/contrato/{id}/generar) para un Gestor en
| incorporación con el expediente completo, como RH y como super_admin.
| Ningún problema de datos o de infraestructura puede responder 500.
*/

function grColaborador(array $estructura, array $extra = []): Colaborador
{
    $puesto = Puesto::factory()->create(['nombre' => 'Gestor', 'grupo_documental' => 'gestor', 'meses_periodo_prueba' => 3]);

    $colaborador = clExpedienteCompleto(Colaborador::factory()->create([
        'name' => 'Nancy Nallely',
        'apellidos' => 'López García',
        'estatus' => 'en_incorporacion',
        'estado_alta' => 'pendiente_contrato',
        'sucursal_principal_id' => $estructura['sucursal']->id,
        'departamento_id' => $estructura['departamento']->id,
        'puesto_id' => $puesto->id,
        'genero' => 'femenino',
        'fecha_nacimiento' => '1995-03-12',
        'curp' => 'LOGN950312MMSPRN09',
        'rfc' => 'LOGN950312AB1',
        'nss' => '12345678901',
        'telefono' => '7771234567',
        'correo_personal' => 'nancy@example.com',
        'domicilio' => 'Calle Morelos 10, Col. Centro, Cuernavaca, Morelos',
        'domicilio_colonia' => 'Centro',
        'domicilio_municipio' => 'Cuernavaca',
        'domicilio_estado' => 'Morelos',
        'domicilio_cp' => '62000',
        'nacionalidad' => 'Mexicana',
        'estado_civil' => 'soltero',
        'lugar_nacimiento' => 'Cuernavaca, Morelos',
        'clave_elector' => 'LOGN950312MMS',
        'profesion' => 'Bachillerato',
        'fecha_ingreso' => '2026-10-20',
        'sueldo_mensual' => 9000,
        'salario_diario' => 300,
        'beneficiario_nombre' => 'María García Ruiz',
        'beneficiario_parentesco' => 'Madre',
        ...$extra,
    ]));
    User::factory()->create(['colaborador_id' => $colaborador->id]);

    return $colaborador;
}

function grContrato(Colaborador $colaborador): ContratoLaboral
{
    return ContratoLaboral::query()->create([
        'colaborador_id' => $colaborador->id,
        'tipo' => TipoContratacion::CapacitacionInicial,
        'fecha_inicio' => '2026-10-20',
        'fecha_fin' => '2027-01-19',
        'estado' => 'vigente',
        'sueldo_mensual' => 9000,
        'puesto_id' => $colaborador->puesto_id,
        'sucursal_id' => $colaborador->sucursal_principal_id,
    ]);
}

beforeEach(function () {
    if (! is_dir(base_path('docs/formatos_fuente')) || glob(base_path('docs/formatos_fuente').'/*.docx') === []) {
        $this->markTestSkipped('Sin originales jurídicos en docs/formatos_fuente (no se versionan).');
    }

    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();
    dmMotorFielDePrueba();
    app(ImportadorFormatosJuridicosService::class)->importarCarpeta(base_path('docs/formatos_fuente'));
    $this->estructura = clEstructura();
    $this->estructura['sucursal']->update(['direccion' => 'Av. Morelos 120', 'ciudad' => 'Cuernavaca', 'estado' => 'Morelos', 'codigo_postal' => '62000']);
});

test('RH y super_admin generan el paquete de un Gestor en incorporación por la ruta web, sin 500', function (string $rol) {
    $colaborador = grColaborador($this->estructura);
    $contrato = grContrato($colaborador);
    $this->actingAs(clUsuario($rol));

    foreach (['contrato_capacitacion', 'contrato_confidencialidad', 'contrato_no_competencia'] as $clave) {
        $this->postJson("/rh/documentos-proceso/contrato/{$contrato->id}/generar", ['clave' => $clave, 'proceso' => 'alta'])
            ->assertCreated();
    }

    // Segundo intento sobre un documento ya generado: 422 claro, nunca 500.
    $this->postJson("/rh/documentos-proceso/contrato/{$contrato->id}/generar", ['clave' => 'contrato_capacitacion', 'proceso' => 'alta'])
        ->assertUnprocessable();

    $generados = GeneratedDocument::query()->where('documentable_id', $contrato->id)->get();
    expect($generados)->toHaveCount(3);

    // Las fechas de los TRES documentos del paquete son la fecha de ingreso
    // (20 de octubre de 2026), nunca la de hoy ni otra.
    foreach ($generados as $documento) {
        foreach ((array) $documento->payload as $campo => $valor) {
            if (preg_match('/\d{1,2} (?:de|DE|días del mes de) \p{L}+ (?:de|DE|del año) \d{4}/u', (string) $valor) === 1 && ! str_contains((string) $campo, 'nacimiento')) {
                expect(mb_strtolower((string) $valor))->toMatch('/20 (de|días del mes de) octubre (de|del año) 2026|19 de enero de 2027/u');
            }
        }
    }
})->with(['rh_admin', 'super_admin']);

test('sin fecha de ingreso: generar y previsualizar el contrato responden 422 pidiendo capturarla', function () {
    $colaborador = grColaborador($this->estructura, ['fecha_ingreso' => null]);
    $contrato = grContrato($colaborador);
    $this->actingAs(clUsuario('rh_admin'));

    $this->postJson("/rh/documentos-proceso/contrato/{$contrato->id}/generar", ['clave' => 'contrato_capacitacion', 'proceso' => 'alta'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.fecha_ingreso.0', fn (string $m): bool => str_contains($m, 'Captura la fecha de ingreso'));

    $this->getJson("/rh/documentos-proceso/contrato/{$contrato->id}/vista-previa?clave=contrato_capacitacion&proceso=alta")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('fecha_ingreso');

    expect(GeneratedDocument::query()->count())->toBe(0);
});

test('regresión 500: con un nombre muy largo la ruta del NAS nunca pasa de 191 caracteres (MariaDB estricto)', function () {
    // El nombre va en la carpeta del expediente Y en el archivo: así se
    // pasaba de varchar(191) y el INSERT tiraba «Data too long for column 'path'».
    $colaborador = grColaborador($this->estructura, [
        'name' => 'Nancy Nallely Guadalupe Alejandra',
        'apellidos' => 'López García de la Fuente Hernández Villaseñor',
        'numero_empleado' => 'EMP-0123',
    ]);
    $contrato = grContrato($colaborador);
    $this->actingAs(clUsuario('rh_admin'));

    $this->postJson("/rh/documentos-proceso/contrato/{$contrato->id}/generar", ['clave' => 'contrato_capacitacion', 'proceso' => 'alta'])
        ->assertCreated();

    $documento = GeneratedDocument::query()->where('documentable_id', $contrato->id)->firstOrFail();

    expect(mb_strlen($documento->path))->toBeLessThanOrEqual(191)
        ->and(mb_strlen((string) $documento->docx_path))->toBeLessThanOrEqual(191)
        ->and($documento->path)->toEndWith('.pdf')
        ->and(Storage::disk($documento->disk)->exists($documento->path))->toBeTrue();
});

test('regresión 500: si la BD rechaza el registro, responde 422 con un mensaje claro y no deja el PDF huérfano', function () {
    $colaborador = grColaborador($this->estructura);
    $contrato = grContrato($colaborador);
    $this->actingAs(clUsuario('super_admin'));
    GeneratedDocument::creating(function (): void {
        throw new RuntimeException("SQLSTATE[22001]: String data, right truncated: 1406 Data too long for column 'path'");
    });

    $this->postJson("/rh/documentos-proceso/contrato/{$contrato->id}/generar", ['clave' => 'contrato_capacitacion', 'proceso' => 'alta'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.documento.0', fn (string $m): bool => str_contains($m, 'No se pudo registrar') && str_contains($m, 'Sistemas'));

    expect(GeneratedDocument::query()->count())->toBe(0)
        ->and(collect(Storage::disk(config('expedientes.disk'))->allFiles())->filter(fn (string $f): bool => str_contains($f, '/Contratos/'))->all())->toBe([]);
});

test('regresión 500: un NSS con guiones del modal de datos faltantes es 422, nunca «Data too long»', function () {
    $colaborador = grColaborador($this->estructura, ['nss' => null]);
    $contrato = grContrato($colaborador);
    $this->actingAs(clUsuario('rh_admin'));

    $this->postJson("/rh/documentos-proceso/contrato/{$contrato->id}/generar", [
        'clave' => 'contrato_capacitacion', 'proceso' => 'alta', 'completar' => ['nss' => '1234-5678-901'],
    ])->assertUnprocessable()->assertJsonValidationErrors('nss');

    expect($colaborador->refresh()->nss)->toBeNull();
});
