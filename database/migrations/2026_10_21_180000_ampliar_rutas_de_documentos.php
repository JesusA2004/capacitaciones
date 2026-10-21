<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Rutas y nombres de archivo de documentos en el NAS: de varchar(191) a
| varchar(500). La ruta lleva el nombre completo de la persona en la carpeta
| Y en el archivo («expedientes/Empresa/Sucursal/EMP-1 - Nombre/Contratos/
| EMP_1_Nombre_Contrato_….pdf»); con un nombre largo superaba 191 y MariaDB
| (modo estricto) rechazaba el INSERT → «Data too long for column 'path'» →
| HTTP 500 al generar el contrato. Ninguna de estas columnas tiene índice.
|
| Idempotente: solo amplía columnas que existen y miden menos de 500.
*/
return new class extends Migration
{
    private const LARGO = 500;

    /** @var array<string, list<string>> */
    private const COLUMNAS = [
        'generated_documents' => ['path', 'original_name', 'generated_name', 'docx_path'],
        'employee_documents' => ['path', 'original_name', 'stored_name'],
        'expedientes_historicos' => ['path', 'original_name', 'stored_name'],
        'solicitud_interna_documentos' => ['path', 'original_name', 'stored_name'],
        'alta_digital_documentos' => ['path', 'original_name'],
        'acta_anexos' => ['path', 'original_name'],
        'candidato_evidencias' => ['path', 'original_name'],
        'colaboradores' => ['expediente_storage_path'],
        'finiquito_calculos' => ['documento_generado_path', 'documento_firmado_path'],
        'recibos_nomina' => ['pdf_path'],
        'official_format_generations' => ['generated_path', 'generated_name', 'signed_path'],
        'seguimientos_documento_fisico' => ['comprobante_path'],
    ];

    public function up(): void
    {
        // SQLite (pruebas) no hace cumplir el largo de varchar: nada que ampliar.
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        foreach (self::COLUMNAS as $tabla => $columnas) {
            if (! Schema::hasTable($tabla)) {
                continue;
            }

            $actuales = collect(Schema::getColumns($tabla))->keyBy('name');
            $porAmpliar = [];

            foreach ($columnas as $columna) {
                $info = $actuales->get($columna);

                if (! is_array($info) || ! $this->esTextoCorto((string) ($info['type'] ?? ''))) {
                    continue;
                }

                $porAmpliar[$columna] = (bool) ($info['nullable'] ?? false);
            }

            if ($porAmpliar === []) {
                continue;
            }

            Schema::table($tabla, function (Blueprint $table) use ($porAmpliar): void {
                foreach ($porAmpliar as $columna => $nullable) {
                    $table->string($columna, self::LARGO)->nullable($nullable)->change();
                }
            });
        }
    }

    /**
     * Sin reversa: volver a 191 truncaría rutas reales ya guardadas.
     */
    public function down(): void {}

    /**
     * varchar(n) con n < 500.
     */
    private function esTextoCorto(string $tipo): bool
    {
        return preg_match('/^varchar\((\d+)\)$/i', $tipo, $m) === 1 && (int) $m[1] < self::LARGO;
    }
};
