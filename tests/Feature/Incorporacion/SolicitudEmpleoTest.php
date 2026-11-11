<?php

use App\Enums\EstadoAltaColaborador;
use App\Enums\TipoContratacion;
use App\Models\Colaborador;
use App\Models\ColaboradorDatosMedicos;
use App\Models\ColaboradorPerfilIngreso;
use App\Models\ContratoLaboral;
use App\Models\DocumentTemplate;
use App\Models\DocumentType;
use App\Models\GeneratedDocument;
use App\Models\User;
use App\Services\Contratos\ContratoLaboralService;
use App\Services\DocumentosMaestros\CatalogoMaestrosService;
use App\Services\DocumentosMaestros\DocumentoProcesoService;
use App\Services\DocumentosMaestros\ImportadorFormatosJuridicosService;
use App\Services\Incorporacion\RegistroAppService;
use App\Services\Incorporacion\SolicitudEmpleo\FaltanDatosIngresoException;
use App\Services\Incorporacion\SolicitudEmpleo\PerfilIngresoService;
use App\Services\Incorporacion\SolicitudEmpleo\RequisitoDocumentosIngresoService;
use App\Services\Incorporacion\SolicitudEmpleo\SolicitudEmpleoRegistroService;
use Carbon\CarbonImmutable;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

/*
| Solicitud de empleo + Estudio socioeconómico generados por PEOPLE
| (docs/SOLICITUD_EMPLEO_Y_SOCIOECONOMICO.md): completitud exacta por
| sección/documento, «No aplica», captura app/RH, 422 DATOS_FALTANTES,
| overlay real sobre los PDF originales y la regla «antes del contrato».
|
| Con SE_MUESTRAS=<carpeta> los PDF generados se escriben ahí para revisarlos
| visualmente (pdftoppm).
*/

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $this->seed(DocumentTypeSeeder::class);
    // Raíz propia del disco falso: otra corrida de pruebas en paralelo que
    // haga Storage::fake('nas') vacía la carpeta compartida a media prueba.
    $raiz = storage_path('framework/testing/disks/nas-solicitud-'.getmypid());
    File::deleteDirectory($raiz);
    File::ensureDirectoryExists($raiz);
    Storage::set('nas', Storage::createLocalDriver(['root' => $raiz]));
    Notification::fake();
    $this->rh = clUsuario('rh_admin');
    $this->estructura = clEstructura();
    $this->estructura['sucursal']->update(['direccion' => 'Av. Morelos 120', 'ciudad' => 'Cuernavaca', 'estado' => 'Morelos', 'codigo_postal' => '62000']);
});

function seImportarMasters(): void
{
    dmMotorFielDePrueba();
    $catalogo = app(CatalogoMaestrosService::class);

    foreach (glob(base_path('docs/formatos_fuente').'/*.pdf') ?: [] as $ruta) {
        foreach ($catalogo->todasPorHash((string) hash_file('sha256', $ruta)) as $fuente) {
            if (in_array($fuente['familia'], ['solicitud_empleo.general', 'estudio_socioeconomico.general'], true)) {
                app(ImportadorFormatosJuridicosService::class)->registrarVersion($fuente['familia'], $fuente['version'], (string) file_get_contents($ruta), [basename($ruta)], $fuente, null);
            }
        }
    }
}

/**
 * @param  array<string, mixed>  $extra
 */
function sePersona(array $estructura, array $extra = []): Colaborador
{
    return Colaborador::factory()->create([
        'name' => 'Ana María', 'apellidos' => 'Torres Aguilar', 'genero' => 'femenino', 'fecha_nacimiento' => '1995-04-12',
        'telefono' => '7771234567', 'estado_civil' => 'soltero', 'correo_personal' => 'ana.torres@correo.test',
        'curp' => 'TOAA950412MMSRRN09', 'rfc' => 'TOAA950412AB1', 'nss' => '12345678901', 'clave_elector' => 'TRANAN95041217M100',
        'domicilio_calle' => 'Av. Emiliano Zapata', 'domicilio_numero_exterior' => '120', 'domicilio_colonia' => 'Centro',
        'domicilio_cp' => '62000', 'domicilio_municipio' => 'Cuernavaca', 'domicilio_estado' => 'Morelos',
        'sucursal_principal_id' => $estructura['sucursal']->id, 'puesto_id' => $estructura['puesto']->id,
        'estatus' => 'en_incorporacion', 'estado_alta' => EstadoAltaColaborador::PendienteContrato->value,
        ...$extra,
    ]);
}

/**
 * Datos completos por sección (lo que la persona captura en la app).
 *
 * @return array<string, array<string, mixed>>
 */
function seDatosCompletos(): array
{
    return [
        'personal' => ['telefono_alterno' => '7779876543', 'numero_hijos' => 2, 'tipo_sanguineo' => 'o_positivo', 'estado_salud' => 'bueno', 'padece_enfermedad_cronica' => false, 'proceso_legal_laboral' => false, 'identificacion_tipo' => 'ine', 'identificacion_folio' => '1234567890123'],
        'domicilio' => ['tiempo_residencia_anios' => 3, 'tiempo_residencia_meses' => 2, 'con_quien_vive' => 'Con mis hijos', 'tiempo_traslado_minutos' => 35, 'entre_calle_1' => 'Hidalgo', 'entre_calle_2' => 'Juárez', 'calle_posterior' => 'Allende', 'referencias_domicilio' => 'Casa azul con portón negro, frente a la tienda.'],
        'dependientes' => ['sin_dependientes' => false, 'dependientes' => [
            ['nombre' => 'Luis', 'apellido_paterno' => 'Torres', 'apellido_materno' => 'Gómez', 'parentesco' => 'hijo', 'fecha_nacimiento' => '2016-05-01', 'escolaridad_ocupacion' => 'Primaria'],
            ['nombre' => 'Sofía', 'apellido_paterno' => 'Torres', 'parentesco' => 'hijo', 'edad' => 6, 'escolaridad_ocupacion' => 'Preescolar'],
        ]],
        'vivienda' => ['vivienda_tipo' => 'casa', 'vivienda_tenencia' => 'renta', 'paredes' => 'concreto', 'numero_cuartos' => 3, 'numero_banos' => 1, 'tiene_patio' => true, 'servicios_vivienda' => ['agua', 'luz', 'drenaje', 'gas'], 'personas_habitan' => 3],
        'economica' => [
            'egreso_alimentacion' => 4000, 'egreso_renta' => 3500, 'egreso_agua' => 150, 'egreso_luz' => 300, 'egreso_telefono' => 250, 'egreso_servicios_medicos' => 0,
            'egreso_vestido' => 500, 'egreso_diversion' => 300, 'egreso_predial' => 0, 'egreso_hipoteca' => 0, 'egreso_transporte' => 600, 'egreso_gasolina' => 0,
            'egreso_gas' => 450, 'egreso_otros' => 0, 'infonavit' => false, 'tiene_inmueble' => false, 'tiene_vehiculo' => false, 'bienes_muebles' => ['estufa', 'refrigerador', 'television'], 'servicio_medico' => 'ninguno',
        ],
        'escolaridad' => ['escolaridad' => 'bachillerato', 'area_cursos' => 'Curso de ventas y atención a clientes', 'licencia_moto' => false, 'exp_ventas' => true, 'exp_ventas_anios' => 2, 'exp_cobranza' => false, 'exp_creditos' => false, 'prospeccion_campo' => true, 'apps_moviles' => true, 'comisiones_objetivos' => true, 'estilos_trabajo' => ['orientado_metas', 'trabajo_campo']],
        'experiencia' => ['sin_experiencia_laboral' => false, 'empleos' => [
            ['empresa' => 'Financiera Sol', 'puesto' => 'Promotora', 'desde' => '2021-03', 'hasta' => '2024-08', 'funciones' => 'Colocación de créditos y cobranza.', 'motivo_salida' => 'Cambio de domicilio', 'telefono' => '7771112233'],
        ]],
        'referencias' => ['referencias' => [
            ['nombre' => 'Rosa Gómez', 'relacion' => 'Vecina', 'telefono' => '7772223344', 'anios_conocerlo' => 8],
            ['nombre' => 'Pedro Ruiz', 'relacion' => 'Amigo', 'telefono' => '7773334455'],
        ]],
        'perfil' => ['fuente_vacante' => 'redes_sociales', 'aplico_antes' => false, 'familiares_empresa' => false, 'tres_palabras' => 'Responsable, puntual, empática', 'fortalezas' => 'Trato con clientes y constancia.', 'logro_profesional' => 'Mejor colocadora del trimestre.', 'situacion_dificil' => 'Un cliente molesto por un cargo; revisé su cuenta y lo aclaré.', 'areas_oportunidad' => 'Excel avanzado.', 'como_te_describen' => 'Comprometida y ordenada.'],
    ];
}

/**
 * @param  array<string, array<string, mixed>>  $cambios
 * @param  array<string, list<string>>  $noAplica
 */
function seCompletar(Colaborador $colaborador, User $actor, array $cambios = [], array $noAplica = ['domicilio' => ['calle_lateral'], 'vivienda' => ['construccion_otros']]): void
{
    foreach (seDatosCompletos() as $seccion => $datos) {
        app(PerfilIngresoService::class)->guardarSeccion($colaborador, $seccion, array_replace($datos, $cambios[$seccion] ?? []), $noAplica[$seccion] ?? null, $actor, PerfilIngresoService::ORIGEN_APP);
    }
}

function seGuardarMuestra(GeneratedDocument $documento, string $nombre): void
{
    $carpeta = getenv('SE_MUESTRAS');

    if (is_string($carpeta) && $carpeta !== '' && is_dir($carpeta)) {
        file_put_contents($carpeta.DIRECTORY_SEPARATOR.$nombre.'.pdf', Storage::disk((string) $documento->disk)->get((string) $documento->path));
    }
}

function seContrato(Colaborador $colaborador): ContratoLaboral
{
    return ContratoLaboral::query()->create([
        'colaborador_id' => $colaborador->id, 'tipo' => TipoContratacion::CapacitacionInicial, 'fecha_inicio' => now()->toDateString(), 'fecha_fin' => now()->addMonths(2)->toDateString(),
        'estado' => 'vigente', 'sueldo_mensual' => 12000, 'puesto_id' => $colaborador->puesto_id, 'sucursal_id' => $colaborador->sucursal_principal_id,
    ]);
}

// ───────────────────────── Completitud ─────────────────────────

test('sin capturar nada, la completitud dice exactamente qué falta por documento y por sección', function () {
    $persona = sePersona($this->estructura);
    $c = app(PerfilIngresoService::class)->completitud($persona);

    expect($c['completo'])->toBeFalse()
        ->and($c['documentos']['solicitud_empleo']['mensaje'])->toStartWith('Faltan ')
        ->and($c['documentos']['solicitud_empleo']['mensaje'])->toContain('para generar la Solicitud de Empleo: ')
        ->and($c['documentos']['solicitud_empleo']['faltantes'])->toContain('Último grado de estudios', 'Número de hijos', '¿Cómo supiste de la vacante?')
        ->and($c['documentos']['estudio_socioeconomico']['faltantes'])->toContain('Tipo sanguíneo', 'Gasto mensual en renta', 'Referencias personales (captura al menos 2)')
        // Datos personales ya capturados en el registro no se piden otra vez.
        ->and($c['documentos']['solicitud_empleo']['faltantes'])->not->toContain('CURP', 'Nombre(s)')
        // Condicionales: soltera → cónyuge no se pide; sin licencia todavía no hay años de moto.
        ->and($c['documentos']['solicitud_empleo']['faltantes'])->not->toContain('Nombre de tu cónyuge')
        ->and(array_column($c['secciones'], 'clave'))->toBe(['personal', 'domicilio', 'dependientes', 'vivienda', 'economica', 'escolaridad', 'experiencia', 'referencias', 'perfil'])
        ->and($c['porcentaje'])->toBeLessThan(40);
});

test('completa por secciones (guardar y continuar), con No aplica explícito, llega al 100 %', function () {
    $persona = sePersona($this->estructura);
    $servicio = app(PerfilIngresoService::class);

    app(PerfilIngresoService::class)->guardarSeccion($persona, 'personal', seDatosCompletos()['personal'], null, $this->rh, 'app');
    $parcial = $servicio->completitud($persona);
    expect(collect($parcial['secciones'])->firstWhere('clave', 'personal')['completa'])->toBeTrue()
        ->and($parcial['completo'])->toBeFalse();

    seCompletar($persona, $this->rh);
    $c = $servicio->completitud($persona->refresh());

    expect($c['completo'])->toBeTrue()
        ->and($c['porcentaje'])->toBe(100)
        ->and($c['documentos']['solicitud_empleo']['mensaje'])->toBe('La Solicitud de Empleo tiene todos sus datos: ya se puede generar.')
        ->and($servicio->noAplica($persona))->toBe(['calle_lateral', 'construccion_otros'])
        // Datos sensibles cifrados en reposo; tipo sanguíneo en datos médicos.
        ->and(ColaboradorDatosMedicos::query()->where('colaborador_id', $persona->id)->value('tipo_sanguineo'))->toBe('o_positivo')
        ->and(DB::table('colaborador_perfiles_ingreso')->where('colaborador_id', $persona->id)->value('situacion_economica'))->not->toContain('3500');
});

test('una condición cambia lo obligatorio: con licencia de moto se piden los años; casada, el cónyuge', function () {
    $persona = sePersona($this->estructura, ['estado_civil' => 'casado']);
    seCompletar($persona, $this->rh, ['escolaridad' => ['licencia_moto' => true]]);

    $faltantes = app(PerfilIngresoService::class)->completitud($persona->refresh())['documentos']['solicitud_empleo']['faltantes'];

    expect($faltantes)->toBe(['Nombre de tu cónyuge', 'Años de experiencia manejando moto']);
});

test('no aplica solo en los datos que lo admiten, y una lista no puede decir «No tengo» con renglones', function () {
    $persona = sePersona($this->estructura);

    expect(fn () => app(PerfilIngresoService::class)->guardarSeccion($persona, 'personal', [], ['numero_hijos'], $this->rh, 'app'))
        ->toThrow(ValidationException::class, 'no admiten «No aplica»');

    expect(fn () => app(PerfilIngresoService::class)->guardarSeccion($persona, 'dependientes', ['sin_dependientes' => true, 'dependientes' => [['nombre' => 'Luis', 'apellido_paterno' => 'T', 'parentesco' => 'hijo', 'edad' => 4, 'escolaridad_ocupacion' => 'Kínder']]], null, $this->rh, 'app'))
        ->toThrow(ValidationException::class, 'No tengo dependientes económicos');
});

// ───────────────────────── API de la app (registro) ─────────────────────────

test('app: lee el estado por sección con prellenado y guarda una sección con validación en español', function () {
    $persona = sePersona($this->estructura, ['estado_alta' => EstadoAltaColaborador::PendienteDocumentos->value]);
    $cuenta = clUsuarioDe($persona);
    $this->mock(RegistroAppService::class, fn ($m) => $m->shouldReceive('aplica')->andReturn(true));
    Sanctum::actingAs($cuenta);

    $estado = $this->getJson('/api/v1/colaborador/registro/solicitud')->assertOk()->json('data');
    $personal = collect($estado['secciones'])->firstWhere('clave', 'personal');
    $identificacion = collect($personal['campos'])->firstWhere('campo', 'identificacion_tipo');

    expect($estado['registro_abierto'])->toBeTrue()
        ->and($identificacion['sugerencia'])->toBe('ine')
        ->and(collect($personal['campos'])->firstWhere('campo', 'curp')['bloqueado'])->toBeTrue()
        ->and($identificacion['opciones'][0])->toBe(['value' => 'ine', 'label' => 'INE / IFE']);

    $this->postJson('/api/v1/colaborador/registro/solicitud/personal', ['datos' => ['tipo_sanguineo' => 'Z+', 'numero_hijos' => 99]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['datos.tipo_sanguineo', 'datos.numero_hijos']);

    $respuesta = $this->postJson('/api/v1/colaborador/registro/solicitud/personal', ['datos' => seDatosCompletos()['personal'], 'no_aplica' => ['telefono_alterno']])->assertOk();

    expect(collect($respuesta->json('data.secciones'))->firstWhere('clave', 'personal')['completa'])->toBeTrue()
        ->and($persona->refresh()->telefono_alterno)->toBeNull();
});

test('app: con el registro cerrado ya no se captura (se corrige por solicitud)', function () {
    $persona = sePersona($this->estructura);
    Sanctum::actingAs(clUsuarioDe($persona));

    $this->postJson('/api/v1/colaborador/registro/solicitud/personal', ['datos' => ['numero_hijos' => 1]])
        ->assertUnprocessable()
        ->assertJsonPath('errors.registro.0', 'Tu registro ya está completo. Si necesitas cambiar un dato, solicita una corrección desde la app.');
});

test('registro: a la app que implementa la solicitud, finalizar exige completitud con lo que falta por sección', function () {
    $persona = sePersona($this->estructura);
    config(['incorporacion.registro_app.exigir_solicitud_empleo' => 'siempre']);
    $servicio = app(SolicitudEmpleoRegistroService::class);

    expect(fn () => $servicio->exigirParaRegistro($persona))->toThrow(ValidationException::class, 'Completa tu solicitud de empleo y estudio socioeconómico. Falta: Datos personales: ');

    seCompletar($persona, $this->rh);
    $servicio->exigirParaRegistro($persona->refresh());
    $resumen = $servicio->resumen($persona);

    expect($resumen['exigida'])->toBeTrue()->and($resumen['completo'])->toBeTrue()->and($resumen['pendientes'])->toBe([]);

    // Apps instaladas (sin la cabecera): no se les exige en el registro.
    config(['incorporacion.registro_app.exigir_solicitud_empleo' => 'cliente']);
    expect(app(SolicitudEmpleoRegistroService::class)->exigidaEnRegistro())->toBeFalse();
});

// ───────────────────────── Web de RH ─────────────────────────

test('RH ve lo capturado por sección y solo completa lo que falta (nunca recaptura)', function () {
    $persona = sePersona($this->estructura);
    $cuenta = clUsuarioDe($persona);
    app(PerfilIngresoService::class)->guardarSeccion($persona, 'personal', ['numero_hijos' => 1], null, $cuenta, 'app');
    $this->actingAs($this->rh);

    $estado = $this->getJson("/rh/colaboradores/{$persona->id}/solicitud-empleo")->assertOk()->json('data');
    $campos = collect(collect($estado['secciones'])->firstWhere('clave', 'personal')['campos']);

    expect($campos->firstWhere('campo', 'numero_hijos')['bloqueado'])->toBeTrue()
        ->and($campos->firstWhere('campo', 'estado_salud')['bloqueado'])->toBeFalse();

    $this->postJson("/rh/colaboradores/{$persona->id}/solicitud-empleo/personal", ['datos' => ['numero_hijos' => 3]])
        ->assertUnprocessable()
        ->assertJsonPath('errors.datos.0', 'La persona ya capturó: Número de hijos. RH solo completa lo que falta; si algo está mal, se corrige por solicitud de corrección.');

    $this->postJson("/rh/colaboradores/{$persona->id}/solicitud-empleo/personal", ['datos' => ['estado_salud' => 'bueno']])->assertOk();

    // La persona ya no cambia lo que completó RH.
    expect(fn () => app(PerfilIngresoService::class)->guardarSeccion($persona, 'personal', ['estado_salud' => 'malo'], null, $cuenta, 'app'))
        ->toThrow(ValidationException::class, 'Recursos Humanos ya registró: ¿Cómo consideras tu estado de salud?');

    // Sin permiso o fuera de alcance: 403.
    $this->actingAs(clUsuario('colaborador'));
    $this->getJson("/rh/colaboradores/{$persona->id}/solicitud-empleo")->assertForbidden();
});

// ───────────────────────── Generación (overlay real) ─────────────────────────

test('incompleto: 422 DATOS_FALTANTES con la lista exacta y nunca un PDF; la vista previa sí se puede ver', function () {
    seImportarMasters();
    $persona = sePersona($this->estructura);
    seCompletar($persona, $this->rh, ['escolaridad' => ['escolaridad' => null]]);
    Sanctum::actingAs($this->rh);

    $this->postJson("/api/v1/rh/documentos-proceso/colaborador/{$persona->id}/generar", ['clave' => 'solicitud_empleo', 'proceso' => 'incorporacion'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'DATOS_FALTANTES')
        ->assertJsonPath('message', 'Falta 1 dato para generar la Solicitud de Empleo: Último grado de estudios.')
        ->assertJsonPath('faltantes.0.etiqueta', 'Último grado de estudios');

    expect(GeneratedDocument::query()->count())->toBe(0);

    $this->get("/api/v1/rh/documentos-proceso/colaborador/{$persona->id}/vista-previa?clave=solicitud_empleo&proceso=incorporacion")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

test('completo: genera ambos PDF sobre el original, ligados al expediente con su tipo documental y versión', function () {
    seImportarMasters();
    $persona = sePersona($this->estructura);
    seCompletar($persona, $this->rh);
    Sanctum::actingAs($this->rh);

    $seccion = collect($this->getJson("/api/v1/rh/documentos-proceso/colaborador/{$persona->id}")->assertOk()->json('data'))->firstWhere('proceso', 'incorporacion');
    expect(array_column($seccion['documentos'], 'clave'))->toBe(['solicitud_empleo', 'estudio_socioeconomico'])
        ->and(array_column($seccion['documentos'][0]['acciones'], 'clave'))->toContain('vista_previa', 'generar');

    $this->postJson("/api/v1/rh/documentos-proceso/colaborador/{$persona->id}/generar", ['clave' => 'solicitud_empleo', 'proceso' => 'incorporacion'])->assertCreated();
    $this->postJson("/api/v1/rh/documentos-proceso/colaborador/{$persona->id}/generar", ['clave' => 'estudio_socioeconomico', 'proceso' => 'incorporacion'])->assertCreated();

    $solicitud = GeneratedDocument::query()->where('clave_plantilla', 'solicitud_empleo')->sole();
    $estudio = GeneratedDocument::query()->where('clave_plantilla', 'estudio_socioeconomico')->sole();
    seGuardarMuestra($solicitud, 'sol-completo');
    seGuardarMuestra($estudio, 'ese-completo');

    expect($solicitud->master_familia)->toBe('solicitud_empleo.general')
        ->and($solicitud->paginas)->toBe(4)
        ->and($estudio->paginas)->toBe(3)
        ->and($solicitud->categoria->value)->toBe('personales')
        ->and($solicitud->documentable_id)->toBe($persona->id)
        ->and($solicitud->conversion_fidelity)->toBe('nativa')
        ->and(DocumentTemplate::query()->where('familia', 'solicitud_empleo.general')->value('document_type_id'))->toBe(DocumentType::query()->where('clave', 'solicitud_empleo')->value('id'))
        ->and(DocumentTemplate::query()->where('familia', 'estudio_socioeconomico.general')->value('document_type_id'))->toBe(DocumentType::query()->where('clave', 'estudio_socioeconomico')->value('id'))
        // Snapshot con el formato del documento: edad derivada, «No aplica», casillas.
        ->and($solicitud->payload['edad_numero'])->toBe((string) (int) $persona->fecha_nacimiento->diffInYears(now('America/Mexico_City')))
        ->and($solicitud->payload['sol_conyuge'])->toBe('No aplica')
        ->and($solicitud->payload['sol_anios_moto'])->toBe('No aplica')
        ->and($solicitud->payload['marca_sexo_f'])->toBe('X')
        ->and($solicitud->payload['sol_dependientes'])->toContain('Luis Torres (', 'Sofía Torres (6)')
        ->and($estudio->payload['ese_calle_lateral'])->toBe('No aplica')
        ->and($estudio->payload['ese_inm_ubicacion'])->toBe('No aplica (sin bienes inmuebles)')
        ->and($estudio->payload['ese_id_institucion'])->toBe('Instituto Nacional Electoral')
        ->and($estudio->payload['ese_egreso_renta'])->toBe('3,500.00')
        ->and(Storage::disk('nas')->exists((string) $solicitud->path))->toBeTrue();

    // Regenerar solo por las reglas del motor: sin «regenerar» no se duplica.
    $this->postJson("/api/v1/rh/documentos-proceso/colaborador/{$persona->id}/generar", ['clave' => 'solicitud_empleo', 'proceso' => 'incorporacion'])
        ->assertUnprocessable();
});

test('datos largos: nombre y domicilio largos, 6 dependientes y 3 empleos caben sin desbordarse', function () {
    seImportarMasters();
    $persona = sePersona($this->estructura, [
        'name' => 'José Francisco de Jesús', 'apellidos' => 'Hernández González', 'estado_civil' => 'casado', 'genero' => 'masculino',
        'correo_personal' => 'jose.francisco.hernandez@correo-ejemplo.com.mx',
        'domicilio_calle' => 'Calle Prolongación Emiliano Zapata', 'domicilio_numero_exterior' => '1250', 'domicilio_numero_interior' => '4-B',
        'domicilio_colonia' => 'Lomas de la Selva Norte Ampliación', 'domicilio_municipio' => 'Cuernavaca', 'domicilio_estado' => 'Morelos', 'domicilio_cp' => '62270',
    ]);
    $dependientes = [];

    foreach (range(1, 6) as $n) {
        $dependientes[] = ['nombre' => 'María Guadalupe '.$n, 'apellido_paterno' => 'Hernández', 'apellido_materno' => 'Fernández de la Torre', 'parentesco' => $n === 1 ? 'conyuge' : 'hijo', 'edad' => 30 - $n, 'escolaridad_ocupacion' => 'Licenciatura en Administración'];
    }

    $empleo = fn (int $n): array => ['empresa' => "Financiera Independencia Sucursal Centro {$n}", 'puesto' => 'Asesor de crédito y cobranza', 'desde' => '2018-01', 'hasta' => $n === 1 ? null : '2020-12',
        'funciones' => 'Promoción y colocación de créditos en campo, cobranza preventiva y correctiva de cartera vencida, atención a clientes en sucursal y reportes diarios de metas semanales al gerente de la sucursal.',
        'motivo_salida' => 'Crecimiento profesional y cambio de domicilio a otra ciudad', 'telefono' => '7771234567'];

    seCompletar($persona, $this->rh, [
        'personal' => ['conyuge_nombre' => 'María Guadalupe Fernández de la Torre y Castañeda'],
        'dependientes' => ['dependientes' => $dependientes],
        'experiencia' => ['empleos' => [$empleo(1), $empleo(2), $empleo(3)]],
        'escolaridad' => ['licencia_moto' => true, 'anios_manejo_moto' => 12, 'escolaridad' => 'licenciatura'],
        'economica' => ['tiene_inmueble' => true, 'inmueble_ubicacion' => 'Terreno en Jiutepec, Morelos', 'inmueble_metros' => 250, 'inmueble_valor' => 850000, 'inmueble_tipo_propiedad' => 'ejidal', 'inmueble_zona' => 'semiurbana', 'inmueble_adquisicion' => 'herencia',
            'tiene_vehiculo' => true, 'vehiculo_tipo' => 'moto', 'vehiculo_modelo' => '2022', 'vehiculo_tipo_detalle' => 'Trabajo 150 cc', 'vehiculo_marca' => 'Italika', 'vehiculo_valor' => 28000, 'servicio_medico' => 'imss', 'egreso_otros' => 1200, 'egreso_otros_concepto' => 'Colegiaturas'],
        'referencias' => ['referencias' => [
            ['nombre' => 'Rosa María Gómez de la Fuente', 'relacion' => 'Vecina', 'telefono' => '7772223344', 'anios_conocerlo' => 8],
            ['nombre' => 'Pedro Ruiz', 'relacion' => 'Amigo', 'telefono' => '7773334455'],
            ['nombre' => 'Laura Martínez', 'relacion' => 'Comadre', 'telefono' => '7774445566', 'anios_conocerlo' => 15],
            ['nombre' => 'Jorge Salgado', 'relacion' => 'Exjefe', 'telefono' => '7775556677', 'anios_conocerlo' => 4],
        ]],
    ]);
    Sanctum::actingAs($this->rh);

    $this->postJson("/api/v1/rh/documentos-proceso/colaborador/{$persona->id}/generar", ['clave' => 'solicitud_empleo', 'proceso' => 'incorporacion'])->assertCreated();
    $this->postJson("/api/v1/rh/documentos-proceso/colaborador/{$persona->id}/generar", ['clave' => 'estudio_socioeconomico', 'proceso' => 'incorporacion'])->assertCreated();

    $solicitud = GeneratedDocument::query()->where('clave_plantilla', 'solicitud_empleo')->sole();
    $estudio = GeneratedDocument::query()->where('clave_plantilla', 'estudio_socioeconomico')->sole();
    seGuardarMuestra($solicitud, 'sol-largo');
    seGuardarMuestra($estudio, 'ese-largo');

    expect($estudio->payload['ese_dep_6_nombre'])->toBe('María Guadalupe 6')
        ->and($estudio->payload['ese_dep_1_parentesco'])->toBe('Cónyuge')
        ->and($solicitud->payload['sol_empleo_3_empresa'])->toBe('Financiera Independencia Sucursal Centro 3')
        ->and($solicitud->payload['sol_empleo_1_periodo'])->toBe('01/2018 – actual')
        ->and($solicitud->payload['sol_conyuge'])->toBe('María Guadalupe Fernández de la Torre y Castañeda')
        ->and($estudio->payload['marca_inm_ejidal'])->toBe('X')
        ->and($estudio->payload['ese_egreso_otros_concepto'])->toBe('Colegiaturas')
        ->and($estudio->payload['ese_ref_4'])->toContain('Jorge Salgado');
});

test('sin dependientes ni experiencia: «No aplica» explícito impreso, no huecos silenciosos', function () {
    seImportarMasters();
    $persona = sePersona($this->estructura);
    seCompletar($persona, $this->rh, [
        'dependientes' => ['sin_dependientes' => true, 'dependientes' => []],
        'experiencia' => ['sin_experiencia_laboral' => true, 'empleos' => []],
    ]);
    Sanctum::actingAs($this->rh);

    $this->postJson("/api/v1/rh/documentos-proceso/colaborador/{$persona->id}/generar", ['clave' => 'solicitud_empleo', 'proceso' => 'incorporacion'])->assertCreated();
    $this->postJson("/api/v1/rh/documentos-proceso/colaborador/{$persona->id}/generar", ['clave' => 'estudio_socioeconomico', 'proceso' => 'incorporacion'])->assertCreated();

    $solicitud = GeneratedDocument::query()->where('clave_plantilla', 'solicitud_empleo')->sole();
    $estudio = GeneratedDocument::query()->where('clave_plantilla', 'estudio_socioeconomico')->sole();
    seGuardarMuestra($solicitud, 'sol-no-aplica');
    seGuardarMuestra($estudio, 'ese-no-aplica');

    expect($solicitud->payload['sol_dependientes'])->toBe('Sin dependientes económicos')
        ->and($solicitud->payload['sol_empleo_1_empresa'])->toBe('Sin experiencia laboral (primer empleo)')
        ->and($estudio->payload['ese_dep_1_nombre'])->toBe('Sin dependientes económicos');
});

// ───────────────────────── Regla: antes del contrato ─────────────────────────

test('el contrato de contratación no se genera sin Solicitud de Empleo y Estudio Socioeconómico generados', function () {
    seImportarMasters();
    $persona = clExpedienteCompleto(sePersona($this->estructura));
    $contrato = seContrato($persona);
    $requisito = app(RequisitoDocumentosIngresoService::class);
    Sanctum::actingAs($this->rh);

    expect($requisito->bloqueoContrato($contrato))->toStartWith('Antes del contrato se deben generar la Solicitud de Empleo y el Estudio Socioeconómico. Pendiente: Solicitud de Empleo: faltan ');

    // Generación automática del paquete: queda pendiente con el mensaje exacto.
    $resultado = app(ContratoLaboralService::class)->prepararFaltantes($contrato, $this->rh);
    expect($resultado['generados'])->toBe([])
        ->and(GeneratedDocument::query()->where('documentable_id', $contrato->id)->where('documentable_type', $contrato->getMorphClass())->count())->toBe(0);

    // Botón de la ficha / API: 422 con el mismo mensaje.
    $this->postJson("/api/v1/rh/documentos-proceso/contrato/{$contrato->id}/paquete", ['proceso' => 'alta'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.documento.0', $requisito->bloqueoContrato($contrato));

    seCompletar($persona, $this->rh);
    expect($requisito->bloqueoContrato($contrato))->toContain('Solicitud de Empleo: datos completos, falta generarla');

    app(DocumentoProcesoService::class)->generar('incorporacion', $persona, 'solicitud_empleo', $this->rh);
    app(DocumentoProcesoService::class)->generar('incorporacion', $persona, 'estudio_socioeconomico', $this->rh);

    expect($requisito->bloqueoContrato($contrato->refresh()))->toBeNull();
});

test('no es retroactiva: altas con contrato ya generado, renovaciones y colaboradores fuera de incorporación no se bloquean', function () {
    $requisito = app(RequisitoDocumentosIngresoService::class);

    // Alta antigua: el contrato ya tiene documentos.
    $antigua = sePersona($this->estructura);
    $contratoAntiguo = seContrato($antigua);
    GeneratedDocument::factory()->create(['colaborador_id' => $antigua->id, 'documentable_type' => $contratoAntiguo->getMorphClass(), 'documentable_id' => $contratoAntiguo->id, 'clave_plantilla' => 'contrato_capacitacion']);

    // Colaborador activo / importado (sin estado de alta en incorporación).
    $activo = sePersona($this->estructura, ['estado_alta' => null, 'estatus' => 'activo']);

    // Renovación.
    $renovado = sePersona($this->estructura);
    $inicial = seContrato($renovado);
    $renovacion = seContrato($renovado);
    $renovacion->update(['contrato_anterior_id' => $inicial->id]);

    expect($requisito->bloqueoContrato($contratoAntiguo))->toBeNull()
        ->and($requisito->bloqueoContrato(seContrato($activo)))->toBeNull()
        ->and($requisito->bloqueoContrato($renovacion->refresh()))->toBeNull();

    config(['incorporacion.documentos_ingreso.exigir_antes_de_contrato' => false]);
    expect($requisito->bloqueoContrato(seContrato(sePersona($this->estructura))))->toBeNull();
});

test('la generación de un documento incompleto desde el servicio lanza la excepción con la lista', function () {
    seImportarMasters();
    $persona = sePersona($this->estructura);

    try {
        app(DocumentoProcesoService::class)->generar('incorporacion', $persona, 'estudio_socioeconomico', $this->rh);
        $this->fail('Debió lanzar FaltanDatosIngresoException.');
    } catch (FaltanDatosIngresoException $e) {
        expect($e->documento)->toBe('Estudio Socioeconómico')
            ->and($e->etiquetas)->toContain('Tipo sanguíneo', 'Dependientes económicos (agrega al menos uno o marca «No tengo dependientes económicos»)')
            ->and($e->mensaje)->toStartWith('Faltan ');
    }
});

// ───────────────────────── Fecha de emisión (RH la elige) ─────────────────────────

test('fecha de emisión: sin elegir sale la de hoy; RH elige una por documento y se imprime en todas sus fechas', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00', 'America/Mexico_City'));
    seImportarMasters();
    // Cumple 31 el 9 de noviembre de 2026: la edad sale a la fecha del documento.
    $persona = sePersona($this->estructura, ['fecha_nacimiento' => '1995-11-09']);
    seCompletar($persona, $this->rh);
    $this->actingAs($this->rh);
    $procesos = app(DocumentoProcesoService::class);

    // Default: hoy, sin fecha elegida.
    $emision = collect($this->getJson("/rh/colaboradores/{$persona->id}/solicitud-empleo")->assertOk()->json('data.emision'))->keyBy('clave');
    expect($emision['solicitud_empleo']['fecha'])->toBe('2026-11-10')
        ->and($emision['solicitud_empleo']['elegida'])->toBeFalse()
        ->and($emision['estudio_socioeconomico']['minimo'])->toBe('2025-11-10')
        ->and($emision['estudio_socioeconomico']['maximo'])->toBe('2026-12-10');

    $hoy = $procesos->camposDelDocumento('incorporacion', $persona, 'solicitud_empleo', $this->rh);
    expect([$hoy['sol_fecha_dia'], $hoy['sol_fecha_mes'], $hoy['sol_fecha_anio']])->toBe(['10', '11', '2026'])
        ->and($hoy['edad_numero'])->toBe('31');

    $bitacora = ColaboradorPerfilIngreso::query()->where('colaborador_id', $persona->id)->sole()->secciones;

    // Solo fechas (sin datos): se guardan sin tocar la bitácora de ninguna sección.
    $this->postJson("/rh/colaboradores/{$persona->id}/solicitud-empleo/personal", ['fechas_emision' => ['solicitud_empleo' => '2026-11-08', 'estudio_socioeconomico' => '2026-11-05']])
        ->assertOk()
        ->assertJsonPath('message', 'Fecha de emisión guardada.')
        ->assertJsonPath('data.emision.0.fecha', '2026-11-08')
        ->assertJsonPath('data.emision.0.elegida', true)
        ->assertJsonPath('data.emision.1.fecha', '2026-11-05');

    $perfil = ColaboradorPerfilIngreso::query()->where('colaborador_id', $persona->id)->sole();
    expect($perfil->fecha_emision_solicitud?->toDateString())->toBe('2026-11-08')
        ->and($perfil->fecha_emision_estudio?->toDateString())->toBe('2026-11-05')
        ->and($perfil->secciones)->toBe($bitacora);

    // La Solicitud: encabezado, «fecha_documento_larga» (hoja 4) y edad a esa fecha.
    $solicitud = $procesos->camposDelDocumento('incorporacion', $persona, 'solicitud_empleo', $this->rh);
    expect([$solicitud['sol_fecha_dia'], $solicitud['sol_fecha_mes'], $solicitud['sol_fecha_anio']])->toBe(['08', '11', '2026'])
        ->and($solicitud['fecha_documento_larga'])->toBe('8 de noviembre de 2026')
        ->and($solicitud['edad_numero'])->toBe('30');

    // El Estudio, con la suya (independiente).
    $estudio = $procesos->camposDelDocumento('incorporacion', $persona, 'estudio_socioeconomico', $this->rh);
    expect([$estudio['ese_fecha_dia'], $estudio['ese_fecha_mes'], $estudio['ese_fecha_anio']])->toBe(['05', 'noviembre', '2026'])
        ->and($estudio['edad_numero'])->toBe('30');

    // Generado: el snapshot conserva la fecha elegida; cambiarla después lo marca para regenerar.
    Sanctum::actingAs($this->rh);
    $this->postJson("/api/v1/rh/documentos-proceso/colaborador/{$persona->id}/generar", ['clave' => 'solicitud_empleo', 'proceso' => 'incorporacion'])->assertCreated();
    $generado = GeneratedDocument::query()->where('clave_plantilla', 'solicitud_empleo')->sole();
    seGuardarMuestra($generado, 'sol-fecha-emision');
    $this->postJson("/api/v1/rh/documentos-proceso/colaborador/{$persona->id}/generar", ['clave' => 'estudio_socioeconomico', 'proceso' => 'incorporacion'])->assertCreated();
    $generadoEstudio = GeneratedDocument::query()->where('clave_plantilla', 'estudio_socioeconomico')->sole();
    seGuardarMuestra($generadoEstudio, 'ese-fecha-emision');
    expect($generado->payload['sol_fecha_dia'])->toBe('08')
        ->and($generado->payload['fecha_documento_larga'])->toBe('8 de noviembre de 2026')
        ->and($generadoEstudio->payload['ese_fecha_dia'])->toBe('05')
        ->and($generadoEstudio->payload['ese_fecha_mes'])->toBe('noviembre');

    $this->postJson("/rh/colaboradores/{$persona->id}/solicitud-empleo/personal", ['fechas_emision' => ['solicitud_empleo' => '2026-11-09']])
        ->assertOk()
        ->assertJsonPath('data.emision.0.generado_con', '2026-11-08')
        ->assertJsonPath('data.emision.0.desactualizado', true)
        // Solo cambian las claves que llegan.
        ->assertJsonPath('data.emision.1.fecha', '2026-11-05');

    // null = volver a «la fecha del día en que se genere».
    $this->postJson("/rh/colaboradores/{$persona->id}/solicitud-empleo/personal", ['fechas_emision' => ['estudio_socioeconomico' => null]])
        ->assertOk()
        ->assertJsonPath('data.emision.1.fecha', '2026-11-10')
        ->assertJsonPath('data.emision.1.elegida', false);
});

test('fecha de emisión al generar: «Documentos» la pregunta, la elegida es la que se imprime y queda guardada; la vista previa no la guarda', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00', 'America/Mexico_City'));
    seImportarMasters();
    $persona = sePersona($this->estructura);
    seCompletar($persona, $this->rh);
    $this->actingAs($this->rh);
    $generar = "/rh/documentos-proceso/colaborador/{$persona->id}/generar";

    // La tarjeta del documento trae la fecha por defecto y el rango del calendario.
    $seccion = collect($this->getJson("/rh/documentos-proceso/colaborador/{$persona->id}")->assertOk()->json('data'))->firstWhere('proceso', 'incorporacion');
    $items = collect($seccion['documentos'] ?? [])->keyBy('clave');
    expect($items['solicitud_empleo']['emision'])->toMatchArray(['fecha' => '2026-11-10', 'elegida' => false, 'minimo' => '2025-11-10', 'maximo' => '2026-12-10']);

    // Vista previa con una fecha: se ve, pero no se guarda.
    $this->get("/rh/documentos-proceso/colaborador/{$persona->id}/vista-previa?clave=solicitud_empleo&proceso=incorporacion&fecha_emision=2026-11-03")
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(ColaboradorPerfilIngreso::query()->where('colaborador_id', $persona->id)->sole()->fecha_emision_solicitud)->toBeNull();

    // Fuera de rango: no genera nada.
    $this->postJson($generar, ['clave' => 'solicitud_empleo', 'proceso' => 'incorporacion', 'fecha_emision' => '2024-01-01'])->assertUnprocessable();
    $this->get("/rh/documentos-proceso/colaborador/{$persona->id}/vista-previa?clave=solicitud_empleo&proceso=incorporacion&fecha_emision=2024-01-01", ['Accept' => 'application/json'])->assertUnprocessable();
    expect(GeneratedDocument::query()->where('clave_plantilla', 'solicitud_empleo')->exists())->toBeFalse();

    // Generar con la fecha elegida: es la que sale en el PDF y queda guardada.
    $this->postJson($generar, ['clave' => 'solicitud_empleo', 'proceso' => 'incorporacion', 'fecha_emision' => '2026-11-04'])
        ->assertCreated();
    $generado = GeneratedDocument::query()->where('clave_plantilla', 'solicitud_empleo')->sole();
    expect([$generado->payload['sol_fecha_dia'], $generado->payload['sol_fecha_mes'], $generado->payload['sol_fecha_anio']])->toBe(['04', '11', '2026'])
        ->and($generado->payload['fecha_documento_larga'])->toBe('4 de noviembre de 2026')
        ->and(ColaboradorPerfilIngreso::query()->where('colaborador_id', $persona->id)->sole()->fecha_emision_solicitud?->toDateString())->toBe('2026-11-04')
        // El Estudio es independiente: sigue sin fecha elegida.
        ->and(ColaboradorPerfilIngreso::query()->where('colaborador_id', $persona->id)->sole()->fecha_emision_estudio)->toBeNull();

    // Regenerar con otra fecha: nueva versión con esa fecha.
    $this->postJson($generar, ['clave' => 'solicitud_empleo', 'proceso' => 'incorporacion', 'regenerar' => true, 'motivo' => 'Se firmó otro día', 'fecha_emision' => '2026-11-06'])
        ->assertCreated()->assertJsonPath('creado', true);
    $vigente = app(DocumentoProcesoService::class)->documentoVigente($persona, 'solicitud_empleo');
    expect($vigente?->payload['sol_fecha_dia'])->toBe('06');

    // El Estudio socioeconómico pide su fecha igual, con su propio calendario.
    expect($items['estudio_socioeconomico']['emision'])->toMatchArray(['fecha' => '2026-11-10', 'elegida' => false, 'minimo' => '2025-11-10', 'maximo' => '2026-12-10']);
    $this->postJson($generar, ['clave' => 'estudio_socioeconomico', 'proceso' => 'incorporacion', 'fecha_emision' => '2026-11-02'])->assertCreated();
    $estudio = GeneratedDocument::query()->where('clave_plantilla', 'estudio_socioeconomico')->sole();
    expect([$estudio->payload['ese_fecha_dia'], $estudio->payload['ese_fecha_mes'], $estudio->payload['ese_fecha_anio']])->toBe(['02', 'noviembre', '2026'])
        ->and(ColaboradorPerfilIngreso::query()->where('colaborador_id', $persona->id)->sole()->fecha_emision_estudio?->toDateString())->toBe('2026-11-02');
});

test('fecha de emisión: validación en español (máx. un año atrás, hasta 30 días adelante o la fecha de ingreso) y aviso tras el ingreso', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00', 'America/Mexico_City'));
    $persona = sePersona($this->estructura, ['fecha_ingreso' => '2026-11-12']);
    $this->actingAs($this->rh);
    $url = "/rh/colaboradores/{$persona->id}/solicitud-empleo/personal";

    $this->postJson($url, ['fechas_emision' => ['solicitud_empleo' => '2025-11-09']])
        ->assertUnprocessable()
        ->assertJsonPath('errors', ['fechas_emision.solicitud_empleo' => ['La fecha de emisión de Solicitud de Empleo no puede ser anterior al 10 de noviembre de 2025 (máximo un año atrás).']]);

    $this->postJson($url, ['fechas_emision' => ['estudio_socioeconomico' => '2026-12-11']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['fechas_emision.estudio_socioeconomico' => 'La fecha de emisión de Estudio Socioeconómico no puede ser posterior al 10 de diciembre de 2026.']);

    $this->postJson($url, ['fechas_emision' => ['solicitud_empleo' => '10/11/2026']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['fechas_emision.solicitud_empleo' => 'Elige una fecha de emisión válida (AAAA-MM-DD).']);

    $this->postJson($url, ['fechas_emision' => ['contrato' => '2026-11-10']])->assertUnprocessable()->assertJsonValidationErrors('fechas_emision');

    // Una inválida: no se guarda ninguna.
    $this->postJson($url, ['fechas_emision' => ['solicitud_empleo' => '2026-11-09', 'estudio_socioeconomico' => '2027-05-01']])->assertUnprocessable();
    expect(ColaboradorPerfilIngreso::query()->where('colaborador_id', $persona->id)->value('fecha_emision_solicitud'))->toBeNull();

    // Posterior al ingreso: se permite, con aviso.
    $this->postJson($url, ['fechas_emision' => ['solicitud_empleo' => '2026-11-13']])
        ->assertOk()
        ->assertJsonPath('data.emision.0.fecha_ingreso', '2026-11-12')
        ->assertJsonPath('data.emision.0.aviso', 'La Solicitud de Empleo quedaría fechada el 13 de noviembre de 2026, después de la fecha de ingreso (12 de noviembre de 2026). Normalmente se llena antes de contratar.')
        ->assertJsonPath('data.emision.1.aviso', null);

    // Ingreso lejano: el máximo se extiende hasta esa fecha.
    $persona->update(['fecha_ingreso' => '2027-01-15']);
    $this->postJson($url, ['fechas_emision' => ['estudio_socioeconomico' => '2027-01-15']])
        ->assertOk()
        ->assertJsonPath('data.emision.1.maximo', '2027-01-15');
});

test('fecha de emisión: la API v1 de RH es compatible (campo opcional) y aplica las mismas reglas', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00', 'America/Mexico_City'));
    $persona = sePersona($this->estructura);
    Sanctum::actingAs($this->rh);
    $url = "/api/v1/rh/colaboradores/{$persona->id}/solicitud-empleo/personal";

    // Cliente anterior: solo datos de la sección, igual que siempre.
    $this->postJson($url, ['datos' => ['estado_salud' => 'bueno']])
        ->assertOk()
        ->assertJsonPath('message', 'Datos guardados.')
        ->assertJsonPath('data.emision.0.elegida', false);

    // Sin `datos` ni fechas sigue siendo 422 (contrato anterior).
    $this->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors('datos');

    // Datos y fecha en la misma petición.
    $this->postJson($url, ['datos' => ['numero_hijos' => 1], 'fechas_emision' => ['estudio_socioeconomico' => '2026-11-09']])
        ->assertOk()
        ->assertJsonPath('message', 'Datos guardados.')
        ->assertJsonPath('data.emision.1.fecha', '2026-11-09');

    $this->postJson($url, ['fechas_emision' => ['estudio_socioeconomico' => '2024-01-01']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('fechas_emision.estudio_socioeconomico');
});
