<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| «2 cartas de recomendación» deja de ser UN documento (las dos en un mismo
| archivo) y pasa a ser dos del checklist:
|
| - «Carta de recomendación 1»: el tipo existente renombrado (mismo id, sus
|   documentos siguen ahí). Obligatoria del alta: con ella aprobada el
|   expediente ya cuenta como completo.
| - «Carta de recomendación 2»: OPCIONAL del checklist (como pagaré o
|   licencia): se ve y se sube, pero nunca cuenta para el avance, los
|   faltantes, el bloqueo de la app ni la carta compromiso.
|
| Cada carta se sube (foto o PDF), se aprueba y se rechaza por separado; si
| vienen juntas, al subir se marca «trae también la otra carta»
| (App\Services\Expedientes\DocumentoParService). Lo ya cargado (las dos
| cartas en un archivo) se queda en la carta 1: no se copia nada.
|
| Idempotente: si la carta 2 ya existe solo se asegura que sea opcional.
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

        $opcional = [
            'nombre' => 'Carta de recomendación 2',
            'categoria' => 'personales',
            'requerido' => false,
            'checklist_opcional' => true,
            'aplica_alta' => false,
            'activo' => true,
            'updated_at' => now(),
        ];

        if (DB::table('document_types')->where('clave', 'carta_recomendacion_2')->exists()) {
            DB::table('document_types')->where('clave', 'carta_recomendacion_2')->update($opcional);

            return;
        }

        // Primera de los opcionales del checklist (justo después de los
        // obligatorios); los demás opcionales se recorren un lugar.
        $orden = (int) DB::table('document_types')->where('requerido', true)->where('orden', '<', 100)->max('orden') + 1;
        DB::table('document_types')->where('orden', '>=', $orden)->where('orden', '<', 100)->increment('orden');

        DB::table('document_types')->insert([
            ...$opcional,
            'clave' => 'carta_recomendacion_2',
            'orden' => $orden,
            'created_at' => now(),
        ]);
    }

    public function down(): void
    {
        // La carta 2 no se borra (employee_documents la referencia con
        // restrictOnDelete): se desactiva y la carta 1 recupera su nombre.
        DB::table('document_types')->where('clave', 'carta_recomendacion_2')->update(['activo' => false, 'checklist_opcional' => false, 'updated_at' => now()]);
        DB::table('document_types')->where('clave', 'carta_recomendacion_1')->update([
            'clave' => 'cartas_recomendacion',
            'nombre' => '2 cartas de recomendación',
            'updated_at' => now(),
        ]);
    }
};
