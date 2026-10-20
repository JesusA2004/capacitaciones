<?php

use App\Models\Candidato;
use App\Models\CandidatoEvidencia;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Storage;

/*
 * Visor embebido del candidato: CV y evidencias (psicométricos,
 * socioeconómico, referencias) se sirven INLINE con el Content-Type real
 * para que el navegador los muestre en el iframe/img/video en vez de
 * descargarlos; la descarga explícita sigue disponible. La autorización
 * (Policy + alcance) es la misma de siempre.
 */
beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    $this->rh = clUsuario('rh_admin');
    $this->candidato = Candidato::factory()->create();
});

function aciConCv(Candidato $candidato, string $nombre, string $contenido = '%PDF-1.4 prueba'): Candidato
{
    $ruta = "candidatos/{$candidato->id}/cv/".md5($nombre).'.'.pathinfo($nombre, PATHINFO_EXTENSION);
    Storage::disk('nas')->put($ruta, $contenido);
    $candidato->forceFill(['cv_disk' => 'nas', 'cv_path' => $ruta, 'cv_original_name' => $nombre])->save();

    return $candidato;
}

function aciEvidencia(Candidato $candidato, string $nombre, ?string $mime, string $tipo = 'documento'): CandidatoEvidencia
{
    $ruta = "candidatos/{$candidato->id}/psicometricos/".md5($nombre).'.'.pathinfo($nombre, PATHINFO_EXTENSION);
    Storage::disk('nas')->put($ruta, 'contenido');

    return CandidatoEvidencia::query()->create([
        'candidato_id' => $candidato->id,
        'tipo' => $tipo,
        'disk' => 'nas',
        'path' => $ruta,
        'original_name' => $nombre,
        'mime' => $mime,
        'size' => 9,
    ]);
}

test('la vista previa del CV en PDF se sirve inline como application/pdf', function () {
    aciConCv($this->candidato, 'cv-laura.pdf');

    $respuesta = $this->actingAs($this->rh)->get(route('rh.candidatos.cv.previsualizar', $this->candidato));

    $respuesta->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Accept-Ranges', 'bytes');
    expect($respuesta->headers->get('Content-Disposition'))->toStartWith('inline;')
        ->toContain('cv-laura.pdf');
});

test('un CV en imagen con acentos se muestra inline con su mime y nombre UTF-8', function () {
    aciConCv($this->candidato, 'Currículum Peña.JPG', 'jpeg');

    $respuesta = $this->actingAs($this->rh)->get(route('rh.candidatos.cv.previsualizar', $this->candidato));

    $respuesta->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    expect($respuesta->headers->get('Content-Disposition'))->toStartWith('inline;')
        ->toContain("filename*=utf-8''".rawurlencode('Currículum Peña.JPG'));
});

test('un CV en imagen se previsualiza inline con su mime real', function (string $nombre, string $mime) {
    aciConCv($this->candidato, $nombre, 'imagen');

    $respuesta = $this->actingAs($this->rh)->get(route('rh.candidatos.cv.previsualizar', $this->candidato));

    $respuesta->assertOk()->assertHeader('Content-Type', $mime);
    expect($respuesta->headers->get('Content-Disposition'))->toStartWith('inline;');
    expect($respuesta->streamedContent())->toBe('imagen');
})->with([
    'png' => ['cv.png', 'image/png'],
    'jpg' => ['cv.jpg', 'image/jpeg'],
    'jpeg' => ['cv.jpeg', 'image/jpeg'],
    'webp' => ['cv.webp', 'image/webp'],
    'gif' => ['cv.gif', 'image/gif'],
]);

test('descargar el CV sigue entregándolo como archivo adjunto', function () {
    aciConCv($this->candidato, 'cv.pdf');

    $respuesta = $this->actingAs($this->rh)->get(route('rh.candidatos.cv.descargar', $this->candidato));

    $respuesta->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($respuesta->headers->get('Content-Disposition'))->toStartWith('attachment;');
});

test('una evidencia sin mime guardado se resuelve por extensión e inline', function () {
    $evidencia = aciEvidencia($this->candidato, 'resultados.png', null, 'fotografia');

    $respuesta = $this->actingAs($this->rh)
        ->get(route('rh.candidatos.evidencias.descargar', [$this->candidato, $evidencia]));

    $respuesta->assertOk()->assertHeader('Content-Type', 'image/png');
    expect($respuesta->headers->get('Content-Disposition'))->toStartWith('inline;');
    expect($respuesta->streamedContent())->toBe('contenido');
});

test('una evidencia con mime genérico también se corrige por extensión', function () {
    $evidencia = aciEvidencia($this->candidato, 'psicometrico.pdf', 'application/octet-stream');

    $this->actingAs($this->rh)
        ->get(route('rh.candidatos.evidencias.descargar', [$this->candidato, $evidencia]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

test('con ?descargar=1 la evidencia se entrega como adjunto', function () {
    $evidencia = aciEvidencia($this->candidato, 'referencia.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

    $respuesta = $this->actingAs($this->rh)
        ->get(route('rh.candidatos.evidencias.descargar', [$this->candidato, $evidencia, 'descargar' => 1]));

    $respuesta->assertOk();
    expect($respuesta->headers->get('Content-Disposition'))->toStartWith('attachment;')
        ->toContain('referencia.docx');
});

test('un SVG se sirve aislado para que no ejecute scripts', function () {
    $evidencia = aciEvidencia($this->candidato, 'diagrama.svg', 'image/svg+xml', 'fotografia');

    $respuesta = $this->actingAs($this->rh)
        ->get(route('rh.candidatos.evidencias.descargar', [$this->candidato, $evidencia]));

    $respuesta->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
    expect($respuesta->headers->get('Content-Security-Policy'))->toContain('sandbox');
});

test('sin permiso sobre el candidato no se puede ver ni el CV ni las evidencias', function () {
    aciConCv($this->candidato, 'cv.pdf');
    $evidencia = aciEvidencia($this->candidato, 'resultados.pdf', 'application/pdf');
    $colaborador = clUsuario('colaborador');

    $this->actingAs($colaborador)->get(route('rh.candidatos.cv.previsualizar', $this->candidato))->assertForbidden();
    $this->actingAs($colaborador)->get(route('rh.candidatos.cv.descargar', $this->candidato))->assertForbidden();
    $this->actingAs($colaborador)
        ->get(route('rh.candidatos.evidencias.descargar', [$this->candidato, $evidencia]))
        ->assertForbidden();
});

test('un archivo que ya no está en el NAS responde 404, no un error', function () {
    $evidencia = aciEvidencia($this->candidato, 'resultados.pdf', 'application/pdf');
    Storage::disk('nas')->delete($evidencia->path);

    $this->actingAs($this->rh)
        ->get(route('rh.candidatos.evidencias.descargar', [$this->candidato, $evidencia]))
        ->assertNotFound();
});

test('una evidencia de otro candidato no se sirve por la ruta de este', function () {
    $ajeno = Candidato::factory()->create();
    $evidencia = aciEvidencia($ajeno, 'resultados.pdf', 'application/pdf');

    $this->actingAs($this->rh)
        ->get(route('rh.candidatos.evidencias.descargar', [$this->candidato, $evidencia]))
        ->assertNotFound();
});
