<?php

use App\Services\Plantillas\DocxUploadValidator;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpWord\PhpWord;

function archivoSubidoDesde(string $ruta, string $nombreOriginal = 'archivo.docx'): UploadedFile
{
    return new UploadedFile($ruta, $nombreOriginal, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true);
}

test('un docx real y pequeño pasa la validacion', function () {
    $phpWord = new PhpWord;
    $phpWord->addSection()->addText('Hola {{nombre_completo}}');
    $ruta = sys_get_temp_dir().'/'.uniqid('docx_valido', true).'.docx';
    $phpWord->save($ruta, 'Word2007');

    expect(DocxUploadValidator::esZipSeguro(archivoSubidoDesde($ruta)))->toBeTrue();

    unlink($ruta);
});

test('un archivo que no es un zip valido se rechaza', function () {
    $ruta = sys_get_temp_dir().'/'.uniqid('no_es_zip', true).'.docx';
    file_put_contents($ruta, 'esto no es un archivo docx, solo texto plano');

    expect(DocxUploadValidator::esZipSeguro(archivoSubidoDesde($ruta)))->toBeFalse();

    unlink($ruta);
});

test('un zip cuyo contenido descomprimido excede el limite se rechaza (zip bomb)', function () {
    $ruta = sys_get_temp_dir().'/'.uniqid('zip_bomb', true).'.docx';

    $zip = new ZipArchive;
    $zip->open($ruta, ZipArchive::CREATE);
    // 101 MB de un solo caracter repetido: comprime a unos cuantos KB con
    // deflate, pero declara 101 MB de tamaño descomprimido en el ZIP —
    // exactamente el patron de un "zip bomb" contra un lector ingenuo.
    $zip->addFromString('word/document.xml', str_repeat('0', 101 * 1024 * 1024));
    $zip->setCompressionName('word/document.xml', ZipArchive::CM_DEFLATE, 9);
    $zip->close();

    expect(DocxUploadValidator::esZipSeguro(archivoSubidoDesde($ruta)))->toBeFalse();

    unlink($ruta);
});
