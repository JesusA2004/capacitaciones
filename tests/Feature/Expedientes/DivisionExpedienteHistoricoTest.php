<?php

use App\Enums\EstadoDatoDetectado;
use App\Enums\EstadoDivisionHistorico;
use App\Enums\EstadoDocumento;
use App\Enums\EstadoParteDivision;
use App\Models\Colaborador;
use App\Models\DivisionExpedienteHistorico;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\ExpedienteHistorico;
use App\Models\ParteDivisionHistorico;
use App\Models\User;
use App\Services\Expedientes\DivisionHistorico\DivisionExpedienteHistoricoService;
use App\Services\Expedientes\DivisionHistorico\Ocr\MotorOcr;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;

/**
 * División del PDF histórico por checklist (docs/DIVISION_EXPEDIENTE_HISTORICO.md),
 * con un PDF de varias páginas generado aquí (FPDF) y un OCR falso.
 */
class OcrFalsoDivision implements MotorOcr
{
    /** @param array<int, string> $textos página => texto reconocido */
    public function __construct(private array $textos = [], private bool $disponible = true) {}

    public function disponible(): bool
    {
        return $this->disponible;
    }

    public function nombre(): string
    {
        return $this->disponible ? 'OCR de prueba' : 'Sin OCR';
    }

    public function motivoNoDisponible(): ?string
    {
        return $this->disponible ? null : 'Tesseract no está instalado en el servidor.';
    }

    public function textoDePagina(string $rutaPdf, int $pagina): ?string
    {
        return $this->textos[$pagina] ?? null;
    }
}

/**
 * 1-2 solicitud de empleo (la 2 es continuación), 3 INE, 4 escaneada (sin
 * texto), 5 constancia de situación fiscal, 6 hoja sin nada reconocible.
 */
function pdfExpedienteDePrueba(): string
{
    $paginas = [
        "SOLICITUD DE EMPLEO\nPuesto solicitado: Gestor\nNombre: Juan Perez Lopez\nCURP: PELJ900101HDFRPN09\nTelefono: 5512345678",
        "Datos adicionales del solicitante\nHorario disponible: matutino\nFirma del solicitante",
        "INSTITUTO NACIONAL ELECTORAL\nCREDENCIAL PARA VOTAR\nJUAN PEREZ LOPEZ\nCURP PELJ900101HDFRPN09\nFECHA DE NACIMIENTO 01/01/1990",
        null,
        "CONSTANCIA DE SITUACION FISCAL\nSERVICIO DE ADMINISTRACION TRIBUTARIA\nRFC: PELJ900101AB1\nCURP: PELJ900101HDFRPN09",
        'Lorem ipsum dolor sit amet consectetur adipiscing elit sed do eiusmod',
    ];

    $pdf = new Fpdi;
    $pdf->SetAutoPageBreak(false);

    foreach ($paginas as $texto) {
        $pdf->AddPage();

        if ($texto !== null) {
            $pdf->SetFont('Helvetica', '', 12);
            $pdf->SetXY(15, 20);
            $pdf->MultiCell(0, 8, $texto);
        }
    }

    return $pdf->Output('S');
}

function historicoDePrueba(Colaborador $colaborador, ?string $bytes = null): ExpedienteHistorico
{
    $bytes ??= pdfExpedienteDePrueba();
    $ruta = sprintf('expedientes/Mr. Lana/Cuernavaca/%d/Historico/Expediente historico unificado.pdf', $colaborador->id);
    Storage::disk('nas')->put($ruta, $bytes);

    return ExpedienteHistorico::query()->create([
        'colaborador_id' => $colaborador->id,
        'estado' => ExpedienteHistorico::VINCULADO,
        'disk' => 'nas',
        'path' => $ruta,
        'original_name' => 'expediente.pdf',
        'stored_name' => 'Expediente historico unificado.pdf',
        'mime' => 'application/pdf',
        'extension' => 'pdf',
        'size' => strlen($bytes),
        'hash' => hash('sha256', $bytes),
        'source_path' => 'origen/expediente.pdf',
    ]);
}

function paginasDe(string $pdf): int
{
    return (new Fpdi)->setSourceFile(StreamReader::createByString($pdf));
}

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $this->seed(DocumentTypeSeeder::class);
    Storage::fake('nas');
    Queue::fake();
    config(['expedientes.disk' => 'nas']);

    $this->rh = User::factory()->create();
    $this->rh->assignRole('rh_admin');

    $this->colaborador = Colaborador::factory()->create([
        'name' => 'Juan',
        'apellidos' => 'Perez Lopez',
        'curp' => null,
        'rfc' => null,
        'fecha_nacimiento' => null,
        'expediente_storage_path' => 'expedientes/Mr. Lana/Cuernavaca/EMP-0001 - Juan Perez Lopez',
    ]);
});

test('divide el PDF por checklist: copias por documento, en revisión, y el original intacto', function () {
    app()->instance(MotorOcr::class, new OcrFalsoDivision([4 => "CURP\nCLAVE UNICA DE REGISTRO DE POBLACION\nPELJ900101HDFRPN09"]));
    $historico = historicoDePrueba($this->colaborador);
    $hashOriginal = hash('sha256', (string) Storage::disk('nas')->get($historico->path));

    $division = app(DivisionExpedienteHistoricoService::class)->dividir($historico, $this->rh);

    expect($division->estado)->toBe(EstadoDivisionHistorico::Completada)
        ->and($division->total_paginas)->toBe(6)
        ->and($division->paginas_sin_clasificar)->toBe(1);

    $partes = $division->partes()->with('tipo')->get();
    $porTipo = $partes->keyBy(fn (ParteDivisionHistorico $p) => $p->tipo?->clave ?? 'sin');

    expect($porTipo['solicitud_empleo']->paginas)->toBe([1, 2])
        ->and($porTipo['ine']->paginas)->toBe([3])
        ->and($porTipo['curp']->paginas)->toBe([4])
        ->and($porTipo['rfc']->paginas)->toBe([5])
        ->and($porTipo['sin']->paginas)->toBe([6])
        ->and($porTipo['sin']->estado)->toBe(EstadoParteDivision::SinClasificar);

    // Página 2 = continuación de la solicitud (confianza baja).
    expect($division->paginas[1]['tipo'])->toBe('solicitud_empleo')
        ->and($division->paginas[1]['confianza'])->toBe('baja')
        ->and($division->paginas[3]['metodo'])->toBe('ocr');

    // Cada parte es un PDF real con sus páginas; las clasificadas entran al checklist EN REVISIÓN.
    foreach ($partes as $parte) {
        $bytes = (string) Storage::disk('nas')->get((string) $parte->path);
        expect(paginasDe($bytes))->toBe(count($parte->paginas));
    }

    $ine = EmployeeDocument::query()->findOrFail($porTipo['ine']->employee_document_id);
    expect($ine->status)->toBe(EstadoDocumento::EnRevision)
        ->and($ine->origen)->toBe(DivisionExpedienteHistoricoService::ORIGEN)
        ->and(paginasDe((string) Storage::disk('nas')->get($ine->path)))->toBe(1);

    // El original nunca se toca.
    expect(Storage::disk('nas')->exists($historico->path))->toBeTrue()
        ->and(hash('sha256', (string) Storage::disk('nas')->get($historico->path)))->toBe($hashOriginal);

    // Las páginas sin clasificar se reportan explícitamente.
    expect(collect($division->advertencias)->contains(fn (string $a) => str_contains($a, 'sin clasificar') && str_contains($a, '6')))->toBeTrue();
});

test('sin OCR la página escaneada se reporta como «sin OCR disponible» y no se adivina', function () {
    app()->instance(MotorOcr::class, new OcrFalsoDivision([], false));
    $historico = historicoDePrueba($this->colaborador);

    $division = app(DivisionExpedienteHistoricoService::class)->dividir($historico, $this->rh);

    expect($division->estado)->toBe(EstadoDivisionHistorico::Completada)
        ->and($division->ocr_disponible)->toBeFalse()
        ->and($division->paginas_sin_texto)->toBe(1)
        ->and($division->paginas[3]['tipo'])->toBeNull()
        ->and($division->paginas[3]['motivo'])->toBe('Página escaneada sin OCR disponible.')
        ->and(collect($division->advertencias)->contains(fn (string $a) => str_contains($a, 'sin OCR disponible')))->toBeTrue();
});

test('un tipo ya cubierto no se reemplaza: la parte se conserva para RH', function () {
    app()->instance(MotorOcr::class, new OcrFalsoDivision);
    $ine = DocumentType::query()->where('clave', 'ine')->firstOrFail();
    $aprobado = EmployeeDocument::factory()->create([
        'colaborador_id' => $this->colaborador->id,
        'document_type_id' => $ine->id,
        'status' => EstadoDocumento::Aprobado->value,
        'version' => 1,
    ]);

    $division = app(DivisionExpedienteHistoricoService::class)->dividir(historicoDePrueba($this->colaborador), $this->rh);
    $parteIne = $division->partes()->where('document_type_id', $ine->id)->firstOrFail();

    expect($parteIne->estado)->toBe(EstadoParteDivision::YaCubierta)
        ->and($parteIne->employee_document_id)->toBeNull()
        ->and($aprobado->fresh()->status)->toBe(EstadoDocumento::Aprobado);
});

test('completitud: tras dividir el checklist sigue incompleto hasta que RH aprueba y lista lo que falta', function () {
    app()->instance(MotorOcr::class, new OcrFalsoDivision);
    $servicio = app(DivisionExpedienteHistoricoService::class);
    $servicio->dividir(historicoDePrueba($this->colaborador), $this->rh);

    $checklist = $servicio->resumenChecklist($this->colaborador);

    expect($checklist['requeridos'])->toBe(14)
        ->and($checklist['entregados'])->toBe(3)
        ->and($checklist['aprobados'])->toBe(0)
        ->and($checklist['completo'])->toBeFalse()
        ->and($checklist['cubierto'])->toBeFalse()
        ->and($checklist['faltantes'])->toContain('Acta de nacimiento')
        ->and($checklist['faltantes'])->not->toContain('Identificación oficial');
});

test('datos faltantes: propone sin aplicar, RH confirma uno por uno', function () {
    app()->instance(MotorOcr::class, new OcrFalsoDivision);
    $servicio = app(DivisionExpedienteHistoricoService::class);
    $division = $servicio->dividir(historicoDePrueba($this->colaborador), $this->rh);

    $datos = collect($servicio->datosDe($division))->keyBy('campo');

    expect($datos['curp']['estado'])->toBe(EstadoDatoDetectado::Faltante->value)
        ->and($datos['curp']['valores'][0]['valor'])->toBe('PELJ900101HDFRPN09')
        ->and($datos['rfc']['valores'][0]['valor'])->toBe('PELJ900101AB1')
        ->and($datos['fecha_nacimiento']['valores'][0]['valor'])->toBe('01/01/1990');

    // Nunca se aplica solo.
    expect($this->colaborador->fresh()->curp)->toBeNull();

    $this->actingAs($this->rh)
        ->post(route('rh.expedientes.division-historico.datos.aplicar', $division), ['campo' => 'curp', 'valor' => 'PELJ900101HDFRPN09'])
        ->assertRedirect();

    expect($this->colaborador->fresh()->curp)->toBe('PELJ900101HDFRPN09')
        ->and($this->colaborador->fresh()->rfc)->toBeNull();

    $curp = collect($servicio->datosDe($division->fresh()))->firstWhere('campo', 'curp');
    expect($curp['estado'])->toBe(EstadoDatoDetectado::Aplicado->value);
});

test('solo se puede aplicar un valor detectado, nunca texto libre', function () {
    app()->instance(MotorOcr::class, new OcrFalsoDivision);
    $servicio = app(DivisionExpedienteHistoricoService::class);
    $division = $servicio->dividir(historicoDePrueba($this->colaborador), $this->rh);

    expect(fn () => $servicio->aplicarDato($division, 'curp', 'XXXX000000HDFXXX00', $this->rh))->toThrow(ValidationException::class);
    expect($this->colaborador->fresh()->curp)->toBeNull();
});

test('una discrepancia se reporta y no pisa el dato capturado', function () {
    app()->instance(MotorOcr::class, new OcrFalsoDivision);
    $this->colaborador->update(['rfc' => 'OTRO900101ZZ9']);

    $servicio = app(DivisionExpedienteHistoricoService::class);
    $division = $servicio->dividir(historicoDePrueba($this->colaborador), $this->rh);
    $rfc = collect($servicio->datosDe($division))->firstWhere('campo', 'rfc');

    expect($rfc['estado'])->toBe(EstadoDatoDetectado::Discrepancia->value)
        ->and($rfc['actual'])->toBe('OTRO900101ZZ9')
        ->and($this->colaborador->fresh()->rfc)->toBe('OTRO900101ZZ9');
});

test('RH asigna a mano una parte sin clasificar y queda en revisión', function () {
    app()->instance(MotorOcr::class, new OcrFalsoDivision);
    $division = app(DivisionExpedienteHistoricoService::class)->dividir(historicoDePrueba($this->colaborador), $this->rh);
    $parte = $division->partes()->where('estado', EstadoParteDivision::SinClasificar->value)->firstOrFail();
    $acta = DocumentType::query()->where('clave', 'acta_nacimiento')->firstOrFail();

    $this->actingAs($this->rh)
        ->post(route('rh.expedientes.division-historico.partes.asignar', $parte), ['document_type_id' => $acta->id])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $parte->refresh();
    expect($parte->estado)->toBe(EstadoParteDivision::AsignadaManual)
        ->and($parte->documento->document_type_id)->toBe($acta->id)
        ->and($parte->documento->status)->toBe(EstadoDocumento::EnRevision);
});

test('es idempotente: repetir sin --forzar regresa la misma división', function () {
    app()->instance(MotorOcr::class, new OcrFalsoDivision);
    $servicio = app(DivisionExpedienteHistoricoService::class);
    $historico = historicoDePrueba($this->colaborador);

    $primera = $servicio->dividir($historico, $this->rh);
    $segunda = $servicio->dividir($historico, $this->rh);

    expect($segunda->id)->toBe($primera->id)
        ->and(DivisionExpedienteHistorico::query()->count())->toBe(1)
        ->and(EmployeeDocument::query()->where('origen', DivisionExpedienteHistoricoService::ORIGEN)->count())->toBe(3);
});

test('el comando --simular no escribe nada', function () {
    app()->instance(MotorOcr::class, new OcrFalsoDivision);
    $historico = historicoDePrueba($this->colaborador);
    $archivosAntes = count(Storage::disk('nas')->allFiles());

    $this->artisan('expedientes:dividir-historico', ['--colaborador' => $this->colaborador->id, '--simular' => true])
        ->expectsOutputToContain('Identificación oficial')
        ->assertSuccessful();

    expect(DivisionExpedienteHistorico::query()->count())->toBe(0)
        ->and(EmployeeDocument::query()->count())->toBe(0)
        ->and(count(Storage::disk('nas')->allFiles()))->toBe($archivosAntes)
        ->and($historico->fresh())->not->toBeNull();
});

test('el comando divide de verdad con --usuario por defecto (super_admin)', function () {
    app()->instance(MotorOcr::class, new OcrFalsoDivision);
    User::factory()->create()->assignRole('super_admin');
    historicoDePrueba($this->colaborador);

    $this->artisan('expedientes:dividir-historico', ['--todos' => true])->assertSuccessful();

    expect(DivisionExpedienteHistorico::query()->where('estado', EstadoDivisionHistorico::Completada->value)->count())->toBe(1);
});

test('la pantalla requiere permiso y nunca muestra el propio expediente', function () {
    app()->instance(MotorOcr::class, new OcrFalsoDivision);
    historicoDePrueba($this->colaborador);

    $this->actingAs($this->rh)
        ->get(route('rh.expedientes.division-historico.show', $this->colaborador))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Rh/Expedientes/DivisionHistorico')->has('detalle.historicos', 1));

    $sinPermiso = User::factory()->create();
    $sinPermiso->assignRole('colaborador');
    $this->actingAs($sinPermiso)->get(route('rh.expedientes.division-historico.show', $this->colaborador))->assertForbidden();

    $this->rh->forceFill(['colaborador_id' => $this->colaborador->id])->save();
    $this->actingAs($this->rh)->get(route('rh.expedientes.division-historico.show', $this->colaborador))->assertForbidden();
});

test('un histórico pendiente de vincular nunca se divide', function () {
    app()->instance(MotorOcr::class, new OcrFalsoDivision);
    $historico = historicoDePrueba($this->colaborador);
    $historico->update(['estado' => ExpedienteHistorico::PENDIENTE, 'colaborador_id' => null]);

    expect(fn () => app(DivisionExpedienteHistoricoService::class)->dividir($historico->fresh(), $this->rh))
        ->toThrow(InvalidArgumentException::class);
});
