<?php

namespace App\Services\Expedientes;

use App\Enums\EstadoSolicitudInterna;
use App\Enums\EstadoUsuario;
use App\Enums\TipoPuesto;
use App\Enums\TipoSolicitudInterna;
use App\Models\AvisoDatosFaltantes;
use App\Models\Colaborador;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Tareas\NotificadorRhService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Datos CONTRACTUALES faltantes del expediente (los que piden contratos y
 * formatos): RH los ve en el expediente («⚠ Faltan datos»), puede avisar al
 * colaborador (notificación + push, sin spam) y la app le muestra
 * «Completa tu información». El colaborador NUNCA edita directo sus datos
 * sensibles: los manda por la solicitud de actualización de datos y, al
 * aprobarla RH, la completitud se recalcula sola (se calcula en vivo).
 *
 * Datos personales (los aporta el colaborador) vs. laborales (los captura
 * RH: puesto, sucursal, ingreso, sueldo, tipo de contratación): al
 * colaborador solo se le piden los personales.
 */
class DatosFaltantesService
{
    /** Horas mínimas entre dos avisos al mismo colaborador. */
    private const HORAS_ENTRE_AVISOS = 24;

    /**
     * columna => etiqueta (lo aporta el colaborador).
     *
     * Debe cubrir TODO lo que los contratos piden del colaborador (misma
     * verdad que DatosDocumentoService::BASE): si un contrato exige un dato
     * que no está aquí, el expediente diría "completo" mientras la generación
     * sigue bloqueada por faltante. Por eso incluye nacionalidad, lugar de
     * nacimiento, clave de elector y profesión (contrato de confidencialidad
     * y no competencia).
     */
    public const PERSONALES = [
        'genero' => 'Sexo',
        'fecha_nacimiento' => 'Fecha de nacimiento',
        'nacionalidad' => 'Nacionalidad',
        'estado_civil' => 'Estado civil',
        'lugar_nacimiento' => 'Lugar de nacimiento',
        'curp' => 'CURP',
        'rfc' => 'RFC',
        'nss' => 'Número de seguridad social (NSS)',
        'clave_elector' => 'Clave de elector (INE)',
        'profesion' => 'Profesión u oficio',
        'telefono' => 'Teléfono celular',
        'correo_personal' => 'Correo electrónico',
        // Domicilio ESTRUCTURADO (DomicilioService): los contratos de
        // préstamo/pagaré piden cada parte en su celda. El texto único
        // `domicilio` se compone de estos campos; un registro con solo el
        // texto queda pendiente de capturarlos (no se parte adivinando).
        'domicilio_calle' => 'Calle',
        'domicilio_numero_exterior' => 'Número exterior',
        'domicilio_colonia' => 'Colonia',
        'domicilio_cp' => 'Código postal',
        'domicilio_municipio' => 'Municipio o alcaldía',
        'domicilio_estado' => 'Estado',
        'contacto_emergencia_nombre' => 'Contacto de emergencia',
        'contacto_emergencia_parentesco' => 'Parentesco del contacto de emergencia',
        'contacto_emergencia_telefono' => 'Teléfono de emergencia',
        'beneficiario_nombre' => 'Beneficiario',
        'beneficiario_parentesco' => 'Parentesco del beneficiario',
    ];

    /**
     * Datos personales que se CAPTURAN junto con los anteriores pero nunca
     * cuentan como faltantes (un domicilio sin número interior está completo).
     */
    public const PERSONALES_OPCIONALES = [
        'domicilio_numero_interior' => 'Número interior',
    ];

    /**
     * Claves que solo se aceptan por compatibilidad (APKs anteriores que
     * mandan el domicilio en un solo texto); ya no se piden.
     */
    public const PERSONALES_LEGADO = [
        'domicilio' => 'Domicilio',
    ];

    /** columna => etiqueta (lo captura RH). */
    public const LABORALES = [
        'puesto_id' => 'Puesto',
        'sucursal_principal_id' => 'Sucursal',
        'fecha_ingreso' => 'Fecha de ingreso',
        'sueldo_mensual' => 'Sueldo',
        'tipo_contratacion' => 'Tipo de contrato',
    ];

    /**
     * Secciones del expediente para mostrar «Identidad ✓ · Fiscal ⚠ ·
     * Domicilio ✗» en vez de solo un porcentaje. clave => [etiqueta, columnas].
     */
    public const SECCIONES = [
        'identidad' => ['Identidad', ['genero', 'fecha_nacimiento', 'nacionalidad', 'estado_civil', 'lugar_nacimiento', 'curp', 'clave_elector', 'profesion']],
        'fiscal' => ['Fiscal', ['rfc', 'nss']],
        'laboral' => ['Laboral', ['puesto_id', 'sucursal_principal_id', 'fecha_ingreso', 'sueldo_mensual', 'tipo_contratacion']],
        'domicilio' => ['Domicilio', ['domicilio_calle', 'domicilio_numero_exterior', 'domicilio_colonia', 'domicilio_cp', 'domicilio_municipio', 'domicilio_estado']],
        'contacto' => ['Contacto', ['telefono', 'correo_personal']],
        'emergencia' => ['Contacto de emergencia', ['contacto_emergencia_nombre', 'contacto_emergencia_parentesco', 'contacto_emergencia_telefono']],
        'beneficiario' => ['Beneficiario', ['beneficiario_nombre', 'beneficiario_parentesco']],
    ];

    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly NotificadorRhService $notificador,
    ) {}

    /**
     * @return array{completo: bool, personales: list<array{campo: string, etiqueta: string}>, laborales: list<array{campo: string, etiqueta: string}>}
     */
    public function faltantes(Colaborador $colaborador): array
    {
        $vacio = fn (string $campo): bool => blank($colaborador->getAttribute($campo)) || ($campo === 'sueldo_mensual' && (float) $colaborador->sueldo_mensual <= 0);
        $lista = fn (array $campos): array => array_values(array_map(
            fn (string $campo) => ['campo' => $campo, 'etiqueta' => $campos[$campo]],
            array_filter(array_keys($campos), $vacio),
        ));

        $personales = $lista(self::PERSONALES);
        // Sucursal solo es obligatoria para puestos operativos (de
        // sucursal): corporativo/remoto/comercial/administrativo puede no
        // tenerla sin que el expediente quede "incompleto" por eso (CLAUDE.md
        // §70-73) — la excepción se decide por el PUESTO, nunca por persona.
        $laborales = array_values(array_filter(
            $lista(self::LABORALES),
            fn (array $dato): bool => $dato['campo'] !== 'sucursal_principal_id' || $this->requiereSucursal($colaborador),
        ));

        return ['completo' => $personales === [] && $laborales === [], 'personales' => $personales, 'laborales' => $laborales];
    }

    private function requiereSucursal(Colaborador $colaborador): bool
    {
        return $colaborador->puesto?->tipo_puesto === TipoPuesto::Operativo;
    }

    /**
     * Resumen para el expediente (RH) y para la app (colaborador).
     *
     * @return array<string, mixed>
     */
    public function resumen(Colaborador $colaborador): array
    {
        $ultimo = AvisoDatosFaltantes::query()->with('enviadoPor:id,name,apellidos')->where('colaborador_id', $colaborador->id)->latest('id')->first();

        $faltantes = $this->faltantes($colaborador);

        return [
            ...$faltantes,
            'secciones' => $this->secciones($faltantes),
            // Faltante accionable independiente del formulario de datos
            // personales (CLAUDE.md §46-48): nunca se mezcla con `personales`
            // (ActualizacionDatosService::conCaptura() no sabe capturar una
            // foto) — la app lo muestra aparte con botón "Subir foto".
            'foto_perfil_pendiente' => blank($colaborador->foto_path),
            'solicitud_en_revision' => $this->solicitudAbierta($colaborador),
            'ultimo_aviso' => $ultimo !== null ? [
                'fecha' => $ultimo->created_at?->toIso8601String(),
                'enviado_por' => $ultimo->enviadoPor !== null ? trim($ultimo->enviadoPor->name.' '.$ultimo->enviadoPor->apellidos) : null,
                'campos' => $ultimo->campos,
            ] : null,
            'puede_avisar_de_nuevo' => $ultimo === null || $ultimo->created_at === null || $ultimo->created_at->lt(now()->subHours(self::HORAS_ENTRE_AVISOS)),
        ];
    }

    /**
     * Estado por sección: completo (✓), incompleto (⚠, falta parte) o
     * faltante (✗, no hay nada).
     *
     * @param  array{personales: list<array{campo: string, etiqueta: string}>, laborales: list<array{campo: string, etiqueta: string}>}  $faltantes
     * @return list<array{clave: string, etiqueta: string, estado: string, faltantes: list<string>}>
     */
    public function secciones(array $faltantes): array
    {
        $vacios = [];

        foreach ([...$faltantes['personales'], ...$faltantes['laborales']] as $dato) {
            $vacios[$dato['campo']] = $dato['etiqueta'];
        }

        $secciones = [];

        foreach (self::SECCIONES as $clave => [$etiqueta, $columnas]) {
            $sinDato = array_values(array_filter($columnas, fn (string $c): bool => isset($vacios[$c])));
            $estado = $sinDato === [] ? 'completo' : (count($sinDato) === count($columnas) ? 'faltante' : 'incompleto');
            $secciones[] = [
                'clave' => $clave,
                'etiqueta' => $etiqueta,
                'estado' => $estado,
                'faltantes' => array_map(fn (string $c): string => $vacios[$c], $sinDato),
            ];
        }

        return $secciones;
    }

    /**
     * «Avisar al colaborador»: notificación in-app + push (NotificadorRhService)
     * con los datos PERSONALES que faltan, guardando el snapshot. Sin spam:
     * a lo más un aviso cada 24 h por colaborador.
     *
     * @throws ValidationException Nada que pedir, sin cuenta o aviso reciente.
     */
    public function avisar(Colaborador $colaborador, User $rh): AvisoDatosFaltantes
    {
        $personales = $this->faltantes($colaborador)['personales'];

        if ($personales === []) {
            throw ValidationException::withMessages(['datos' => 'El colaborador no tiene datos personales pendientes (los laborales los captura RH).']);
        }

        $colaborador->loadMissing('user');

        if ($colaborador->user === null) {
            throw ValidationException::withMessages(['datos' => 'El colaborador todavía no tiene cuenta en la app para recibir el aviso.']);
        }

        $reciente = AvisoDatosFaltantes::query()
            ->where('colaborador_id', $colaborador->id)
            ->where('created_at', '>=', now()->subHours(self::HORAS_ENTRE_AVISOS))
            ->exists();

        if ($reciente) {
            throw ValidationException::withMessages(['datos' => 'Ya se le avisó en las últimas 24 horas.']);
        }

        $aviso = AvisoDatosFaltantes::query()->create([
            'colaborador_id' => $colaborador->id,
            'campos' => $personales,
            'enviado_por' => $rh->id,
        ]);

        $nombres = implode(', ', array_column($personales, 'etiqueta'));
        $this->notificador->notificar([$colaborador->user], 'datos_faltantes', 'Completa tu información', sprintf('Faltan: %s. Ábrelo en la app para completarlo.', $nombres), $colaborador, 'completar_datos', 'media');

        return $aviso;
    }

    /**
     * Cuántos colaboradores ACTIVOS del alcance tienen algún dato
     * contractual vacío (para «Mis pendientes»).
     */
    public function conteoIncompletos(User $usuario): int
    {
        return $this->filtrarIncompletos($this->alcance->limitarColaboradoresPorAlcance(Colaborador::query()->where('estatus', EstadoUsuario::Activo->value), $usuario))->count();
    }

    /**
     * Restringe una consulta de colaboradores a los que tienen algún dato
     * contractual vacío (filtro «Datos incompletos» de Expedientes).
     *
     * @param  Builder<Colaborador>  $consulta
     * @return Builder<Colaborador>
     */
    public function filtrarIncompletos(Builder $consulta): Builder
    {
        return $consulta->where(function (Builder $q): void {
            foreach (array_keys([...self::PERSONALES, ...self::LABORALES]) as $columna) {
                if ($columna === 'sucursal_principal_id') {
                    // Solo cuenta como faltante en puestos operativos (ver
                    // requiereSucursal()) — nunca para corporativo/remoto.
                    $q->orWhere(fn (Builder $sub) => $sub->whereNull('sucursal_principal_id')
                        ->whereHas('puesto', fn (Builder $p) => $p->where('tipo_puesto', TipoPuesto::Operativo->value)));

                    continue;
                }

                $q->orWhereNull($columna);

                if (! in_array($columna, ['puesto_id', 'sucursal_principal_id', 'fecha_ingreso', 'fecha_nacimiento', 'sueldo_mensual'], true)) {
                    $q->orWhere($columna, '');
                }
            }
        });
    }

    private function solicitudAbierta(Colaborador $colaborador): bool
    {
        return SolicitudInterna::query()
            ->where('colaborador_id', $colaborador->id)
            ->where('tipo', TipoSolicitudInterna::ActualizacionDatos->value)
            ->whereIn('estado', [EstadoSolicitudInterna::Enviada->value, EstadoSolicitudInterna::EnRevision->value, EstadoSolicitudInterna::RequiereCorreccion->value])
            ->exists();
    }
}
