<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| La sucursal Corporativo (clave CORP01) nunca quedó marcada como
| es_corporativo: el directorio, las láminas del organigrama y el tablero de
| RH la trataban como una sucursal más. Solo marca; nunca desmarca otras.
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::table('sucursales')->where('clave', 'CORP01')->update(['es_corporativo' => true]);
    }

    public function down(): void
    {
        // Sin reversa: el dato correcto es que Corporativo es corporativo.
    }
};
