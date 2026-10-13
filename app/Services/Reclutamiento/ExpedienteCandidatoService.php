<?php

namespace App\Services\Reclutamiento;

use App\Enums\TipoEvidenciaCandidato;
use App\Enums\TipoSeguimientoCandidato;
use App\Models\Candidato;
use App\Models\CandidatoEvidencia;
use App\Models\CandidatoPsicometrica;
use App\Models\CandidatoReferencia;
use App\Models\CandidatoSocioeconomico;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Mini expediente del candidato ANTES de la contratación: CV, resultados
 * psicométricos, videos/fotografías del estudio socioeconómico y soporte de
 * referencias. Todo vive en el NAS (Synology, disco config('reclutamiento.disk'))
 * en una carpeta legible por candidato:
 *
 *   candidatos/{id}/cv/
 *   candidatos/{id}/psicometricos/
 *   candidatos/{id}/socioeconomico/videos/
 *   candidatos/{id}/socioeconomico/fotos/
 *   candidatos/{id}/socioeconomico/documentos/
 *   candidatos/{id}/referencias/
 *
 * Disco y ruta nunca salen al cliente: la descarga pasa por un controlador
 * con Policy + alcance (Dirección Comercial, RH y administrativos con
 * `candidatos.ver_todos`; gerentes solo los de su alcance).
 */
class ExpedienteCandidatoService
{
    public const ETAPA_SOCIOECONOMICO = 'socioeconomico';

    public const ETAPA_PSICOMETRICAS = 'psicometricas';

    public const ETAPA_REFERENCIAS = 'referencias';

    public const ETAPAS = [self::ETAPA_SOCIOECONOMICO, self::ETAPA_PSICOMETRICAS, self::ETAPA_REFERENCIAS];

    public function __construct(
        private readonly CvStorageService $almacenamiento,
        private readonly AuditoriaService $auditoria,
    ) {}

    public function carpetaCandidato(int $candidatoId): string
    {
        return sprintf('candidatos/%d', $candidatoId);
    }

    /**
     * Carpeta del NAS de una evidencia según su etapa y tipo de archivo.
     */
    public function carpetaEvidencia(int $candidatoId, string $etapa, TipoEvidenciaCandidato $tipo): string
    {
        $base = $this->carpetaCandidato($candidatoId);

        return match ($etapa) {
            self::ETAPA_SOCIOECONOMICO => sprintf('%s/socioeconomico/%s', $base, match ($tipo) {
                TipoEvidenciaCandidato::Video => 'videos',
                TipoEvidenciaCandidato::Fotografia => 'fotos',
                TipoEvidenciaCandidato::Documento => 'documentos',
            }),
            self::ETAPA_PSICOMETRICAS => sprintf('%s/psicometricos', $base),
            self::ETAPA_REFERENCIAS => sprintf('%s/referencias', $base),
            default => sprintf('%s/otros', $base),
        };
    }

    public function etapaDe(Model $evidenciable): string
    {
        return match (true) {
            $evidenciable instanceof CandidatoSocioeconomico => self::ETAPA_SOCIOECONOMICO,
            $evidenciable instanceof CandidatoPsicometrica => self::ETAPA_PSICOMETRICAS,
            $evidenciable instanceof CandidatoReferencia => self::ETAPA_REFERENCIAS,
            default => 'otros',
        };
    }

    /**
     * CV del candidato (fase inicial). Reemplaza el anterior, si lo había.
     */
    public function guardarCv(Candidato $candidato, UploadedFile $archivo, ?User $actor): Candidato
    {
        $anterior = $candidato->cv_path;
        $ruta = sprintf('%s/cv/%s', $this->carpetaCandidato($candidato->id), $this->almacenamiento->nombreInterno($archivo->getClientOriginalName()));
        $this->almacenamiento->guardar($archivo, $ruta);

        $candidato->update([
            'cv_disk' => config('reclutamiento.disk'),
            'cv_path' => $ruta,
            'cv_original_name' => $archivo->getClientOriginalName(),
            'cv_mime' => $archivo->getMimeType() ?? $archivo->getClientMimeType(),
            'cv_size' => $archivo->getSize(),
        ]);

        if ($anterior !== null && $anterior !== $ruta) {
            $this->almacenamiento->eliminar($anterior);
        }

        if ($actor !== null) {
            $this->auditoria->registrar('candidato_cv_cargado', $candidato, $actor, ['candidato_id' => $candidato->id]);
        }

        return $candidato;
    }

    /**
     * Guarda archivos ligados a un registro de etapa (socioeconómico,
     * psicométricas o referencia), cada uno en la carpeta de su etapa.
     *
     * @param  list<UploadedFile>  $archivos
     * @return list<CandidatoEvidencia>
     */
    public function guardarEvidencias(Candidato $candidato, Model $evidenciable, array $archivos, User $actor): array
    {
        $etapa = $this->etapaDe($evidenciable);
        $guardadas = [];

        foreach ($archivos as $archivo) {
            $mime = $archivo->getMimeType();
            $tipo = TipoEvidenciaCandidato::desdeMime($mime);
            $extension = strtolower($archivo->guessExtension() ?? $archivo->getClientOriginalExtension());
            $ruta = sprintf('%s/%s.%s', $this->carpetaEvidencia($candidato->id, $etapa, $tipo), Str::uuid(), $extension);
            $this->almacenamiento->guardar($archivo, $ruta);

            $guardadas[] = CandidatoEvidencia::query()->create([
                'candidato_id' => $candidato->id,
                'evidenciable_type' => $evidenciable->getMorphClass(),
                'evidenciable_id' => $evidenciable->getKey(),
                'tipo' => $tipo,
                'disk' => (string) config('reclutamiento.disk'),
                'path' => $ruta,
                'original_name' => $archivo->getClientOriginalName(),
                'mime' => $mime,
                'size' => $archivo->getSize(),
                'checksum' => hash_file('sha256', (string) $archivo->getRealPath()) ?: null,
                'subida_por' => $actor->id,
            ]);
        }

        return $guardadas;
    }

    /**
     * Agrega archivos DESPUÉS de registrar la etapa (p. ej. más videos del
     * socioeconómico o el PDF de psicométricos que llegó tarde). No cambia
     * el estado del candidato; queda auditado y en su seguimiento.
     *
     * @param  list<UploadedFile>  $archivos
     * @return list<CandidatoEvidencia>
     */
    public function agregarEvidencias(Candidato $candidato, User $actor, string $etapa, array $archivos, ?int $registroId = null): array
    {
        if (! $actor->can('adjuntar', $candidato)) {
            throw new AuthorizationException('No tienes permiso para agregar archivos a este candidato.');
        }

        $registro = $this->registroDeEtapa($candidato, $etapa, $registroId);

        $guardadas = DB::transaction(function () use ($candidato, $actor, $registro, $archivos, $etapa): array {
            $guardadas = $this->guardarEvidencias($candidato, $registro, $archivos, $actor);

            $candidato->seguimientos()->create([
                'tipo' => TipoSeguimientoCandidato::Nota,
                'nota' => sprintf('%s: se %s %d archivo(s) al expediente del candidato.', $this->tituloEtapa($etapa), count($guardadas) === 1 ? 'agregó' : 'agregaron', count($guardadas)),
                'fecha' => now(),
                'registrado_por' => $actor->id,
            ]);

            return $guardadas;
        });

        $this->auditoria->registrar('candidato_evidencias_agregadas', $candidato, $actor, [
            'candidato_id' => $candidato->id,
            'etapa' => $etapa,
            'evidencias' => array_map(fn (CandidatoEvidencia $e): int => $e->id, $guardadas),
        ]);

        return $guardadas;
    }

    public function tituloEtapa(string $etapa): string
    {
        return match ($etapa) {
            self::ETAPA_SOCIOECONOMICO => 'Estudio socioeconómico',
            self::ETAPA_PSICOMETRICAS => 'Psicométricos',
            self::ETAPA_REFERENCIAS => 'Referencias',
            default => 'Otros',
        };
    }

    /**
     * Resumen del mini expediente: CV + archivos agrupados como en el NAS.
     *
     * @return array{cv: array{nombre: string, tamano: int|null, subido_en: string|null}|null, grupos: list<array{clave: string, titulo: string, etapa: string, archivos: list<array<string, mixed>>}>, total: int}
     */
    public function resumen(Candidato $candidato): array
    {
        $evidencias = $candidato->relationLoaded('evidencias') ? $candidato->evidencias : $candidato->evidencias()->get();
        $morfologia = [
            (new CandidatoSocioeconomico)->getMorphClass() => self::ETAPA_SOCIOECONOMICO,
            (new CandidatoPsicometrica)->getMorphClass() => self::ETAPA_PSICOMETRICAS,
            (new CandidatoReferencia)->getMorphClass() => self::ETAPA_REFERENCIAS,
        ];
        $grupos = [
            'socioeconomico_videos' => ['titulo' => 'Videos del estudio socioeconómico', 'etapa' => self::ETAPA_SOCIOECONOMICO],
            'socioeconomico_fotos' => ['titulo' => 'Fotografías del estudio socioeconómico', 'etapa' => self::ETAPA_SOCIOECONOMICO],
            'socioeconomico_documentos' => ['titulo' => 'Documentos del estudio socioeconómico', 'etapa' => self::ETAPA_SOCIOECONOMICO],
            'psicometricas' => ['titulo' => 'Resultados psicométricos', 'etapa' => self::ETAPA_PSICOMETRICAS],
            'referencias' => ['titulo' => 'Soporte de referencias', 'etapa' => self::ETAPA_REFERENCIAS],
            'otros' => ['titulo' => 'Otros archivos', 'etapa' => 'otros'],
        ];
        $archivos = array_fill_keys(array_keys($grupos), []);

        foreach ($evidencias as $evidencia) {
            $etapa = $morfologia[(string) $evidencia->evidenciable_type] ?? 'otros';
            $clave = $etapa === self::ETAPA_SOCIOECONOMICO
                ? match ($evidencia->tipo) {
                    TipoEvidenciaCandidato::Video => 'socioeconomico_videos',
                    TipoEvidenciaCandidato::Fotografia => 'socioeconomico_fotos',
                    TipoEvidenciaCandidato::Documento => 'socioeconomico_documentos',
                }
            : $etapa;
            $archivos[$clave][] = $this->evidencia($evidencia);
        }

        $salida = [];

        foreach ($grupos as $clave => $grupo) {
            if ($archivos[$clave] !== [] || in_array($clave, ['socioeconomico_videos', 'psicometricas', 'referencias'], true)) {
                $salida[] = ['clave' => $clave, 'titulo' => $grupo['titulo'], 'etapa' => $grupo['etapa'], 'archivos' => $archivos[$clave]];
            }
        }

        return [
            'cv' => $candidato->cv_path !== null ? [
                'nombre' => (string) $candidato->cv_original_name,
                'tamano' => $candidato->cv_size,
                'subido_en' => $candidato->updated_at?->toIso8601String(),
            ] : null,
            'grupos' => $salida,
            'total' => $evidencias->count() + ($candidato->cv_path !== null ? 1 : 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function evidencia(CandidatoEvidencia $evidencia): array
    {
        return [
            'id' => $evidencia->id,
            'tipo' => $evidencia->tipo->value,
            'tipo_etiqueta' => $evidencia->tipo->etiqueta(),
            'nombre' => $evidencia->original_name,
            'mime' => $evidencia->mime,
            'tamano' => $evidencia->size,
            'subida_en' => $evidencia->created_at?->toIso8601String(),
        ];
    }

    private function registroDeEtapa(Candidato $candidato, string $etapa, ?int $registroId): Model
    {
        $consulta = match ($etapa) {
            self::ETAPA_SOCIOECONOMICO => CandidatoSocioeconomico::query(),
            self::ETAPA_PSICOMETRICAS => CandidatoPsicometrica::query(),
            self::ETAPA_REFERENCIAS => CandidatoReferencia::query(),
            default => throw ValidationException::withMessages(['etapa' => 'Elige a qué etapa pertenecen los archivos.']),
        };

        $registro = $consulta->where('candidato_id', $candidato->id)
            ->when($registroId !== null, fn ($q) => $q->whereKey($registroId))
            ->latest('id')
            ->first();

        if ($registro !== null) {
            return $registro;
        }

        // Psicométricos: el resultado puede llegar antes de "registrar
        // resultados"; se abre el registro para no perder el archivo.
        if ($etapa === self::ETAPA_PSICOMETRICAS && $registroId === null) {
            return CandidatoPsicometrica::query()->create(['candidato_id' => $candidato->id]);
        }

        throw ValidationException::withMessages(['etapa' => match ($etapa) {
            self::ETAPA_SOCIOECONOMICO => 'Primero registra el estudio socioeconómico; después puedes agregarle más videos o fotografías.',
            default => 'Primero registra la referencia; después puedes adjuntarle su soporte.',
        }]);
    }
}
