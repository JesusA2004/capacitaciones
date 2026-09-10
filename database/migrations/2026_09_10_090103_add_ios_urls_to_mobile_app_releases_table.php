<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mobile_app_releases', function (Blueprint $table) {
            // Distribucion iOS futura (TestFlight/App Store): la app movil no
            // tiene APK que descargar para esta plataforma, solo un enlace
            // externo. Ver App\Http\Controllers\Api\V1\AppConfigController.
            $table->string('install_url')->nullable()->after('file_path');
            $table->string('store_url')->nullable()->after('install_url');
        });
    }

    public function down(): void
    {
        Schema::table('mobile_app_releases', function (Blueprint $table) {
            $table->dropColumn(['install_url', 'store_url']);
        });
    }
};
