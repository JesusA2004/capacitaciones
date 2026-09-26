<?php

use App\Models\BirthdayGreeting;
use App\Models\Colaborador;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

/*
 * Panel RH de Celebraciones (docs/CELEBRACIONES.md): Cumpleaños y
 * Aniversarios entregan la MISMA forma de fila (hoy / calendario del mes /
 * próximos) y comparten el flujo de tarjeta (ver, generar, descargar).
 */

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    // 12:00 en México: "hoy" es viernes 25/09/2026.
    Carbon::setTestNow(Carbon::parse('2026-09-25 18:00:00', 'UTC'));

    $this->sucursalPanel = Sucursal::factory()->create(['nombre' => 'Huamantla']);
    $this->puestoPanel = Puesto::factory()->create(['nombre' => 'Gerente de Sucursal']);
    $this->rhPanel = User::factory()->create();
    $this->rhPanel->assignRole('rh_admin');
    // La factory de User crea su propio Colaborador con fechas al azar: se
    // fijan fuera de los rangos que revisan estas pruebas.
    $this->rhPanel->colaborador?->update(['fecha_nacimiento' => '1980-01-15', 'fecha_ingreso' => '2015-01-15']);
});

afterEach(fn () => Carbon::setTestNow());

function colaboradorPanel(array $atributos = []): Colaborador
{
    return Colaborador::factory()->create([
        'sucursal_principal_id' => test()->sucursalPanel->id,
        'puesto_id' => test()->puestoPanel->id,
        ...$atributos,
    ]);
}

const LLAVES_FILA = ['colaborador_id', 'nombre', 'puesto', 'sucursal', 'departamento', 'foto_url', 'fecha', 'es_hoy', 'anios', 'detalle', 'celebracion_id', 'enviada_at', 'avisada_todos_at'];

// --- Cumpleaños --------------------------------------------------------------

test('cumpleaños: hoy, mes y próximos traen nombre completo, puesto, sucursal y fecha de la celebración', function () {
    config(['cumpleanos.show_age' => true]);
    $hoy = colaboradorPanel(['name' => 'María Fernanda', 'apellidos' => 'Hernández Rodríguez', 'fecha_nacimiento' => '1990-09-25']);
    $proximo = colaboradorPanel(['name' => 'Ana', 'apellidos' => 'Ruiz', 'fecha_nacimiento' => '1995-09-28']);
    $noviembre = colaboradorPanel(['fecha_nacimiento' => '1988-11-20']); // fuera del mes y de los próximos 30 días

    $this->actingAs($this->rhPanel)
        ->get(route('rh.cumpleanos.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Rh/Cumpleanos/Index')
            ->where('fechaHoy', '2026-09-25')
            ->has('hoy', 1, fn (AssertableInertia $fila) => $fila
                ->where('colaborador_id', $hoy->id)
                ->where('nombre', 'María Fernanda Hernández Rodríguez')
                ->where('puesto', 'Gerente de Sucursal')
                ->where('sucursal', 'Huamantla')
                ->where('fecha', '2026-09-25')
                ->where('es_hoy', true)
                ->where('anios', 36)
                ->where('detalle', 'Cumple 36 años')
                ->etc())
            ->where('delMes', fn ($filas) => collect($filas)->pluck('colaborador_id')->sort()->values()->all() === collect([$hoy->id, $proximo->id])->sort()->values()->all())
            ->where('proximos', fn ($filas) => collect($filas)->pluck('colaborador_id')->all() === [$hoy->id, $proximo->id]
                && collect($filas)->firstWhere('colaborador_id', $proximo->id)['fecha'] === '2026-09-28')
            ->missing('calendario'));

    expect($noviembre->id)->not->toBeIn([$hoy->id, $proximo->id]);
});

test('cumpleaños: la fila nunca expone la fecha de nacimiento y la edad solo si la configuración lo permite', function () {
    config(['cumpleanos.show_age' => false]);
    colaboradorPanel(['fecha_nacimiento' => '1990-09-25']);

    $this->actingAs($this->rhPanel)
        ->get(route('rh.cumpleanos.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('hoy.0', fn (AssertableInertia $fila) => $fila
                ->hasAll(LLAVES_FILA)
                ->missing('fecha_nacimiento')
                ->where('anios', null)
                ->where('detalle', null)
                ->etc()));
});

test('cumpleaños: el calendario sigue el mes pedido y la foto sale como URL protegida', function () {
    $conFoto = colaboradorPanel(['fecha_nacimiento' => '1990-03-10', 'foto_path' => 'expedientes/x/foto.jpg']);

    $this->actingAs($this->rhPanel)
        ->get(route('rh.cumpleanos.index', ['mes' => 3, 'anio' => 2027]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('mes', 3)
            ->where('anio', 2027)
            ->has('delMes', 1, fn (AssertableInertia $fila) => $fila
                ->where('colaborador_id', $conFoto->id)
                ->where('fecha', '2027-03-10')
                ->where('es_hoy', false)
                ->where('foto_url', fn (string $url) => str_contains($url, route('rh.expedientes.foto', $conFoto, false)) && ! str_contains($url, 'foto.jpg'))
                ->etc()));
});

test('cumpleaños: el rango de próximos es libre y excluye lo que queda fuera', function () {
    $dentro = colaboradorPanel(['fecha_nacimiento' => '1990-12-01']);
    colaboradorPanel(['fecha_nacimiento' => '1990-12-20']);

    $this->actingAs($this->rhPanel)
        ->get(route('rh.cumpleanos.index', ['rango_desde' => '2026-11-25', 'rango_hasta' => '2026-12-05']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('rango', ['desde' => '2026-11-25', 'hasta' => '2026-12-05'])
            ->where('proximos', fn ($filas) => collect($filas)->pluck('colaborador_id')->all() === [$dentro->id]));
});

// --- Aniversarios ------------------------------------------------------------

test('aniversarios: misma forma de fila que cumpleaños, con años y texto «en MR. LANA»', function () {
    $roberto = colaboradorPanel(['name' => 'Roberto', 'apellidos' => 'Galicia Velazquez', 'fecha_ingreso' => '2020-09-25']);
    $proximo = colaboradorPanel(['fecha_ingreso' => '2025-09-30']);
    colaboradorPanel(['fecha_ingreso' => '2026-09-25']); // ingresó hoy: 0 años, no celebra

    $this->actingAs($this->rhPanel)
        ->get(route('rh.aniversarios.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Rh/Aniversarios/Index')
            ->where('fechaHoy', '2026-09-25')
            ->where('mes', 9)
            ->has('hoy', 1, fn (AssertableInertia $fila) => $fila
                ->hasAll(LLAVES_FILA)
                ->where('nombre', 'Roberto Galicia Velazquez')
                ->where('puesto', 'Gerente de Sucursal')
                ->where('sucursal', 'Huamantla')
                ->where('anios', 6)
                ->where('detalle', '6 años en MR. LANA')
                ->etc())
            ->where('proximos', fn ($filas) => collect($filas)->pluck('colaborador_id')->all() === [$roberto->id, $proximo->id]
                && collect($filas)->last()['detalle'] === '1 año en MR. LANA')
            ->where('delMes', fn ($filas) => collect($filas)->pluck('colaborador_id')->sort()->values()->all() === collect([$roberto->id, $proximo->id])->sort()->values()->all()));
});

test('aniversarios: "hoy" no se esconde con los filtros; el calendario y próximos sí se filtran', function () {
    $otraSucursal = Sucursal::factory()->create();
    $festejado = colaboradorPanel(['fecha_ingreso' => '2020-09-25']);

    $this->actingAs($this->rhPanel)
        ->get(route('rh.aniversarios.index', ['sucursal_id' => $otraSucursal->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('hoy.0.colaborador_id', $festejado->id)
            ->where('delMes', [])
            ->where('proximos', []));
});

// --- Paridad del flujo de tarjeta ---------------------------------------------

test('paridad: generar y ver la tarjeta funcionan igual para cumpleaños y aniversario', function (string $tipo, array $atributos, string $archivo) {
    $colaborador = colaboradorPanel($atributos);

    $this->actingAs($this->rhPanel)
        ->post(route('rh.celebraciones.tarjeta.regenerar', [$colaborador->id, $tipo]))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('toast', fn (array $t) => $t['type'] === 'success');

    $evento = BirthdayGreeting::query()->where('tipo', $tipo)->sole();
    expect($evento->card_path)->not->toBeNull()
        ->and(Storage::disk('nas')->exists($evento->card_path))->toBeTrue();

    // "Ver tarjeta": inline (vista previa en el diálogo).
    $vista = $this->actingAs($this->rhPanel)->get(route('rh.celebraciones.tarjeta', [$colaborador->id, $tipo]).'?ver=1');
    $vista->assertOk()->assertHeader('Content-Type', 'image/png');
    expect($vista->headers->get('Content-Disposition'))->toStartWith('inline')->toContain($archivo);

    // "Descargar": adjunto.
    $descarga = $this->actingAs($this->rhPanel)->get(route('rh.celebraciones.tarjeta', [$colaborador->id, $tipo]));
    expect($descarga->headers->get('Content-Disposition'))->toStartWith('attachment');
})->with([
    'cumpleaños' => ['cumpleanos', ['fecha_nacimiento' => '1990-09-25'], 'feliz-cumpleanos'],
    'aniversario' => ['aniversario_laboral', ['fecha_ingreso' => '2020-09-25'], 'aniversario'],
]);

test('paridad: sin celebración hoy, generar responde un error legible (no un 500)', function (string $tipo, array $atributos) {
    $colaborador = colaboradorPanel($atributos);

    $this->actingAs($this->rhPanel)
        ->from(route('rh.cumpleanos.index'))
        ->post(route('rh.celebraciones.tarjeta.regenerar', [$colaborador->id, $tipo]))
        ->assertRedirect(route('rh.cumpleanos.index'))
        ->assertSessionHasErrors('celebracion');
})->with([
    'cumpleaños' => ['cumpleanos', ['fecha_nacimiento' => '1990-10-25']],
    'aniversario' => ['aniversario_laboral', ['fecha_ingreso' => '2020-10-25']],
]);

// --- Configuración -------------------------------------------------------------

test('configuración de cumpleaños: vista previa real (PNG) y frases en la misma pantalla', function () {
    $this->actingAs($this->rhPanel)
        ->get(route('rh.cumpleanos.configuracion.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Rh/Cumpleanos/Configuracion')
            ->where('puedeGestionarFrases', true)
            ->has('frases'));

    $png = $this->actingAs($this->rhPanel)->get(route('rh.cumpleanos.configuracion.vista-previa'));
    $png->assertOk()->assertHeader('Content-Type', 'image/png');
    [$ancho, $alto] = getimagesizefromstring($png->getContent());
    expect([$ancho, $alto])->toBe([1080, 1350]);
});

test('configuración de aniversarios: vista previa real (PNG)', function () {
    $png = $this->actingAs($this->rhPanel)->get(route('rh.aniversarios.configuracion.vista-previa'));
    $png->assertOk()->assertHeader('Content-Type', 'image/png');
});

// --- Avisos -------------------------------------------------------------------

test('los toasts de `->with(toast)` llegan al frontend por el canal flash de Inertia', function () {
    $respuesta = $this->actingAs($this->rhPanel)
        ->withSession(['toast' => ['type' => 'success', 'message' => 'Tarjeta generada.']])
        ->get(route('rh.cumpleanos.index'))
        ->assertOk();

    preg_match('/<script data-page="app" type="application\/json">(.*?)<\/script>/s', (string) $respuesta->getContent(), $coincidencia);
    $pagina = json_decode($coincidencia[1] ?? '{}', true);

    expect($pagina['flash']['toast'] ?? null)->toBe(['type' => 'success', 'message' => 'Tarjeta generada.']);
});
