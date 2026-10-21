<?php

use App\Enums\EstadoReciboNomina;
use App\Models\Colaborador;
use App\Models\EmployeeDocument;
use App\Models\ReciboNomina;
use App\Models\SolicitudInterna;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/*
| Punto 19 L y M del cierre 2026-10-20 (PDF en la app): la app descarga los
| documentos SOLO por la API con su Bearer token (nunca una URL pública del
| NAS) y una cuenta sin permiso nunca descarga el documento de otra persona.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();
    $this->rh = clUsuario('rh_admin');
    $estructura = clEstructura();
    $this->colaborador = Colaborador::factory()->create([
        'sucursal_principal_id' => $estructura['sucursal']->id,
        'sueldo_mensual' => 15000,
        'numero_empleado' => 'EMP-0410',
    ]);
    $this->titular = User::factory()->create(['colaborador_id' => $this->colaborador->id]);
    $this->titular->assignRole('colaborador');
    $this->ajeno = clUsuario('colaborador');
});

test('L: el PDF de un documento laboral se descarga autenticado — sin token 401, el titular 200 application/pdf sin redirigir al NAS', function () {
    Sanctum::actingAs($this->rh);
    clPlantilla('contrato_confidencialidad');
    $id = $this->postJson("/api/v1/rh/colaboradores/{$this->colaborador->id}/documentos-laborales", ['clave' => 'contrato_confidencialidad'])
        ->assertCreated()
        ->json('data.id');

    // Nueva instancia de la app sin sesión (se olvida el guard de Sanctum).
    app('auth')->forgetGuards();
    $this->getJson("/api/v1/colaborador/documentos-laborales/{$id}/descargar")->assertUnauthorized();

    Sanctum::actingAs($this->titular);
    $respuesta = $this->get("/api/v1/colaborador/documentos-laborales/{$id}/descargar")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    // Se sirve el archivo (streaming), nunca una redirección a una URL pública.
    expect($respuesta->headers->get('Location'))->toBeNull()
        ->and($respuesta->streamedContent())->toStartWith('%PDF-');
});

test('M: otra cuenta sin permiso no descarga el documento laboral, la solicitud, el recibo ni el expediente ajenos', function () {
    Sanctum::actingAs($this->rh);
    clPlantilla('contrato_confidencialidad');
    $documentoLaboral = $this->postJson("/api/v1/rh/colaboradores/{$this->colaborador->id}/documentos-laborales", ['clave' => 'contrato_confidencialidad'])
        ->json('data.id');

    config(['features.recibos_colaborador' => true]);
    $recibo = ReciboNomina::factory()->create(['colaborador_id' => $this->colaborador->id, 'estado' => EstadoReciboNomina::Emitido->value]);
    $solicitud = SolicitudInterna::factory()->create(['user_id' => $this->titular->id, 'colaborador_id' => $this->colaborador->id]);
    $expediente = EmployeeDocument::factory()->create(['colaborador_id' => $this->colaborador->id]);

    Sanctum::actingAs($this->ajeno);

    $this->get("/api/v1/colaborador/documentos-laborales/{$documentoLaboral}/descargar")->assertForbidden();
    $this->get("/api/v1/colaborador/recibos/{$recibo->id}/pdf")->assertForbidden();
    $this->get("/api/v1/solicitudes/{$solicitud->id}/permiso-pdf")->assertForbidden();
    $this->get("/api/v1/rh/expedientes/{$this->colaborador->id}/documentos/{$expediente->id}/ver")->assertForbidden();
});

test('sin token ninguna ruta de descarga que usa la app responde contenido', function () {
    $recibo = ReciboNomina::factory()->create(['colaborador_id' => $this->colaborador->id]);
    $solicitud = SolicitudInterna::factory()->create(['user_id' => $this->titular->id, 'colaborador_id' => $this->colaborador->id]);
    $expediente = EmployeeDocument::factory()->create(['colaborador_id' => $this->colaborador->id]);

    $this->getJson("/api/v1/colaborador/recibos/{$recibo->id}/pdf")->assertUnauthorized();
    $this->getJson("/api/v1/solicitudes/{$solicitud->id}/permiso-pdf")->assertUnauthorized();
    $this->getJson("/api/v1/rh/expedientes/{$this->colaborador->id}/documentos/{$expediente->id}/ver")->assertUnauthorized();
});
