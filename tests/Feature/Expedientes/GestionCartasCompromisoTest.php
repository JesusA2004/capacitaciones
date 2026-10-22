<?php

use App\Enums\EstadoFlujoDocumento;
use App\Enums\TipoNodoComercial;
use App\Models\CartaCompromiso;
use App\Models\Colaborador;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\NodoComercial;
use App\Models\NotificacionEntrega;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Expedientes\CartaCompromisoFirmaService;
use App\Services\Pdf\BrowsershotRenderer;
use App\Services\Pdf\OpcionesPdf;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

/*
 * Módulo «Cartas compromiso» (GestionCartasCompromisoService,
 * CartaCompromisoPolicy, CartaCompromisoFirmaService): RH, el gerente (de
 * sucursal: gerente_sucursal = gerente) y la gerente regional generan la
 * carta de su gente de nuevo ingreso con vista previa; quien entra la firma
 * y se sube el escaneo. Sin Chrome: el renderer se sustituye.
 */

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    config(['pdf.renderer' => 'browsershot']);

    $this->htmlCartas = new ArrayObject;
    $this->app->instance(BrowsershotRenderer::class, new class($this->htmlCartas) extends BrowsershotRenderer
    {
        /** @param ArrayObject<int, string> $html */
        public function __construct(private ArrayObject $html) {}

        public function renderizar(string $html, OpcionesPdf $opciones): string
        {
            $this->html->append($html);

            return '%PDF-1.4 carta de prueba';
        }
    });

    $this->ine = DocumentType::factory()->create(['nombre' => 'Identificación oficial', 'clave' => 'ine', 'requerido' => true, 'aplica_alta' => true, 'activo' => true, 'orden' => 1]);
    $this->acta = DocumentType::factory()->create(['nombre' => 'Acta de nacimiento', 'clave' => 'acta_nacimiento', 'requerido' => true, 'aplica_alta' => true, 'activo' => true, 'orden' => 2]);
    $this->domicilio = DocumentType::factory()->create(['nombre' => 'Comprobante de domicilio', 'clave' => 'comprobante_domicilio', 'requerido' => true, 'aplica_alta' => true, 'activo' => true, 'orden' => 3]);

    $estructura = clEstructura();
    $this->sucursal = $estructura['sucursal'];
    $this->otraSucursal = Sucursal::factory()->create(['empresa_id' => $estructura['empresa']->id, 'nombre' => 'Cuautla']);
    $this->fueraDeRegion = Sucursal::factory()->create(['empresa_id' => $estructura['empresa']->id, 'nombre' => 'Toluca']);
    $puestoRegional = Puesto::factory()->create(['nombre' => 'Gerente regional']);

    // Matriz comercial: la región agrupa Cuernavaca y Cuautla (no Toluca).
    $matriz = NodoComercial::factory()->create(['tipo' => TipoNodoComercial::Matriz->value, 'nombre' => 'MATRIZ']);
    $region = NodoComercial::factory()->create(['tipo' => TipoNodoComercial::Region->value, 'nombre' => 'Región Q1', 'parent_id' => $matriz->id, 'puesto_id' => $puestoRegional->id]);
    NodoComercial::factory()->create(['tipo' => TipoNodoComercial::Zona->value, 'nombre' => 'CUERNAVACA', 'parent_id' => $region->id, 'sucursal_id' => $this->sucursal->id]);
    NodoComercial::factory()->create(['tipo' => TipoNodoComercial::Zona->value, 'nombre' => 'CUAUTLA', 'parent_id' => $region->id, 'sucursal_id' => $this->otraSucursal->id]);

    $base = ['estatus' => 'activo', 'sucursal_principal_id' => $this->sucursal->id];
    $this->rh = clUsuario('rh_admin', $base);
    $this->gerente = clUsuario('gerente_sucursal', $base);
    $this->gerenteRol = clUsuario('gerente', $base);
    $this->regional = clUsuario('gerente_regional', [...$base, 'puesto_id' => $puestoRegional->id]);
});

/** Nuevo ingreso con cuenta, al que le faltan acta y comprobante de domicilio. */
function gccNuevoIngreso(Sucursal $sucursal, int $faltantes = 2): Colaborador
{
    $colaborador = clColaboradorEnContratacion(['sucursal_principal_id' => $sucursal->id]);
    $usuario = User::factory()->create(['colaborador_id' => $colaborador->id]);
    $usuario->assignRole('colaborador');

    $entregados = DocumentType::query()->where('aplica_alta', true)->orderBy('orden')->get()->slice(0, 3 - $faltantes);

    foreach ($entregados as $tipo) {
        EmployeeDocument::factory()->aprobado()->create(['user_id' => null, 'colaborador_id' => $colaborador->id, 'document_type_id' => $tipo->id]);
    }

    return $colaborador->fresh();
}

function gccGenerar(User $actor, Colaborador $colaborador, array $documentos)
{
    return test()->actingAs($actor)->post(route('rh.cartas-compromiso.store', $colaborador), ['documentos' => $documentos]);
}

test('el gerente genera la carta de su gente: queda en el expediente y se le avisa a quien entra que debe firmarla', function () {
    $persona = gccNuevoIngreso($this->sucursal);

    gccGenerar($this->gerente, $persona, [$this->acta->id, $this->domicilio->id])->assertRedirect();

    $carta = CartaCompromiso::query()->where('colaborador_id', $persona->id)->sole();
    expect($carta->generada_por)->toBe($this->gerente->id)
        ->and(array_column($carta->documentos, 'clave'))->toBe(['acta_nacimiento', 'comprobante_domicilio'])
        ->and($carta->fecha_limite->toDateString())->toBe(now()->startOfDay()->addDays(7)->toDateString())
        ->and($carta->path)->not->toBeNull()
        ->and($carta->firmada())->toBeFalse();

    // El PDF generado nunca imprime al usuario que lo capturó.
    expect(collect($this->htmlCartas->getArrayCopy())->last())->not->toContain($this->gerente->nombreCompleto());

    expect(NotificacionEntrega::query()->where('user_id', $persona->user->id)->where('evento', 'carta_compromiso_por_firmar')->exists())->toBeTrue();

    $this->actingAs($this->gerente)->get(route('rh.cartas-compromiso.show', $carta))->assertOk();
    $this->actingAs($this->gerente)->get(route('rh.cartas-compromiso.pdf', $carta))->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

test('gerente y gerente de sucursal son el mismo rol para el módulo', function () {
    $persona = gccNuevoIngreso($this->sucursal);

    gccGenerar($this->gerenteRol, $persona, [$this->acta->id])->assertRedirect();

    expect(CartaCompromiso::query()->where('colaborador_id', $persona->id)->exists())->toBeTrue();
});

test('el gerente no genera cartas de otra sucursal; la regional sí dentro de su región, no fuera', function () {
    $otra = gccNuevoIngreso($this->otraSucursal);
    $fuera = gccNuevoIngreso($this->fueraDeRegion);

    $this->actingAs($this->gerente)->getJson(route('rh.cartas-compromiso.preparar', $otra))->assertForbidden();
    gccGenerar($this->gerente, $otra, [$this->acta->id])->assertForbidden();

    gccGenerar($this->regional, $otra, [$this->acta->id])->assertRedirect();
    gccGenerar($this->regional, $fuera, [$this->acta->id])->assertForbidden();
    gccGenerar($this->rh, $fuera, [$this->acta->id])->assertRedirect();

    // La lista y el buscador respetan el alcance.
    $ids = collect($this->actingAs($this->gerente)->getJson(route('rh.cartas-compromiso.colaboradores'))->assertOk()->json('data'))->pluck('id');
    expect($ids)->not->toContain($otra->id)->not->toContain($fuera->id);

    $this->actingAs($this->gerente)->get(route('rh.cartas-compromiso.index'))
        ->assertInertia(fn ($page) => $page->component('Rh/CartasCompromiso/Index')->where('cartas.total', 0));
    $this->actingAs($this->regional)->get(route('rh.cartas-compromiso.index'))
        ->assertInertia(fn ($page) => $page->where('cartas.total', 1)->where('indicadores.vigentes', 1)->where('indicadores.pendientes_firma', 1));
    $this->actingAs($this->regional)->get(route('rh.cartas-compromiso.index', ['busqueda' => 'cuautla']))
        ->assertInertia(fn ($page) => $page->where('cartas.total', 1));
    $this->actingAs($this->regional)->get(route('rh.cartas-compromiso.index', ['busqueda' => 'toluca']))
        ->assertInertia(fn ($page) => $page->where('cartas.total', 0));
});

test('una carta cubre como máximo 2 documentos y la persona no debe tener más faltantes', function () {
    $tres = gccNuevoIngreso($this->sucursal, 3);

    gccGenerar($this->gerente, $tres, [$this->ine->id, $this->acta->id])->assertSessionHasErrors('colaborador');
    expect(CartaCompromiso::query()->count())->toBe(0);

    $dos = gccNuevoIngreso($this->sucursal, 2);
    config(['expedientes.carta_compromiso.max_documentos' => 1]);
    gccGenerar($this->gerente, $dos, [$this->acta->id, $this->domicilio->id])->assertSessionHasErrors('colaborador');

    config(['expedientes.carta_compromiso.max_documentos' => 2]);
    gccGenerar($this->gerente, $dos, [$this->acta->id, $this->domicilio->id])->assertRedirect();

    // Una sola carta abierta: no se genera otra que reinicie el plazo.
    gccGenerar($this->gerente, $dos, [$this->acta->id])->assertSessionHasErrors('colaborador');
    expect(CartaCompromiso::query()->where('colaborador_id', $dos->id)->count())->toBe(1);
});

test('el personal que ya laboraba no recibe carta', function () {
    $antiguo = Colaborador::factory()->create(['estatus' => 'activo', 'estado_alta' => 'activo', 'sucursal_principal_id' => $this->sucursal->id]);

    $this->actingAs($this->gerente)->getJson(route('rh.cartas-compromiso.preparar', $antiguo))
        ->assertOk()
        ->assertJsonPath('data.motivo_no_elegible', fn (string $m) => str_contains($m, 'nuevo ingreso'));
    gccGenerar($this->gerente, $antiguo, [$this->acta->id])->assertSessionHasErrors('colaborador');
});

test('la vista previa muestra la carta sin guardar nada', function () {
    $persona = gccNuevoIngreso($this->sucursal);

    $this->actingAs($this->gerente)
        ->post(route('rh.cartas-compromiso.vista-previa', $persona), ['documentos' => [$this->acta->id, $this->domicilio->id]])
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    $html = collect($this->htmlCartas->getArrayCopy())->last();
    expect($html)->toContain('VISTA PREVIA')->toContain('Acta de nacimiento')->toContain('Comprobante de domicilio')
        ->and(CartaCompromiso::query()->count())->toBe(0);

    // Documento que no le falta: se rechaza.
    $this->actingAs($this->gerente)
        ->postJson(route('rh.cartas-compromiso.vista-previa', $persona), ['documentos' => [$this->ine->id]])
        ->assertUnprocessable();
});

test('el gerente sube la carta firmada: queda en el expediente y la carta pasa a firmada', function () {
    $persona = gccNuevoIngreso($this->sucursal);
    gccGenerar($this->gerente, $persona, [$this->acta->id])->assertRedirect();
    $carta = CartaCompromiso::query()->where('colaborador_id', $persona->id)->sole();

    // Sin confirmar firma y huella no se acepta.
    $this->actingAs($this->gerente)->post(route('rh.cartas-compromiso.subir-firmada', $carta), [
        'archivo' => UploadedFile::fake()->create('carta-firmada.pdf', 120, 'application/pdf'),
    ])->assertSessionHasErrors('confirmo_firma');

    $this->actingAs($this->gerente)->post(route('rh.cartas-compromiso.subir-firmada', $carta), [
        'archivo' => UploadedFile::fake()->create('carta-firmada.pdf', 120, 'application/pdf'),
        'confirmo_firma' => '1',
    ])->assertSessionHasNoErrors()->assertRedirect();

    $carta->refresh();
    expect($carta->firmada())->toBeTrue()
        ->and($carta->firma_registrada_por)->toBe($this->gerente->id)
        ->and($carta->documentoFirmado?->tipo?->clave)->toBe(CartaCompromisoFirmaService::CLAVE_TIPO)
        ->and($carta->documentoGenerado?->estado_flujo)->toBe(EstadoFlujoDocumento::FirmadoFisicamente);

    $this->actingAs($this->gerente)->get(route('rh.cartas-compromiso.firmada', $carta))->assertOk();

    // Ya firmada: no se vuelve a subir.
    $this->actingAs($this->gerente)->post(route('rh.cartas-compromiso.subir-firmada', $carta), [
        'archivo' => UploadedFile::fake()->create('otra.pdf', 120, 'application/pdf'),
        'confirmo_firma' => '1',
    ])->assertForbidden();
});

test('sin permisos del módulo: 403', function () {
    $persona = gccNuevoIngreso($this->sucursal);
    gccGenerar($this->rh, $persona, [$this->acta->id])->assertRedirect();
    $carta = CartaCompromiso::query()->sole();

    $colaborador = clUsuario('colaborador', ['estatus' => 'activo', 'sucursal_principal_id' => $this->sucursal->id]);
    $this->actingAs($colaborador)->get(route('rh.cartas-compromiso.index'))->assertForbidden();
    $this->actingAs($colaborador)->get(route('rh.cartas-compromiso.show', $carta))->assertForbidden();
    $this->actingAs($colaborador)->get(route('rh.cartas-compromiso.create'))->assertForbidden();

    // La propia persona no gestiona su carta desde el módulo.
    $this->actingAs($persona->user)->get(route('rh.cartas-compromiso.pdf', $carta))->assertForbidden();

    // Gerente sin la acción de subir la firmada.
    $this->gerente->roles->first()?->revokePermissionTo('cartas_compromiso.subir_firmada');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs($this->gerente->fresh())->post(route('rh.cartas-compromiso.subir-firmada', $carta), [
        'archivo' => UploadedFile::fake()->create('carta.pdf', 120, 'application/pdf'),
        'confirmo_firma' => '1',
    ])->assertForbidden();
});
