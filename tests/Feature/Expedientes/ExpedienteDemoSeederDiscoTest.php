<?php

use App\Models\Colaborador;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use Database\Seeders\ExpedienteDemoSeeder;
use Illuminate\Support\Facades\Storage;

/*
| expedientes/ puede ser el árbol real de la empresa: el seeder demo solo lo
| purga en testing o con permiso explícito, y jamás en producción.
*/

function sembrarExpedienteDemoEn(string $entorno): void
{
    app()->detectEnvironment(fn () => $entorno);
    (new ExpedienteDemoSeeder)->run();
}

beforeEach(function () {
    Storage::disk('nas')->put('expedientes/Mr. Lana/Atlixco/REAL/contrato.pdf', '%PDF real');
});

test('producción nunca purga, ni con permiso explícito', function () {
    config(['features.seed_demo_purgar_nas' => true]);

    expect(fn () => sembrarExpedienteDemoEn('production'))->toThrow(RuntimeException::class, 'no se borran');

    Storage::disk('nas')->assertExists('expedientes/Mr. Lana/Atlixco/REAL/contrato.pdf');
});

test('local sin permiso detiene la siembra sin borrar', function () {
    expect(fn () => sembrarExpedienteDemoEn('local'))->toThrow(RuntimeException::class, 'NAS_ROOT');

    Storage::disk('nas')->assertExists('expedientes/Mr. Lana/Atlixco/REAL/contrato.pdf');
});

test('una resiembra sobre la misma BD no borra ni se detiene', function () {
    EmployeeDocument::factory()->create([
        'colaborador_id' => Colaborador::factory(),
        'document_type_id' => DocumentType::factory(),
    ]);

    sembrarExpedienteDemoEn('local');

    Storage::disk('nas')->assertExists('expedientes/Mr. Lana/Atlixco/REAL/contrato.pdf');
});

test('local con permiso explícito sí purga', function () {
    config(['features.seed_demo_purgar_nas' => true]);

    sembrarExpedienteDemoEn('local');

    Storage::disk('nas')->assertMissing('expedientes/Mr. Lana/Atlixco/REAL/contrato.pdf');
});

test('testing purga el disco falso', function () {
    sembrarExpedienteDemoEn('testing');

    Storage::disk('nas')->assertMissing('expedientes/Mr. Lana/Atlixco/REAL/contrato.pdf');
});
