<?php

namespace App\Services\Colaboradores;

use App\Enums\CategoriaDocumento;
use App\Enums\EstadoUsuario;
use App\Enums\EstatusImss;
use App\Models\Colaborador;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Expedientes\DocumentoStorageService;
use App\Support\FechaMexico;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Acuse de alta ante el IMSS: al concluir el alta, RH sube el archivo y el
 * expediente lo muestra en VERDE; mientras falte, en NARANJA. Así se ve de
 * un vistazo quién ya lo tiene y quién no — sin abrir un pendiente en la
 * bandeja (es un indicador, no una tarea).
 *
 * Reemplazar el acuse nunca borra el anterior: se guarda uno nuevo en el
 * expediente y se apunta a él.
 */
class AltaImssService
{
    public const REGISTRADA = 'registrada';

    public const PENDIENTE = 'pendiente';

    public function __construct(
        private readonly DocumentoStorageService $storage,
        private readonly AuditoriaService $auditoria,
    ) {}

    /**
     * `registrada` (verde) | `pendiente` (naranja) | null (aún no aplica:
     * la persona no ha concluido su alta).
     */
    public function indicador(Colaborador $colaborador): ?string
    {
        if ($colaborador->alta_imss_path !== null) {
            return self::REGISTRADA;
        }

        return $colaborador->estatus === EstadoUsuario::Activo ? self::PENDIENTE : null;
    }

    public function registrar(Colaborador $colaborador, UploadedFile $archivo, User $actor): Colaborador
    {
        $extension = strtolower($archivo->getClientOriginalExtension() ?: ($archivo->guessExtension() ?? 'pdf'));
        $ruta = $this->storage->guardarContenidoEnExpediente(
            $colaborador,
            CategoriaDocumento::Contratos,
            sprintf('Acuse alta IMSS.%s', $extension),
            (string) file_get_contents($archivo->getRealPath()),
        );

        DB::transaction(function () use ($colaborador, $archivo, $actor, $ruta): void {
            $colaborador->forceFill([
                'alta_imss_path' => $ruta,
                'alta_imss_nombre' => mb_substr($archivo->getClientOriginalName(), 0, 255),
                'alta_imss_subido_en' => now(),
                'alta_imss_subido_por' => $actor->id,
                'estatus_imss' => EstatusImss::ConImss,
                // La fecha de alta capturada por RH se respeta; si no había, hoy
                // (día calendario de México, no UTC: ver FechaMexico y P1-28).
                'fecha_alta_imss' => $colaborador->fecha_alta_imss ?? FechaMexico::hoy(),
            ])->save();
        });

        $this->auditoria->registrar('alta_imss_registrada', $colaborador, $actor, ['colaborador_id' => $colaborador->id]);

        return $colaborador->refresh();
    }

    public function respuesta(Colaborador $colaborador): StreamedResponse
    {
        abort_if($colaborador->alta_imss_path === null, 404, 'Este colaborador aún no tiene acuse de alta IMSS.');

        return $this->storage->respuesta($colaborador->alta_imss_path, [
            'Content-Disposition' => sprintf('inline; filename="%s"', str_replace('"', '', $colaborador->alta_imss_nombre ?? 'acuse-alta-imss.pdf')),
        ]);
    }
}
