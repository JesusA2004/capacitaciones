<?php

namespace App\Services\Incorporacion\SolicitudEmpleo;

use App\Enums\TipoSolicitudInterna;
use App\Models\Colaborador;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Services\Expedientes\ActualizacionDatosService;
use App\Services\Expedientes\DatosFaltantesService;
use App\Services\Incorporacion\RegistroAppService;
use App\Services\Solicitudes\SolicitudesService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Los datos PERSONALES (domicilio, teléfono, CURP…) que la Solicitud de
 * empleo necesita y todavía no están en el expediente, capturados DENTRO de
 * «Mi solicitud de empleo» (misma pantalla, mismo «Guardar y seguir»):
 *
 *  - Fuente única de «qué le falta a la PERSONA»: avance(). La usan Mis
 *    pendientes, el encabezado del asistente y el contador de cada paso, así
 *    que los números nunca se contradicen.
 *  - Nunca se pide dos veces: lo que ya está en el expediente sale como «Ya
 *    registrado»; lo que ya envió y RH aún revisa, «En revisión por RH» (para
 *    la persona cuenta como llenado).
 *  - La persona no edita su expediente directo (docs/SOLICITUDES_UNIFICADAS.md):
 *    al guardar la sección, lo que capturó viaja a RH como solicitud de
 *    «Completar dato faltante» (ActualizacionDatosService) y se aplica cuando
 *    RH la autoriza. Durante su registro (captura inicial) se guarda directo
 *    con RegistroAppService, igual que el paso «Datos personales».
 *  - Una sección nunca se bloquea por lo que le toca a otra o a RH.
 *
 * La completitud para GENERAR los documentos no cambia: sigue saliendo solo
 * de lo que realmente está en el expediente (PerfilIngresoService).
 *
 * @phpstan-type Abierto array{valor: string, legible: string, solicitud_id: int, folio: string, requiere_respuesta: bool}
 * @phpstan-type Situacion array{secciones: array<string, array{por_llenar: list<string>, en_revision: list<string>, de_rh: list<string>}>, campos: array<string, string>, abiertos: array<string, Abierto>, total: int, unidades: int, completo: bool}
 */
class DatosPersonalesSolicitudService
{
    public const POR_LLENAR = 'por_llenar';

    public const EN_REVISION = 'en_revision';

    public const DE_RH = 'de_rh';

    /**
     * Datos del domicilio que el catálogo de la solicitud no lista pero que
     * se capturan junto con la calle (un solo domicilio, una sola vez). Solo
     * se piden mientras la calle no esté en el expediente.
     */
    public const EXTRAS = [
        'domicilio_numero_exterior' => ['seccion' => 'domicilio', 'etiqueta' => 'Número exterior', 'requerido' => true, 'ayuda' => 'Si no tiene número, escribe «S/N».'],
        'domicilio_numero_interior' => ['seccion' => 'domicilio', 'etiqueta' => 'Número interior', 'requerido' => false, 'ayuda' => 'Solo si aplica (departamento, interior, lote).'],
    ];

    /** Los extras se muestran justo después de este dato. */
    private const ANCLA_EXTRAS = 'domicilio_calle';

    private const LARGOS = [
        'telefono' => 20, 'curp' => 22, 'rfc' => 16, 'nss' => 15, 'correo_personal' => 150,
        'domicilio_calle' => 150, 'domicilio_numero_exterior' => 20, 'domicilio_numero_interior' => 20,
        'domicilio_colonia' => 120, 'domicilio_municipio' => 120, 'domicilio_cp' => 5,
    ];

    private const TECLADOS = ['telefono' => 'tel', 'nss' => 'numeric', 'domicilio_cp' => 'numeric', 'correo_personal' => 'email'];

    private const AYUDAS = [
        'telefono' => '10 dígitos.',
        'curp' => '18 caracteres, como viene en tu CURP.',
        'nss' => '11 dígitos.',
        'domicilio_cp' => '5 dígitos.',
    ];

    public function __construct(
        private readonly PerfilIngresoService $perfil,
        private readonly ActualizacionDatosService $actualizacion,
    ) {}

    /**
     * Datos personales que la persona puede capturar en una sección (los del
     * catálogo que acepta una solicitud de actualización + los extras).
     *
     * @return list<string>
     */
    public static function clavesDeSeccion(string $seccion): array
    {
        $claves = [];

        foreach (CatalogoSolicitudEmpleo::camposDeSeccion($seccion) as $clave => $campo) {
            if ($campo['almacen'] === 'base' && self::capturable($clave)) {
                $claves[] = $clave;
            }
        }

        foreach (self::EXTRAS as $clave => $extra) {
            if ($extra['seccion'] === $seccion) {
                $claves[] = $clave;
            }
        }

        return $claves;
    }

    /** Nombre y apellidos nunca se proponen: los registra Recursos Humanos. */
    private static function capturable(string $campo): bool
    {
        return array_key_exists($campo, DatosFaltantesService::PERSONALES) || array_key_exists($campo, DatosFaltantesService::PERSONALES_OPCIONALES);
    }

    /**
     * Lo que le falta a la PERSONA (no a RH) en su solicitud de empleo.
     * `por_llenar` cuenta dato por dato, igual que el contador de cada paso.
     *
     * @return array{por_llenar: int, en_revision: int, secciones_pendientes: int, total: int, porcentaje: int, listo: bool, completo: bool, frase: string}
     */
    public function avance(Colaborador $colaborador): array
    {
        return $this->resumir($this->situacion($colaborador));
    }

    /** Datos por llenar SIN los personales (los cuenta aparte «Pedir información»). */
    public function porLlenarSinPersonales(Colaborador $colaborador): int
    {
        return count(array_filter($this->perfil->completitud($colaborador)['faltantes'], fn (array $f): bool => $f['origen'] === 'solicitud'));
    }

    /**
     * Agrega al estado por sección (PerfilIngresoService::estado) lo que la
     * persona necesita ver: los datos personales faltantes como campos por
     * llenar, lo que está «En revisión por RH», el avance propio de cada
     * sección (`por_llenar`, `en_revision`, `lista_propia`) y el `avance`
     * general. Solo AGREGA llaves: el contrato de la app no cambia.
     *
     * @param  array{secciones: list<array<string, mixed>>, completitud: array<string, mixed>}  $estado
     * @return array<string, mixed>
     */
    public function decorar(array $estado, Colaborador $colaborador): array
    {
        $situacion = $this->situacion($colaborador);
        $captura = [];

        foreach ($this->actualizacion->camposCaptura() as $control) {
            $captura[$control['campo']] = $control;
        }

        $secciones = [];

        foreach ($estado['secciones'] as $seccion) {
            $clave = is_string($seccion['clave'] ?? null) ? $seccion['clave'] : '';
            $campos = [];

            foreach (is_array($seccion['campos'] ?? null) ? $seccion['campos'] : [] as $campo) {
                if (! is_array($campo) || ! is_string($campo['campo'] ?? null)) {
                    continue;
                }

                $nombre = $campo['campo'];
                $campos[] = $this->campoPersonal($campo, $situacion['campos'][$nombre] ?? null, $situacion['abiertos'][$nombre] ?? null, $captura[$nombre] ?? null);

                if ($nombre !== self::ANCLA_EXTRAS) {
                    continue;
                }

                foreach (self::EXTRAS as $extra => $definicion) {
                    if (isset($situacion['campos'][$extra])) {
                        $campos[] = $this->campoExtra($extra, $definicion, $situacion['campos'][$extra], $situacion['abiertos'][$extra] ?? null);
                    }
                }
            }

            $propio = $situacion['secciones'][$clave] ?? ['por_llenar' => [], 'en_revision' => [], 'de_rh' => []];

            $secciones[] = [
                ...$seccion,
                'campos' => $campos,
                'por_llenar' => $propio['por_llenar'],
                'en_revision' => $propio['en_revision'],
                'de_rh' => $propio['de_rh'],
                'lista_propia' => $propio['por_llenar'] === [],
            ];
        }

        return [...$estado, 'secciones' => $secciones, 'avance' => $this->resumir($situacion)];
    }

    /**
     * Toma de lo capturado en una sección los datos personales y los manda a
     * donde corresponde: en el registro (captura inicial) se guardan directo;
     * después, viajan a RH como solicitud de «Completar dato faltante».
     *
     * @param  array<string, mixed>  $datos  Lo capturado en la sección (campo => valor).
     * @return int Datos personales guardados o enviados a revisión.
     *
     * @throws ValidationException Formato inválido (CURP, C.P., teléfono…) o un dato que ya está registrado.
     */
    public function guardar(User $usuario, Colaborador $colaborador, string $seccion, array $datos, string $modo): int
    {
        $recibidos = array_intersect_key($datos, array_flip(self::clavesDeSeccion($seccion)));

        if ($recibidos === []) {
            return 0;
        }

        $situacion = $this->situacion($colaborador);
        $nuevos = [];
        $registrados = [];

        foreach ($this->actualizacion->limpiarCaptura($recibidos) as $campo => $valor) {
            $texto = is_scalar($valor) ? trim((string) $valor) : '';

            if ($texto === '') {
                continue;
            }

            $estado = $situacion['campos'][$campo] ?? null;

            if ($estado === self::POR_LLENAR) {
                $nuevos[$campo] = $texto;

                continue;
            }

            // Ya lo envió (RH lo revisa) o lo completa RH: no se duplica.
            if ($estado !== null) {
                continue;
            }

            // Ya está en el expediente: aquí no se cambia (se pide corrección).
            // (Un dato vacío que no se pide aquí —p. ej. el número exterior
            // cuando la calle ya existe— simplemente no se toma.)
            $vigente = $colaborador->getAttribute($campo);
            $vigente = trim(is_scalar($vigente) ? (string) $vigente : '');

            if ($vigente !== '' && mb_strtolower($vigente) !== mb_strtolower($texto)) {
                $registrados[] = $this->etiqueta($campo);
            }
        }

        if ($registrados !== []) {
            throw ValidationException::withMessages(['datos' => sprintf('Ya está registrado: %s. Si algo está mal, pide una corrección de datos desde «Mis solicitudes».', implode(', ', $registrados))]);
        }

        if ($nuevos === []) {
            return 0;
        }

        $atributos = [];

        foreach (array_keys($nuevos) as $campo) {
            $atributos[sprintf('datos.%s', $campo)] = $this->etiqueta($campo);
        }

        // Mismas reglas que la solicitud de actualización de datos: el error
        // llega con la llave del campo (`datos.domicilio_cp`) para pintarlo
        // junto a él.
        Validator::make(['datos' => $nuevos], $this->actualizacion->reglas(), $this->actualizacion->mensajes(), $atributos)->validate();

        if ($modo === PerfilIngresoService::ORIGEN_APP) {
            app(RegistroAppService::class)->guardarDatos($usuario, $nuevos);

            return count($nuevos);
        }

        app(SolicitudesService::class)->crear($usuario, [
            'tipo' => TipoSolicitudInterna::ActualizacionDatos->value,
            'datos' => $nuevos,
            'motivo' => null,
            'observaciones' => sprintf('Capturado por la persona en «Mi solicitud de empleo» (%s).', CatalogoSolicitudEmpleo::SECCIONES[$seccion]['titulo'] ?? $seccion),
        ]);

        return count($nuevos);
    }

    /**
     * Estado de cada dato faltante desde el punto de vista de la persona.
     *
     * @return Situacion
     */
    private function situacion(Colaborador $colaborador): array
    {
        $completitud = $this->perfil->completitud($colaborador);
        $abiertos = $this->actualizacion->propuestosAbiertos($colaborador);
        $puedeEnviar = $this->puedeEnviar($colaborador);
        $listas = CatalogoSolicitudEmpleo::listas();
        // estado => sección => etiquetas
        $etiquetas = [self::POR_LLENAR => [], self::EN_REVISION => [], self::DE_RH => []];
        $campos = [];
        $unidades = 0;
        $listasContadas = [];
        $faltaCalle = false;

        foreach ($completitud['faltantes'] as $faltante) {
            $campo = $faltante['campo'];
            $seccion = $faltante['seccion'];

            // Lo propio de la solicitud (perfil, listas): siempre lo llena la persona.
            if ($faltante['origen'] === 'solicitud') {
                $etiquetas[self::POR_LLENAR][$seccion][] = $faltante['etiqueta'];

                if (! isset($listas[$campo])) {
                    $unidades++;
                } elseif (! isset($listasContadas[$campo])) {
                    $listasContadas[$campo] = true;
                    $unidades++;
                }

                continue;
            }

            $faltaCalle = $faltaCalle || $campo === self::ANCLA_EXTRAS;
            $estado = match (true) {
                ! $puedeEnviar || ! self::capturable($campo) => self::DE_RH,
                isset($abiertos[$campo]) => self::EN_REVISION,
                default => self::POR_LLENAR,
            };
            $campos[$campo] = $estado;
            $etiquetas[$estado][$seccion][] = $faltante['etiqueta'];
            $unidades += $estado === self::POR_LLENAR ? 1 : 0;
        }

        $total = $completitud['total'];

        // Número exterior/interior: se piden con la calle, una sola vez.
        if ($faltaCalle && $puedeEnviar) {
            foreach (self::EXTRAS as $campo => $extra) {
                if (! blank($colaborador->getAttribute($campo))) {
                    continue;
                }

                $estado = isset($abiertos[$campo]) ? self::EN_REVISION : self::POR_LLENAR;

                // El opcional se ofrece junto con la calle (no suelto cuando
                // el domicilio ya se envió) y nunca cuenta como faltante.
                if (! $extra['requerido']) {
                    if ($estado === self::EN_REVISION || ($campos[self::ANCLA_EXTRAS] ?? null) === self::POR_LLENAR) {
                        $campos[$campo] = $estado;
                    }

                    continue;
                }

                $campos[$campo] = $estado;

                $total++;
                $etiquetas[$estado][$extra['seccion']][] = $extra['etiqueta'];
                $unidades += $estado === self::POR_LLENAR ? 1 : 0;
            }
        }

        $secciones = [];

        foreach (array_keys(CatalogoSolicitudEmpleo::SECCIONES) as $seccion) {
            $secciones[$seccion] = [
                'por_llenar' => $etiquetas[self::POR_LLENAR][$seccion] ?? [],
                'en_revision' => $etiquetas[self::EN_REVISION][$seccion] ?? [],
                'de_rh' => $etiquetas[self::DE_RH][$seccion] ?? [],
            ];
        }

        return ['secciones' => $secciones, 'campos' => $campos, 'abiertos' => $abiertos, 'total' => $total, 'unidades' => $unidades, 'completo' => $completitud['completo']];
    }

    /**
     * @param  Situacion  $situacion
     * @return array{por_llenar: int, en_revision: int, secciones_pendientes: int, total: int, porcentaje: int, listo: bool, completo: bool, frase: string}
     */
    private function resumir(array $situacion): array
    {
        $porLlenar = 0;
        $enRevision = 0;
        $pendientes = 0;

        foreach ($situacion['secciones'] as $seccion) {
            $porLlenar += count($seccion['por_llenar']);
            $enRevision += count($seccion['en_revision']);
            $pendientes += $seccion['por_llenar'] !== [] ? 1 : 0;
        }

        $total = $situacion['total'];

        return [
            'por_llenar' => $porLlenar,
            'en_revision' => $enRevision,
            'secciones_pendientes' => $pendientes,
            'total' => $total,
            'porcentaje' => $total > 0 ? (int) floor(max(0, $total - $situacion['unidades']) * 100 / $total) : 100,
            'listo' => $porLlenar === 0,
            'completo' => $situacion['completo'],
            'frase' => self::frase($porLlenar, $pendientes),
        ];
    }

    /** «Te faltan 6 datos en 2 secciones» (el mismo texto en todas las pantallas). */
    public static function frase(int $porLlenar, int $secciones): string
    {
        if ($porLlenar === 0) {
            return 'No te falta ningún dato';
        }

        return sprintf(
            '%s en %d %s',
            $porLlenar === 1 ? 'Te falta 1 dato' : sprintf('Te faltan %d datos', $porLlenar),
            $secciones,
            $secciones === 1 ? 'sección' : 'secciones',
        );
    }

    /**
     * ¿La persona puede mandar sus datos? En su registro, siempre (captura
     * inicial); después, con la misma regla que «Mis solicitudes»
     * (SolicitudInternaPolicy::create). Si no puede, esos datos los completa
     * RH: ni se le piden ni le cuentan como pendientes.
     */
    private function puedeEnviar(Colaborador $colaborador): bool
    {
        $colaborador->loadMissing('user');
        $usuario = $colaborador->user;

        if ($usuario === null) {
            return false;
        }

        return app(RegistroAppService::class)->aplica($usuario) || Gate::forUser($usuario)->allows('create', SolicitudInterna::class);
    }

    /**
     * @param  array<array-key, mixed>  $campo  Campo de PerfilIngresoService::estado().
     * @param  Abierto|null  $abierto
     * @param  array{campo: string, etiqueta: string, tipo: string, opciones?: list<array{value: string, label: string}>}|null  $control
     * @return array<array-key, mixed>
     */
    private function campoPersonal(array $campo, ?string $estado, ?array $abierto, ?array $control): array
    {
        $nombre = is_string($campo['campo'] ?? null) ? $campo['campo'] : '';
        $campo = [...$campo, 'en_revision' => false, 'propuesto' => null, 'propuesto_valor' => null, 'solicitud_id' => null, 'requiere_respuesta' => false, 'solo_rh' => false, 'teclado' => null];

        return match ($estado) {
            self::EN_REVISION => [...$campo, ...$this->enRevision($abierto)],
            self::DE_RH => [...$campo, 'bloqueado' => true, 'solo_rh' => true],
            self::POR_LLENAR => [
                ...$campo,
                'bloqueado' => false,
                'tipo' => $this->tipoDe($control),
                'opciones' => $control['opciones'] ?? [],
                'max' => self::LARGOS[$nombre] ?? 191,
                'teclado' => self::TECLADOS[$nombre] ?? null,
                'ayuda' => self::AYUDAS[$nombre] ?? null,
            ],
            default => $campo,
        };
    }

    /**
     * @param  array{seccion: string, etiqueta: string, requerido: bool, ayuda: string}  $definicion
     * @param  Abierto|null  $abierto
     * @return array<string, mixed>
     */
    private function campoExtra(string $campo, array $definicion, string $estado, ?array $abierto): array
    {
        $base = [
            'campo' => $campo,
            'etiqueta' => $definicion['etiqueta'],
            'tipo' => 'texto',
            'opciones' => [],
            'valor' => null,
            'valor_legible' => null,
            'sugerencia' => null,
            'requerido' => $definicion['requerido'],
            'requerido_si' => null,
            'permite_no_aplica' => false,
            'no_aplica' => false,
            'bloqueado' => false,
            'origen' => 'datos_personales',
            'documentos' => [],
            'min' => null,
            'max' => self::LARGOS[$campo] ?? 191,
            'ayuda' => $definicion['ayuda'],
            'en_revision' => false,
            'propuesto' => null,
            'propuesto_valor' => null,
            'solicitud_id' => null,
            'requiere_respuesta' => false,
            'solo_rh' => false,
            'teclado' => null,
        ];

        return $estado === self::EN_REVISION ? [...$base, ...$this->enRevision($abierto)] : $base;
    }

    /**
     * @param  Abierto|null  $abierto
     * @return array{bloqueado: bool, en_revision: bool, propuesto: string|null, propuesto_valor: string|null, solicitud_id: int|null, requiere_respuesta: bool}
     */
    private function enRevision(?array $abierto): array
    {
        return [
            'bloqueado' => true,
            'en_revision' => true,
            'propuesto' => $abierto['legible'] ?? null,
            'propuesto_valor' => $abierto['valor'] ?? null,
            'solicitud_id' => $abierto['solicitud_id'] ?? null,
            'requiere_respuesta' => $abierto['requiere_respuesta'] ?? false,
        ];
    }

    /**
     * Control del catálogo de la solicitud que corresponde al de «datos
     * personales» (lista → select, fecha → fecha, lo demás texto).
     *
     * @param  array{campo: string, etiqueta: string, tipo: string, opciones?: list<array{value: string, label: string}>}|null  $control
     */
    private function tipoDe(?array $control): string
    {
        return match ($control['tipo'] ?? 'text') {
            'opciones' => 'select',
            'date' => 'fecha',
            default => 'texto',
        };
    }

    private function etiqueta(string $campo): string
    {
        if (isset(self::EXTRAS[$campo])) {
            return self::EXTRAS[$campo]['etiqueta'];
        }

        return CatalogoSolicitudEmpleo::existe($campo) ? CatalogoSolicitudEmpleo::campo($campo)['etiqueta'] : $campo;
    }
}
