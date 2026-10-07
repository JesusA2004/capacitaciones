<?php

use App\Models\Candidato;
use App\Models\CandidatoEvidencia;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Support\Facades\Storage;

/*
 * Evidencia de candidato (psicométricos/socioeconómico) con soporte real de
 * HTTP Range (CLAUDE.md §8-9): un video pesado debe poder adelantarse sin
 * descargarse completo, y el controlador nunca debe exponer disk/path.
 */
beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Storage::fake('nas');
    $this->rh = clUsuario('rh_admin');
    $this->candidato = Candidato::factory()->create();
});

function erContenido(): string
{
    // 26 bytes, uno por letra: facil de verificar un rango exacto.
    return 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
}

function erEvidencia(Candidato $candidato, string $mime = 'video/mp4'): CandidatoEvidencia
{
    $contenido = erContenido();
    $ruta = "candidatos/{$candidato->id}/evidencias/video.mp4";
    Storage::disk('nas')->put($ruta, $contenido);

    return CandidatoEvidencia::query()->create([
        'candidato_id' => $candidato->id,
        'tipo' => 'video',
        'disk' => 'nas',
        'path' => $ruta,
        'original_name' => 'video.mp4',
        'mime' => $mime,
        'size' => strlen($contenido),
    ]);
}

test('sin encabezado Range manda el archivo completo con Accept-Ranges', function () {
    $evidencia = erEvidencia($this->candidato);

    $respuesta = $this->actingAs($this->rh)
        ->get(route('rh.candidatos.evidencias.descargar', [$this->candidato, $evidencia]));

    $respuesta->assertOk()
        ->assertHeader('Accept-Ranges', 'bytes')
        ->assertHeader('Content-Length', (string) strlen(erContenido()))
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($respuesta->streamedContent())->toBe(erContenido());
});

test('con encabezado Range manda solo el fragmento pedido en 206', function () {
    $evidencia = erEvidencia($this->candidato);

    $respuesta = $this->actingAs($this->rh)
        ->withHeaders(['Range' => 'bytes=5-9'])
        ->get(route('rh.candidatos.evidencias.descargar', [$this->candidato, $evidencia]));

    $respuesta->assertStatus(206)
        ->assertHeader('Content-Range', 'bytes 5-9/26')
        ->assertHeader('Content-Length', '5');

    expect($respuesta->streamedContent())->toBe('FGHIJ');
});

test('un Range abierto al final (bytes=20-) manda hasta el último byte', function () {
    $evidencia = erEvidencia($this->candidato);

    $respuesta = $this->actingAs($this->rh)
        ->withHeaders(['Range' => 'bytes=20-'])
        ->get(route('rh.candidatos.evidencias.descargar', [$this->candidato, $evidencia]));

    $respuesta->assertStatus(206)
        ->assertHeader('Content-Range', 'bytes 20-25/26');

    expect($respuesta->streamedContent())->toBe('UVWXYZ');
});

test('nunca expone disk ni path de la evidencia en JSON', function () {
    $evidencia = erEvidencia($this->candidato);

    expect($evidencia->toArray())->not->toHaveKeys(['disk', 'path']);
});
