<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Documentos OPCIONALES del checklist del expediente (docs/EXPEDIENTES_DIGITALES.md):
| aparecen en el checklist (etiqueta «Opcional») y se pueden asignar desde la
| división del PDF histórico, pero NUNCA cuentan para el avance, los
| faltantes, el bloqueo de la app ni la carta compromiso — todo eso sigue
| leyendo solo `requerido`.
|
| Viaja como migración (no solo en DocumentTypeSeeder) para que producción
| reciba los 3 tipos nuevos con un `php artisan migrate`. Idempotente: si la
| clave ya existe solo la marca como opcional del checklist.
*/
return new class extends Migration
{
    /** @var list<array{clave: string, nombre: string, orden: int}> */
    private const OPCIONALES = [
        ['clave' => 'pagare_expediente', 'nombre' => 'Pagaré', 'orden' => 15],
        ['clave' => 'carta_antecedentes_no_penales', 'nombre' => 'Carta de antecedentes no penales', 'orden' => 16],
        ['clave' => 'licencia_conducir', 'nombre' => 'Licencia de conducir', 'orden' => 17],
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('document_types', 'checklist_opcional')) {
            Schema::table('document_types', function (Blueprint $table) {
                $table->boolean('checklist_opcional')->default(false)->after('requerido');
            });
        }

        foreach (self::OPCIONALES as $tipo) {
            $existe = DB::table('document_types')->where('clave', $tipo['clave'])->exists();

            if ($existe) {
                DB::table('document_types')->where('clave', $tipo['clave'])->update([
                    'checklist_opcional' => true,
                    'requerido' => false,
                    'aplica_alta' => false,
                    'activo' => true,
                    'updated_at' => now(),
                ]);

                continue;
            }

            DB::table('document_types')->insert([
                'clave' => $tipo['clave'],
                'nombre' => $tipo['nombre'],
                'categoria' => 'personales',
                'requerido' => false,
                'checklist_opcional' => true,
                'aplica_alta' => false,
                'activo' => true,
                'orden' => $tipo['orden'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Los tipos no se borran (employee_documents los referencia con
        // restrictOnDelete); solo se quita la bandera.
        if (Schema::hasColumn('document_types', 'checklist_opcional')) {
            Schema::table('document_types', function (Blueprint $table) {
                $table->dropColumn('checklist_opcional');
            });
        }
    }
};
