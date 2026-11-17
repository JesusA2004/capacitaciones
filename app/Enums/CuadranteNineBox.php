<?php

namespace App\Enums;

/**
 * Los 9 cuadrantes de la Nine Box (Desempeño × Potencial), con su nombre
 * en español, qué significa y la acción sugerida para el jefe/RH
 * (docs/NINE_BOX.md). El color de cada uno es un token de la paleta
 * pastel (tono()), nunca un hex.
 */
enum CuadranteNineBox: string
{
    // Potencial alto
    case Enigma = 'enigma';
    case AltoPotencial = 'alto_potencial';
    case Estrella = 'estrella';

    // Potencial medio
    case Dilema = 'dilema';
    case Nucleo = 'nucleo';
    case ProfesionalClave = 'profesional_clave';

    // Potencial bajo
    case BajoDesempeno = 'bajo_desempeno';
    case Efectivo = 'efectivo';
    case Confiable = 'confiable';

    public static function de(NivelNineBox $desempeno, NivelNineBox $potencial): self
    {
        return match ([$potencial, $desempeno]) {
            [NivelNineBox::Alto, NivelNineBox::Bajo] => self::Enigma,
            [NivelNineBox::Alto, NivelNineBox::Medio] => self::AltoPotencial,
            [NivelNineBox::Alto, NivelNineBox::Alto] => self::Estrella,
            [NivelNineBox::Medio, NivelNineBox::Bajo] => self::Dilema,
            [NivelNineBox::Medio, NivelNineBox::Medio] => self::Nucleo,
            [NivelNineBox::Medio, NivelNineBox::Alto] => self::ProfesionalClave,
            [NivelNineBox::Bajo, NivelNineBox::Bajo] => self::BajoDesempeno,
            [NivelNineBox::Bajo, NivelNineBox::Medio] => self::Efectivo,
            default => self::Confiable,
        };
    }

    public function etiqueta(): string
    {
        return match ($this) {
            self::Estrella => 'Estrella',
            self::AltoPotencial => 'Alto potencial',
            self::Enigma => 'Enigma',
            self::ProfesionalClave => 'Profesional clave',
            self::Nucleo => 'Núcleo',
            self::Dilema => 'Dilema',
            self::Confiable => 'Confiable',
            self::Efectivo => 'Efectivo',
            self::BajoDesempeno => 'Bajo desempeño',
        };
    }

    public function desempeno(): NivelNineBox
    {
        return match ($this) {
            self::Enigma, self::Dilema, self::BajoDesempeno => NivelNineBox::Bajo,
            self::AltoPotencial, self::Nucleo, self::Efectivo => NivelNineBox::Medio,
            self::Estrella, self::ProfesionalClave, self::Confiable => NivelNineBox::Alto,
        };
    }

    public function potencial(): NivelNineBox
    {
        return match ($this) {
            self::Enigma, self::AltoPotencial, self::Estrella => NivelNineBox::Alto,
            self::Dilema, self::Nucleo, self::ProfesionalClave => NivelNineBox::Medio,
            self::BajoDesempeno, self::Efectivo, self::Confiable => NivelNineBox::Bajo,
        };
    }

    public function descripcion(): string
    {
        return match ($this) {
            self::Estrella => 'Supera sus resultados y tiene todo para crecer.',
            self::AltoPotencial => 'Cumple y muestra capacidad para retos mayores.',
            self::Enigma => 'Tiene potencial, pero sus resultados aún no lo reflejan.',
            self::ProfesionalClave => 'Resultados sobresalientes con potencial moderado.',
            self::Nucleo => 'Cumple de forma consistente: la base del equipo.',
            self::Dilema => 'Potencial medio con resultados por debajo de lo esperado.',
            self::Confiable => 'Da grandes resultados en su puesto actual.',
            self::Efectivo => 'Cumple lo esperado en su puesto actual.',
            self::BajoDesempeno => 'Resultados y potencial por debajo de lo esperado.',
        };
    }

    public function accionSugerida(): string
    {
        return match ($this) {
            self::Estrella => 'Retener y preparar para sucesión: proyectos retadores y plan de carrera.',
            self::AltoPotencial => 'Desarrollar: mentoría y metas más altas para llevarlo a Estrella.',
            self::Enigma => 'Investigar qué frena sus resultados: acompañamiento cercano y metas semanales.',
            self::ProfesionalClave => 'Reconocer y retener; ampliar responsabilidades en su puesto.',
            self::Nucleo => 'Mantener el ritmo con capacitación y retroalimentación constante.',
            self::Dilema => 'Plan de mejora con metas claras y seguimiento quincenal.',
            self::Confiable => 'Reconocer su aporte y aprovecharlo como referente del equipo.',
            self::Efectivo => 'Reforzar habilidades técnicas para subir su desempeño.',
            self::BajoDesempeno => 'Plan de acción inmediato con seguimiento documentado; si no mejora, valorar reubicación.',
        };
    }

    /** Clave del tono pastel (la UI lo mapea a utilidades de la paleta). */
    public function tono(): string
    {
        return match ($this) {
            self::Estrella => 'estrella',
            self::AltoPotencial, self::ProfesionalClave => 'fuerte',
            self::Enigma => 'info',
            self::Nucleo => 'base',
            self::Confiable, self::Efectivo => 'calido',
            self::Dilema => 'alerta',
            self::BajoDesempeno => 'riesgo',
        };
    }

    /**
     * Los 9 cuadrantes en orden de pintado (fila de potencial alto primero,
     * de izquierda a derecha por desempeño).
     *
     * @return list<array{clave: string, etiqueta: string, descripcion: string, accion: string, tono: string, desempeno: string, potencial: string}>
     */
    public static function catalogo(): array
    {
        return array_map(fn (self $c): array => [
            'clave' => $c->value,
            'etiqueta' => $c->etiqueta(),
            'descripcion' => $c->descripcion(),
            'accion' => $c->accionSugerida(),
            'tono' => $c->tono(),
            'desempeno' => $c->desempeno()->value,
            'potencial' => $c->potencial()->value,
        ], self::cases());
    }
}
