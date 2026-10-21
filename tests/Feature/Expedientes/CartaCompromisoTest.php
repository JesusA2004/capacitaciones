<?php

use App\Enums\EstadoCartaCompromiso;
use App\Http\Middleware\ExigirDocumentosPendientes;
use App\Models\CartaCompromiso;
use App\Models\Colaborador;
use App\Models\DocumentType;
use App\Models\GeneratedDocument;
use App\Models\User;
use App\Services\Expedientes\CartaCompromisoService;
use App\Services\Incorporacion\IncorporacionInvitacionService;
use App\Services\Incorporacion\RegistroAppService;
use App\Services\Pdf\BrowsershotRenderer;
use App\Services\Pdf\OpcionesPdf;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
 * Carta compromiso de documentos pendientes + bloqueo de la app por
 * documentos faltantes (CartaCompromisoService, BloqueoDocumentosService,
 * middleware documentos.pendientes):
 *  - al cerrar el registro, lo marcado «No tengo este documento» genera UNA
 *    carta con todos los documentos y 7 días de plazo;
 *  - vencida la carta (o sin carta, personal que ya laboraba) la API solo
 *    deja subir los documentos pendientes;
 *  - subirlos cumple la carta y levanta el bloqueo; nunca a super_admin.
 * Sin Chrome: el renderer de Browsershot se sustituye y captura el HTML.
 */

beforeEach(function () {
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

    // Tres documentos que la persona sube ella misma + uno que genera RH.
    $this->ine = DocumentType::factory()->create(['nombre' => 'Identificación oficial', 'clave' => 'ine', 'requerido' => true, 'aplica_alta' => true, 'activo' => true, 'orden' => 1]);
    $this->acta = DocumentType::factory()->create(['nombre' => 'Acta de nacimiento', 'clave' => 'acta_nacimiento', 'requerido' => true, 'aplica_alta' => true, 'activo' => true, 'orden' => 2]);
    $this->domicilio = DocumentType::factory()->create(['nombre' => 'Comprobante de domicilio', 'clave' => 'comprobante_domicilio', 'requerido' => true, 'aplica_alta' => true, 'activo' => true, 'orden' => 3]);
    DocumentType::factory()->create(['nombre' => 'Contrato laboral', 'clave' => 'contrato', 'requerido' => true, 'aplica_alta' => false, 'activo' => true, 'orden' => 4]);
});

/** Datos personales completos y válidos para cerrar el registro. */
function ccpDatosCompletos(Colaborador $colaborador): array
{
    $datos = [
        'fecha_nacimiento' => '1995-04-12',
        'nacionalidad' => 'Mexicana',
        'estado_civil' => 'soltero',
        'lugar_nacimiento' => 'Cuernavaca, Morelos',
        'curp' => 'TOAA950412MMSRRN09',
        'rfc' => 'TOAA950412AB1',
        'nss' => '12345678901',
        'clave_elector' => 'TRANAN95041217M100',
        'profesion' => 'Ventas',
        'correo_personal' => 'ana.personal@mrlana.test',
        'domicilio_calle' => 'Av. Morelos',
        'domicilio_numero_exterior' => '120',
        'domicilio_colonia' => 'Centro',
        'domicilio_cp' => '62000',
        'domicilio_municipio' => 'Cuernavaca',
        'domicilio_estado' => 'Morelos',
        'contacto_emergencia_nombre' => 'Rosa Torres',
        'contacto_emergencia_parentesco' => 'Madre',
        'contacto_emergencia_telefono' => '7771234567',
        'beneficiario_nombre' => 'Rosa Torres',
        'beneficiario_parentesco' => 'Madre',
    ];

    return array_filter($datos, fn (string $campo): bool => blank($colaborador->getAttribute($campo)), ARRAY_FILTER_USE_KEY);
}

/**
 * Persona en contratación que se registra por QR, con sus datos capturados
 * (solo le faltan documentos). Cabeceras de la app nueva.
 *
 * @return array{colaborador: Colaborador, headers: array<string, string>}
 */
function ccpRegistrarPorQr(): array
{
    $rh = User::factory()->create();
    $rh->assignRole('rh_admin');

    $colaborador = clColaboradorEnContratacion();
    ['token' => $tokenQr] = app(IncorporacionInvitacionService::class)->crear(['colaborador_id' => $colaborador->id], $rh);

    $token = (string) test()->postJson("/api/v1/incorporacion/invitaciones/{$tokenQr}/registrar", [
        'name' => 'Ana',
        'apellidos' => 'Torres',
        'password' => 'Capacitacion2026!',
        'password_confirmation' => 'Capacitacion2026!',
    ])->assertCreated()->json('token');

    $headers = [
        'Authorization' => 'Bearer '.$token,
        RegistroAppService::CABECERA_CLIENTE => '1',
        ExigirDocumentosPendientes::CABECERA_CLIENTE => '1',
    ];

    test()->withHeaders($headers)->postJson('/api/v1/colaborador/registro/datos', ['datos' => ccpDatosCompletos($colaborador->fresh())])->assertOk();

    return ['colaborador' => $colaborador->fresh(), 'headers' => $headers];
}

function ccpSubir(array $headers, DocumentType $tipo): void
{
    test()->withHeaders($headers)
        ->post("/api/v1/colaborador/incorporacion/documentos/{$tipo->id}/subir", [
            'archivo' => UploadedFile::fake()->create("{$tipo->clave}.pdf", 120, 'application/pdf'),
        ])->assertOk();
}

/** Colaborador que ya laboraba (sin registro por QR ni carta). */
function ccpColaboradorExistente(string $rol = 'colaborador'): User
{
    $colaborador = Colaborador::factory()->create(['estatus' => 'activo']);
    $usuario = User::factory()->create(['colaborador_id' => $colaborador->id]);
    $usuario->assignRole($rol);

    return $usuario;
}

test('al cerrar el registro, lo marcado «No tengo este documento» genera UNA carta con todos los documentos', function () {
    ['colaborador' => $colaborador, 'headers' => $headers] = ccpRegistrarPorQr();
    ccpSubir($headers, $this->ine);

    $respuesta = test()->withHeaders($headers)->postJson('/api/v1/colaborador/registro/finalizar', [
        'documentos_sin_tener' => [$this->acta->id, $this->domicilio->id],
    ])->assertOk()
        ->assertJsonPath('data.registro_completo', true)
        ->assertJsonPath('data.carta_compromiso.estado', 'vigente')
        ->assertJsonPath('data.carta_compromiso.dias_restantes', 7)
        ->assertJsonPath('data.carta_compromiso.fecha_limite', now()->addDays(7)->toDateString())
        ->assertJsonCount(2, 'data.carta_compromiso.documentos');

    expect($respuesta->json('data.carta_compromiso.folio'))->toMatch('/^CC-\d{4}-\d{6}$/')
        ->and(CartaCompromiso::query()->where('colaborador_id', $colaborador->id)->count())->toBe(1);

    $carta = CartaCompromiso::query()->firstOrFail();

    // PDF en el expediente (NAS) y registrado como documento generado.
    expect($carta->path)->not->toBeNull()
        ->and(Storage::disk('nas')->exists((string) $carta->path))->toBeTrue()
        ->and(GeneratedDocument::query()->where('clave_plantilla', CartaCompromisoService::CLAVE)->where('colaborador_id', $colaborador->id)->exists())->toBeTrue();

    $html = (string) collect($this->htmlCartas)->last();
    expect($html)->toContain('Carta compromiso')
        ->and($html)->toContain('Acta de nacimiento')
        ->and($html)->toContain('Comprobante de domicilio')
        ->and($html)->not->toContain('Identificación oficial')
        ->and($html)->toContain((string) $carta->folio);

    // La carta en /me y en su endpoint.
    test()->withHeaders($headers)->getJson('/api/v1/me')
        ->assertJsonPath('carta_compromiso.folio', $carta->folio)
        ->assertJsonPath('bloqueo_documentos.activo', false)
        ->assertJsonPath('bloqueo_documentos.pendientes', 2);

    test()->withHeaders($headers)->getJson('/api/v1/colaborador/carta-compromiso')
        ->assertOk()
        ->assertJsonPath('data.carta.folio', $carta->folio)
        ->assertJsonCount(2, 'data.documentos_pendientes')
        ->assertJsonPath('data.documentos_pendientes.0.clave', 'acta_nacimiento')
        ->assertJsonPath('data.bloqueo.activo', false);

    $pdf = test()->withHeaders($headers)->get('/api/v1/colaborador/carta-compromiso/pdf')->assertOk();
    expect($pdf->headers->get('Content-Type'))->toBe('application/pdf');
});

test('sin marcar «No tengo este documento» el registro sigue exigiendo los documentos', function () {
    ['headers' => $headers] = ccpRegistrarPorQr();
    ccpSubir($headers, $this->ine);

    // Falta el comprobante de domicilio sin marcar.
    test()->withHeaders($headers)->postJson('/api/v1/colaborador/registro/finalizar', [
        'documentos_sin_tener' => [$this->acta->id],
    ])->assertUnprocessable()->assertJsonValidationErrors('registro');

    // Un documento ya subido no se puede marcar como «no lo tengo».
    test()->withHeaders($headers)->postJson('/api/v1/colaborador/registro/finalizar', [
        'documentos_sin_tener' => [$this->ine->id, $this->acta->id, $this->domicilio->id],
    ])->assertUnprocessable()->assertJsonValidationErrors('documentos_sin_tener');

    expect(CartaCompromiso::query()->count())->toBe(0);
});

test('vista previa de la carta antes de cerrar el registro, sin guardar nada', function () {
    ['headers' => $headers] = ccpRegistrarPorQr();

    $respuesta = test()->withHeaders($headers)->postJson('/api/v1/colaborador/carta-compromiso/vista-previa', [
        'documentos' => [$this->acta->id, $this->domicilio->id],
    ])->assertOk();

    expect($respuesta->headers->get('Content-Type'))->toBe('application/pdf')
        ->and((string) collect($this->htmlCartas)->last())->toContain('VISTA PREVIA')
        ->and(CartaCompromiso::query()->count())->toBe(0);

    // Solo documentos que de verdad le faltan (el contrato lo genera RH).
    $contrato = DocumentType::query()->where('clave', 'contrato')->firstOrFail();
    test()->withHeaders($headers)->postJson('/api/v1/colaborador/carta-compromiso/vista-previa', [
        'documentos' => [$contrato->id],
    ])->assertUnprocessable()->assertJsonValidationErrors('documentos');
});

test('con la carta vigente usa la app; vencida, solo puede subir sus documentos; al subirlos se cumple', function () {
    ['colaborador' => $colaborador, 'headers' => $headers] = ccpRegistrarPorQr();
    ccpSubir($headers, $this->ine);
    test()->withHeaders($headers)->postJson('/api/v1/colaborador/registro/finalizar', [
        'documentos_sin_tener' => [$this->acta->id, $this->domicilio->id],
    ])->assertOk();

    $vigente = test()->withHeaders($headers)->getJson('/api/v1/colaborador/solicitudes');
    expect($vigente->json('code'))->not->toBe('documentos_pendientes');

    $this->travel(8)->days();

    test()->withHeaders($headers)->getJson('/api/v1/colaborador/solicitudes')
        ->assertForbidden()
        ->assertJsonPath('code', 'documentos_pendientes')
        ->assertJsonPath('bloqueo.motivo', 'carta_vencida')
        ->assertJsonPath('bloqueo.pendientes', 2);

    test()->withHeaders($headers)->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('bloqueo_documentos.activo', true)
        ->assertJsonPath('carta_compromiso.estado', 'vencida');

    test()->withHeaders($headers)->getJson('/api/v1/colaborador/carta-compromiso')
        ->assertOk()
        ->assertJsonPath('data.carta.estado', 'vencida')
        ->assertJsonPath('data.carta.dias_restantes', 0);

    // Subir lo pendiente sí responde; al terminar, la carta se cumple sola.
    ccpSubir($headers, $this->acta);
    expect(CartaCompromiso::query()->where('colaborador_id', $colaborador->id)->latest('id')->firstOrFail()->estado)->toBe(EstadoCartaCompromiso::Vencida);

    ccpSubir($headers, $this->domicilio);
    $carta = CartaCompromiso::query()->where('colaborador_id', $colaborador->id)->latest('id')->firstOrFail();
    expect($carta->estado)->toBe(EstadoCartaCompromiso::Cumplida);

    // Sin pendientes: el bloqueo se levanta (aunque RH aún no apruebe).
    $libre = test()->withHeaders($headers)->getJson('/api/v1/colaborador/solicitudes');
    expect($libre->json('code'))->not->toBe('documentos_pendientes');
    test()->withHeaders($headers)->getJson('/api/v1/me')->assertJsonPath('bloqueo_documentos.activo', false);
});

test('subir todo dentro del plazo marca la carta como cumplida', function () {
    ['colaborador' => $colaborador, 'headers' => $headers] = ccpRegistrarPorQr();
    ccpSubir($headers, $this->ine);
    test()->withHeaders($headers)->postJson('/api/v1/colaborador/registro/finalizar', [
        'documentos_sin_tener' => [$this->acta->id, $this->domicilio->id],
    ])->assertOk();

    ccpSubir($headers, $this->acta);
    expect(CartaCompromiso::query()->firstOrFail()->estado)->toBe(EstadoCartaCompromiso::Vigente);

    ccpSubir($headers, $this->domicilio);
    $carta = CartaCompromiso::query()->firstOrFail();

    expect($carta->estado)->toBe(EstadoCartaCompromiso::Cumplida)
        ->and($carta->cumplida_en)->not->toBeNull();
});

test('el personal que ya laboraba con documentos faltantes solo puede subirlos', function () {
    $usuario = ccpColaboradorExistente();
    $headers = [ExigirDocumentosPendientes::CABECERA_CLIENTE => '1'];

    test()->actingAs($usuario, 'sanctum')->withHeaders($headers)->getJson('/api/v1/colaborador/solicitudes')
        ->assertForbidden()
        ->assertJsonPath('code', 'documentos_pendientes')
        ->assertJsonPath('bloqueo.motivo', 'sin_carta')
        ->assertJsonPath('bloqueo.pendientes', 3);

    // Lo necesario para subir sí responde.
    test()->actingAs($usuario, 'sanctum')->withHeaders($headers)->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('bloqueo_documentos.activo', true)
        ->assertJsonPath('carta_compromiso', null);
    test()->actingAs($usuario, 'sanctum')->withHeaders($headers)->getJson('/api/v1/colaborador/carta-compromiso')
        ->assertOk()
        ->assertJsonPath('data.carta', null)
        ->assertJsonCount(3, 'data.documentos_pendientes');
    test()->actingAs($usuario, 'sanctum')->withHeaders($headers)->getJson('/api/v1/colaborador/incorporacion')->assertOk();

    foreach ([$this->ine, $this->acta, $this->domicilio] as $tipo) {
        test()->actingAs($usuario, 'sanctum')->withHeaders($headers)
            ->post("/api/v1/colaborador/incorporacion/documentos/{$tipo->id}/subir", [
                'archivo' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
            ])->assertOk();
    }

    // Completo (en revisión basta): se levanta el bloqueo.
    $libre = test()->actingAs($usuario, 'sanctum')->withHeaders($headers)->getJson('/api/v1/colaborador/solicitudes');
    expect($libre->json('code'))->not->toBe('documentos_pendientes');
});

test('nunca bloquea a super_admin, ni con la bandera apagada, ni a la app que no declara entenderlo', function () {
    $headers = [ExigirDocumentosPendientes::CABECERA_CLIENTE => '1'];
    $usuario = ccpColaboradorExistente();

    // APK anterior (sin la cabecera): no se le rompe la app.
    $anterior = test()->actingAs($usuario, 'sanctum')->getJson('/api/v1/colaborador/solicitudes');
    expect($anterior->json('code'))->not->toBe('documentos_pendientes');

    $super = ccpColaboradorExistente('super_admin');
    $respuesta = test()->actingAs($super, 'sanctum')->withHeaders($headers)->getJson('/api/v1/colaborador/solicitudes');
    expect($respuesta->json('code'))->not->toBe('documentos_pendientes');
    test()->actingAs($super, 'sanctum')->withHeaders($headers)->getJson('/api/v1/me')->assertJsonPath('bloqueo_documentos.activo', false);

    config(['expedientes.bloqueo_documentos_faltantes' => false]);
    $apagado = test()->actingAs($usuario, 'sanctum')->withHeaders($headers)->getJson('/api/v1/colaborador/solicitudes');
    expect($apagado->json('code'))->not->toBe('documentos_pendientes');
    test()->actingAs($usuario, 'sanctum')->withHeaders($headers)->getJson('/api/v1/me')->assertJsonPath('bloqueo_documentos.activo', false);
});

test('generar otra carta sustituye a la vigente y RH descarga el PDF desde el expediente', function () {
    $usuario = ccpColaboradorExistente();
    $colaborador = $usuario->colaborador;
    $servicio = app(CartaCompromisoService::class);

    $primera = $servicio->generar($colaborador, [$this->acta->id], $usuario);
    $segunda = $servicio->generar($colaborador, [$this->acta->id, $this->domicilio->id], $usuario);

    expect($primera->refresh()->estado)->toBe(EstadoCartaCompromiso::Sustituida)
        ->and($segunda->estado)->toBe(EstadoCartaCompromiso::Vigente)
        ->and(CartaCompromiso::query()->where('estado', 'vigente')->count())->toBe(1);

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $respuesta = test()->actingAs($admin)->get(route('rh.expedientes.carta-compromiso.pdf', $colaborador))->assertOk();
    expect($respuesta->headers->get('Content-Type'))->toBe('application/pdf');

    expect($servicio->paraExpediente($colaborador))->toMatchArray(['folio' => $segunda->folio, 'estado' => 'vigente']);
});

test('la carta no imprime huecos de datos vacíos ni el nombre de quien la generó', function () {
    $usuario = ccpColaboradorExistente();
    $colaborador = $usuario->colaborador;
    $colaborador->update(['curp' => null, 'numero_empleado' => null]);

    $html = app(CartaCompromisoService::class)->html(app(CartaCompromisoService::class)->datos(
        $colaborador->refresh(),
        [['document_type_id' => $this->acta->id, 'clave' => 'acta_nacimiento', 'nombre' => 'Acta de nacimiento']],
        'CC-2026-000001',
        now()->addDays(7),
        now(),
    ));

    expect($html)->not->toContain('CURP')
        ->and($html)->not->toContain('número de colaborador')
        ->and($html)->toContain('Acta de nacimiento')
        ->and($html)->toContain('Huella digital')
        ->and($html)->not->toContain('Sistemas Mrlana');
});

test('con más faltantes que el máximo de una carta termina el registro, sin carta y con la app bloqueada', function () {
    ['colaborador' => $colaborador, 'headers' => $headers] = ccpRegistrarPorQr();

    // Puede cerrar aunque le falten 3 (más que el máximo de 2).
    test()->withHeaders($headers)->getJson('/api/v1/colaborador/registro')
        ->assertOk()
        ->assertJsonPath('data.puede_finalizar', true)
        ->assertJsonPath('data.max_documentos_carta', 2);

    test()->withHeaders($headers)->postJson('/api/v1/colaborador/registro/finalizar', [
        'documentos_sin_tener' => [$this->ine->id, $this->acta->id, $this->domicilio->id],
    ])->assertOk()
        ->assertJsonPath('data.registro_completo', true)
        ->assertJsonPath('data.carta_compromiso', null);

    expect(CartaCompromiso::query()->where('colaborador_id', $colaborador->id)->count())->toBe(0);

    test()->withHeaders($headers)->getJson('/api/v1/colaborador/solicitudes')
        ->assertForbidden()
        ->assertJsonPath('code', 'documentos_pendientes')
        ->assertJsonPath('bloqueo.motivo', 'excede_maximo_carta')
        ->assertJsonPath('bloqueo.pendientes', 3)
        ->assertJsonPath('bloqueo.max_documentos_carta', 2);

    test()->withHeaders($headers)->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('bloqueo_documentos.activo', true)
        ->assertJsonPath('bloqueo_documentos.motivo', 'excede_maximo_carta')
        ->assertJsonPath('carta_compromiso', null);

    test()->withHeaders($headers)->getJson('/api/v1/colaborador/carta-compromiso')
        ->assertOk()
        ->assertJsonPath('data.carta', null)
        ->assertJsonPath('data.bloqueo.motivo', 'excede_maximo_carta');
});

test('al bajar sus faltantes al máximo, la carta se genera sola con exactamente esos documentos y se levanta el bloqueo', function () {
    ['colaborador' => $colaborador, 'headers' => $headers] = ccpRegistrarPorQr();
    test()->withHeaders($headers)->postJson('/api/v1/colaborador/registro/finalizar', [
        'documentos_sin_tener' => [$this->ine->id, $this->acta->id, $this->domicilio->id],
    ])->assertOk();

    $this->travel(3)->days();
    ccpSubir($headers, $this->ine);

    $carta = CartaCompromiso::query()->where('colaborador_id', $colaborador->id)->sole();
    expect($carta->estado)->toBe(EstadoCartaCompromiso::Vigente)
        ->and(array_column($carta->documentos, 'document_type_id'))->toBe([$this->acta->id, $this->domicilio->id])
        // El plazo empieza a correr al generarse la carta, no al cerrar el registro.
        ->and($carta->fecha_limite->toDateString())->toBe(now()->addDays(7)->toDateString())
        ->and($carta->generada_por)->toBe($colaborador->user->id)
        ->and($carta->path)->not->toBeNull();

    expect((string) collect($this->htmlCartas)->last())->toContain('los siguientes dos documentos')
        ->and((string) collect($this->htmlCartas)->last())->not->toContain('Identificación oficial');

    $libre = test()->withHeaders($headers)->getJson('/api/v1/colaborador/solicitudes');
    expect($libre->json('code'))->not->toBe('documentos_pendientes');

    test()->withHeaders($headers)->getJson('/api/v1/colaborador/carta-compromiso')
        ->assertOk()
        ->assertJsonPath('data.carta.folio', $carta->folio)
        ->assertJsonPath('data.bloqueo.activo', false);

    // Subir el resto la cumple; nunca se genera una segunda carta.
    ccpSubir($headers, $this->acta);
    ccpSubir($headers, $this->domicilio);
    expect(CartaCompromiso::query()->where('colaborador_id', $colaborador->id)->sole()->estado)->toBe(EstadoCartaCompromiso::Cumplida);
});

test('la carta automática también se genera cuando RH sube el documento desde el expediente', function () {
    ['colaborador' => $colaborador, 'headers' => $headers] = ccpRegistrarPorQr();
    test()->withHeaders($headers)->postJson('/api/v1/colaborador/registro/finalizar', [
        'documentos_sin_tener' => [$this->ine->id, $this->acta->id, $this->domicilio->id],
    ])->assertOk();

    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    test()->actingAs($admin)->post(route('rh.expedientes.documentos.store', $colaborador), [
        'document_type_id' => $this->domicilio->id,
        'archivo' => UploadedFile::fake()->create('domicilio.pdf', 100, 'application/pdf'),
    ])->assertRedirect();

    $carta = CartaCompromiso::query()->where('colaborador_id', $colaborador->id)->sole();
    expect(array_column($carta->documentos, 'document_type_id'))->toBe([$this->ine->id, $this->acta->id])
        ->and($carta->generada_por)->toBe($colaborador->user->id);
});

test('con el máximo o menos marcado al cerrar, la carta se genera de inmediato como siempre', function () {
    config(['expedientes.carta_compromiso.max_documentos' => 3]);
    ['colaborador' => $colaborador, 'headers' => $headers] = ccpRegistrarPorQr();

    test()->withHeaders($headers)->postJson('/api/v1/colaborador/registro/finalizar', [
        'documentos_sin_tener' => [$this->ine->id, $this->acta->id, $this->domicilio->id],
    ])->assertOk()
        ->assertJsonPath('data.carta_compromiso.estado', 'vigente')
        ->assertJsonCount(3, 'data.carta_compromiso.documentos');

    expect(CartaCompromiso::query()->where('colaborador_id', $colaborador->id)->count())->toBe(1);
});

test('una carta nunca cubre más documentos que el máximo y la vista previa responde 422', function () {
    $usuario = ccpColaboradorExistente();

    expect(fn () => app(CartaCompromisoService::class)->generar($usuario->colaborador, [$this->ine->id, $this->acta->id, $this->domicilio->id], $usuario))
        ->toThrow(ValidationException::class);
    expect(CartaCompromiso::query()->count())->toBe(0);

    ['headers' => $headers] = ccpRegistrarPorQr();
    $respuesta = test()->withHeaders($headers)->postJson('/api/v1/colaborador/carta-compromiso/vista-previa', [
        'documentos' => [$this->ine->id, $this->acta->id, $this->domicilio->id],
    ])->assertUnprocessable()->assertJsonValidationErrors('documentos');

    expect($respuesta->json('errors.documentos.0'))->toContain('como máximo 2 documentos pendientes')
        ->and(collect($this->htmlCartas)->count())->toBe(0);
});

test('el personal que ya laboraba nunca recibe carta automática al bajar sus faltantes', function () {
    $usuario = ccpColaboradorExistente();
    $headers = [ExigirDocumentosPendientes::CABECERA_CLIENTE => '1'];

    test()->actingAs($usuario, 'sanctum')->withHeaders($headers)
        ->post("/api/v1/colaborador/incorporacion/documentos/{$this->ine->id}/subir", [
            'archivo' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
        ])->assertOk();

    expect(CartaCompromiso::query()->count())->toBe(0);
    test()->actingAs($usuario, 'sanctum')->withHeaders($headers)->getJson('/api/v1/colaborador/solicitudes')
        ->assertForbidden()
        ->assertJsonPath('bloqueo.motivo', 'sin_carta')
        ->assertJsonPath('bloqueo.pendientes', 2);
});

test('la carta nombra el documento en singular o los dos documentos en plural', function () {
    $usuario = ccpColaboradorExistente();
    $servicio = app(CartaCompromisoService::class);
    $acta = ['document_type_id' => $this->acta->id, 'clave' => 'acta_nacimiento', 'nombre' => 'Acta de nacimiento'];
    $domicilio = ['document_type_id' => $this->domicilio->id, 'clave' => 'comprobante_domicilio', 'nombre' => 'Comprobante de domicilio'];

    $uno = $servicio->html($servicio->datos($usuario->colaborador, [$acta], 'CC-2026-000001', now()->addDays(7), now()));
    expect($uno)->toContain('el siguiente documento para integrar')
        ->and($uno)->toContain('este documento es necesario')
        ->and($uno)->toContain('<strong>«Acta de nacimiento»</strong> dentro del plazo');

    $dos = $servicio->html($servicio->datos($usuario->colaborador, [$acta, $domicilio], 'CC-2026-000002', now()->addDays(7), now()));
    expect($dos)->toContain('los siguientes dos documentos para integrar')
        ->and($dos)->toContain('<strong>«Acta de nacimiento»</strong> y <strong>«Comprobante de domicilio»</strong> dentro del plazo');
});
