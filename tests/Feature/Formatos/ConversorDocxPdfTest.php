<?php

use App\Services\Formatos\Motor\ConversorDocxPdf;
use PhpOffice\PhpWord\PhpWord;

function crearDocxParaConversor(): string
{
    $phpWord = new PhpWord;
    $phpWord->addSection()->addText('Contenido de prueba.');

    $ruta = sys_get_temp_dir().'/'.uniqid('conversor', true).'.docx';
    $phpWord->save($ruta, 'Word2007');

    $contenido = file_get_contents($ruta);
    unlink($ruta);

    return $contenido !== false ? $contenido : '';
}

test('fiel() es false cuando no hay ruta de LibreOffice configurada', function () {
    config(['formatos_oficiales.conversor' => 'libreoffice', 'formatos_oficiales.libreoffice' => null]);

    expect(app(ConversorDocxPdf::class)->fiel())->toBeFalse();
});

test('fiel() es false cuando la ruta configurada es una cadena vacía', function () {
    config(['formatos_oficiales.conversor' => 'libreoffice', 'formatos_oficiales.libreoffice' => '']);

    expect(app(ConversorDocxPdf::class)->fiel())->toBeFalse();
});

test('fiel() es true cuando hay una ruta de LibreOffice configurada', function () {
    config(['formatos_oficiales.conversor' => 'libreoffice', 'formatos_oficiales.libreoffice' => '/usr/bin/soffice']);

    expect(app(ConversorDocxPdf::class)->fiel())->toBeTrue();
});

test('sin LibreOffice configurado, convertir() cae a PhpWord/DomPDF con fidelidad aproximada', function () {
    config(['formatos_oficiales.conversor' => 'libreoffice', 'formatos_oficiales.libreoffice' => null]);
    $docx = crearDocxParaConversor();

    $resultado = app(ConversorDocxPdf::class)->convertir($docx);

    expect($resultado)->not->toBeNull()
        ->and($resultado['fidelidad'])->toBe('aproximada')
        ->and($resultado['pdf'])->not->toBeEmpty();
});

test('convertir() con contenido que no es un DOCX real nunca truena (nunca rompe la descarga)', function () {
    config(['formatos_oficiales.conversor' => 'libreoffice', 'formatos_oficiales.libreoffice' => null]);

    expect(fn () => app(ConversorDocxPdf::class)->convertir('esto no es un docx'))->not->toThrow(Throwable::class);
});
