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
    $fuente = sys_get_temp_dir().'/'.uniqid('zip_bomb_xml', true).'.xml';

    // 101 MB de un solo caracter repetido: comprime a unos cuantos KB con
    // deflate, pero declara 101 MB de tamaño descomprimido en el ZIP —
    // exactamente el patron de un "zip bomb" contra un lector ingenuo.
    // Se escribe a disco en bloques de 1 MB y libzip lo comprime en streaming
    // desde el archivo (addFile): la prueba nunca reserva 101 MB en memoria
    // (antes str_repeat + addFromString tiraba la suite por memory_limit).
    $bloque = str_repeat('0', 1024 * 1024);
    $manejador = fopen($fuente, 'wb');
    for ($i = 0; $i < 101; $i++) {
        fwrite($manejador, $bloque);
    }
    fclose($manejador);
    unset($bloque);

    $zip = new ZipArchive;
    $zip->open($ruta, ZipArchive::CREATE);
    $zip->addFile($fuente, 'word/document.xml');
    $zip->setCompressionName('word/document.xml', ZipArchive::CM_DEFLATE, 9);
    $zip->close();
    unlink($fuente);

    expect(filesize($ruta))->toBeLessThan(1024 * 1024) // el .docx real pesa KB…
        ->and(DocxUploadValidator::esZipSeguro(archivoSubidoDesde($ruta)))->toBeFalse(); // …pero declara 101 MB

    unlink($ruta);
});
