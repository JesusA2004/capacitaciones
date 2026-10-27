<?php

namespace Database\Seeders;

use App\Models\DocumentType;
use Illuminate\Database\Seeder;

class DocumentTypeSeeder extends Seeder
{
    /**
     * Catálogo de documentos de expediente (ver docs/EXPEDIENTES_DIGITALES.md).
     *
     * El checklist del expediente son EXACTAMENTE los 15 obligatorios, en
     * este orden, más 3 OPCIONALES del checklist (pagaré, carta de
     * antecedentes no penales, licencia de conducir) que se ven con la
     * etiqueta «Opcional» y se pueden asignar, pero nunca cuentan para el
     * avance, los faltantes, el bloqueo de la app ni la carta compromiso.
     * Los demás tipos existen para otros módulos (solicitudes, bajas,
     * préstamos, historial) y no aparecen en el checklist. El PDF histórico de la migración inicial NO es un tipo de
     * documento (vive en expedientes_historicos).
     */
    public function run(): void
    {
        $obligatorios = [
            ['clave' => 'solicitud_empleo', 'nombre' => 'Solicitud de empleo (Formato interno)', 'categoria' => 'personales', 'aplica_alta' => true],
            ['clave' => 'fotografia', 'nombre' => '2 fotografías tamaño infantil B/N o Color', 'categoria' => 'personales', 'aplica_alta' => true],
            ['clave' => 'acta_nacimiento', 'nombre' => 'Acta de nacimiento', 'categoria' => 'personales', 'aplica_alta' => true],
            ['clave' => 'ine', 'nombre' => 'Identificación oficial', 'categoria' => 'personales', 'aplica_alta' => true],
            ['clave' => 'nss', 'nombre' => 'Número de Seguridad Social', 'categoria' => 'personales', 'aplica_alta' => true],
            ['clave' => 'curp', 'nombre' => 'CURP', 'categoria' => 'personales', 'aplica_alta' => true],
            ['clave' => 'rfc', 'nombre' => 'Constancia de situación fiscal (RFC)', 'categoria' => 'personales', 'aplica_alta' => true],
            ['clave' => 'comprobante_domicilio', 'nombre' => 'Comprobante de domicilio', 'categoria' => 'personales', 'aplica_alta' => true],
            ['clave' => 'comprobante_estudios', 'nombre' => 'Comprobante de estudios', 'categoria' => 'personales', 'aplica_alta' => true],
            // Cada carta es su propio documento (se aprueba o rechaza aparte);
            // si vienen en un mismo archivo se sube una vez marcando «trae
            // también la otra carta» (config expedientes.pares_mismo_archivo).
            ['clave' => 'carta_recomendacion_1', 'nombre' => 'Carta de recomendación 1', 'categoria' => 'personales', 'aplica_alta' => true],
            ['clave' => 'carta_recomendacion_2', 'nombre' => 'Carta de recomendación 2', 'categoria' => 'personales', 'aplica_alta' => true],
            ['clave' => 'datos_bancarios', 'nombre' => 'Datos bancarios (número de cuenta, tarjeta y CLABE interbancaria)', 'categoria' => 'personales', 'aplica_alta' => true],
            ['clave' => 'contrato', 'nombre' => 'Contrato laboral', 'categoria' => 'contratos', 'aplica_alta' => false],
            ['clave' => 'carta_confidencialidad', 'nombre' => 'Contrato / carta de confidencialidad', 'categoria' => 'contratos', 'aplica_alta' => false],
            ['clave' => 'contrato_no_competencia', 'nombre' => 'Contrato de No Competencia', 'categoria' => 'contratos', 'aplica_alta' => false],
        ];

        foreach ($obligatorios as $orden => $tipo) {
            DocumentType::query()->updateOrCreate(['clave' => $tipo['clave']], [...$tipo, 'requerido' => true, 'activo' => true, 'orden' => $orden + 1]);
        }

        // Opcionales del checklist. La clave del pagaré es «pagare_expediente»
        // a propósito: «pagare» es la clave de la PLANTILLA del pagaré de
        // préstamos (config/contratos.php), que es otro documento.
        $opcionalesChecklist = [
            ['clave' => 'pagare_expediente', 'nombre' => 'Pagaré'],
            ['clave' => 'carta_antecedentes_no_penales', 'nombre' => 'Carta de antecedentes no penales'],
            ['clave' => 'licencia_conducir', 'nombre' => 'Licencia de conducir'],
        ];

        foreach ($opcionalesChecklist as $orden => $tipo) {
            DocumentType::query()->updateOrCreate(['clave' => $tipo['clave']], [...$tipo, 'categoria' => 'personales', 'requerido' => false, 'checklist_opcional' => true, 'aplica_alta' => false, 'activo' => true, 'orden' => count($obligatorios) + $orden + 1]);
        }

        // De otros módulos (no obligatorios, fuera del checklist).
        $otros = [
            ['clave' => 'estado_cuenta', 'nombre' => 'Estado de cuenta bancario'],
            ['clave' => 'aviso_privacidad', 'nombre' => 'Aviso de privacidad firmado'],
            ['clave' => 'cv', 'nombre' => 'Currículum (CV)'],
            ['clave' => 'reglamento', 'nombre' => 'Reglamento interno de trabajo firmado'],
            ['clave' => 'incapacidad', 'nombre' => 'Incapacidad médica'],
            ['clave' => 'permiso', 'nombre' => 'Formato de permiso'],
            ['clave' => 'formato_vacaciones', 'nombre' => 'Formato de vacaciones'],
            ['clave' => 'documento_baja', 'nombre' => 'Documento de baja'],
            ['clave' => 'contrato_credito_colaborador', 'nombre' => 'Contrato de crédito para colaboradores'],
            ['clave' => 'otro', 'nombre' => 'Otro documento'],
        ];

        foreach ($otros as $tipo) {
            DocumentType::query()->firstOrCreate(['clave' => $tipo['clave']], [...$tipo, 'requerido' => false, 'aplica_alta' => false, 'activo' => true, 'orden' => 100]);
        }

        DocumentType::query()->whereNotIn('clave', array_column($obligatorios, 'clave'))->update(['requerido' => false]);

        // Vigencia (meses) de los documentos que caducan: en un reingreso se
        // vuelven a pedir si ya vencieron. Solo llena lo vacío.
        foreach (['comprobante_domicilio' => 3, 'estado_cuenta' => 3] as $clave => $meses) {
            DocumentType::query()->where('clave', $clave)->whereNull('vigencia_meses')->update(['vigencia_meses' => $meses]);
        }
    }
}
