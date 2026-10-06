<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Candidato espontáneo (sin vacante): única forma válida de que
 * `candidatos.vacante_id` quede en null a partir de ahora — antes era el
 * estado implícito de "todavía no elegiste vacante" (ver CLAUDE.md §2-3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidatos', function (Blueprint $table): void {
            $table->boolean('espontaneo')->default(false)->after('vacante_id');
        });

        // Los candidatos que ya existían sin vacante eran, de hecho,
        // espontáneos (antes era el estado implícito de "pipeline
        // general"): se marcan para que la UI los explique igual que a los
        // nuevos, sin inventar ni perder ningún dato.
        DB::table('candidatos')->whereNull('vacante_id')->update(['espontaneo' => true]);
    }

    public function down(): void
    {
        Schema::table('candidatos', function (Blueprint $table): void {
            $table->dropColumn('espontaneo');
        });
    }
};
