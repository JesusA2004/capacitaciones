<?php

use App\Enums\EstadoUsuario;
use App\Models\Baja;
use App\Models\CausalRotacion;
use App\Models\Colaborador;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Bajas\CatalogoBajasService;
use Database\Seeders\CausalesRotacionSeeder;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
| «Bajas y finiquitos» → Documentos: una tarjeta por persona dada de baja
| con todos sus documentos (generados, finiquito y su entrega, adjuntos de
| la baja) y su estado, el avance de obligatorios N/M, filtros y alcance.
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $this->seed(CausalesRotacionSeeder::class);
    Storage::fake('nas');
    Notification::fake();

    $this->estructura = clEstructura();
    $sucursal = $this->estructura['sucursal'];
    $this->otraSucursal = Sucursal::factory()->create(['empresa_id' => $this->estructura['empresa']->id]);
    $this->rh = clUsuario('rh_admin', ['sucursal_principal_id' => $sucursal->id]);
    $this->gerente = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $sucursal->id]);

    $persona = fn (array $extra) => tap(Colaborador::factory()->create([
        'sucursal_principal_id' => $sucursal->id,
        'puesto_id' => $this->estructura['puesto']->id,
        'estatus' => EstadoUsuario::Activo,
        'fecha_ingreso' => '2024-01-01',
        'sueldo_mensual' => 12000,
        ...$extra,
    ]), fn (Colaborador $c) => User::factory()->create(['colaborador_id' => $c->id])->assignRole('colaborador'));

    $this->ana = $persona(['name' => 'Ana', 'apellidos' => 'Ríos Luna']);
    $this->beto = $persona(['name' => 'Beto', 'apellidos' => 'Salas Mora']);
    $this->ciro = $persona(['name' => 'Ciro', 'apellidos' => 'Vela Paz', 'sucursal_principal_id' => $this->otraSucursal->id]);
});

function rcRegistrar(User $quien, Colaborador $colaborador, string $clave, array $extra = []): Baja
{
    $causal = CausalRotacion::query()->where('clave', $clave)->firstOrFail();

    test()->actingAs($quien)->post(route('rh.bajas.store'), [
        'colaborador_id' => $colaborador->id,
        'forma_salida' => $causal->forma()->value,
        'causal_rotacion_id' => $causal->id,
        'fecha_baja' => now()->subDay()->toDateString(),
        'recontratable' => 'si',
        'comentarios' => 'Comentario suficiente para la causal elegida.',
        ...$extra,
    ])->assertSessionHasNoErrors();

    return Baja::query()->where('colaborador_id', $colaborador->id)->latest('id')->firstOrFail();
}

/**
 * @return array<int, array<string, mixed>>
 */
function rcTarjetas(User $quien, array $filtros = []): array
{
    return test()->actingAs($quien)->get(route('rh.cierres.index', $filtros))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('Rh/Cierres/Index')->where('seccion', 'documentos'))
        ->viewData('page')['props']['recopilacion']['data'];
}

test('cada persona dada de baja tiene su tarjeta con forma · causal, avance N/M y TODOS sus documentos con su estado', function () {
    $renuncia = rcRegistrar($this->gerente, $this->ana, 'RV-01');
    $rescision = rcRegistrar($this->rh, $this->beto, 'RE-03', [
        'recontratable' => 'no',
        'fecha_conocimiento_falta' => now()->subDays(3)->toDateString(),
        'entrega_aviso_rescision' => 'personal',
        'fecha_entrega_aviso' => now()->subDay()->toDateString(),
    ]);

    $tarjetas = collect(rcTarjetas($this->rh))->keyBy('id');
    $ana = $tarjetas->get($renuncia->id);
    $beto = $tarjetas->get($rescision->id);

    expect($tarjetas)->toHaveCount(2)
        ->and($ana['colaborador'])->toBe($this->ana->nombreCompleto())
        ->and($ana['forma'])->toBe('RV')
        ->and($ana['forma_token'])->toBe('renuncia')
        ->and($ana['causal'])->toContain('RV-01')
        ->and($ana['estatus'])->toBe('en_revision_rh')
        ->and($ana['avance'])->toBe(['completos' => 0, 'total' => 3])
        ->and($beto['forma'])->toBe('RE')
        // Flujo sin Jurídico (Etapa 1): una rescisión queda En trámite, no «En revisión Jurídico».
        ->and($beto['estatus'])->toBe('en_revision_rh');

    $docsAna = collect($ana['documentos'])->keyBy('clave');
    // Finiquito propuesto (con su vista previa) y su entrega pendiente.
    expect($docsAna->get('finiquito'))->toMatchArray(['estado' => 'generado', 'origen' => 'finiquito'])
        ->and($docsAna->get('finiquito')['url'])->toContain('/finiquito/vista-previa')
        ->and($docsAna->get('entrega_finiquito')['estado'])->toBe('pendiente')
        // La renuncia la genera el sistema (sin master en la prueba queda «por generar»), nunca un adjunto.
        ->and($docsAna->first(fn (array $d) => $d['origen'] === 'generado')['nombre'])->toContain('enuncia')
        // La entrevista de salida falta y se puede adjuntar desde la tarjeta.
        ->and($docsAna->first(fn (array $d) => str_contains($d['nombre'], 'ntrevista'))['estado'])->toBe('pendiente')
        ->and($ana['puede_adjuntar'])->toBeTrue();

    // Rescisión: no genera documento; el aviso de rescisión se adjunta.
    $docsBeto = collect($beto['documentos']);
    expect($docsBeto->where('origen', 'generado'))->toHaveCount(0)
        ->and($docsBeto->first(fn (array $d) => str_contains($d['nombre'], 'viso de rescisi'))['estado'])->toBe('pendiente');

    // Adjuntar el que falta desde la tarjeta: queda «Adjunto» con vista previa y sube el avance.
    $entrevista = collect($ana['documentos'])->first(fn (array $d) => str_contains($d['nombre'], 'ntrevista'));
    $this->actingAs($this->gerente)->post(route('rh.bajas.documentos.store', $renuncia), [
        'archivo' => UploadedFile::fake()->image('entrevista.jpg'),
        'documento_requerido_id' => $entrevista['requerido_id'],
    ])->assertSessionHasNoErrors();

    $ana = collect(rcTarjetas($this->rh))->firstWhere('id', $renuncia->id);
    $adjunto = collect($ana['documentos'])->firstWhere('origen', 'adjunto');
    expect($ana['avance'])->toBe(['completos' => 1, 'total' => 3])
        ->and($adjunto['estado'])->toBe('firmado')
        ->and($adjunto['url'])->not->toBeNull();

    // La URL del visor sirve el archivo en línea.
    $ver = $this->actingAs($this->rh)->get($adjunto['url']);
    $ver->assertOk();
    expect((string) $ver->headers->get('Content-Disposition'))->toStartWith('inline');
});

test('filtros: estatus, sucursal, forma, documentos completos / incompletos y buscador sin mínimo de letras', function () {
    $renuncia = rcRegistrar($this->gerente, $this->ana, 'RV-01');
    rcRegistrar($this->rh, $this->beto, 'NR-04');
    $ciro = rcRegistrar($this->rh, $this->ciro, 'RV-02');

    // Completa los documentos obligatorios de Ana que se adjuntan.
    $renuncia->load('causal');
    foreach (app(CatalogoBajasService::class)->documentosRequeridos($renuncia->forma_salida, $renuncia->causal) as $requerido) {
        $this->actingAs($this->rh)->post(route('rh.bajas.documentos.store', $renuncia), [
            'archivo' => UploadedFile::fake()->create('doc.pdf', 20, 'application/pdf'),
            'documento_requerido_id' => $requerido->id,
        ])->assertSessionHasNoErrors();
    }

    $ids = fn (array $filtros) => collect(rcTarjetas($this->rh, $filtros))->pluck('id')->sort()->values()->all();

    expect($ids(['documentos' => 'completos']))->toBe([$renuncia->id])
        ->and($ids(['documentos' => 'incompletos']))->not->toContain($renuncia->id)
        ->and($ids(['forma' => 'NR']))->toHaveCount(1)
        ->and($ids(['sucursal_id' => $this->otraSucursal->id]))->toBe([$ciro->id])
        // Una sola letra basta.
        ->and($ids(['busqueda' => 'C']))->toContain($ciro->id)
        ->and($ids(['busqueda' => 'Ríos']))->toBe([$renuncia->id]);

    $this->actingAs($this->rh)->post(route('rh.bajas.cancelar', $ciro), ['motivo' => 'Se queda en la empresa.'])->assertSessionHasNoErrors();
    expect($ids([]))->not->toContain($ciro->id)
        ->and($ids(['estatus' => 'cancelada']))->toBe([$ciro->id])
        ->and($ids(['estatus' => 'todas']))->toHaveCount(3);
});

test('alcance: el gerente solo ve las tarjetas de su sucursal; sin «cierres.ver», 403', function () {
    $renuncia = rcRegistrar($this->gerente, $this->ana, 'RV-01');
    rcRegistrar($this->rh, $this->ciro, 'RV-02');

    expect(collect(rcTarjetas($this->gerente))->pluck('id')->all())->toBe([$renuncia->id]);

    $this->actingAs(clUsuario('colaborador'))->get(route('rh.cierres.index'))->assertForbidden();

    // Las pestañas «Cierres y finiquitos» y «Causales» siguen.
    $this->actingAs($this->rh)->get(route('rh.cierres.index', ['seccion' => 'cierres']))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('seccion', 'cierres')->has('cierres.data', 2)->where('recopilacion', null));
    $this->actingAs($this->rh)->get(route('rh.cierres.index', ['seccion' => 'causales']))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('seccion', 'causales')->has('causales'));
});
