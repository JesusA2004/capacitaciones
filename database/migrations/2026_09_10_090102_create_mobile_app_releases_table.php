<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mobile_app_releases', function (Blueprint $table) {
            $table->id();
            $table->string('platform')->default('android');
            $table->string('version');
            $table->string('build_number')->nullable();
            // Nunca se expone al frontend: solo se sirve via controller
            // (ver App\Services\AppReleases\AppReleaseStorageService).
            $table->string('file_path')->nullable();
            // Distribucion iOS futura (TestFlight/App Store): la app movil no
            // tiene APK que descargar para esta plataforma, solo un enlace
            // externo. Ver App\Http\Controllers\Api\V1\AppConfigController.
            $table->string('install_url')->nullable();
            $table->string('store_url')->nullable();
            $table->string('original_filename')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('mime_type')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->text('changelog')->nullable();
            $table->boolean('is_published')->default(false);
            $table->boolean('is_latest')->default(false);
            $table->boolean('minimum_required')->default(false);
            $table->foreignId('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['platform', 'is_published']);
            $table->index(['platform', 'is_latest']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_app_releases');
    }
};
