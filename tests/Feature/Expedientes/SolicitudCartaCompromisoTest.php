<?php

use App\Enums\EstadoCartaCompromiso;
use App\Models\CartaCompromiso;
use App\Models\Colaborador;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\NotificacionEntrega;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Expedientes\BloqueoDocumentosService;
use App\Services\Pdf\BrowsershotRenderer;
use App\Services\Pdf\OpcionesPdf;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Storage;

/*
 * La propia persona pide su carta compromiso desde «Mi expediente» (web) o
 * la app (SolicitudCartaCompromisoService): elige sus documentos
 * pendientes, ve la vista previa y la envía; queda pendiente de aprobación
 * de RH (cartas_compromiso.aprobar). Sin aprobar no da plazo ni levanta el
 * bloqueo de la app; al aprobarla el plazo corre desde ese día.
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
    $base = ['estatus' => 'activo', 'sucursal_principal_id' => $this->sucursal->id];
    $this->rh = clUsuario('rh_admin', $base);
    $this->gerente = clUsuario('gerente_sucursal', $base);
});

/** Nuevo ingreso con cuenta, al que le faltan los últimos $faltantes documentos. */
function sccNuevoIngreso(Sucursal $sucursal, int $faltantes = 2): User
{
    $colaborador = clColaboradorEnContratacion(['sucursal_principal_id' => $sucursal->id]);
    $usuario = User::factory()->create(['colaborador_id' => $colaborador->id]);
    $usuario->assignRole('colaborador');

    foreach (DocumentType::query()->where('aplica_alta', true)->orderBy('orden')->get()->slice(0, 3 - $faltantes) as $tipo) {
        EmployeeDocument::factory()->aprobado()->create(['user_id' => null, 'colaborador_id' => $colaborador->id, 'document_type_id' => $tipo->id]);
    }

    return $usuario->fresh();
}

test('«Mi expediente» ofrece pedir la carta con sus pendientes, el máximo y la fecha límite', function () {
    $persona = sccNuevoIngreso($this->sucursal);

    $this->actingAs($persona)->get(route('mi-expediente'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Rh/Expedientes/MiExpediente')
            ->where('solicitudCartaCompromiso.mostrar', true)
            ->where('solicitudCartaCompromiso.puede_solicitar', true)
            ->where('solicitudCartaCompromiso.max_documentos', 2)
            ->where('solicitudCartaCompromiso.dias_plazo', 7)
            ->has('solicitudCartaCompromiso.pendientes', 2));
});

test('con más faltantes que el máximo no puede pedirla y se le explica', function () {
    $persona = sccNuevoIngreso($this->sucursal, 3);

    $this->actingAs($persona)->get(route('mi-expediente'))
        ->assertInertia(fn ($page) => $page
            ->where('solicitudCartaCompromiso.mostrar', true)
            ->where('solicitudCartaCompromiso.puede_solicitar', false)
            ->where('solicitudCartaCompromiso.motivo', fn (string $m) => str_contains($m, 'Te faltan 3 documentos')));

    $this->actingAs($persona)
        ->post(route('portal.carta-compromiso.store'), ['documentos' => [$this->ine->id, $this->acta->id]])
        ->assertSessionHasErrors('documentos');
    expect(CartaCompromiso::query()->count())->toBe(0);
});

test('la vista previa no guarda nada y solo acepta sus propios pendientes', function () {
    $persona = sccNuevoIngreso($this->sucursal);

    $this->actingAs($persona)
        ->post(route('portal.carta-compromiso.vista-previa'), ['documentos' => [$this->acta->id]])
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
    expect(collect($this->htmlCartas->getArrayCopy())->last())->toContain('VISTA PREVIA')
        ->and(CartaCompromiso::query()->count())->toBe(0);

    // La identificación ya la entregó: no va en la carta.
    $this->actingAs($persona)
        ->postJson(route('portal.carta-compromiso.vista-previa'), ['documentos' => [$this->ine->id]])
        ->assertUnprocessable();
});

test('pedirla la deja pendiente de aprobación: sin PDF en el expediente, sin plazo y con aviso a RH', function () {
    $persona = sccNuevoIngreso($this->sucursal);

    $this->actingAs($persona)
        ->post(route('portal.carta-compromiso.store'), ['documentos' => [$this->acta->id, $this->domicilio->id]])
        ->assertRedirect(route('mi-expediente', ['tab' => 'documentos']));

    $carta = CartaCompromiso::query()->where('colaborador_id', $persona->colaborador_id)->sole();
    expect($carta->estado)->toBe(EstadoCartaCompromiso::PendienteAprobacion)
        ->and($carta->solicitada_por_colaborador)->toBeTrue()
        ->and($carta->path)->toBeNull()
        ->and(array_column($carta->documentos, 'clave'))->toBe(['acta_nacimiento', 'comprobante_domicilio']);

    // Solo RH (cartas_compromiso.aprobar) recibe la tarea; el gerente no.
    expect(NotificacionEntrega::query()->where('user_id', $this->rh->id)->where('evento', 'carta_compromiso_solicitada')->exists())->toBeTrue()
        ->and(NotificacionEntrega::query()->where('user_id', $this->gerente->id)->where('evento', 'carta_compromiso_solicitada')->exists())->toBeFalse();

    // Sin aprobar no da plazo: la app sigue limitada a subir documentos.
    $bloqueo = app(BloqueoDocumentosService::class)->estado($persona->fresh());
    expect($bloqueo['activo'])->toBeTrue()->and($bloqueo['fecha_limite'])->toBeNull();

    // Ya tiene una abierta: no se ofrece otra ni se acepta.
    $this->actingAs($persona)->get(route('mi-expediente'))
        ->assertInertia(fn ($page) => $page->where('solicitudCartaCompromiso.mostrar', false)
            ->where('cartaCompromiso.estado', 'pendiente_aprobacion'));
    $this->actingAs($persona)
        ->post(route('portal.carta-compromiso.store'), ['documentos' => [$this->acta->id]])
        ->assertSessionHasErrors('documentos');

    // Ver su carta: borrador, nunca se guarda en el expediente.
    $this->actingAs($persona)->get(route('rh.expedientes.carta-compromiso.pdf', $persona->colaborador_id))->assertOk();
    expect($carta->fresh()->path)->toBeNull();
});

test('RH aprueba: queda vigente, el plazo corre desde hoy, se guarda el PDF y se le avisa que la firme', function () {
    $persona = sccNuevoIngreso($this->sucursal);
    $this->actingAs($persona)->post(route('portal.carta-compromiso.store'), ['documentos' => [$this->acta->id]]);
    $carta = CartaCompromiso::query()->sole();
    $carta->update(['fecha_limite' => now()->subDays(3)->toDateString()]);

    // El gerente puede verla, pero no aprobarla.
    $this->actingAs($this->gerente)->post(route('rh.cartas-compromiso.aprobar', $carta))->assertForbidden();
    // Mientras no esté aprobada, no se sube su firma.
    $this->actingAs($this->rh)->get(route('rh.cartas-compromiso.show', $carta))
        ->assertInertia(fn ($page) => $page->where('carta.puede.aprobar', true)->where('carta.puede.subir_firmada', false));

    $this->actingAs($this->rh)->post(route('rh.cartas-compromiso.aprobar', $carta))->assertRedirect();

    $carta->refresh();
    expect($carta->estado)->toBe(EstadoCartaCompromiso::Vigente)
        ->and($carta->aprobada_por)->toBe($this->rh->id)
        ->and($carta->fecha_limite->toDateString())->toBe(now()->startOfDay()->addDays(7)->toDateString())
        ->and($carta->path)->not->toBeNull()
        ->and(NotificacionEntrega::query()->where('user_id', $persona->id)->where('evento', 'carta_compromiso_por_firmar')->exists())->toBeTrue();

    // Ya con plazo: la app deja de estar limitada.
    expect(app(BloqueoDocumentosService::class)->estado($persona->fresh())['activo'])->toBeFalse();

    // Aprobar dos veces no se puede.
    $this->actingAs($this->rh)->post(route('rh.cartas-compromiso.aprobar', $carta))->assertForbidden();
});

test('RH rechaza con motivo: no da plazo, se le avisa y puede pedir otra', function () {
    $persona = sccNuevoIngreso($this->sucursal);
    $this->actingAs($persona)->post(route('portal.carta-compromiso.store'), ['documentos' => [$this->acta->id]]);
    $carta = CartaCompromiso::query()->sole();

    $this->actingAs($this->rh)->post(route('rh.cartas-compromiso.rechazar', $carta), ['motivo' => ''])->assertSessionHasErrors('motivo');
    $this->actingAs($this->rh)->post(route('rh.cartas-compromiso.rechazar', $carta), ['motivo' => 'Ese documento ya se tramita en línea.'])->assertRedirect();

    $carta->refresh();
    expect($carta->estado)->toBe(EstadoCartaCompromiso::Rechazada)
        ->and($carta->motivo_rechazo)->toBe('Ese documento ya se tramita en línea.')
        ->and($carta->path)->toBeNull()
        ->and(NotificacionEntrega::query()->where('user_id', $persona->id)->where('evento', 'carta_compromiso_rechazada')->exists())->toBeTrue()
        ->and(app(BloqueoDocumentosService::class)->estado($persona->fresh())['activo'])->toBeTrue();

    $this->actingAs($persona)->get(route('mi-expediente'))
        ->assertInertia(fn ($page) => $page->where('solicitudCartaCompromiso.puede_solicitar', true)
            ->where('cartaCompromiso.estado', 'rechazada')
            ->where('cartaCompromiso.motivo_rechazo', 'Ese documento ya se tramita en línea.'));

    $this->actingAs($persona)->post(route('portal.carta-compromiso.store'), ['documentos' => [$this->acta->id, $this->domicilio->id]])->assertRedirect();
    expect(CartaCompromiso::query()->where('estado', EstadoCartaCompromiso::PendienteAprobacion->value)->count())->toBe(1);
});

test('la lista de RH filtra las cartas por aprobar', function () {
    $persona = sccNuevoIngreso($this->sucursal);
    $this->actingAs($persona)->post(route('portal.carta-compromiso.store'), ['documentos' => [$this->acta->id]]);

    $this->actingAs($this->rh)->get(route('rh.cartas-compromiso.index', ['pestana' => 'por_aprobar']))
        ->assertInertia(fn ($page) => $page->where('cartas.total', 1)
            ->where('indicadores.por_aprobar', 1)
            ->where('indicadores.pendientes_firma', 0)
            ->where('puedeAprobar', true));
    $this->actingAs($this->rh)->get(route('rh.cartas-compromiso.index', ['pestana' => 'pendientes_firma']))
        ->assertInertia(fn ($page) => $page->where('cartas.total', 0));
});

test('el personal que ya laboraba no puede pedirla ni se le ofrece', function () {
    $colaborador = Colaborador::factory()->create(['estatus' => 'activo', 'estado_alta' => 'activo', 'sucursal_principal_id' => $this->sucursal->id]);
    $usuario = User::factory()->create(['colaborador_id' => $colaborador->id]);
    $usuario->assignRole('colaborador');

    $this->actingAs($usuario)->get(route('mi-expediente'))
        ->assertInertia(fn ($page) => $page->where('solicitudCartaCompromiso.mostrar', false));
    $this->actingAs($usuario)
        ->post(route('portal.carta-compromiso.store'), ['documentos' => [$this->acta->id]])
        ->assertSessionHasErrors('documentos');
});

test('la app usa el mismo servicio: POST colaborador/carta-compromiso/solicitar', function () {
    $persona = sccNuevoIngreso($this->sucursal);

    $this->actingAs($persona, 'sanctum')
        ->postJson(route('api.v1.colaborador.carta-compromiso.solicitar'), ['documentos' => [$this->acta->id]])
        ->assertCreated()
        ->assertJsonPath('data.estado', 'pendiente_aprobacion');
});
