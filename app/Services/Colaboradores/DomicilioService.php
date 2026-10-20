<?php

namespace App\Services\Colaboradores;

use App\Enums\EstadoRepublica;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Domicilio ESTRUCTURADO del colaborador (y del alta digital): calle,
 * número exterior, número interior, colonia, C.P., municipio/alcaldía y
 * estado. Es la única regla de captura/validación para todos los puntos de
 * entrada (alta RH, expediente, alta digital, QR de incorporación,
 * solicitud de actualización de datos, API móvil).
 *
 * `domicilio` (texto único) se conserva por compatibilidad (APKs ya
 * instaladas, documentos y pantallas que lo leen): cuando hay calle
 * capturada se COMPONE a partir de los campos (sincronizar(), llamado al
 * guardar el modelo). Un registro que solo trae el texto no se parte
 * adivinando: queda tal cual hasta que alguien capture los campos.
 */
class DomicilioService
{
    /** Columnas estructuradas, en orden de captura. */
    public const CAMPOS = [
        'domicilio_calle',
        'domicilio_numero_exterior',
        'domicilio_numero_interior',
        'domicilio_colonia',
        'domicilio_cp',
        'domicilio_municipio',
        'domicilio_estado',
    ];

    /** columna => etiqueta en español. */
    public const ETIQUETAS = [
        'domicilio_calle' => 'Calle',
        'domicilio_numero_exterior' => 'Número exterior',
        'domicilio_numero_interior' => 'Número interior',
        'domicilio_colonia' => 'Colonia',
        'domicilio_cp' => 'Código postal',
        'domicilio_municipio' => 'Municipio o alcaldía',
        'domicilio_estado' => 'Estado',
    ];

    /** Únicos campos que pueden quedar vacíos en un domicilio completo. */
    public const OPCIONALES = ['domicilio_numero_interior'];

    /**
     * Reglas de validación. Con $requerido, todo menos el número interior
     * es obligatorio (alta digital); si no, cada campo es opcional pero, si
     * viene, se valida (C.P. de 5 dígitos, estado de la lista).
     *
     * @return array<string, list<mixed>>
     */
    public function reglas(bool $requerido = false, string $prefijo = ''): array
    {
        $presencia = fn (string $campo): string => $requerido && ! in_array($campo, self::OPCIONALES, true) ? 'required' : 'nullable';

        return [
            $prefijo.'domicilio_calle' => [$presencia('domicilio_calle'), 'string', 'max:150'],
            $prefijo.'domicilio_numero_exterior' => [$presencia('domicilio_numero_exterior'), 'string', 'max:20'],
            $prefijo.'domicilio_numero_interior' => ['nullable', 'string', 'max:20'],
            $prefijo.'domicilio_colonia' => [$presencia('domicilio_colonia'), 'string', 'max:120'],
            $prefijo.'domicilio_cp' => [$presencia('domicilio_cp'), 'string', 'regex:/^\d{5}$/'],
            $prefijo.'domicilio_municipio' => [$presencia('domicilio_municipio'), 'string', 'max:120'],
            $prefijo.'domicilio_estado' => [$presencia('domicilio_estado'), 'string', $this->reglaEstado()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function mensajes(string $prefijo = ''): array
    {
        return [
            $prefijo.'domicilio_cp.regex' => 'El código postal son 5 dígitos.',
            $prefijo.'domicilio_calle.required' => 'Captura la calle.',
            $prefijo.'domicilio_numero_exterior.required' => 'Captura el número exterior (o «S/N» si no tiene).',
            $prefijo.'domicilio_colonia.required' => 'Captura la colonia.',
            $prefijo.'domicilio_cp.required' => 'Captura el código postal.',
            $prefijo.'domicilio_municipio.required' => 'Captura el municipio o alcaldía.',
            $prefijo.'domicilio_estado.required' => 'Elige el estado.',
        ];
    }

    /** Estado: uno de los 32 (acepta mayúsculas/sin acentos; se guarda el nombre oficial). */
    public function reglaEstado(): Closure
    {
        return function (string $atributo, mixed $valor, Closure $fallar): void {
            if (! is_string($valor) || EstadoRepublica::desdeTexto($valor) === null) {
                $fallar('Elige un estado de la lista.');
            }
        };
    }

    /**
     * Solo las llaves de domicilio presentes en $datos (validados), ya
     * normalizadas. El texto `domicilio` legado se respeta tal cual si viene
     * (APK vieja); si además vienen los campos, sincronizar() lo recompone.
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, string|null>
     */
    public function extraer(array $datos): array
    {
        $resultado = [];

        foreach (['domicilio', ...self::CAMPOS] as $campo) {
            if (array_key_exists($campo, $datos)) {
                $resultado[$campo] = $this->normalizar($campo, $datos[$campo]);
            }
        }

        return $resultado;
    }

    /** Valor limpio: espacios, C.P. solo dígitos, estado con su nombre oficial. */
    public function normalizar(string $campo, mixed $valor): ?string
    {
        $texto = trim((string) preg_replace('/\s+/', ' ', (string) (is_scalar($valor) ? $valor : '')));

        if ($texto === '') {
            return null;
        }

        return match ($campo) {
            'domicilio_cp' => (string) preg_replace('/\D/', '', $texto),
            'domicilio_estado' => EstadoRepublica::desdeTexto($texto)->value ?? $texto,
            default => $texto,
        };
    }

    /**
     * Antes de guardar un modelo con domicilio estructurado: normaliza lo
     * capturado y, si hay calle, recompone `domicilio`. Si no cambió ningún
     * campo estructurado, no toca nada (un texto legado editado aparte se
     * respeta).
     */
    public function sincronizar(Model $modelo): void
    {
        if (! $modelo->isDirty(self::CAMPOS)) {
            return;
        }

        foreach (self::CAMPOS as $campo) {
            if ($modelo->isDirty($campo)) {
                $modelo->setAttribute($campo, $this->normalizar($campo, $modelo->getAttribute($campo)));
            }
        }

        $partes = [];

        foreach (self::CAMPOS as $campo) {
            $partes[$campo] = (string) $modelo->getAttribute($campo);
        }

        if (trim($partes['domicilio_calle']) !== '') {
            $modelo->setAttribute('domicilio', $this->componer($partes));
        }
    }

    /**
     * «Av. Morelos 123 Int. 4, col. Centro, Cuernavaca, Morelos, C.P. 62000»
     * (mismo orden que la variable domicilio_completo de los documentos).
     *
     * @param  array<string, string|null>  $partes
     */
    public function componer(array $partes): string
    {
        $colonia = trim((string) ($partes['domicilio_colonia'] ?? ''));
        $cp = trim((string) ($partes['domicilio_cp'] ?? ''));

        return implode(', ', array_filter([
            $this->calleYNumero($partes),
            $colonia !== '' ? sprintf('col. %s', $colonia) : '',
            trim((string) ($partes['domicilio_municipio'] ?? '')),
            trim((string) ($partes['domicilio_estado'] ?? '')),
            $cp !== '' ? sprintf('C.P. %s', $cp) : '',
        ], fn (string $p): bool => $p !== ''));
    }

    /**
     * «Av. Morelos 123 Int. 4» (celda «Calle y No.» de los formatos).
     *
     * @param  array<string, string|null>  $partes
     */
    public function calleYNumero(array $partes): string
    {
        $interior = trim((string) ($partes['domicilio_numero_interior'] ?? ''));

        return trim(implode(' ', array_filter([
            trim((string) ($partes['domicilio_calle'] ?? '')),
            trim((string) ($partes['domicilio_numero_exterior'] ?? '')),
            $interior !== '' ? sprintf('Int. %s', $interior) : '',
        ], fn (string $p): bool => $p !== '')));
    }

    /** Todos los campos obligatorios capturados (el número interior es opcional). */
    public function completo(Model $modelo): bool
    {
        foreach (self::CAMPOS as $campo) {
            if (! in_array($campo, self::OPCIONALES, true) && blank($modelo->getAttribute($campo))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Campos estructurados con su valor actual (para formularios).
     *
     * @return array<string, string|null>
     */
    public function valores(Model $modelo): array
    {
        $valores = [];

        foreach (self::CAMPOS as $campo) {
            $valor = $modelo->getAttribute($campo);
            $valores[$campo] = $valor === null || $valor === '' ? null : (string) $valor;
        }

        return $valores;
    }
}
