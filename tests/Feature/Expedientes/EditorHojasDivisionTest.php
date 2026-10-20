<?php

use App\Enums\AccionDocumentoReorganizado;
use App\Enums\EstadoDocumento;
use App\Enums\EstadoParteDivision;
use App\Models\Colaborador;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\ExpedienteHistorico;
use App\Models\ParteDivisionHistorico;
use App\Models\RevisionDivisionHistorico;
use App\Models\User;
use App\Services\Expedientes\DivisionHistorico\DivisionExpedienteHistoricoService;
use App\Services\Expedientes\DivisionHistorico\EditorPaginasDivisionService;
use App\Services\Expedientes\DivisionHistorico\Ocr\MotorOcr;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use setasign\Fpdi\Fpdi;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * Editor de hojas del PDF histórico (docs/DIVISION_EXPEDIENTE_HISTORICO.md,
 * «Corregir hojas a mano»), con un PDF sintético de 12 hojas:
 *
 *   1-2 solicitud de empleo · 3 INE · 4 constancia fiscal (RFC)
 *   5-7 escaneadas sin OCR (sin clasificar) · 8 inicio del contrato
 *   9-11 escaneadas (el resto del contrato, que el OCR no detecta)
 *   12 hoja sin nada reconocible
 *
 * Cada hoja lleva «Hoja N» (muy poco texto para clasificarse) para poder
 * comprobar el orden de las hojas en los PDFs generados.
 */
class OcrApagadoEditorHojas implements MotorOcr
{
    public function disponible(): bool
    {
        return false;
    }

    public function nombre(): string
    {
        return 'Sin OCR';
    }

    public function motivoNoDisponible(): ?string
    {
        return 'Tesseract no está instalado en el servidor.';
    }

    public function textoDePagina(string $rutaPdf, int $pagina): ?string
    {
        return null;
    }
}

function pdfDoceHojasEditor(): string
{
    $textos = [
        1 => "SOLICITUD DE EMPLEO\nPuesto solicitado: Gestor\nNombre: Ana Ruiz Soto",
        2 => "Datos adicionales del solicitante\nHorario disponible: matutino\nFirma del solicitante",
        3 => "INSTITUTO NACIONAL ELECTORAL\nCREDENCIAL PARA VOTAR\nANA RUIZ SOTO",
        4 => "CONSTANCIA DE SITUACION FISCAL\nSERVICIO DE ADMINISTRACION TRIBUTARIA\nRFC: RUSA900101AB1",
        8 => "CONTRATO INDIVIDUAL DE TRABAJO\nque celebran por una parte la empresa y por la otra Ana Ruiz Soto",
        12 => 'Lorem ipsum dolor sit amet consectetur adipiscing elit sed do eiusmod',
    ];

    $pdf = new Fpdi;
    $pdf->SetAutoPageBreak(false);

    for ($n = 1; $n <= 12; $n++) {
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', 'B', 28);
        $pdf->SetXY(15, 250);
        $pdf->Cell(0, 12, sprintf('Hoja %d', $n));

        if (isset($textos[$n])) {
            $pdf->SetFont('Helvetica', '', 12);
            $pdf->SetXY(15, 20);
            $pdf->MultiCell(0, 8, $textos[$n]);
        }
    }

    return $pdf->Output('S');
}

function historicoDoceHojas(Colaborador $colaborador): ExpedienteHistorico
{
    $bytes = pdfDoceHojasEditor();
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

function tipoEditor(string $clave): int
{
    return DocumentType::query()->where('clave', $clave)->firstOrFail()->id;
}

/** «Hoja N» de cada página de un PDF, en orden. */
function hojasDelPdf(string $bytes): array
{
    $hojas = [];

    foreach ((new PdfParser)->parseContent($bytes)->getPages() as $pagina) {
        preg_match('/Hoja\s*(\d+)/', $pagina->getText(), $m);
        $hojas[] = isset($m[1]) ? (int) $m[1] : null;
    }

    return $hojas;
}

/**
 * Plan tal como lo manda el editor. Por omisión, lo que dejó el OCR.
 *
 * @param  array<string, list<int>>  $porClave  clave => hojas (en orden)
 */
function planEditor(array $porClave, array $sinClasificar = []): array
{
    $documentos = [];

    foreach ($porClave as $clave => $paginas) {
        $documentos[] = ['document_type_id' => tipoEditor(preg_replace('/#\d+$/', '', $clave)), 'paginas' => $paginas];
    }

    foreach ($sinClasificar as $tramo) {
        $documentos[] = ['document_type_id' => null, 'paginas' => $tramo];
    }

    return $documentos;
}

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    $this->seed(DocumentTypeSeeder::class);
    Storage::fake('nas');
    Queue::fake();
    config(['expedientes.disk' => 'nas']);
    app()->instance(MotorOcr::class, new OcrApagadoEditorHojas);

    $this->rh = User::factory()->create();
    $this->rh->assignRole('rh_admin');

    $this->colaborador = Colaborador::factory()->create([
        'name' => 'Ana',
        'apellidos' => 'Ruiz Soto',
        'expediente_storage_path' => 'expedientes/Mr. Lana/Cuernavaca/EMP-0002 - Ana Ruiz Soto',
    ]);

    $this->historico = historicoDoceHojas($this->colaborador);
    $this->division = app(DivisionExpedienteHistoricoService::class)->dividir($this->historico, $this->rh);
    $this->editor = app(EditorPaginasDivisionService::class);
});

test('el OCR deja el bloque esperado: contrato solo en la hoja 8 y 5-7, 9-12 sin clasificar', function () {
    $plan = $this->editor->planVigente($this->division);

    expect($plan)->toBe([
        ['document_type_id' => tipoEditor('solicitud_empleo'), 'paginas' => [1, 2]],
        ['document_type_id' => tipoEditor('ine'), 'paginas' => [3]],
        ['document_type_id' => tipoEditor('rfc'), 'paginas' => [4]],
        ['document_type_id' => null, 'paginas' => [5, 6, 7]],
        ['document_type_id' => tipoEditor('contrato'), 'paginas' => [8]],
        ['document_type_id' => null, 'paginas' => [9, 10, 11, 12]],
    ]);
});

test('asignación POR HOJA: del bloque 5-7, la 5 es acta, la 6 es CURP y la 7 queda sin clasificar', function () {
    $documentos = planEditor(
        ['solicitud_empleo' => [1, 2], 'ine' => [3], 'rfc' => [4], 'acta_nacimiento' => [5], 'curp' => [6], 'contrato' => [8]],
        [[7], [9, 10, 11, 12]],
    );

    $this->actingAs($this->rh)
        ->post(route('rh.expedientes.division-historico.editor.confirmar', $this->division), ['documentos' => $documentos, 'revision_base' => 0])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $vigentes = $this->division->partesVigentes()->get()->keyBy(fn (ParteDivisionHistorico $p) => $p->document_type_id ?? 'sin-'.$p->paginas[0]);

    $acta = $vigentes[tipoEditor('acta_nacimiento')];
    $curp = $vigentes[tipoEditor('curp')];

    expect($acta->paginas)->toBe([5])
        ->and($acta->estado)->toBe(EstadoParteDivision::AsignadaManual)
        ->and($curp->paginas)->toBe([6])
        ->and($vigentes['sin-7']->paginas)->toBe([7])
        ->and($vigentes['sin-7']->estado)->toBe(EstadoParteDivision::SinClasificar);

    $docActa = EmployeeDocument::query()->findOrFail($acta->employee_document_id);
    expect($docActa->status)->toBe(EstadoDocumento::EnRevision)
        ->and(hojasDelPdf((string) Storage::disk('nas')->get($docActa->path)))->toBe([5])
        ->and(hojasDelPdf((string) Storage::disk('nas')->get((string) $curp->path)))->toBe([6]);

    // Auditoría hoja por hoja en la revisión.
    $revision = RevisionDivisionHistorico::query()->where('division_id', $this->division->id)->firstOrFail();
    expect($revision->version)->toBe(1)
        ->and($revision->hecha_por)->toBe($this->rh->id)
        // La 7 sigue sin clasificar: no cuenta como hoja movida.
        ->and(collect($revision->cambios)->pluck('pagina')->all())->toBe([5, 6])
        ->and($revision->cambios[0]['de'])->toBe('Sin clasificar (p. 5-7)')
        ->and($revision->cambios[0]['a'])->toBe('Acta de nacimiento (p. 5)');

    // Las hojas quedan marcadas como corregidas por RH.
    $paginas = collect($this->division->fresh()->paginas)->keyBy('pagina');
    expect($paginas[5]['manual'])->toBeTrue()
        ->and($paginas[5]['tipo'])->toBe('acta_nacimiento')
        ->and($paginas[5]['tipo_ocr'])->toBeNull()
        ->and($paginas[8]['manual'] ?? false)->toBeFalse()
        // El aviso de hojas sin clasificar se recalcula con lo que dejó RH.
        ->and($this->division->fresh()->advertencias)->toContain('Páginas sin clasificar tras la corrección 1 de RH: 7, 9-12.')
        ->and($this->division->fresh()->paginas_sin_clasificar)->toBe(5);
});

test('rango: las hojas 5-7 se asignan juntas a un solo documento de 3 hojas', function () {
    $documentos = planEditor(
        ['solicitud_empleo' => [1, 2], 'ine' => [3], 'rfc' => [4], 'comprobante_domicilio' => [5, 6, 7], 'contrato' => [8]],
        [[9, 10, 11, 12]],
    );

    $revision = $this->editor->confirmar($this->division, $documentos, 0, $this->rh);

    $parte = $this->division->partesVigentes()->where('document_type_id', tipoEditor('comprobante_domicilio'))->firstOrFail();
    expect($revision)->not->toBeNull()
        ->and($parte->paginas)->toBe([5, 6, 7])
        ->and(hojasDelPdf((string) Storage::disk('nas')->get($parte->documento->path)))->toBe([5, 6, 7]);
});

test('el plan debe cubrir cada hoja exactamente una vez', function () {
    $faltaLa12 = planEditor(['solicitud_empleo' => [1, 2], 'ine' => [3], 'rfc' => [4], 'contrato' => [8]], [[5, 6, 7], [9, 10, 11]]);
    $repetida = planEditor(['solicitud_empleo' => [1, 2], 'ine' => [3, 4], 'rfc' => [4], 'contrato' => [8]], [[5, 6, 7], [9, 10, 11, 12]]);

    $this->actingAs($this->rh)
        ->postJson(route('rh.expedientes.division-historico.editor.vista-previa', $this->division), ['documentos' => $faltaLa12])
        ->assertStatus(422)
        ->assertJsonPath('errors.documentos.0', 'Faltan las hojas 12: cada hoja debe quedar en un documento o «Sin clasificar».');

    expect(fn () => $this->editor->vistaPrevia($this->division, $repetida))->toThrow(ValidationException::class);
});

test('«continúa el documento anterior»: las hojas 9-11 se unen al contrato que empieza en la 8, en el MISMO documento', function () {
    $contratoAntes = EmployeeDocument::query()->where('document_type_id', tipoEditor('contrato'))->firstOrFail();

    $documentos = planEditor(
        ['solicitud_empleo' => [1, 2], 'ine' => [3], 'rfc' => [4], 'contrato' => [8, 9, 10, 11]],
        [[5, 6, 7], [12]],
    );

    $previa = $this->editor->vistaPrevia($this->division, $documentos);
    $contrato = collect($previa['documentos'])->firstWhere('document_type_id', tipoEditor('contrato'));

    expect($contrato['accion'])->toBe(AccionDocumentoReorganizado::NuevaVersion)
        ->and($contrato['total_paginas'])->toBe(4)
        ->and(collect($previa['documentos'])->where('document_type_id', tipoEditor('contrato'))->count())->toBe(1);

    $this->editor->confirmar($this->division, $documentos, 0, $this->rh);

    $vigentes = $this->division->partesVigentes()->where('document_type_id', tipoEditor('contrato'))->get();
    expect($vigentes)->toHaveCount(1)
        ->and($vigentes[0]->paginas)->toBe([8, 9, 10, 11]);

    // Nueva versión EN REVISIÓN con las 4 hojas; la anterior se archiva, no se borra.
    $nuevo = $vigentes[0]->documento;
    expect($nuevo->status)->toBe(EstadoDocumento::EnRevision)
        ->and($nuevo->previous_version_id)->toBe($contratoAntes->id)
        ->and(hojasDelPdf((string) Storage::disk('nas')->get($nuevo->path)))->toBe([8, 9, 10, 11])
        ->and($contratoAntes->fresh()->status)->toBe(EstadoDocumento::Archivado)
        ->and(Storage::disk('nas')->exists($contratoAntes->path))->toBeTrue();

    // La parte anterior (solo hoja 8) se conserva como evidencia.
    $anterior = ParteDivisionHistorico::query()->where('division_id', $this->division->id)->whereNotNull('reemplazada_por_revision_id')->where('document_type_id', tipoEditor('contrato'))->firstOrFail();
    expect($anterior->paginas)->toBe([8])
        ->and(Storage::disk('nas')->exists((string) $anterior->path))->toBeTrue();
});

test('reordenar dentro del documento: el PDF sale con las hojas en el orden indicado', function () {
    $documentos = planEditor(
        ['solicitud_empleo' => [1, 2], 'ine' => [3], 'rfc' => [4], 'contrato' => [8, 10, 9, 11]],
        [[5, 6, 7], [12]],
    );

    $this->editor->confirmar($this->division, $documentos, 0, $this->rh);
    $parte = $this->division->partesVigentes()->where('document_type_id', tipoEditor('contrato'))->firstOrFail();

    expect($parte->paginas)->toBe([8, 10, 9, 11])
        ->and(hojasDelPdf((string) Storage::disk('nas')->get((string) $parte->path)))->toBe([8, 10, 9, 11]);
});

test('partir un bloque: «nuevo documento desde esta hoja» separa la hoja 2 de la solicitud', function () {
    $documentos = planEditor(
        ['solicitud_empleo' => [1], 'cartas_recomendacion' => [2], 'ine' => [3], 'rfc' => [4], 'contrato' => [8]],
        [[5, 6, 7], [9, 10, 11, 12]],
    );

    $this->editor->confirmar($this->division, $documentos, 0, $this->rh);

    $solicitud = $this->division->partesVigentes()->where('document_type_id', tipoEditor('solicitud_empleo'))->firstOrFail();
    $carta = $this->division->partesVigentes()->where('document_type_id', tipoEditor('cartas_recomendacion'))->firstOrFail();

    expect($solicitud->paginas)->toBe([1])
        ->and(hojasDelPdf((string) Storage::disk('nas')->get($solicitud->documento->path)))->toBe([1])
        ->and($carta->paginas)->toBe([2])
        ->and($carta->documento->status)->toBe(EstadoDocumento::EnRevision);

    // INE, RFC y contrato no cambiaron: se reutilizan tal cual (sin versión nueva).
    expect(EmployeeDocument::query()->where('document_type_id', tipoEditor('ine'))->count())->toBe(1);
});

test('un tipo repetido se advierte y solo se carga el primero', function () {
    $documentos = planEditor(
        ['solicitud_empleo' => [1], 'solicitud_empleo#2' => [2], 'ine' => [3], 'rfc' => [4], 'contrato' => [8]],
        [[5, 6, 7], [9, 10, 11, 12]],
    );

    $previa = $this->editor->vistaPrevia($this->division, $documentos);
    $acciones = collect($previa['documentos'])->where('document_type_id', tipoEditor('solicitud_empleo'))->pluck('accion')->all();

    expect($acciones)->toBe([AccionDocumentoReorganizado::NuevaVersion, AccionDocumentoReorganizado::Duplicado])
        ->and(collect($previa['advertencias'])->pluck('mensaje')->contains(fn (string $m) => str_contains($m, 'más de un documento')))->toBeTrue();
});

test('vista previa: advierte hojas fuera, requeridos faltantes y NO escribe nada', function () {
    $archivosAntes = Storage::disk('nas')->allFiles();
    $documentosAntes = EmployeeDocument::query()->count();
    $partesAntes = ParteDivisionHistorico::query()->count();
    $paginasAntes = $this->division->fresh()->paginas;

    $documentos = planEditor(
        ['solicitud_empleo' => [1, 2], 'ine' => [3], 'rfc' => [4], 'contrato' => [8, 9, 10, 11]],
        [[5, 6, 7], [12]],
    );

    $respuesta = $this->actingAs($this->rh)
        ->postJson(route('rh.expedientes.division-historico.editor.vista-previa', $this->division), ['documentos' => $documentos])
        ->assertOk()
        ->assertJsonPath('sin_cambios', false)
        ->json();

    $mensajes = collect($respuesta['advertencias'])->pluck('mensaje');
    expect($mensajes->contains(fn (string $m) => str_contains($m, 'Las hojas 5-7, 12 quedan sin clasificar')))->toBeTrue()
        ->and($mensajes->contains(fn (string $m) => str_contains($m, 'Siguen faltando') && str_contains($m, 'Acta de nacimiento')))->toBeTrue()
        ->and($respuesta['documentos'][4]['accion_etiqueta'])->toBe(AccionDocumentoReorganizado::NuevaVersion->etiqueta())
        ->and(collect($respuesta['cambios'])->pluck('pagina')->all())->toBe([9, 10, 11]);

    // PDF de vista previa de un documento resultante: en memoria.
    $pdf = $this->actingAs($this->rh)->get($respuesta['documentos'][4]['pdf_url'])->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(hojasDelPdf((string) $pdf->getContent()))->toBe([8, 9, 10, 11]);

    expect(Storage::disk('nas')->allFiles())->toBe($archivosAntes)
        ->and(EmployeeDocument::query()->count())->toBe($documentosAntes)
        ->and(ParteDivisionHistorico::query()->count())->toBe($partesAntes)
        ->and(RevisionDivisionHistorico::query()->count())->toBe(0)
        ->and($this->division->fresh()->paginas)->toBe($paginasAntes);
});

test('confirmar es idempotente: el mismo plan dos veces no crea nada la segunda', function () {
    $documentos = planEditor(
        ['solicitud_empleo' => [1, 2], 'ine' => [3], 'rfc' => [4], 'acta_nacimiento' => [5, 6, 7], 'contrato' => [8, 9, 10, 11]],
        [[12]],
    );

    $primera = $this->editor->confirmar($this->division, $documentos, 0, $this->rh);
    $archivos = count(Storage::disk('nas')->allFiles());
    $documentosDespues = EmployeeDocument::query()->count();

    // Reintento (doble clic / red): incluso con la revisión base vieja.
    $segunda = $this->editor->confirmar($this->division->fresh(), $documentos, 0, $this->rh);

    expect($primera)->not->toBeNull()
        ->and($segunda)->toBeNull()
        ->and(RevisionDivisionHistorico::query()->count())->toBe(1)
        ->and(EmployeeDocument::query()->count())->toBe($documentosDespues)
        ->and(count(Storage::disk('nas')->allFiles()))->toBe($archivos);

    // Y la división muestra lo nuevo como plan vigente.
    expect($this->editor->planVigente($this->division->fresh()))->toBe([
        ['document_type_id' => tipoEditor('solicitud_empleo'), 'paginas' => [1, 2]],
        ['document_type_id' => tipoEditor('ine'), 'paginas' => [3]],
        ['document_type_id' => tipoEditor('rfc'), 'paginas' => [4]],
        ['document_type_id' => tipoEditor('acta_nacimiento'), 'paginas' => [5, 6, 7]],
        ['document_type_id' => tipoEditor('contrato'), 'paginas' => [8, 9, 10, 11]],
        ['document_type_id' => null, 'paginas' => [12]],
    ]);
});

test('si otra persona guardó antes, la revisión base vieja se rechaza', function () {
    $this->editor->confirmar($this->division, planEditor(['solicitud_empleo' => [1, 2], 'ine' => [3], 'rfc' => [4], 'acta_nacimiento' => [5], 'contrato' => [8]], [[6, 7], [9, 10, 11, 12]]), 0, $this->rh);

    $otro = planEditor(['solicitud_empleo' => [1, 2], 'ine' => [3], 'rfc' => [4], 'curp' => [5], 'contrato' => [8]], [[6, 7], [9, 10, 11, 12]]);

    expect(fn () => $this->editor->confirmar($this->division->fresh(), $otro, 0, $this->rh))->toThrow(ValidationException::class)
        ->and($this->editor->confirmar($this->division->fresh(), $otro, 1, $this->rh)?->version)->toBe(2);
});

test('un documento de este PDF que se queda sin hojas se archiva (en revisión) y se advierte', function () {
    $rfc = EmployeeDocument::query()->where('document_type_id', tipoEditor('rfc'))->firstOrFail();

    $documentos = planEditor(['solicitud_empleo' => [1, 2], 'ine' => [3], 'contrato' => [8]], [[4, 5, 6, 7], [9, 10, 11, 12]]);
    $previa = $this->editor->vistaPrevia($this->division, $documentos);

    expect(collect($previa['advertencias'])->pluck('mensaje')->contains(fn (string $m) => str_contains($m, 'se quedó sin hojas')))->toBeTrue()
        ->and($previa['faltantes'])->toContain('Constancia de situación fiscal (RFC)');

    $this->editor->confirmar($this->division, $documentos, 0, $this->rh);

    expect($rfc->fresh()->status)->toBe(EstadoDocumento::Archivado)
        ->and(Storage::disk('nas')->exists($rfc->path))->toBeTrue();
});

test('volver a dividir con el OCR respeta las hojas corregidas por RH', function () {
    $this->editor->confirmar($this->division, planEditor(
        ['solicitud_empleo' => [1, 2], 'ine' => [3], 'rfc' => [4], 'acta_nacimiento' => [5], 'contrato' => [8, 9, 10, 11]],
        [[6, 7], [12]],
    ), 0, $this->rh);

    $documentosAntes = EmployeeDocument::query()->count();
    $nueva = app(DivisionExpedienteHistoricoService::class)->dividir($this->historico->fresh(), $this->rh, true);

    expect($nueva->id)->not->toBe($this->division->id);

    $partes = $nueva->partesVigentes()->get();
    $contrato = $partes->firstWhere('document_type_id', tipoEditor('contrato'));
    $acta = $partes->firstWhere('document_type_id', tipoEditor('acta_nacimiento'));
    $paginas = collect($nueva->paginas)->keyBy('pagina');

    expect($contrato->paginas)->toBe([8, 9, 10, 11])
        ->and($contrato->confianza)->toBe('manual')
        ->and($acta->paginas)->toBe([5])
        ->and($paginas[9]['tipo'])->toBe('contrato')
        ->and($paginas[9]['manual'])->toBeTrue()
        ->and($paginas[5]['manual'])->toBeTrue()
        // Lo que el OCR no corrigió sigue igual que siempre.
        ->and($paginas[6]['tipo'])->toBeNull()
        ->and(collect($nueva->advertencias)->contains(fn (string $a) => str_contains($a, 'Se respetaron')))->toBeTrue()
        // Ya estaban cargados: no se duplican en el expediente.
        ->and(EmployeeDocument::query()->count())->toBe($documentosAntes);

    // La división vieja ya no se edita; la nueva sí.
    expect(fn () => $this->editor->vistaPrevia($this->division->fresh(), []))->toThrow(ValidationException::class);
});

test('permisos: sin permiso o sobre el propio expediente todo es 403', function () {
    $documentos = planEditor(['solicitud_empleo' => [1, 2], 'ine' => [3], 'rfc' => [4], 'contrato' => [8]], [[5, 6, 7], [9, 10, 11, 12]]);
    $rutas = fn () => [
        ['get', route('rh.expedientes.division-historico.editor', $this->division), []],
        ['postJson', route('rh.expedientes.division-historico.editor.vista-previa', $this->division), ['documentos' => $documentos]],
        ['get', route('rh.expedientes.division-historico.editor.pdf', ['division' => $this->division->id, 'paginas' => '1,2']), []],
        ['post', route('rh.expedientes.division-historico.editor.confirmar', $this->division), ['documentos' => $documentos, 'revision_base' => 0]],
    ];

    $this->actingAs($this->rh)
        ->get(route('rh.expedientes.division-historico.editor', $this->division))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Rh/Expedientes/EditorHojasDivision')
            ->where('division.total_paginas', 12)
            ->has('paginas', 12)
            ->has('documentos', 6)
            ->where('permisos.guardar', true));

    $sinPermiso = User::factory()->create();
    $sinPermiso->assignRole('colaborador');

    foreach ($rutas() as [$metodo, $url, $datos]) {
        $this->actingAs($sinPermiso)->{$metodo}($url, $datos)->assertForbidden();
    }

    $this->rh->forceFill(['colaborador_id' => $this->colaborador->id])->save();

    foreach ($rutas() as [$metodo, $url, $datos]) {
        $this->actingAs($this->rh)->{$metodo}($url, $datos)->assertForbidden();
    }

    expect(RevisionDivisionHistorico::query()->count())->toBe(0);
});
