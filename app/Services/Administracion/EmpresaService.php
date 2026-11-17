<?php

namespace App\Services\Administracion;

use App\Models\Empresa;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Alta y edición de empresas con su logo.
 *
 * El logo es una imagen ligera de marca (no un documento laboral pesado):
 * se guarda en el disco `public`, no en el NAS reservado para expedientes y
 * documentos (ver App\Services\Expedientes\DocumentoStorageService).
 */
class EmpresaService
{
    private const DISCO = 'public';

    private const CARPETA_LOGOS = 'empresas/logos';

    /**
     * @param  array<string, mixed>  $datos  Campos validados (sin el archivo).
     */
    public function crear(array $datos, ?UploadedFile $logo): Empresa
    {
        if ($logo !== null) {
            $datos['logo_path'] = $this->guardarLogo($logo);
        }

        return Empresa::query()->create($datos);
    }

    /**
     * Un logo nuevo reemplaza al anterior: el anterior se borra solo después
     * de guardar el nuevo y actualizar la empresa (si algo falla antes, la
     * empresa conserva su logo).
     *
     * @param  array<string, mixed>  $datos  Campos validados (sin el archivo).
     */
    public function actualizar(Empresa $empresa, array $datos, ?UploadedFile $logo): Empresa
    {
        $anterior = $empresa->logo_path;

        if ($logo !== null) {
            $datos['logo_path'] = $this->guardarLogo($logo);
        }

        $empresa->update($datos);

        if ($logo !== null && is_string($anterior) && $anterior !== '') {
            Storage::disk(self::DISCO)->delete($anterior);
        }

        return $empresa;
    }

    private function guardarLogo(UploadedFile $logo): string
    {
        $ruta = $logo->store(self::CARPETA_LOGOS, self::DISCO);

        if ($ruta === false) {
            throw new RuntimeException('No se pudo guardar el logo de la empresa. Intenta de nuevo.');
        }

        return $ruta;
    }
}
