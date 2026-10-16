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
use App\Services\Expedientes\DocumentoStorageService;
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
 * en una carpeta con el nombre del candidato, aparte de `expedientes/` (el
 * expediente del colaborador se arma hasta que se contrata):
 *
 *   candidatos/{Nombre Apellidos} - {id}/cv/
 *   candidatos/{Nombre Apellidos} - {id}/psicometricos/
 *   candidatos/{Nombre Apellidos} - {id}/socioeconomico/videos/
 *   candidatos/{Nombre Apellidos} - {id}/socioeconomico/fotos/
 *   candidatos/{Nombre Apellidos} - {id}/socioeconomico/documentos/
 *   candidatos/{Nombre Apellidos} - {id}/referencias/
 *
 * El id resuelve homónimos. La carpeta se fija (`carpeta_nas`) con el primer
 * archivo: corregir el nombre después no parte el mini expediente en dos.
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
        private readonly DocumentoStorageService $documentos,
    ) {}

    /**
     * Carpeta calculada con el nombre actual: `candidatos/{Nombre Apellidos} - {id}`.
     */
    public function carpetaConNombre(Candidato $candidato): string
    {
        return sprintf('candidatos/%s', $this->documentos->sanitizarSegmento(sprintf('%s - %d', $candidato->nombreCompleto(), $candidato->id)));
    }

    /**
     * Carpeta del mini expediente: la ya fijada o, si todavía no tiene, la
     * calcula con su nombre y la guarda para los archivos siguientes.
     */
    public function carpetaCandidato(Candidato $candidato): string
    {
        $actual = $candidato->carpeta_nas;

        if ($actual !== null && trim($actual) !== '') {
            return $actual;
        }

        $carpeta = $this->carpetaConNombre($candidato);
        $candidato->forceFill(['carpeta_nas' => $carpeta])->save();

        return $carpeta;
    }

    /**
     * Mueve los archivos de un candidato que siguen en la carpeta antigua
     * por número (`candidatos/{id}/...`) a su carpeta con nombre, y actualiza
     * las rutas guardadas. Idempotente: lo que ya está en la carpeta con
     * nombre no se toca. Un archivo que no aparece en el NAS se reporta
     * (`faltantes`); un movimiento que el NAS rechaza o que no deja el
     * archivo en su destino se reporta aparte (`fallidos`) y NO toca la BD
     * (la ruta guardada sigue apuntando al archivo original) — nunca se
     * omite en silencio ni se da por movido lo que no se movió.
     *
     * @return array{carpeta: string, movidos: int, faltantes: list<string>, fallidos: list<string>}
     */
    public function moverACarpetaConNombre(Candidato $candidato, bool $simular = false): array
    {
        $disco = $this->almacenamiento->disco();
        $antigua = sprintf('candidatos/%d/', $candidato->id);
        $carpeta = $simular ? ($candidato->carpeta_nas ?: $this->carpetaConNombre($candidato)) : $this->carpetaCandidato($candidato);
        $movidos = 0;
        $faltantes = [];
        $fallidos = [];

        $mover = function (string $ruta) use ($disco, $antigua, $carpeta, $simular, &$movidos, &$faltantes, &$fallidos): ?string {
            if (! str_starts_with($ruta, $antigua) || $antigua === $carpeta.'/') {
                return null;
            }

            if (! $disco->exists($ruta)) {
                $faltantes[] = $ruta;

                return null;
            }

            $nueva = $carpeta.'/'.substr($ruta, strlen($antigua));

            if ($simular) {
                $movidos++;

                return $nueva;
            }

            // Solo se da por movido (y se actualiza la BD) si el NAS confirmó
            // el move Y el archivo quedó realmente en su destino: un move que
            // falla o un destino que no aparece deja la ruta original intacta.
            if ($disco->move($ruta, $nueva) === false || ! $disco->exists($nueva)) {
                $fallidos[] = $ruta;

                return null;
            }

            $movidos++;

            return $nueva;
        };

        if ($candidato->cv_path !== null && ($nueva = $mover($candidato->cv_path)) !== null && ! $simular) {
            $candidato->forceFill(['cv_path' => $nueva])->save();
        }

        CandidatoEvidencia::query()->where('candidato_id', $candidato->id)->each(function (CandidatoEvidencia $evidencia) use ($mover, $simular): void {
            if (($nueva = $mover($evidencia->path)) !== null && ! $simular) {
                $evidencia->forceFill(['path' => $nueva])->save();
            }
        });

        return ['carpeta' => $carpeta, 'movidos' => $movidos, 'faltantes' => $faltantes, 'fallidos' => $fallidos];
    }

    /**
     * Carpeta del NAS de una evidencia según su etapa y tipo de archivo.
     */
    public function carpetaEvidencia(Candidato $candidato, string $etapa, TipoEvidenciaCandidato $tipo): string
    {
        $base = $this->carpetaCandidato($candidato);

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
        $ruta = sprintf('%s/cv/%s', $this->carpetaCandidato($candidato), $this->almacenamiento->nombreInterno($archivo->getClientOriginalName()));
        $this->almacenamiento->guardar($archivo, $ruta);

        return $this->registrarCv($candidato, $ruta, $archivo->getClientOriginalName(), $archivo->getMimeType() ?? $archivo->getClientMimeType(), (int) $archivo->getSize(), $actor);
    }

    /**
     * Mismo resultado que guardarCv(), pero el archivo llega como flujo (la
     * bandeja de CVs del NAS, ver BandejaCvService) en vez de un upload.
     *
     * @param  resource  $flujo
     */
    public function guardarCvDesdeFlujo(Candidato $candidato, $flujo, string $nombreOriginal, string $mime, int $tamano, ?User $actor): Candidato
    {
        $ruta = sprintf('%s/cv/%s', $this->carpetaCandidato($candidato), $this->almacenamiento->nombreInterno($nombreOriginal));
        $this->almacenamiento->guardarFlujo($flujo, $ruta);

        return $this->registrarCv($candidato, $ruta, $nombreOriginal, $mime, $tamano, $actor);
    }

    private function registrarCv(Candidato $candidato, string $ruta, string $nombreOriginal, string $mime, int $tamano, ?User $actor): Candidato
    {
        $anterior = $candidato->cv_path;

        $candidato->update([
            'cv_disk' => config('reclutamiento.disk'),
            'cv_path' => $ruta,
            'cv_original_name' => $nombreOriginal,
            'cv_mime' => $mime,
            'cv_size' => $tamano,
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
            $ruta = sprintf('%s/%s.%s', $this->carpetaEvidencia($candidato, $etapa, $tipo), Str::uuid(), $extension);
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
