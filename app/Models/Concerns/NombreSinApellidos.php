<?php

namespace App\Models\Concerns;

use App\Support\NombrePersona;

/**
 * Modelos con «nombre» + «apellidos» por separado (Colaborador, User,
 * Candidato, AltaDigital): al guardar, si el nombre trae los apellidos al
 * final («NANCY NALLELY LOPEZ GARCIA» + «Lopez Garcia») se quitan del nombre.
 * Así TODO escritor (registro en la app, alta pública, conversión de
 * candidato/alta, importaciones, edición en RH) deja el dato limpio y el
 * nombre completo nunca sale duplicado en pantallas ni en contratos.
 *
 * El modelo indica la columna del nombre con columnaNombrePropio().
 */
trait NombreSinApellidos
{
    public static function bootNombreSinApellidos(): void
    {
        static::saving(function (self $modelo): void {
            $columna = $modelo->columnaNombrePropio();
            $nombre = $modelo->getAttribute($columna);

            if (! is_string($nombre) || ! $modelo->isDirty([$columna, 'apellidos'])) {
                return;
            }

            $apellidos = $modelo->getAttribute('apellidos');
            $apellidos = is_string($apellidos) ? $apellidos : null;

            if (NombrePersona::terminaConApellidos($nombre, $apellidos)) {
                $modelo->setAttribute($columna, NombrePersona::sinApellidos($nombre, $apellidos));
            }
        });
    }

    public function columnaNombrePropio(): string
    {
        return 'name';
    }
}
