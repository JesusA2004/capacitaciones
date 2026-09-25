<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Celebraciones (docs/CELEBRACIONES.md): el mismo registro que ya usaba
 * cumpleaños (`birthday_greetings` + `birthday_wall_messages`) ahora
 * modela cualquier celebración — cumpleaños y aniversario laboral — en vez
 * de duplicar tablas. Solo agrega columnas/índices y conserva todos los
 * datos existentes (todo lo anterior queda como tipo `cumpleanos`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('birthday_greetings', function (Blueprint $table): void {
            $table->string('tipo', 30)->default('cumpleanos')->after('colaborador_id');
            $table->unsignedSmallInteger('anios')->nullable()->after('fecha');
            $table->timestamp('avisada_todos_at')->nullable()->after('enviada_por_id');
            $table->foreignId('avisada_todos_por_id')->nullable()->after('avisada_todos_at')->constrained('users')->nullOnDelete();
        });

        // Una celebración por colaborador, fecha y TIPO: el mismo día puede
        // coincidir cumpleaños y aniversario.
        Schema::table('birthday_greetings', function (Blueprint $table): void {
            $table->unique(['colaborador_id', 'fecha', 'tipo'], 'celebraciones_colaborador_fecha_tipo_unico');
        });

        Schema::table('birthday_greetings', function (Blueprint $table): void {
            $table->dropUnique('birthday_greetings_colaborador_fecha_unico');
        });

        Schema::table('birthday_wall_messages', function (Blueprint $table): void {
            $table->foreignId('autor_colaborador_id')->nullable()->after('user_id')->constrained('colaboradores')->nullOnDelete();
        });

        // Autor como persona (Colaborador), a partir de su cuenta.
        DB::table('birthday_wall_messages')
            ->whereNull('autor_colaborador_id')
            ->orderBy('id')
            ->each(function (object $mensaje): void {
                $colaboradorId = DB::table('users')->where('id', $mensaje->user_id)->value('colaborador_id');

                if ($colaboradorId !== null) {
                    DB::table('birthday_wall_messages')->where('id', $mensaje->id)->update(['autor_colaborador_id' => $colaboradorId]);
                }
            });

        Schema::create('celebracion_configuraciones', function (Blueprint $table): void {
            $table->id();
            $table->string('tipo', 30)->unique();
            $table->boolean('activo')->default(true);
            $table->text('mensaje')->nullable();
            $table->string('fondo_path')->nullable();
            $table->boolean('auto_enviar_colaborador')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('celebracion_configuraciones');

        Schema::table('birthday_wall_messages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('autor_colaborador_id');
        });

        Schema::table('birthday_greetings', function (Blueprint $table): void {
            $table->unique(['colaborador_id', 'fecha'], 'birthday_greetings_colaborador_fecha_unico');
        });

        Schema::table('birthday_greetings', function (Blueprint $table): void {
            $table->dropUnique('celebraciones_colaborador_fecha_tipo_unico');
            $table->dropConstrainedForeignId('avisada_todos_por_id');
            $table->dropColumn(['tipo', 'anios', 'avisada_todos_at']);
        });
    }
};
