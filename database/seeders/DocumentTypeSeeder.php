<?php

namespace Database\Seeders;

use App\Models\DocumentType;
use Illuminate\Database\Seeder;

class DocumentTypeSeeder extends Seeder
{
    /**
     * Catalogo base de documentos de expediente (ver docs/EXPEDIENTES_DIGITALES.md).
     * `requerido` determina si cuenta para el porcentaje de expediente completo.
     */
    public function run(): void
    {
        $tipos = [
            ['clave' => 'ine', 'nombre' => 'Identificación oficial (INE)', 'requerido' => true, 'aplica_alta' => true],
            ['clave' => 'curp', 'nombre' => 'CURP', 'requerido' => true, 'aplica_alta' => true],
            ['clave' => 'rfc', 'nombre' => 'Constancia de situación fiscal (RFC)', 'requerido' => true, 'aplica_alta' => true],
            ['clave' => 'nss', 'nombre' => 'Número de Seguridad Social (NSS)', 'requerido' => true, 'aplica_alta' => true],
            ['clave' => 'acta_nacimiento', 'nombre' => 'Acta de nacimiento', 'requerido' => true, 'aplica_alta' => true],
            ['clave' => 'comprobante_domicilio', 'nombre' => 'Comprobante de domicilio', 'requerido' => true, 'aplica_alta' => true],
            ['clave' => 'comprobante_estudios', 'nombre' => 'Comprobante de estudios', 'requerido' => false, 'aplica_alta' => true],
            ['clave' => 'estado_cuenta', 'nombre' => 'Estado de cuenta bancario', 'requerido' => false, 'aplica_alta' => true],
            ['clave' => 'fotografia', 'nombre' => 'Fotografía', 'requerido' => true, 'aplica_alta' => true],
            ['clave' => 'contrato', 'nombre' => 'Contrato laboral', 'requerido' => true, 'aplica_alta' => false],
            ['clave' => 'aviso_privacidad', 'nombre' => 'Aviso de privacidad firmado', 'requerido' => false, 'aplica_alta' => true],
            ['clave' => 'carta_confidencialidad', 'nombre' => 'Carta de confidencialidad', 'requerido' => false, 'aplica_alta' => false],
            ['clave' => 'otro', 'nombre' => 'Otro documento', 'requerido' => false, 'aplica_alta' => false],
        ];

        foreach ($tipos as $tipo) {
            DocumentType::firstOrCreate(
                ['clave' => $tipo['clave']],
                [...$tipo, 'activo' => true],
            );
        }
    }
}
