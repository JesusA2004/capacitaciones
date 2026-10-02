<?php

namespace Database\Seeders;

use App\Enums\EstadoDocumento;
use App\Enums\EstadoUsuario;
use App\Models\Colaborador;
use App\Models\DocumentType;
use App\Models\User;
use App\Services\Expedientes\DocumentoStorageService;
use App\Services\Expedientes\ExpedienteService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;

/**
 * Datos demo: todo colaborador ACTIVO (RH, gerentes, administrativos,
 * gestores…) queda con su expediente completo — cada documento requerido
 * con un PDF "DOCUMENTO DE DEMOSTRACIÓN — SIN VALIDEZ" aprobado. Una
 * persona activa con expediente faltante no tiene sentido; quien sigue en
 * contratación (en incorporación) conserva sus pendientes reales.
 *
 * Corre al final de DemoSeeder (después de todos los que crean personas).
 * Idempotente: solo sube lo que falta y nunca toca un documento existente.
 */
class CompletarExpedientesDemoSeeder extends Seeder
{
    public function run(): void
    {
        $storage = app(DocumentoStorageService::class);
        $expediente = app(ExpedienteService::class);
        $actor = User::query()->where('email', 'superadmin@mrlana.test')->first();

        if ($actor === null) {
            return;
        }

        $colaboradores = Colaborador::query()
            ->where('estatus', EstadoUsuario::Activo->value)
            ->with(['sucursalPrincipal.empresa', 'user:id,colaborador_id'])
            ->get();

        foreach ($colaboradores as $colaborador) {
            $vigentes = $expediente->documentosVigentes($colaborador);

            foreach ($expediente->tiposRequeridosPara($colaborador) as $tipo) {
                if ($vigentes->has($tipo->id)) {
                    continue;
                }

                $this->subirAprobado($storage, $colaborador, $tipo, $colaborador->user->id ?? $actor->id);
            }
        }
    }

    private function subirAprobado(DocumentoStorageService $storage, Colaborador $colaborador, DocumentType $tipo, int $subidoPorId): void
    {
        $pdf = Pdf::loadHTML(
            '<h1>DOCUMENTO DE DEMOSTRACIÓN</h1><h2>SIN VALIDEZ</h2><p>'.e($tipo->nombre).'</p>'
            .'<p>Colaborador: '.e($colaborador->nombreCompleto()).'</p>',
        )->output();

        $archivo = UploadedFile::fake()->createWithContent(str($tipo->clave)->slug().'-demo.pdf', $pdf);

        $storage->subirVersion($colaborador, $tipo, $archivo, $subidoPorId, EstadoDocumento::Aprobado)
            ->update(['reviewed_at' => now()]);
    }
}
