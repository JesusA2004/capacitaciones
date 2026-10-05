<?php

namespace App\Services\DocumentosMaestros\Calidad;

/**
 * Datos de prueba LARGOS pero realistas para el QA visual de un master
 * ("prueba de estrés"): si con estos valores el documento cambia de número
 * de páginas, se le encima texto o se deforma el encabezado, la versión no
 * se valida. Son fijos (deterministas) y nunca se guardan en un expediente.
 *
 * Los largos salen de casos reales de RH: nombres compuestos con dos
 * apellidos, domicilios con número interior y colonia larga, sucursales
 * con nombre de municipio compuesto, puestos largos y sueldos de 6 cifras.
 */
class ValoresPruebaDocumento
{
    public const NOMBRE = 'José Francisco de Jesús Hernández González';

    public const DOMICILIO = 'Calle Prolongación Emiliano Zapata número 1250 interior 4-B, colonia Lomas de la Selva Norte, C.P. 62270, Cuernavaca, Morelos';

    public const SUCURSAL = 'Pachuca de Soto Centro Histórico';

    public const PUESTO = 'Gerente de Sucursal de Crédito Grupal';

    /**
     * Valor de prueba para un campo del master (por nombre de campo).
     */
    public function valor(string $campo): string
    {
        $mayusculas = str_ends_with($campo, '_mayusculas');
        $base = $mayusculas ? substr($campo, 0, -strlen('_mayusculas')) : $campo;
        $valor = $this->porNombre($base);

        return $mayusculas ? mb_strtoupper($valor) : $valor;
    }

    /**
     * @param  list<string>  $campos
     * @return array<string, string>
     */
    public function valores(array $campos): array
    {
        $valores = [];

        foreach (array_unique($campos) as $campo) {
            $valores[$campo] = $this->valor($campo);
        }

        return $valores;
    }

    private function porNombre(string $campo): string
    {
        return match (true) {
            str_starts_with($campo, 'marca_'), str_starts_with($campo, 'criterio_'), str_starts_with($campo, 'resultado_') => '☒',
            $campo === 'nombre' => 'José Francisco de Jesús',
            $campo === 'apellido_paterno' => 'Hernández',
            $campo === 'apellido_materno' => 'González',
            str_contains($campo, 'nombre_completo'), $campo === 'colaborador_nombre' => self::NOMBRE,
            str_contains($campo, 'representante') => 'Lesli Maribel Rodríguez Herrera',
            str_contains($campo, 'gerente_nombre'), str_contains($campo, 'jefe'), str_contains($campo, 'testigo'), str_contains($campo, 'beneficiario_nombre') => 'María Guadalupe Fernández de la Torre',
            str_contains($campo, 'parentesco') => 'Cónyuge',
            str_contains($campo, 'sucursal_domicilio'), str_contains($campo, 'sucursal_direccion') => 'Avenida Revolución número 1520, local 3, colonia Centro Histórico, C.P. 42000',
            str_contains($campo, 'domicilio_colonia') => 'Lomas de la Selva Norte',
            str_contains($campo, 'domicilio_municipio'), str_contains($campo, 'municipio') => 'Pachuca de Soto',
            str_contains($campo, 'domicilio_estado'), str_contains($campo, '_estado') => 'Hidalgo',
            str_contains($campo, '_cp'), $campo === 'cp' => '62270',
            str_contains($campo, 'domicilio') => self::DOMICILIO,
            str_contains($campo, 'sucursal_numero') => '127',
            str_contains($campo, 'sucursal') => self::SUCURSAL,
            str_contains($campo, 'departamento') => 'Mesa de Control y Cobranza',
            str_contains($campo, 'puesto') => self::PUESTO,
            str_contains($campo, 'lugar_y_fecha') => 'Pachuca de Soto, Hidalgo, a 30 de septiembre de 2026',
            str_contains($campo, 'fecha_firma_dias'), str_contains($campo, 'dias_del_mes') => 'treinta días del mes de septiembre de dos mil veintiséis',
            str_contains($campo, 'fecha') && str_contains($campo, 'corta') => '30/09/2026',
            str_contains($campo, 'fecha') => '30 de septiembre de 2026',
            str_contains($campo, 'lugar'), str_contains($campo, 'ciudad') => 'Pachuca de Soto, Hidalgo',
            str_contains($campo, '_letra') && (str_contains($campo, 'monto') || str_contains($campo, 'sueldo') || str_contains($campo, 'pago')) => 'CIENTO VEINTITRÉS MIL CUATROCIENTOS CINCUENTA Y SEIS PESOS 78/100 M.N.',
            str_contains($campo, 'duracion') && str_contains($campo, 'letra') => 'tres meses',
            str_contains($campo, 'sueldo'), str_contains($campo, 'monto'), str_contains($campo, 'pago_prestamo'), str_contains($campo, 'neto') => '123,456.78',
            str_contains($campo, 'plazo') && str_contains($campo, 'texto') => '52 semanas',
            str_contains($campo, 'plazo') => '52',
            str_contains($campo, 'curp') => 'HEGJ850315HHGRNS09',
            str_contains($campo, 'rfc') => 'HEGJ850315AB1',
            str_contains($campo, 'nss') => '12345678901',
            str_contains($campo, 'clave_elector') => 'HRGNJS85031513H400',
            str_contains($campo, 'telefono') => '7712345678',
            str_contains($campo, 'correo') => 'jose.francisco.hernandez@correo-ejemplo.com.mx',
            str_contains($campo, 'nacionalidad') => 'Mexicana',
            str_contains($campo, 'sexo'), str_contains($campo, 'genero') => 'Masculino',
            str_contains($campo, 'estado_civil') => 'Unión libre',
            str_contains($campo, 'edad') => '41',
            str_contains($campo, 'profesion') => 'Licenciado en Administración de Empresas',
            str_contains($campo, 'hora') => '09:30',
            str_contains($campo, 'motivo') => 'Cita médica programada en el IMSS para estudios de laboratorio y entrega de resultados con el especialista.',
            str_contains($campo, 'folio') => 'PRE-2026-000123',
            str_contains($campo, '_dia') => '30',
            str_contains($campo, '_mes') => 'septiembre',
            str_contains($campo, 'anio_corto') => '26',
            str_contains($campo, 'anio') => '2026',
            str_contains($campo, 'identificacion') => 'INE',
            default => 'Dato de prueba largo para validar el formato',
        };
    }
}
