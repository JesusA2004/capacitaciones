<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Avisos: de "imagen opcional" a ADJUNTO opcional (imagen o PDF). Se crean
 * las columnas archivo_*, se copian los avisos con imagen (nombre y tamaño
 * se completan con lo que se sabe: el archivo nunca se mueve) y se retiran
 * las columnas imagen_*. Idempotente: cada paso revisa si ya se hizo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('avisos', 'archivo_path')) {
            Schema::table('avisos', function (Blueprint $table): void {
                // Disco privado (nunca público), mismo patrón que fondos/documentos.
                $table->string('archivo_disk', 40)->nullable()->after('colaborador_objetivo_id');
                $table->string('archivo_path')->nullable()->after('archivo_disk');
                $table->string('archivo_mime', 80)->nullable()->after('archivo_path');
                $table->string('archivo_nombre', 180)->nullable()->after('archivo_mime');
                $table->unsignedBigInteger('archivo_size')->nullable()->after('archivo_nombre');
            });
        }

        if (Schema::hasColumn('avisos', 'imagen_path')) {
            DB::table('avisos')
                ->whereNotNull('imagen_path')
                ->whereNull('archivo_path')
                ->orderBy('id')
                ->each(function (object $aviso): void {
                    DB::table('avisos')->where('id', $aviso->id)->update([
                        'archivo_disk' => $aviso->imagen_disk,
                        'archivo_path' => $aviso->imagen_path,
                        'archivo_mime' => $aviso->imagen_mime,
                        'archivo_nombre' => basename((string) $aviso->imagen_path),
                        'archivo_size' => $this->tamano((string) $aviso->imagen_disk, (string) $aviso->imagen_path),
                    ]);
                });

            Schema::table('avisos', function (Blueprint $table): void {
                $table->dropColumn(['imagen_disk', 'imagen_path', 'imagen_mime']);
            });
        }
    }

    private function tamano(string $disco, string $ruta): ?int
    {
        try {
            return Storage::disk($disco)->exists($ruta) ? Storage::disk($disco)->size($ruta) : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('avisos', 'imagen_path')) {
            Schema::table('avisos', function (Blueprint $table): void {
                $table->string('imagen_disk', 40)->nullable();
                $table->string('imagen_path')->nullable();
                $table->string('imagen_mime', 80)->nullable();
            });
        }

        // Solo las imágenes caben en el esquema anterior.
        DB::table('avisos')->where('archivo_mime', 'like', 'image/%')->update([
            'imagen_disk' => DB::raw('archivo_disk'),
            'imagen_path' => DB::raw('archivo_path'),
            'imagen_mime' => DB::raw('archivo_mime'),
        ]);

        Schema::table('avisos', function (Blueprint $table): void {
            $table->dropColumn(['archivo_disk', 'archivo_path', 'archivo_mime', 'archivo_nombre', 'archivo_size']);
        });
    }
};
