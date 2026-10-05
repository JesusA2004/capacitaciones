<?php

namespace App\Services\Expedientes\MigracionInicial;

use App\Models\EmployeeDocument;
use App\Models\GeneratedDocument;
use App\Models\Sucursal;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Recorre (SOLO LECTURA) los expedientes anteriores al sistema.
 *
 * Origen `sitio` (por defecto): ya están en su ubicación definitiva del
 * disco de expedientes → expedientes/{empresa}/{SUCURSAL}/{CARPETA}/*.pdf.
 * Origen `legacy`: {disco_origen}:{ruta_origen}/{SUCURSAL}/{CARPETA}/**.pdf.
 *
 * Sucursales: WHITELIST (config sucursales_permitidas). Excluidas
 * (Aguascalientes, Tulancingo, Colombia) no se recorren ni se reportan.
 * Cualquier otra carpeta de sucursal (p. ej. «MR. LANA», Pachuca) se
 * reporta como no autorizada y NO se vincula. Nunca se crea una sucursal.
 */
class InventarioNasHistorico
{
    public function enSitio(): bool
    {
        return config('expedientes.migracion_inicial.origen', 'sitio') !== 'legacy';
    }

    public function disco(): Filesystem
    {
        return Storage::disk($this->nombreDisco());
    }

    public function nombreDisco(): string
    {
        return $this->enSitio()
            ? (string) config('expedientes.disk', 'nas')
            : (string) config('expedientes.migracion_inicial.disco_origen', 'nas_legacy');
    }

    public function raiz(): string
    {
        return $this->enSitio()
            ? 'expedientes/'.(string) config('expedientes.migracion_inicial.empresa', 'Mr. Lana')
            : trim((string) config('expedientes.migracion_inicial.ruta_origen'), '/');
    }

    /**
     * @return array{carpetas: list<array<string, mixed>>, sucursales_no_autorizadas: list<array{carpeta: string, expedientes: int}>, origen_existe: bool}
     */
    public function inventario(): array
    {
        $disco = $this->disco();
        $raiz = $this->raiz();

        if (! $disco->exists($raiz)) {
            return ['carpetas' => [], 'sucursales_no_autorizadas' => [], 'origen_existe' => false];
        }

        $carpetas = [];
        $noAutorizadas = [];
        $pendientes = Normalizador::clave((string) config('expedientes.migracion_inicial.carpeta_pendientes', 'Pendientes de vincular'));

        foreach ($disco->directories($raiz) as $rutaSucursal) {
            $nombreSucursal = basename($rutaSucursal);

            if ($this->esSucursalExcluida($nombreSucursal)) {
                continue;
            }

            $sucursal = $this->resolverSucursal($nombreSucursal);

            if ($sucursal === null) {
                $noAutorizadas[] = ['carpeta' => $nombreSucursal, 'expedientes' => count($disco->directories($rutaSucursal))];

                continue;
            }

            foreach ($disco->directories($rutaSucursal) as $rutaCarpeta) {
                if (Normalizador::clave(basename($rutaCarpeta)) === $pendientes) {
                    continue;
                }

                $carpetas[] = $this->carpeta($rutaCarpeta, basename($rutaCarpeta), $nombreSucursal, $sucursal, $this->pdfsDe($rutaCarpeta));
            }

            // PDFs sueltos en la carpeta de la sucursal: cada uno es su propia «carpeta».
            foreach ($disco->files($rutaSucursal) as $suelto) {
                if (strtolower(pathinfo($suelto, PATHINFO_EXTENSION)) === 'pdf' && ! $this->esArchivoDelSistema($suelto)) {
                    $carpetas[] = $this->carpeta($suelto, pathinfo($suelto, PATHINFO_FILENAME), $nombreSucursal, $sucursal, [$suelto]);
                }
            }
        }

        return ['carpetas' => $carpetas, 'sucursales_no_autorizadas' => $noAutorizadas, 'origen_existe' => true];
    }

    /**
     * @param  list<string>  $pdfs
     * @return array<string, mixed>
     */
    private function carpeta(string $ruta, string $nombre, string $nombreSucursal, Sucursal $sucursal, array $pdfs): array
    {
        $disco = $this->disco();

        return [
            'ruta' => $ruta,
            'sucursal_carpeta' => $nombreSucursal,
            'sucursal_id' => $sucursal->id,
            'sucursal_nombre' => $sucursal->nombre,
            'carpeta' => $nombre,
            'tokens' => Normalizador::tokensNombre($nombre),
            'tokens_pdf' => array_map(fn (string $p) => Normalizador::tokensNombre(basename($p)), $pdfs),
            'fecha_en_nombre' => Normalizador::fechaEnNombre($nombre),
            'pdfs' => array_map(fn (string $p) => ['ruta' => $p, 'nombre' => basename($p), 'size' => (int) $disco->size($p)], $pdfs),
        ];
    }

    /**
     * PDFs históricos de una carpeta (o el PDF mismo si la «carpeta» es un
     * archivo suelto). En sitio: solo los del primer nivel y nunca los que el
     * sistema ya registró como documento del checklist o generado.
     *
     * @return list<string>
     */
    public function pdfsDe(string $ruta): array
    {
        $disco = $this->disco();

        if (strtolower(pathinfo($ruta, PATHINFO_EXTENSION)) === 'pdf' && $disco->fileExists($ruta)) {
            return [$ruta];
        }

        $archivos = $this->enSitio() ? $disco->files($ruta) : $disco->allFiles($ruta);
        $pdfs = array_values(array_filter($archivos, fn (string $f) => strtolower(pathinfo($f, PATHINFO_EXTENSION)) === 'pdf' && ! $this->esArchivoDelSistema($f)));
        sort($pdfs, SORT_NATURAL | SORT_FLAG_CASE);

        return $pdfs;
    }

    private function esArchivoDelSistema(string $ruta): bool
    {
        if (! $this->enSitio()) {
            return false;
        }

        return EmployeeDocument::withTrashed()->where('path', $ruta)->exists()
            || GeneratedDocument::query()->where('path', $ruta)->exists();
    }

    /**
     * Sucursal autorizada a partir de un nombre histórico (carpeta o
     * Excel): alias explícito → nombre normalizado, y SOLO si está en la
     * whitelist. Nunca crea sucursales.
     */
    public function resolverSucursal(?string $nombre): ?Sucursal
    {
        if ($nombre === null || trim($nombre) === '') {
            return null;
        }

        $clave = trim((string) preg_replace('/^SUCURSAL /', '', Normalizador::clave($nombre)));
        $alias = collect((array) config('expedientes.migracion_inicial.alias_sucursales', []))
            ->mapWithKeys(fn ($destino, $origen) => [Normalizador::clave((string) $origen) => (string) $destino]);
        $buscado = Normalizador::clave($alias->get($clave, $clave));
        $permitidas = array_map(fn ($s) => Normalizador::clave((string) $s), (array) config('expedientes.migracion_inicial.sucursales_permitidas', []));

        if (! in_array($buscado, $permitidas, true)) {
            return null;
        }

        return $this->sucursales()->first(fn (Sucursal $s) => Normalizador::clave($s->nombre) === $buscado);
    }

    public function esSucursalExcluida(?string $nombre): bool
    {
        $clave = trim((string) preg_replace('/^SUCURSAL /', '', Normalizador::clave($nombre)));

        return in_array($clave, array_map(fn ($s) => Normalizador::clave((string) $s), (array) config('expedientes.migracion_inicial.sucursales_excluidas', [])), true);
    }

    /** @var Collection<int, Sucursal>|null */
    private ?Collection $cache = null;

    /**
     * @return Collection<int, Sucursal>
     */
    private function sucursales(): Collection
    {
        return $this->cache ??= Sucursal::query()->with('empresa:id,nombre')->get();
    }
}
