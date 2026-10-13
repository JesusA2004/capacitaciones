<?php

use App\Enums\EstadoReciboNomina;
use App\Enums\EstadoUsuario;
use App\Enums\PeriodicidadNomina;
use App\Models\Colaborador;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Nomina\LoteNominaService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;
use Smalot\PdfParser\Parser;
use Spatie\Activitylog\Models\Activity;

/*
| «Descargar todos para imprimir» (LoteNominaService::pdfImpresion): un solo
| PDF con los PDFs CONGELADOS de los recibos del lote emitido, ordenados por
| sucursal → nombre. Los recibos se entregan impresos: el colaborador nunca
| lo descarga (403) y RH sí.
*/

beforeEach(function () {
    config(['features.recibos_colaborador' => false]);
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    Notification::fake();

    $this->rh = clUsuario('rh_admin');
    $this->rh->colaborador?->update(['sueldo_mensual' => null]);
    $estructura = clEstructura();
    $otraSucursal = Sucursal::factory()->create(['empresa_id' => $estructura['empresa']->id, 'nombre' => 'Acapulco']);

    $activo = ['estatus' => EstadoUsuario::Activo, 'sueldo_mensual' => 9000, 'fecha_ingreso' => '2025-01-01'];
    Colaborador::factory()->create(['name' => 'Zoe', 'apellidos' => 'Ruiz', 'sucursal_principal_id' => $estructura['sucursal']->id] + $activo);
    Colaborador::factory()->create(['name' => 'Beto', 'apellidos' => 'Sosa', 'sucursal_principal_id' => $estructura['sucursal']->id] + $activo);
    Colaborador::factory()->create(['name' => 'Carla', 'apellidos' => 'Díaz', 'sucursal_principal_id' => $otraSucursal->id] + $activo);

    // Colaborador normal (sin sueldo: no entra al lote).
    $persona = Colaborador::factory()->create(['estatus' => EstadoUsuario::Activo, 'sueldo_mensual' => null]);
    $this->cuenta = User::factory()->create(['colaborador_id' => $persona->id]);
    $this->cuenta->assignRole('colaborador');

    $this->lotes = app(LoteNominaService::class);
    $this->lote = $this->lotes->preparar(PeriodicidadNomina::Semanal, CarbonImmutable::parse('2026-10-08'), $this->rh);
});

test('un lote en borrador no permite la descarga masiva ni muestra el botón', function () {
    $this->actingAs($this->rh)
        ->get(route('rh.nomina.lotes.show', $this->lote))
        ->assertInertia(fn ($page) => $page->where('impresion', null));

    $this->actingAs($this->rh)
        ->from(route('rh.nomina.lotes.show', $this->lote))
        ->get(route('rh.nomina.lotes.imprimir', $this->lote))
        ->assertRedirect(route('rh.nomina.lotes.show', $this->lote))
        ->assertSessionHas('toast.message', 'Solo un lote emitido se puede descargar para imprimir.');

    Sanctum::actingAs($this->rh);
    $this->getJson("/api/v1/rh/nomina/lotes/{$this->lote->id}/imprimir")->assertUnprocessable();
});

test('RH descarga un solo PDF con los 3 recibos del lote emitido, en orden sucursal → nombre, y queda auditado', function () {
    $this->lotes->emitir($this->lote, $this->rh);

    expect($this->lote->recibos()->count())->toBe(3);

    $this->actingAs($this->rh)
        ->get(route('rh.nomina.lotes.show', $this->lote))
        ->assertInertia(fn ($page) => $page->where('impresion.total', 3)->where('impresion.sin_pdf', 0));

    $respuesta = $this->actingAs($this->rh)->get(route('rh.nomina.lotes.imprimir', $this->lote));

    $respuesta->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition', 'attachment; filename="recibos_nomina_semanal_2026-10-05_2026-10-11.pdf"');

    $contenido = $respuesta->getContent();
    $paginasPorRecibo = $this->lote->recibos()->orderBy('id')->get()
        ->map(fn ($r) => (new Fpdi)->setSourceFile(StreamReader::createByString((string) Storage::disk($r->pdf_disk)->get($r->pdf_path))))
        ->sum();
    expect($contenido)->toStartWith('%PDF')
        ->and((new Fpdi)->setSourceFile(StreamReader::createByString($contenido)))->toBe($paginasPorRecibo);

    $auditoria = Activity::query()->where('event', 'descarga_masiva_recibos')->sole();
    expect($auditoria->causer_id)->toBe($this->rh->id)
        ->and($auditoria->subject_id)->toBe($this->lote->id)
        ->and($auditoria->properties['recibos'])->toBe(3);

    // La API móvil de RH usa el mismo service.
    Sanctum::actingAs($this->rh);
    $this->get("/api/v1/rh/nomina/lotes/{$this->lote->id}/imprimir")->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

test('el orden de impresión es sucursal y luego nombre del colaborador', function () {
    $this->lotes->emitir($this->lote, $this->rh);

    $contenido = $this->actingAs($this->rh)->get(route('rh.nomina.lotes.imprimir', $this->lote))->assertOk()->getContent();
    $texto = mb_strtoupper((new Parser)->parseContent($contenido)->getText());

    // Acapulco (Carla) → Cuernavaca (Beto, luego Zoe).
    $carla = mb_strpos($texto, 'CARLA');
    $beto = mb_strpos($texto, 'BETO');
    $zoe = mb_strpos($texto, 'ZOE');
    expect($carla)->not->toBeFalse()
        ->and($beto)->not->toBeFalse()
        ->and($zoe)->not->toBeFalse()
        ->and($carla < $beto && $beto < $zoe)->toBeTrue();
});

test('un colaborador recibe 403 en la descarga masiva (web y app)', function () {
    $this->lotes->emitir($this->lote, $this->rh);

    $this->actingAs($this->cuenta)->get(route('rh.nomina.lotes.imprimir', $this->lote))->assertForbidden();

    Sanctum::actingAs($this->cuenta);
    $this->getJson("/api/v1/rh/nomina/lotes/{$this->lote->id}/imprimir")->assertForbidden();
});

test('si algún recibo no tiene PDF generado se bloquea la descarga con un mensaje claro', function () {
    $this->lotes->emitir($this->lote, $this->rh);
    $recibo = $this->lote->recibos()->where('estado', EstadoReciboNomina::Emitido->value)->firstOrFail();
    Storage::disk($recibo->pdf_disk)->delete($recibo->pdf_path);

    $this->actingAs($this->rh)
        ->get(route('rh.nomina.lotes.show', $this->lote))
        ->assertInertia(fn ($page) => $page->where('impresion.total', 3)->where('impresion.sin_pdf', 1));

    $this->actingAs($this->rh)
        ->from(route('rh.nomina.lotes.show', $this->lote))
        ->get(route('rh.nomina.lotes.imprimir', $this->lote))
        ->assertRedirect(route('rh.nomina.lotes.show', $this->lote))
        ->assertSessionHas('toast.message', 'Hay 1 recibo sin PDF generado.');

    Sanctum::actingAs($this->rh);
    $this->getJson("/api/v1/rh/nomina/lotes/{$this->lote->id}/imprimir")
        ->assertUnprocessable()
        ->assertJsonPath('errors.lote.0', 'Hay 1 recibo sin PDF generado.');

    expect(Activity::query()->where('event', 'descarga_masiva_recibos')->exists())->toBeFalse();
});
