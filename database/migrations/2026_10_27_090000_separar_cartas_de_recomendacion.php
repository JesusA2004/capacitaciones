<?php

use App\Services\Expedientes\DocumentoParService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
| «2 cartas de recomendación» deja de ser UN documento (las dos en un mismo
| archivo) y pasa a ser DOS del checklist: «Carta de recomendación 1» y
| «Carta de recomendación 2». Cada una se sube (foto o PDF), se aprueba y se
| rechaza por separado; si vienen juntas, al subir se marca «trae también la
| otra carta» (App\Services\Expedientes\DocumentoParService).
|
| - El tipo existente se conserva (mismo id, sus documentos siguen ahí) y se
|   renombra a la carta 1; la carta 2 es un tipo nuevo, obligatorio y del
|   alta, justo después.
| - Lo que ya estaba cargado se pidió como «las dos cartas en un archivo»:
|   se copia a la carta 2 con el mismo estado, para que ningún expediente
|   pierda avance. Si el NAS falla con algún archivo, se registra en el log y
|   se reintenta con `php artisan expedientes:separar-cartas-recomendacion`.
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::table('document_types')->where('clave', 'cartas_recomendacion')->update([
            'clave' => 'carta_recomendacion_1',
            'nombre' => 'Carta de recomendación 1',
            'updated_at' => now(),
        ]);

        $carta1 = DB::table('document_types')->where('clave', 'carta_recomendacion_1')->first();

        if ($carta1 === null || DB::table('document_types')->where('clave', 'carta_recomendacion_2')->exists()) {
            return;
        }

        $orden = (int) $carta1->orden;

        DB::table('document_types')->where('orden', '>', $orden)->where('orden', '<', 100)->increment('orden');

        DB::table('document_types')->insert([
            'clave' => 'carta_recomendacion_2',
            'nombre' => 'Carta de recomendación 2',
            'categoria' => 'personales',
            'requerido' => (bool) $carta1->requerido,
            'checklist_opcional' => false,
            'aplica_alta' => (bool) $carta1->aplica_alta,
            'activo' => (bool) $carta1->activo,
            'orden' => $orden + 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resultado = app(DocumentoParService::class)->separarArchivosCompartidos(
            'carta_recomendacion_1',
            'carta_recomendacion_2',
            'Copia de la carta 1: cuando se cargó, las dos cartas se pedían en un solo archivo.',
        );

        foreach ($resultado['errores'] as $error) {
            Log::warning(sprintf('Separar cartas de recomendación: %s', $error));
        }
    }

    public function down(): void
    {
        // La carta 2 no se borra (employee_documents la referencia con
        // restrictOnDelete): se desactiva y la carta 1 recupera su nombre.
        DB::table('document_types')->where('clave', 'carta_recomendacion_2')->update(['activo' => false, 'requerido' => false, 'updated_at' => now()]);
        DB::table('document_types')->where('clave', 'carta_recomendacion_1')->update([
            'clave' => 'cartas_recomendacion',
            'nombre' => '2 cartas de recomendación',
            'updated_at' => now(),
        ]);
    }
};
