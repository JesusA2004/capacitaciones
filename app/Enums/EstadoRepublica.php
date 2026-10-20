<?php

namespace App\Enums;

/**
 * Las 32 entidades federativas de México (domicilio del colaborador). El
 * valor ES el nombre que se imprime en los documentos («Morelos», «Ciudad
 * de México»), así la columna `domicilio_estado` guarda texto legible y los
 * datos previos (OCR, captura libre) siguen siendo compatibles.
 *
 * Espejo en el frontend: resources/js/lib/estadosRepublica.ts y, en la app,
 * src/constants/estadosRepublica.ts (un test verifica que la web no se
 * desincronice).
 */
enum EstadoRepublica: string
{
    case Aguascalientes = 'Aguascalientes';
    case BajaCalifornia = 'Baja California';
    case BajaCaliforniaSur = 'Baja California Sur';
    case Campeche = 'Campeche';
    case Chiapas = 'Chiapas';
    case Chihuahua = 'Chihuahua';
    case CiudadDeMexico = 'Ciudad de México';
    case Coahuila = 'Coahuila';
    case Colima = 'Colima';
    case Durango = 'Durango';
    case EstadoDeMexico = 'Estado de México';
    case Guanajuato = 'Guanajuato';
    case Guerrero = 'Guerrero';
    case Hidalgo = 'Hidalgo';
    case Jalisco = 'Jalisco';
    case Michoacan = 'Michoacán';
    case Morelos = 'Morelos';
    case Nayarit = 'Nayarit';
    case NuevoLeon = 'Nuevo León';
    case Oaxaca = 'Oaxaca';
    case Puebla = 'Puebla';
    case Queretaro = 'Querétaro';
    case QuintanaRoo = 'Quintana Roo';
    case SanLuisPotosi = 'San Luis Potosí';
    case Sinaloa = 'Sinaloa';
    case Sonora = 'Sonora';
    case Tabasco = 'Tabasco';
    case Tamaulipas = 'Tamaulipas';
    case Tlaxcala = 'Tlaxcala';
    case Veracruz = 'Veracruz';
    case Yucatan = 'Yucatán';
    case Zacatecas = 'Zacatecas';

    /** Nombres alternos frecuentes (OCR, captura libre) → entidad. */
    private const ALIAS = [
        'cdmx' => self::CiudadDeMexico,
        'df' => self::CiudadDeMexico,
        'distrito federal' => self::CiudadDeMexico,
        'mexico df' => self::CiudadDeMexico,
        'mexico' => self::EstadoDeMexico,
        'edo de mexico' => self::EstadoDeMexico,
        'edo mex' => self::EstadoDeMexico,
        'edomex' => self::EstadoDeMexico,
        'coahuila de zaragoza' => self::Coahuila,
        'michoacan de ocampo' => self::Michoacan,
        'veracruz de ignacio de la llave' => self::Veracruz,
        'nuevo leon' => self::NuevoLeon,
    ];

    public function etiqueta(): string
    {
        return $this->value;
    }

    /**
     * La entidad que corresponde a un texto capturado sin importar
     * mayúsculas, acentos ni puntos («MORELOS», «Edo. de México», «CDMX»).
     * null si no hay una equivalencia inequívoca (nunca se adivina).
     */
    public static function desdeTexto(?string $texto): ?self
    {
        $clave = self::clave((string) $texto);

        if ($clave === '') {
            return null;
        }

        foreach (self::cases() as $estado) {
            if (self::clave($estado->value) === $clave) {
                return $estado;
            }
        }

        return self::ALIAS[$clave] ?? null;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function opciones(): array
    {
        return array_map(fn (self $e): array => ['value' => $e->value, 'label' => $e->etiqueta()], self::cases());
    }

    private static function clave(string $texto): string
    {
        $sinAcentos = strtr(mb_strtolower(trim($texto)), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);

        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9 ]/', ' ', $sinAcentos)));
    }
}
