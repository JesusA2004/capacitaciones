<?php

use App\Enums\CategoriaDocumento;
use App\Enums\EstadoUsuario;
use App\Enums\MotorPdf;
use App\Enums\TipoModuloOnboarding;
use App\Models\Colaborador;
use App\Models\ContratoLaboral;
use App\Models\EvaluacionInduccion;
use App\Models\EvaluacionInduccionIntento;
use App\Models\GeneratedDocument;
use App\Models\OnboardingModulo;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\DocumentosLaborales\DocumentoLaboralConsultaService;
use App\Services\Onboarding\EvaluacionInduccionPdfService;
use App\Services\Onboarding\EvaluacionInduccionService;
use App\Services\Onboarding\OnboardingService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/*
| PDF del formato FO-RH-EI (EvaluacionInduccionPdfService): mismo contenido
| que el cuestionario oficial con las respuestas del colaborador, sin
| metadata técnica; vista previa sin guardar; guardado idempotente en el
| expediente; auto-adjunto al aprobar; el colaborador nunca lo ve (trae la
| clave de respuestas) y RH sin alcance tampoco.
*/

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-01 09:00:00', 'America/Mexico_City'));
    $this->seed(RolesYPermisosSeeder::class);
    Notification::fake();
    Storage::fake('nas');

    $this->estructura = clEstructura();
    $this->rh = clUsuario('rh_admin', ['name' => 'Rocío', 'apellidos' => 'Hernández Paz']);

    OnboardingModulo::query()->create([
        'clave' => 'induccion_mr_lana_2026',
        'titulo' => 'Inducción Mr. Lana',
        'tipo' => TipoModuloOnboarding::Institucional,
        'orden' => 1,
        'evaluacion_formal_clave' => 'induccion_mr_lana_2026',
        'obligatorio' => true,
        'activo' => true,
    ]);

    $this->colaborador = Colaborador::factory()->create([
        'name' => 'Adriana',
        'apellidos' => 'Cortés Lima',
        'sucursal_principal_id' => $this->estructura['sucursal']->id,
        'puesto_id' => $this->estructura['puesto']->id,
        'estatus' => EstadoUsuario::EnIncorporacion,
        'fecha_ingreso' => now()->toDateString(),
    ]);
    $this->usuario = User::factory()->create(['colaborador_id' => $this->colaborador->id]);
    $this->usuario->assignRole('colaborador');

    $contrato = ContratoLaboral::factory()->create(['colaborador_id' => $this->colaborador->id]);

    $this->onboarding = app(OnboardingService::class);
    $this->evaluaciones = app(EvaluacionInduccionService::class);
    $this->pdf = app(EvaluacionInduccionPdfService::class);
    $this->proceso = $this->onboarding->iniciar($this->colaborador, $contrato, $this->rh);
});

afterEach(fn () => Carbon::setTestNow());

function eipdfHabilitar(object $t): EvaluacionInduccion
{
    $avance = $t->proceso->avances()->firstOrFail();
    $t->onboarding->marcarContenidoVisto($avance, $t->usuario);

    return $t->evaluaciones->habilitarPara($t->proceso->refresh());
}

/** Respuestas correctas contra config/induccion_evaluacion.php; $fallarA = índices de A contestados mal. */
function eipdfRespuestas(array $fallarA = []): array
{
    $a = [];
    foreach (config('induccion_evaluacion.seccion_a') as $i => $p) {
        $a[$i] = in_array($i, $fallarA, true) ? ($p['correcta'] + 1) % count($p['opciones']) : $p['correcta'];
    }
    $b = [];
    foreach (config('induccion_evaluacion.seccion_b') as $i => $p) {
        $b[$i] = $p['correcto'];
    }
    $c = [];
    foreach (config('induccion_evaluacion.seccion_c.conceptos') as $i => $concepto) {
        $c[$i] = $concepto['letra_correcta'];
    }
    $d = [];
    foreach (config('induccion_evaluacion.seccion_d') as $caso) {
        $d[$caso['clave']] = 'Respuesta escrita del colaborador para '.$caso['clave'].'.';
    }

    return ['a' => $a, 'b' => $b, 'c' => $c, 'd' => $d];
}

function eipdfPuntosD(): array
{
    $puntos = [];
    foreach (config('induccion_evaluacion.seccion_d') as $caso) {
        foreach ($caso['rubrica'] as $criterio) {
            $puntos[$caso['clave']][$criterio['clave']] = true;
        }
    }

    return $puntos;
}

/** Intento presentado (y calificado si $calificar) — 29/30 aprobado por omisión. */
function eipdfIntento(object $t, array $fallarA = [0], bool $calificar = true, array $puntosD = []): EvaluacionInduccionIntento
{
    $evaluacion = eipdfHabilitar($t);
    $intento = $t->evaluaciones->presentar($evaluacion, $t->usuario, eipdfRespuestas($fallarA), [
        'claridad' => 5, 'conocimiento_puesto' => 4, 'bienvenida' => 5, 'tema_reforzar' => 'Código de vestimenta',
    ]);

    if ($calificar) {
        $t->actingAs($t->rh);
        $t->evaluaciones->calificarCasosPracticos($intento, $t->rh, $puntosD === [] ? eipdfPuntosD() : $puntosD, 'Buen dominio de los principios.');
    }

    return $intento->refresh();
}

function eipdfDocumentos(EvaluacionInduccionIntento $intento)
{
    return GeneratedDocument::query()
        ->where('documentable_type', $intento->getMorphClass())
        ->where('documentable_id', $intento->id);
}

test('el PDF trae el formato oficial con las respuestas del colaborador, sin metadata técnica', function () {
    $intento = eipdfIntento($this);
    $html = $this->pdf->html($intento, MotorPdf::DomPdf);
    $texto = html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');

    foreach (config('induccion_evaluacion.seccion_a') as $p) {
        expect($texto)->toContain($p['pregunta']);
    }
    foreach (config('induccion_evaluacion.seccion_b') as $p) {
        expect($texto)->toContain($p['afirmacion']);
    }
    foreach (config('induccion_evaluacion.seccion_c.descripciones') as $descripcion) {
        expect($texto)->toContain($descripcion);
    }
    foreach (config('induccion_evaluacion.seccion_d') as $caso) {
        expect($texto)->toContain($caso['enunciado'])
            ->toContain('Respuesta escrita del colaborador para '.$caso['clave'].'.');
        foreach ($caso['rubrica'] as $criterio) {
            expect($texto)->toContain($criterio['criterio']);
        }
    }

    expect($texto)
        ->toContain('EVALUACIÓN DEL PROGRAMA DE INDUCCIÓN')
        ->toContain('FO-RH-EI')
        ->toContain('Adriana Cortés Lima')
        ->toContain($this->estructura['puesto']->nombre)
        ->toContain($this->estructura['sucursal']->nombre)
        // «Aplicó (RH)» = el área; el nombre de la cuenta que calificó nunca
        // se imprime (se escribe y firma a pluma).
        ->toMatch('/Aplicó \(RH\)\s*Recursos Humanos/u')
        ->not->toContain('Rocío Hernández Paz')
        ->toContain('Incorrecta') // la pregunta 1 se contestó mal
        ->toContain('Correcta')
        ->toContain('Buen dominio de los principios.')
        ->toContain('Código de vestimenta')
        ->toContain('Tu opinión sobre la inducción')
        ->toContain('Aprobado (24 a 30 puntos)')
        ->toContain('Jefe(a) inmediato(a)')
        ->toContain('29')
        ->not->toContain('Generado por')
        ->not->toContain('Generado con');

    // PDF real (DomPDF en pruebas, el mismo factory que las actas).
    expect(substr($this->pdf->renderizar($intento), 0, 4))->toBe('%PDF');
});

test('la vista previa y la descarga entregan el PDF sin crear ningún documento', function () {
    $intento = eipdfIntento($this, calificar: false);

    $this->actingAs($this->rh)->get(route('rh.onboarding.induccion.pdf.vista', $intento))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
    $this->actingAs($this->rh)->get(route('rh.onboarding.induccion.pdf.descargar', $intento))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="Evaluacion de induccion - Adriana Cortés Lima - intento 1.pdf"');

    expect(GeneratedDocument::query()->count())->toBe(0);
});

test('guardar en el expediente es idempotente y exige el intento calificado', function () {
    // Sin calificar: no hay versión final.
    $pendiente = eipdfIntento($this, fallarA: range(0, 11), calificar: false);
    $this->actingAs($this->rh)->post(route('rh.onboarding.induccion.pdf.expediente', $pendiente))
        ->assertRedirect()
        ->assertSessionHas('toast', fn (array $t) => $t['type'] === 'error');
    expect(eipdfDocumentos($pendiente)->count())->toBe(0);

    // Calificado como «requiere reforzamiento» (no se auto-adjunta): RH lo guarda dos veces.
    $this->actingAs($this->rh);
    $this->evaluaciones->calificarCasosPracticos($pendiente, $this->rh, []);
    $pendiente->refresh();
    expect($pendiente->aprobado)->toBeFalse()
        ->and(eipdfDocumentos($pendiente)->count())->toBe(0);

    $this->actingAs($this->rh)->post(route('rh.onboarding.induccion.pdf.expediente', $pendiente))
        ->assertSessionHas('toast', fn (array $t) => $t['type'] === 'success');
    Sanctum::actingAs($this->rh);
    $this->postJson("/api/v1/rh/induccion/intentos/{$pendiente->id}/pdf/expediente")->assertOk();

    expect(eipdfDocumentos($pendiente)->count())->toBe(1);
    $documento = eipdfDocumentos($pendiente)->firstOrFail();
    expect($documento->categoria)->toBe(CategoriaDocumento::Otros)
        ->and($documento->clave_plantilla)->toBe(EvaluacionInduccionPdfService::CLAVE)
        ->and($documento->colaborador_id)->toBe($this->colaborador->id);
    Storage::disk('nas')->assertExists($documento->path);
});

test('al aprobar la evaluación el PDF se adjunta solo al expediente', function () {
    $intento = eipdfIntento($this);

    expect($intento->aprobado)->toBeTrue()
        ->and(eipdfDocumentos($intento)->count())->toBe(1);

    // Una segunda petición manual no lo duplica.
    $this->pdf->guardarEnExpediente($intento, $this->rh);
    expect(eipdfDocumentos($intento)->count())->toBe(1);
});

test('el colaborador no puede ver ni descargar su PDF ni el de otro, ni la copia del expediente', function () {
    $intento = eipdfIntento($this);
    $otro = clUsuario('colaborador', ['sucursal_principal_id' => $this->estructura['sucursal']->id]);

    foreach ([$this->usuario, $otro] as $usuario) {
        $this->actingAs($usuario)->get(route('rh.onboarding.induccion.pdf.vista', $intento))->assertForbidden();
        $this->actingAs($usuario)->get(route('rh.onboarding.induccion.pdf.descargar', $intento))->assertForbidden();
        $this->actingAs($usuario)->post(route('rh.onboarding.induccion.pdf.expediente', $intento))->assertForbidden();

        Sanctum::actingAs($usuario);
        $this->getJson("/api/v1/rh/induccion/intentos/{$intento->id}/pdf")->assertForbidden();
        $this->getJson("/api/v1/rh/induccion/intentos/{$intento->id}/pdf/descargar")->assertForbidden();
    }

    // La copia en el expediente tampoco llega a su autoservicio.
    $documento = eipdfDocumentos($intento)->firstOrFail();
    expect($this->usuario->can('ver', $documento))->toBeFalse()
        ->and($this->usuario->can('descargar', $documento))->toBeFalse()
        ->and(collect(app(DocumentoLaboralConsultaService::class)->delColaborador($this->colaborador)->items())->pluck('id'))->not->toContain($documento->id)
        ->and($this->rh->can('ver', $documento))->toBeTrue();
});

test('RH sin alcance sobre el colaborador recibe 403', function () {
    $intento = eipdfIntento($this, calificar: false);
    $otraSucursal = Sucursal::factory()->create(['empresa_id' => $this->estructura['empresa']->id]);
    $gerenteAjeno = clUsuario('gerente_sucursal', ['sucursal_principal_id' => $otraSucursal->id]);
    $gerenteAjeno->givePermissionTo(OnboardingService::PERMISO_GESTIONAR);

    $this->actingAs($gerenteAjeno)->get(route('rh.onboarding.induccion.pdf.vista', $intento))->assertForbidden();
    $this->actingAs($gerenteAjeno)->get(route('rh.onboarding.induccion.pdf.descargar', $intento))->assertForbidden();
    $this->actingAs($gerenteAjeno)->post(route('rh.onboarding.induccion.pdf.expediente', $intento))->assertForbidden();

    Sanctum::actingAs($gerenteAjeno);
    $this->getJson("/api/v1/rh/induccion/intentos/{$intento->id}/pdf")->assertForbidden();
    $this->postJson("/api/v1/rh/induccion/intentos/{$intento->id}/pdf/expediente")->assertForbidden();

    expect(GeneratedDocument::query()->count())->toBe(0);
});
