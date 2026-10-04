<?php

use App\Enums\EstadoSolicitudInterna;
use App\Models\Colaborador;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\GeneratedDocument;
use App\Models\OfficialFormat;
use App\Models\OfficialFormatGeneration;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Services\Nomina\PrestamoAutorizacionService;
use App\Services\Solicitudes\SolicitudFormatoOficialService;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $this->seed(DocumentTypeSeeder::class);
    Storage::fake('nas');

    $this->rh = User::factory()->create();
    $this->rh->assignRole('rh_admin');
});

function crearPdfDePruebaFormatoAutomatico(string $texto = 'Documento de prueba'): string
{
    $pdf = new Fpdi;
    $pdf->AddPage();
    $pdf->SetFont('Helvetica', '', 14);
    $pdf->Cell(0, 10, $texto);

    return (string) $pdf->Output('S');
}

function crearFormatoOficialConfiguradoParaSlug(string $slug): OfficialFormat
{
    $ruta = 'formatos-oficiales/originales/'.uniqid($slug, true).'.pdf';
    Storage::disk('nas')->put($ruta, crearPdfDePruebaFormatoAutomatico());

    return OfficialFormat::factory()->configurado()->create([
        'slug' => $slug,
        'source_disk' => 'nas',
        'source_path' => $ruta,
        'is_active' => true,
    ]);
}

test('aprobar una solicitud de prestamo genera el contrato de credito desde documentos maestros, no desde el formato oficial anterior', function () {
    // El formato oficial anterior sigue cargado: ya no debe usarse para préstamos.
    crearFormatoOficialConfiguradoParaSlug('contrato-credito-colaboradores');

    foreach (['prestamo_contrato', 'prestamo_pagare', 'prestamo_consentimiento_retencion'] as $clave) {
        clPlantilla($clave);
    }

    $colaborador = User::factory()->create(['colaborador_id' => Colaborador::factory()->create()->id]);
    $solicitud = SolicitudInterna::factory()->create([
        'user_id' => $colaborador->id,
        'tipo' => 'prestamo',
        'estado' => EstadoSolicitudInterna::Enviada,
        'monto_solicitado' => 5000,
        'plazo_meses' => 6,
    ]);

    // Un préstamo nunca se aprueba con el botón genérico
    // (SolicitudesService::cambiarEstado() lo bloquea) — solo
    // PrestamoAutorizacionService::autorizar() (pantalla "Autorizar
    // préstamo") fija monto/plazo y aprueba de verdad.
    $prestamo = app(PrestamoAutorizacionService::class)->autorizar($solicitud, [
        'monto_autorizado' => 5000,
        'plazo_autorizado' => 6,
    ], $this->rh);

    expect(OfficialFormatGeneration::where('solicitud_interna_id', $solicitud->id)->exists())->toBeFalse()
        ->and($prestamo->refresh()->contrato_documento_id)->not->toBeNull()
        ->and($prestamo->pagare_documento_id)->not->toBeNull()
        ->and(GeneratedDocument::query()->where('colaborador_id', $colaborador->colaborador_id)->pluck('clave_plantilla')->sort()->values()->all())
        ->toBe(['prestamo_consentimiento_retencion', 'prestamo_contrato', 'prestamo_pagare']);
});

test('aprobar una solicitud de vacaciones genera el formato de vacaciones', function () {
    crearFormatoOficialConfiguradoParaSlug('formato-vacaciones');

    $colaborador = User::factory()->create();
    $solicitud = SolicitudInterna::factory()->create([
        'user_id' => $colaborador->id,
        'tipo' => 'vacaciones',
        'estado' => EstadoSolicitudInterna::Enviada,
        'dias_solicitados' => 5,
    ]);

    $this->actingAs($this->rh)
        ->post(route('rh.solicitudes.aprobar', $solicitud))
        ->assertSessionHasNoErrors();

    expect(OfficialFormatGeneration::where('solicitud_interna_id', $solicitud->id)->exists())->toBeTrue();
});

test('aprobar una solicitud general no genera ningun documento oficial', function () {
    $solicitud = SolicitudInterna::factory()->create([
        'tipo' => 'solicitud_general',
        'estado' => EstadoSolicitudInterna::Enviada,
    ]);

    $this->actingAs($this->rh)
        ->post(route('rh.solicitudes.aprobar', $solicitud))
        ->assertSessionHasNoErrors();

    expect(OfficialFormatGeneration::where('solicitud_interna_id', $solicitud->id)->exists())->toBeFalse();
});

test('la generacion automatica es idempotente y no duplica si se aprueba de nuevo', function () {
    crearFormatoOficialConfiguradoParaSlug('formato-vacaciones');

    $solicitud = SolicitudInterna::factory()->create([
        'tipo' => 'vacaciones',
        'estado' => EstadoSolicitudInterna::Enviada,
        'dias_solicitados' => 3,
    ]);

    $this->actingAs($this->rh)->post(route('rh.solicitudes.aprobar', $solicitud))
        ->assertSessionHasNoErrors();

    // Aprobar de nuevo un estado que ya es "aprobada" es una transicion
    // invalida (EstadoSolicitudInterna::puedeTransicionarA) y debe
    // rechazarse con 422 -- prueba directamente el servicio para la parte
    // de idempotencia de la generacion en si.
    $servicio = app(SolicitudFormatoOficialService::class);
    $servicio->generarSiAplica($solicitud->fresh(), $this->rh);

    expect(OfficialFormatGeneration::where('solicitud_interna_id', $solicitud->id)->count())->toBe(1);
});

test('un formato no configurado no bloquea la aprobacion y no genera nada', function () {
    // Sin OfficialFormat creado en absoluto para 'formato-permiso'.
    $solicitud = SolicitudInterna::factory()->create([
        'tipo' => 'permiso_con_goce',
        'estado' => EstadoSolicitudInterna::Enviada,
    ]);

    $this->actingAs($this->rh)
        ->post(route('rh.solicitudes.aprobar', $solicitud))
        ->assertSessionHasNoErrors();

    expect(OfficialFormatGeneration::where('solicitud_interna_id', $solicitud->id)->exists())->toBeFalse();
});

test('subir el firmado archiva el documento en el expediente del colaborador', function () {
    $formato = crearFormatoOficialConfiguradoParaSlug('formato-vacaciones');
    $colaborador = User::factory()->create();
    $solicitud = SolicitudInterna::factory()->create([
        'user_id' => $colaborador->id,
        'tipo' => 'vacaciones',
        'estado' => EstadoSolicitudInterna::Enviada,
        'dias_solicitados' => 2,
    ]);

    $this->actingAs($this->rh)->post(route('rh.solicitudes.aprobar', $solicitud))
        ->assertSessionHasNoErrors();

    $generacion = OfficialFormatGeneration::where('solicitud_interna_id', $solicitud->id)->firstOrFail();

    $this->actingAs($this->rh)
        ->post(route('rh.formatos-oficiales.subir-firmado', $generacion), [
            'archivo' => UploadedFile::fake()->create('firmado.pdf', 50, 'application/pdf'),
        ])
        ->assertSessionHasNoErrors();

    $generacion->refresh();
    expect($generacion->status->value)->toBe('firmado')
        ->and($generacion->signed_uploaded_by)->toBe($this->rh->id);

    $tipo = DocumentType::where('clave', 'formato_vacaciones')->firstOrFail();
    expect(EmployeeDocument::where('user_id', $colaborador->id)->where('document_type_id', $tipo->id)->exists())->toBeTrue();

    unset($formato);
});

test('un usuario fuera de alcance no puede previsualizar el documento oficial de otro colaborador', function () {
    crearFormatoOficialConfiguradoParaSlug('formato-vacaciones');

    $otraSucursalColaborador = User::factory()->create();
    $solicitud = SolicitudInterna::factory()->create([
        'user_id' => $otraSucursalColaborador->id,
        'tipo' => 'vacaciones',
        'estado' => EstadoSolicitudInterna::Enviada,
        'dias_solicitados' => 1,
    ]);

    $this->actingAs($this->rh)->post(route('rh.solicitudes.aprobar', $solicitud))
        ->assertSessionHasNoErrors();

    $generacion = OfficialFormatGeneration::where('solicitud_interna_id', $solicitud->id)->firstOrFail();

    $auxiliarSinAlcance = User::factory()->create();
    $auxiliarSinAlcance->assignRole('gerente_sucursal');

    $this->actingAs($auxiliarSinAlcance)
        ->get(route('rh.formatos-oficiales.previsualizar', $generacion))
        ->assertNotFound();
});
