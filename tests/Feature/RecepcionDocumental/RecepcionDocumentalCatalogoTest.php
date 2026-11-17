<?php

use App\Enums\EstadoCorteRecepcion;
use App\Enums\EstadoRecepcionDocumento;
use App\Enums\EstadoSolicitudInterna;
use App\Enums\EstadoUsuario;
use App\Enums\ReglaRecepcionDocumental;
use App\Enums\TipoSolicitudInterna;
use App\Models\Colaborador;
use App\Models\Puesto;
use App\Models\RecepcionDocumentalCatalogo;
use App\Models\RecepcionDocumentalCorte;
use App\Models\RecepcionDocumentalRenglon;
use App\Models\SolicitudInterna;
use App\Models\Sucursal;
use App\Services\RecepcionDocumental\CatalogoReglasRecepcion;
use App\Services\RecepcionDocumental\PeriodoRecepcion;
use App\Services\RecepcionDocumental\RecepcionDocumentalService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;

/*
| Recepción documental — catálogo configurable de «lo que recibo»
| (docs/RECEPCION_DOCUMENTAL_RH.md → «Catálogo de lo que se recibe»). El
| paquete de alta es el REAL de config/documentos_maestros.php →
| paquetes.alta.capacitacion_inicial: Gestor firma 3 contratos.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $this->withoutVite();
    Carbon::setTestNow(Carbon::parse('2026-11-20 18:00:00', 'UTC'));
    $this->sucursal = Sucursal::factory()->create(['nombre' => 'Toluca Centro']);
    $this->gestor = Puesto::factory()->create(['nombre' => 'Gestor', 'grupo_documental' => 'gestor']);
    $this->rh = clUsuario('rh_admin', ['sucursal_principal_id' => $this->sucursal->id]);
    $this->auxiliar = clUsuario('rh_auxiliar', ['sucursal_principal_id' => $this->sucursal->id]);
    $this->colaborador = clUsuario('colaborador', ['sucursal_principal_id' => $this->sucursal->id]);
    app(CatalogoReglasRecepcion::class)->sembrar();
});

afterEach(function () {
    Carbon::setTestNow();
});

function rcEntrada(ReglaRecepcionDocumental $regla): RecepcionDocumentalCatalogo
{
    return RecepcionDocumentalCatalogo::query()->where('regla', $regla->value)->firstOrFail();
}

/** Deja encendidas solo las reglas dadas (como «activo Vacaciones y Contratos»). */
function rcSoloActivas(ReglaRecepcionDocumental ...$reglas): void
{
    RecepcionDocumentalCatalogo::query()->whereNotIn('regla', array_map(fn ($r) => $r->value, $reglas))->update(['activa' => false]);
}

function rcAlta(object $prueba, string $fecha): Colaborador
{
    return Colaborador::factory()->create([
        'fecha_ingreso' => $fecha, 'puesto_id' => $prueba->gestor->id,
        'sucursal_principal_id' => $prueba->sucursal->id, 'estatus' => EstadoUsuario::Activo,
    ]);
}

function rcCorte(string $periodo): RecepcionDocumentalCorte
{
    return app(RecepcionDocumentalService::class)->generar(PeriodoRecepcion::desdeClave($periodo), null)['corte'];
}

function rcVigentes(RecepcionDocumentalCorte $corte, ReglaRecepcionDocumental $regla)
{
    return RecepcionDocumentalRenglon::query()->where('corte_id', $corte->id)->where('regla', $regla->value)
        ->where('estado', '!=', EstadoRecepcionDocumento::NoAplica->value)->get();
}

test('con Contratos y Vacaciones activos, 3 altas de Gestor en el mes esperan 9 contratos y las vacaciones aprobadas del mes', function () {
    rcSoloActivas(ReglaRecepcionDocumental::Altas, ReglaRecepcionDocumental::Vacaciones);

    // Paquete real de Gestor (capacitacion_inicial): 3 contratos por persona.
    expect(config('recepcion_documental.modalidad_alta_por_defecto'))->toBe('capacitacion_inicial')
        ->and(config('documentos_maestros.paquetes.alta.capacitacion_inicial.gestor'))->toHaveCount(3);

    foreach (['2026-11-03', '2026-11-10', '2026-11-17'] as $fecha) {
        rcAlta($this, $fecha);
    }
    rcAlta($this, '2026-10-30'); // otro mes: no cuenta

    $persona = rcAlta($this, '2025-01-10');
    $base = ['colaborador_id' => $persona->id, 'tipo' => TipoSolicitudInterna::Vacaciones, 'fecha_inicio' => '2026-12-14', 'fecha_fin' => '2026-12-18', 'dias_solicitados' => 5];
    SolicitudInterna::factory()->create([...$base, 'estado' => EstadoSolicitudInterna::Aprobada, 'revisado_en' => Carbon::parse('2026-11-05 17:00:00', 'UTC')]);
    SolicitudInterna::factory()->create([...$base, 'estado' => EstadoSolicitudInterna::Aprobada, 'revisado_en' => Carbon::parse('2026-11-18 17:00:00', 'UTC')]);
    SolicitudInterna::factory()->create([...$base, 'estado' => EstadoSolicitudInterna::Aprobada, 'revisado_en' => Carbon::parse('2026-10-18 17:00:00', 'UTC')]);
    SolicitudInterna::factory()->create([...$base, 'estado' => EstadoSolicitudInterna::Rechazada, 'revisado_en' => Carbon::parse('2026-11-12 17:00:00', 'UTC')]);

    $corte = rcCorte('2026-11');

    expect(rcVigentes($corte, ReglaRecepcionDocumental::Altas))->toHaveCount(9)
        ->and(rcVigentes($corte, ReglaRecepcionDocumental::Vacaciones))->toHaveCount(2)
        ->and(RecepcionDocumentalRenglon::query()->where('corte_id', $corte->id)->whereNotIn('regla', ['altas', 'vacaciones'])->count())->toBe(0)
        ->and(app(RecepcionDocumentalService::class)->metricas($corte, $this->rh)['esperados'])->toBe(11);
});

test('apagar una entrada recalcula el mes en curso (lo recibido se conserva) y encenderla la regresa; el mes cerrado no cambia', function () {
    rcSoloActivas(ReglaRecepcionDocumental::Altas);
    rcAlta($this, '2026-10-06');
    $octubre = rcCorte('2026-10');
    app(RecepcionDocumentalService::class)->cerrar($octubre, $this->rh, 'Cierre de octubre con pendientes.');

    foreach (['2026-11-03', '2026-11-10'] as $fecha) {
        rcAlta($this, $fecha);
    }
    $noviembre = rcCorte('2026-11');
    $recibido = rcVigentes($noviembre, ReglaRecepcionDocumental::Altas)->first();
    $recibido->update(['estado' => EstadoRecepcionDocumento::Recibido, 'cantidad_recibida' => 1]);
    $entrada = rcEntrada(ReglaRecepcionDocumental::Altas);

    $this->actingAs($this->rh)
        ->patchJson(route('rh.recepcion-documental.catalogo.update', $entrada), ['activa' => false])
        ->assertOk()
        ->assertJsonPath('recalculo.recalculado', true)
        ->assertJsonPath('recalculo.no_aplica', 5);

    $altas = RecepcionDocumentalRenglon::query()->where('corte_id', $noviembre->id)->where('regla', 'altas')->get();
    expect($altas)->toHaveCount(6)
        ->and($altas->firstWhere('id', $recibido->id)->estado)->toBe(EstadoRecepcionDocumento::Recibido)
        ->and($altas->where('estado', EstadoRecepcionDocumento::NoAplica)->count())->toBe(5)
        ->and($altas->firstWhere('estado', EstadoRecepcionDocumento::NoAplica)->justificacion)->toContain('se desactivó en el catálogo')
        ->and(RecepcionDocumentalRenglon::query()->where('corte_id', $octubre->id)->where('estado', EstadoRecepcionDocumento::Pendiente->value)->count())->toBe(3)
        ->and($octubre->refresh()->estado)->toBe(EstadoCorteRecepcion::Cerrado)
        ->and(Activity::query()->where('event', 'recepcion_documental_catalogo_actualizado')->where('subject_id', $entrada->id)->exists())->toBeTrue();

    $this->actingAs($this->rh)
        ->patchJson(route('rh.recepcion-documental.catalogo.update', $entrada), ['activa' => true])
        ->assertOk()
        ->assertJsonPath('recalculo.reactivados', 5);

    expect(rcVigentes($noviembre, ReglaRecepcionDocumental::Altas))->toHaveCount(6)
        ->and(RecepcionDocumentalRenglon::query()->count())->toBe(9);
});

test('configurar exige recepcion_documental.configurar: RH Admin sí, RH auxiliar y colaborador no', function () {
    $entrada = rcEntrada(ReglaRecepcionDocumental::Vacaciones);

    expect($this->rh->can('recepcion_documental.configurar'))->toBeTrue()
        ->and($this->auxiliar->can('recepcion_documental.configurar'))->toBeFalse()
        ->and(clUsuario('super_admin')->can('recepcion_documental.configurar'))->toBeTrue();

    $this->actingAs($this->auxiliar)->get(route('rh.recepcion-documental.catalogo.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Rh/RecepcionDocumental/Catalogo')->where('puedeConfigurar', false)->has('entradas', 8));
    $this->actingAs($this->auxiliar)->patchJson(route('rh.recepcion-documental.catalogo.update', $entrada), ['activa' => false])->assertForbidden();
    $this->actingAs($this->auxiliar)->postJson(route('rh.recepcion-documental.catalogo.reordenar'), ['ids' => [$entrada->id]])->assertForbidden();
    $this->actingAs($this->colaborador)->get(route('rh.recepcion-documental.catalogo.index'))->assertForbidden();

    $this->actingAs($this->rh)->patchJson(route('rh.recepcion-documental.catalogo.update', $entrada), ['documentos' => ['no_existe']])
        ->assertUnprocessable()->assertJsonValidationErrors('documentos');
    $this->actingAs($this->rh)->patchJson(route('rh.recepcion-documental.catalogo.update', $entrada), ['documentos' => []])
        ->assertUnprocessable()->assertJsonValidationErrors('documentos');
    $this->actingAs($this->rh)->patchJson(route('rh.recepcion-documental.catalogo.update', $entrada), ['nombre' => 'Formatos de vacaciones'])->assertOk();

    expect($entrada->refresh()->nombre)->toBe('Formatos de vacaciones');
});
