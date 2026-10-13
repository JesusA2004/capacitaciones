<?php

namespace App\Services\Puestos\Descriptivos;

use App\Models\DescriptivoPuesto;
use App\Models\PerfilPuesto;
use App\Models\Puesto;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Descriptivos de puesto OFICIALES:
 *
 *   PDF oficial → capa de texto (LectorDescriptivoPdf) → estructura →
 *   puesto(s) PEOPLE → se guarda la versión → se muestra en el módulo.
 *
 * - El PDF se conserva EXACTO (copia byte a byte en el NAS); nunca se
 *   edita, regenera ni convierte.
 * - Idempotente por SHA-256: el mismo archivo no se duplica. Un PDF
 *   distinto del mismo descriptivo crea la versión siguiente.
 * - Asociación: nombre exacto → alias de config/descriptivos.php → RH
 *   confirma. Nunca por parecido ni en silencio.
 * - El perfil del puesto se precarga como BORRADOR (origen «documento»)
 *   solo con lo que el PDF trae; si RH ya lo ajustó, no se toca.
 * - El organigrama sigue siendo la jerarquía oficial: si el «Jefe
 *   inmediato» del PDF no coincide, solo se advierte.
 */
class DescriptivoPuestoService
{
    public function __construct(
        private readonly LectorDescriptivoPdf $lector,
        private readonly AuditoriaService $auditoria,
    ) {}

    /**
     * Lee un PDF sin guardar nada: qué se detectó y a qué puesto(s) se
     * asociaría.
     *
     * @return array<string, mixed>
     */
    public function analizar(string $pdf, string $nombreArchivo): array
    {
        $sha = hash('sha256', $pdf);
        $existente = DescriptivoPuesto::query()->where('sha256', $sha)->with('puestos:id,nombre')->first();
        $datos = $this->lector->leer($pdf);

        if ($datos['requiere_ocr'] ?? false) {
            return [
                'archivo' => $nombreArchivo, 'sha256' => $sha, 'estado' => 'requiere_revision',
                'mensaje' => 'Este archivo necesita revisión manual / OCR: no tiene capa de texto.',
                'datos' => null, 'detectado' => [], 'sugeridos' => [], 'paginas' => 0, 'importado' => null,
            ];
        }

        $datos['metadatos'] = $this->lector->metadatos($pdf);
        $sugeridos = $this->puestosSugeridos((string) ($datos['nombre_puesto'] ?? ''));

        return [
            'archivo' => $nombreArchivo,
            'sha256' => $sha,
            'paginas' => (int) $datos['paginas'],
            'datos' => $datos,
            'detectado' => $this->resumen($datos),
            'sugeridos' => $sugeridos,
            'estado' => $existente !== null ? 'ya_importado' : ($sugeridos === [] ? 'confirmar_puesto' : 'listo'),
            'importado' => $existente !== null ? [
                'id' => $existente->id,
                'version' => $existente->version,
                'puestos' => array_values($existente->puestos->pluck('nombre')->all()),
            ] : null,
        ];
    }

    /**
     * Importa (o reutiliza) una versión y la asocia a los puestos dados.
     *
     * @param  list<int>  $puestoIds
     */
    public function importar(string $pdf, string $nombreArchivo, array $puestoIds, ?User $actor): DescriptivoPuesto
    {
        $puestos = Puesto::query()->whereIn('id', $puestoIds)->get();

        if ($puestos->isEmpty()) {
            throw ValidationException::withMessages(['puestos' => 'Elige a qué puesto(s) corresponde este descriptivo.']);
        }

        $analisis = $this->analizar($pdf, $nombreArchivo);

        if ($analisis['datos'] === null) {
            throw ValidationException::withMessages(['archivo' => (string) $analisis['mensaje']]);
        }

        $datos = $analisis['datos'];
        $sha = (string) $analisis['sha256'];

        $descriptivo = DB::transaction(function () use ($pdf, $nombreArchivo, $datos, $sha, $puestos, $actor): DescriptivoPuesto {
            $descriptivo = DescriptivoPuesto::query()->where('sha256', $sha)->first();

            if ($descriptivo === null) {
                $clave = Str::slug((string) ($datos['nombre_puesto'] ?? pathinfo($nombreArchivo, PATHINFO_FILENAME)));
                $version = (int) DescriptivoPuesto::query()->where('clave', $clave)->max('version') + 1;
                $disco = (string) config('descriptivos.disk', 'nas');
                $ruta = sprintf('descriptivos/%s/v%d-%s.pdf', $clave, $version, substr($sha, 0, 12));

                // Copia EXACTA del original (sin conversión).
                Storage::disk($disco)->put($ruta, $pdf);

                $descriptivo = DescriptivoPuesto::query()->create([
                    'clave' => $clave,
                    'nombre_puesto' => (string) ($datos['nombre_puesto'] ?? $nombreArchivo),
                    'version' => $version,
                    'archivo_nombre' => $nombreArchivo,
                    'disk' => $disco,
                    'path' => $ruta,
                    'sha256' => $sha,
                    'paginas' => (int) $datos['paginas'],
                    'datos' => $this->sinControl($datos),
                    'importado_por' => $actor?->id,
                ]);
            }

            $descriptivo->puestos()->syncWithoutDetaching($puestos->pluck('id')->all());
            $advertencias = [];

            foreach ($puestos as $puesto) {
                $advertencias = [...$advertencias, ...$this->revisarJerarquia($descriptivo, $puesto)];
                $advertencia = $this->precargarPerfil($descriptivo, $puesto, $actor);
                if ($advertencia !== null) {
                    $advertencias[] = $advertencia;
                }
            }

            $descriptivo->update(['advertencias' => array_values(array_unique($advertencias))]);

            return $descriptivo;
        });

        $this->auditoria->registrar('descriptivo_puesto_importado', $descriptivo, $actor, [
            'descriptivo_id' => $descriptivo->id, 'version' => $descriptivo->version, 'puestos' => $puestos->pluck('id')->all(),
        ]);

        return $descriptivo;
    }

    /**
     * Importa la carpeta (comando). Solo asocia por nombre exacto o alias;
     * lo demás queda para que RH lo confirme en pantalla.
     *
     * @return list<array<string, mixed>>
     */
    public function importarCarpeta(?string $carpeta, ?User $actor, bool $simular = false): array
    {
        $reporte = [];

        foreach ($this->archivosCarpeta($carpeta) as $ruta) {
            $pdf = (string) file_get_contents($ruta);
            $analisis = $this->analizar($pdf, basename($ruta));
            $fila = [
                'archivo' => basename($ruta),
                'puesto_detectado' => $analisis['datos']['nombre_puesto'] ?? null,
                'puestos' => array_map(fn ($p) => $p['nombre'], $analisis['sugeridos']),
                'paginas' => $analisis['paginas'],
                'funciones' => count($analisis['datos']['funciones'] ?? []),
                'secciones' => array_keys(array_filter($analisis['detectado'])),
                'estado' => $analisis['estado'],
            ];

            if (! $simular && $analisis['estado'] === 'listo') {
                $descriptivo = $this->importar($pdf, basename($ruta), array_values(array_map(fn ($p) => (int) $p['id'], $analisis['sugeridos'])), $actor);
                $fila['estado'] = 'importado';
                $fila['version'] = $descriptivo->version;
                $fila['advertencias'] = $descriptivo->advertencias ?? [];
            }

            $reporte[] = $fila;
        }

        return $reporte;
    }

    /**
     * @return list<string>
     */
    public function archivosCarpeta(?string $carpeta = null): array
    {
        $carpeta ??= (string) config('descriptivos.carpeta');
        $archivos = glob(rtrim($carpeta, '/\\').DIRECTORY_SEPARATOR.'*.pdf') ?: [];
        sort($archivos);

        return $archivos;
    }

    /**
     * Descriptivo vigente (última versión) de un puesto, para la lectura.
     *
     * @return array<string, mixed>|null
     */
    public function paraPuesto(Puesto $puesto): ?array
    {
        $descriptivo = DescriptivoPuesto::query()
            ->whereHas('puestos', fn ($q) => $q->whereKey($puesto->id))
            ->orderByDesc('version')->orderByDesc('id')
            ->first();

        if ($descriptivo === null) {
            return null;
        }

        $versiones = DescriptivoPuesto::query()->where('clave', $descriptivo->clave)->count();

        return [
            'id' => $descriptivo->id,
            'version' => $descriptivo->version,
            'versiones' => $versiones,
            'archivo' => $descriptivo->archivo_nombre,
            'paginas' => $descriptivo->paginas,
            'fecha' => $descriptivo->datos['metadatos']['fecha_revision'] ?? ($descriptivo->datos['metadatos']['fecha_elaboracion'] ?? null),
            'importado_en' => $descriptivo->created_at?->toIso8601String(),
            'datos' => $descriptivo->datos,
            'advertencias' => $descriptivo->advertencias ?? [],
        ];
    }

    public function respuestaPdf(DescriptivoPuesto $descriptivo, bool $descargar): StreamedResponse
    {
        $disco = Storage::disk($descriptivo->disk);
        abort_unless($disco->exists($descriptivo->path), 404, 'El PDF original no está disponible en el almacenamiento.');

        /** @var FilesystemAdapter $disco */
        return $disco->response($descriptivo->path, $descriptivo->archivo_nombre, [
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
        ], $descargar ? 'attachment' : 'inline');
    }

    /**
     * Puestos PEOPLE: nombre exacto primero, luego alias explícito.
     *
     * @return list<array{id: int, nombre: string, por: string}>
     */
    public function puestosSugeridos(string $nombrePdf): array
    {
        $normal = fn (string $t): string => Str::lower(Str::ascii(trim($t)));
        $activos = Puesto::query()->where('activo', true)->orderBy('nombre')->get(['id', 'nombre']);

        $exactos = $activos->filter(fn (Puesto $p) => $normal($p->nombre) === $normal($nombrePdf));
        if ($exactos->isNotEmpty()) {
            return array_values($exactos->map(fn (Puesto $p) => ['id' => $p->id, 'nombre' => $p->nombre, 'por' => 'exacto'])->all());
        }

        foreach ((array) config('descriptivos.alias', []) as $pdf => $patrones) {
            if ($normal((string) $pdf) !== $normal($nombrePdf)) {
                continue;
            }

            $encontrados = [];
            foreach ((array) $patrones as $patron) {
                $regex = '/^'.str_replace('%', '.*', preg_quote($normal((string) $patron), '/')).'$/';
                foreach ($activos as $p) {
                    if (preg_match($regex, $normal($p->nombre)) === 1) {
                        $encontrados[$p->id] = ['id' => $p->id, 'nombre' => $p->nombre, 'por' => 'alias'];
                    }
                }
            }

            return array_values($encontrados);
        }

        return [];
    }

    /**
     * Qué secciones trae el PDF (para la pantalla de importación).
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, int|bool>
     */
    private function resumen(array $datos): array
    {
        return [
            'area' => filled($datos['area'] ?? null),
            'jefe_inmediato' => filled($datos['jefe_inmediato'] ?? null),
            'formacion' => filled($datos['formacion']['minima'] ?? null),
            'experiencia' => filled($datos['experiencia']['minima'] ?? null),
            'objetivo' => filled($datos['objetivo'] ?? null),
            'funciones' => count($datos['funciones'] ?? []),
            'conocimientos' => count($datos['conocimientos'] ?? []),
            'competencias' => count($datos['competencias'] ?? []),
            'actividades_criticas' => count($datos['actividades_criticas'] ?? []),
            'facultades' => count($datos['facultades'] ?? []),
            'plan_formacion' => count($datos['plan_formacion'] ?? []),
            'compromisos_culturales' => count($datos['compromisos_culturales'] ?? []),
        ];
    }

    /**
     * @return list<string>
     */
    private function revisarJerarquia(DescriptivoPuesto $descriptivo, Puesto $puesto): array
    {
        $jefePdf = (string) ($descriptivo->datos['jefe_inmediato'] ?? '');
        $superior = $puesto->puestoSuperior()->value('nombre');

        if ($jefePdf === '' || $superior === null) {
            return [];
        }

        $normal = fn (string $t): string => Str::lower(Str::ascii(trim($t)));
        // Coincide si el puesto superior real es el jefe del PDF o uno de sus alias.
        $equivalentes = array_map($normal, [$jefePdf, ...array_map(fn ($p) => $p['nombre'], $this->puestosSugeridos($jefePdf))]);
        $equivalentes[] = $normal((string) preg_replace('/^Director\b/iu', 'Dirección', $jefePdf));

        if (in_array($normal((string) $superior), $equivalentes, true)) {
            return [];
        }

        return [sprintf('Revisar jerarquía de %s: el PDF dice que su jefe inmediato es «%s»; el organigrama de PEOPLE dice «%s». No se cambió nada.', $puesto->nombre, $jefePdf, $superior)];
    }

    /**
     * Precarga el perfil del puesto (borrador «documento») con lo que el
     * PDF trae. Si RH ya lo ajustó, no se toca y se avisa.
     */
    private function precargarPerfil(DescriptivoPuesto $descriptivo, Puesto $puesto, ?User $actor): ?string
    {
        $perfil = PerfilPuesto::query()->firstOrNew(['puesto_id' => $puesto->id]);

        if ($perfil->exists && $perfil->origen === 'rh') {
            return sprintf('%s: RH ya ajustó su información; se conservó (el PDF v%d queda como documento oficial).', $puesto->nombre, $descriptivo->version);
        }

        $d = $descriptivo->datos;
        $experiencia = array_filter([
            filled($d['experiencia']['minima'] ?? null) ? 'Mínima: '.$d['experiencia']['minima'] : null,
            filled($d['experiencia']['deseable'] ?? null) ? 'Deseable: '.$d['experiencia']['deseable'] : null,
            ($d['experiencia']['puestos_referencia'] ?? []) !== [] ? 'Puesto de referencia: '.implode(', ', $d['experiencia']['puestos_referencia']) : null,
        ]);

        $perfil->fill([
            'origen' => 'documento',
            'descriptivo_puesto_id' => $descriptivo->id,
            'objetivo' => $d['objetivo'] ?? null,
            'funciones' => array_values($d['funciones'] ?? []),
            'escolaridad' => $d['formacion']['minima'] ?? null,
            'experiencia' => $experiencia !== [] ? implode(' · ', $experiencia) : null,
            'conocimientos' => array_values(array_map(fn ($c) => (string) $c['conocimiento'], $d['conocimientos'] ?? [])),
            'competencias' => array_values(array_map(fn ($c) => (string) $c['competencia'], $d['competencias'] ?? [])),
            'actualizado_por' => $actor?->id,
        ]);
        foreach (['responsabilidades', 'indicadores', 'habilidades', 'valores', 'requisitos'] as $lista) {
            $perfil->setAttribute($lista, $perfil->getAttribute($lista) ?? []);
        }
        $perfil->save();

        return null;
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function sinControl(array $datos): array
    {
        unset($datos['requiere_ocr']);

        return $datos;
    }
}
