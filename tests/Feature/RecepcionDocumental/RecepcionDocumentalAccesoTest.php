<?php

use App\Enums\EstadoCorteRecepcion;
use App\Enums\EstadoRecepcionDocumento;
use App\Enums\EstadoUsuario;
use App\Models\Colaborador;
use App\Models\Puesto;
use App\Models\RecepcionDocumentalCorte;
use App\Models\RecepcionDocumentalPdf;
use App\Models\RecepcionDocumentalRenglon;
use App\Models\Sucursal;
use App\Services\RecepcionDocumental\PeriodoRecepcion;
use App\Services\RecepcionDocumental\RecepcionDocumentalPdfService;
use App\Services\RecepcionDocumental\RecepcionDocumentalService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;

/*
| Recepción documental RH — permisos por acción (web y API móvil), palomeo,
| cierre / reapertura auditados y PDF versionado.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    // Las páginas nuevas aún no están en el manifest de Vite hasta el build.
    $this->withoutVite();
    Carbon::setTestNow(Carbon::parse('2026-11-20 18:00:00', 'UTC'));

    $this->sucursal = Sucursal::factory()->create(['nombre' => 'Toluca Centro']);
    $this->gestor = Puesto::factory()->create(['nombre' => 'Gestor', 'grupo_documental' => 'gestor']);
    $this->rh = clUsuario('rh_admin', ['sucursal_principal_id' => $this->sucursal->id]);
    $this->auxiliar = clUsuario('rh_auxiliar', ['sucursal_principal_id' => $this->sucursal->id]);
    $this->colaborador = clUsuario('colaborador', ['sucursal_principal_id' => $this->sucursal->id]);

    Colaborador::factory()->create([
        'name' => 'Adriana', 'apellidos' => 'Cortés Lima', 'fecha_ingreso' => '2026-11-05',
        'puesto_id' => $this->gestor->id, 'sucursal_principal_id' => $this->sucursal->id, 'estatus' => EstadoUsuario::Activo,
    ]);
    $this->corte = app(RecepcionDocumentalService::class)->generar(PeriodoRecepcion::desdeClave('2026-11'), $this->rh)['corte'];
});

afterEach(function () {
    Carbon::setTestNow();
});

test('el colaborador común recibe 403 en web y API; RH entra con 200', function () {
    $this->actingAs($this->colaborador)->get(route('rh.recepcion-documental.index'))->assertForbidden();
    $this->actingAs($this->colaborador)->get(route('rh.recepcion-documental.show', $this->corte))->assertForbidden();

    Sanctum::actingAs($this->colaborador);
    $this->getJson('/api/v1/rh/recepcion-documental')->assertForbidden();
    $this->getJson(sprintf('/api/v1/rh/recepcion-documental/%d', $this->corte->id))->assertForbidden();

    $this->actingAs($this->rh)->get(route('rh.recepcion-documental.index'))->assertOk();
    $this->actingAs($this->rh)->get(route('rh.recepcion-documental.show', $this->corte))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Rh/RecepcionDocumental/Show')
            ->where('corte.metricas.esperados', 3)
            ->where('corte.metricas.pendientes', 3)
            ->has('renglones.data', 3));

    Sanctum::actingAs($this->rh);
    $this->getJson('/api/v1/rh/recepcion-documental')->assertOk()->assertJsonPath('data.0.periodo', '2026-11')->assertJsonPath('data.0.metricas.esperados', 3);
    $this->getJson(sprintf('/api/v1/rh/recepcion-documental/%d', $this->corte->id))->assertOk()->assertJsonPath('data.sucursales_con_pendientes.0.nombre', 'Toluca Centro');
    $this->getJson(sprintf('/api/v1/rh/recepcion-documental/%d/renglones?estado=pendiente', $this->corte->id))->assertOk()->assertJsonPath('meta.total', 3);
});

test('RH palomea desde la lista (JSON) y el cambio queda en la bitácora; No aplica exige justificación', function () {
    $renglon = RecepcionDocumentalRenglon::query()->where('corte_id', $this->corte->id)->orderBy('id')->firstOrFail();

    $this->actingAs($this->rh)->patchJson(route('rh.recepcion-documental.renglones.marcar', $renglon), ['estado' => 'recibido'])
        ->assertOk()
        ->assertJsonPath('renglon.estado', 'recibido')
        ->assertJsonPath('metricas.recibidos', 1);

    expect($renglon->refresh()->estado)->toBe(EstadoRecepcionDocumento::Recibido)
        ->and($renglon->recibido_por)->toBe($this->rh->id)
        ->and(Activity::query()->where('event', 'recepcion_documental_renglon_actualizado')->where('subject_id', $renglon->id)->exists())->toBeTrue();

    $otro = RecepcionDocumentalRenglon::query()->where('corte_id', $this->corte->id)->where('id', '!=', $renglon->id)->firstOrFail();
    $this->actingAs($this->rh)->patchJson(route('rh.recepcion-documental.renglones.marcar', $otro), ['estado' => 'no_aplica'])
        ->assertUnprocessable()->assertJsonValidationErrors('justificacion');

    // Misma regla en la API móvil.
    Sanctum::actingAs($this->auxiliar);
    $this->patchJson(sprintf('/api/v1/rh/recepcion-documental/renglones/%d', $otro->id), ['estado' => 'no_aplica', 'justificacion' => 'El colaborador firmó en otra sucursal.'])
        ->assertOk()->assertJsonPath('data.renglon.estado', 'no_aplica');

    Sanctum::actingAs($this->colaborador);
    $this->patchJson(sprintf('/api/v1/rh/recepcion-documental/renglones/%d', $otro->id), ['estado' => 'recibido'])->assertForbidden();
});

test('cerrar deja constancia; reabrir exige permiso y motivo y queda auditado', function () {
    $this->actingAs($this->rh)->post(route('rh.recepcion-documental.cerrar', $this->corte))->assertSessionHasErrors('observaciones');
    $this->actingAs($this->rh)->post(route('rh.recepcion-documental.cerrar', $this->corte), ['observaciones' => 'Faltan originales de Toluca; se enviarán en diciembre.'])->assertSessionHasNoErrors();
    expect($this->corte->refresh()->estado)->toBe(EstadoCorteRecepcion::Cerrado);

    // Cerrado = solo lectura: no se palomea.
    $renglon = RecepcionDocumentalRenglon::query()->where('corte_id', $this->corte->id)->firstOrFail();
    $this->actingAs($this->rh)->patchJson(route('rh.recepcion-documental.renglones.marcar', $renglon), ['estado' => 'recibido'])->assertUnprocessable();

    // RH auxiliar no tiene «reabrir».
    $this->actingAs($this->auxiliar)->post(route('rh.recepcion-documental.reabrir', $this->corte), ['motivo' => 'Llegó un paquete tarde.'])->assertForbidden();
    $this->actingAs($this->rh)->post(route('rh.recepcion-documental.reabrir', $this->corte), ['motivo' => ''])->assertSessionHasErrors('motivo');
    $this->actingAs($this->rh)->post(route('rh.recepcion-documental.reabrir', $this->corte), ['motivo' => 'Llegó un paquete tarde de Toluca.'])->assertSessionHasNoErrors();

    $corte = $this->corte->refresh();
    expect($corte->estado)->not->toBe(EstadoCorteRecepcion::Cerrado)
        ->and($corte->reabierto_conteo)->toBe(1)
        ->and($corte->motivo_reapertura)->toBe('Llegó un paquete tarde de Toluca.')
        ->and(Activity::query()->where('event', 'recepcion_documental_corte_reabierto')->where('subject_id', $corte->id)->value('properties')['motivo'] ?? null)->toBe('Llegó un paquete tarde de Toluca.')
        ->and(Activity::query()->where('event', 'recepcion_documental_corte_cerrado')->where('subject_id', $corte->id)->exists())->toBeTrue();
});

test('el PDF operativo se genera, se guarda versionado y no destruye la versión anterior', function (string $variante) {
    $this->actingAs($this->rh)->get(route('rh.recepcion-documental.pdf', ['corte' => $this->corte, 'variante' => $variante]))
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    // Segunda generación por el servicio (el cierre de la petición HTTP de
    // prueba limpia el disco falso compartido; en producción no aplica).
    app(RecepcionDocumentalPdfService::class)->generar($this->corte, $this->rh, $variante);

    $versiones = RecepcionDocumentalPdf::query()->where('corte_id', $this->corte->id)->where('variante', $variante)->orderBy('version')->get();

    expect($versiones->pluck('version')->all())->toBe([1, 2])
        ->and($versiones[0]->path)->not->toBe($versiones[1]->path)
        ->and(Storage::disk('nas')->exists($versiones[1]->path))->toBeTrue()
        ->and(Activity::query()->where('event', 'recepcion_documental_pdf_generado')->count())->toBe(2);

    $this->actingAs($this->colaborador)->get(route('rh.recepcion-documental.pdf', ['corte' => $this->corte]))->assertForbidden();
})->with([RecepcionDocumentalPdf::VARIANTE_CONTROL, RecepcionDocumentalPdf::VARIANTE_ESTADO]);

test('el comando materializa el mes en curso de forma idempotente', function () {
    $this->artisan('recepcion-documental:materializar')->assertSuccessful();
    $this->artisan('recepcion-documental:materializar')->assertSuccessful();

    expect(RecepcionDocumentalCorte::query()->count())->toBe(1)
        ->and(RecepcionDocumentalRenglon::query()->count())->toBe(3);
});

test('RH genera el corte de un mes desde la web', function () {
    $this->actingAs($this->rh)->post(route('rh.recepcion-documental.generar'), ['periodo' => '2026-10'])->assertRedirect();
    $this->actingAs($this->colaborador)->post(route('rh.recepcion-documental.generar'), ['periodo' => '2026-10'])->assertForbidden();

    expect(RecepcionDocumentalCorte::query()->where('periodo', '2026-10')->value('estado'))->not->toBeNull();
});
