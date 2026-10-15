<?php

namespace App\Services\Expedientes;

use App\Enums\EstadoCivil;
use App\Enums\Genero;
use App\Enums\TipoSolicitudInterna;
use App\Models\Colaborador;
use App\Models\SolicitudInterna;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * «Completar dato faltante» (el campo está vacío) y «Solicitar cambio de
 * información» (ya tiene valor): el colaborador NUNCA edita su expediente directo
 * (ver docs/ROLES_Y_NAVEGACION.md); propone valores con una solicitud de
 * actualización de datos y, SOLO cuando Recursos Humanos la autoriza, se
 * aplican a su expediente. Lo que había antes queda en la solicitud
 * (`datos_anteriores`) y en la auditoría.
 *
 * Únicamente los datos PERSONALES contractuales de DatosFaltantesService
 * (los laborales los captura RH).
 */
class ActualizacionDatosService
{
    public function __construct(private readonly AuditoriaService $auditoria) {}

    /**
     * Cómo se captura cada dato (la app y la web dibujan el control).
     *
     * @return list<array{campo: string, etiqueta: string, tipo: string, opciones?: list<array{value: string, label: string}>}>
     */
    public function camposCaptura(): array
    {
        $tipos = [
            'genero' => 'opciones',
            'estado_civil' => 'opciones',
            'fecha_nacimiento' => 'date',
            'telefono' => 'tel',
            'contacto_emergencia_telefono' => 'tel',
            'correo_personal' => 'email',
            'domicilio_cp' => 'number',
            'nss' => 'number',
        ];
        $opciones = [
            'genero' => array_map(fn (Genero $g) => ['value' => $g->value, 'label' => $g->etiqueta()], [Genero::Femenino, Genero::Masculino]),
            'estado_civil' => EstadoCivil::opciones(),
        ];
        $campos = [];

        foreach (DatosFaltantesService::PERSONALES as $campo => $etiqueta) {
            $fila = ['campo' => $campo, 'etiqueta' => $etiqueta, 'tipo' => $tipos[$campo] ?? 'text'];

            if (isset($opciones[$campo])) {
                $fila['opciones'] = $opciones[$campo];
            }

            $campos[] = $fila;
        }

        return $campos;
    }

    /**
     * Los datos faltantes (DatosFaltantesService) con su control de captura,
     * para «Completar mis datos» en la app.
     *
     * @param  list<array{campo: string, etiqueta: string}>  $faltan
     * @return list<array{campo: string, etiqueta: string, tipo: string, opciones?: list<array{value: string, label: string}>}>
     */
    public function conCaptura(array $faltan): array
    {
        $captura = collect($this->camposCaptura())->keyBy('campo');

        return array_values(array_filter(array_map(fn (array $f) => $captura->get($f['campo']), $faltan)));
    }

    /**
     * Reglas de `datos.*` para StoreSolicitudInternaRequest.
     *
     * @return array<string, mixed>
     */
    public function reglas(): array
    {
        return [
            // Solo datos personales: un dato laboral (puesto, sueldo…) lo captura RH.
            'datos' => ['nullable', sprintf('array:%s', implode(',', array_keys(DatosFaltantesService::PERSONALES))), 'min:1'],
            'datos.*' => ['nullable', 'string', 'max:255'],
            'datos.genero' => ['nullable', Rule::in([Genero::Femenino->value, Genero::Masculino->value])],
            'datos.estado_civil' => ['nullable', Rule::enum(EstadoCivil::class)],
            'datos.fecha_nacimiento' => ['nullable', 'date_format:Y-m-d', 'before:-15 years'],
            'datos.curp' => ['nullable', 'regex:/^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d$/i'],
            'datos.rfc' => ['nullable', 'regex:/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/i'],
            'datos.nss' => ['nullable', 'digits:11'],
            'datos.telefono' => ['nullable', 'regex:/^\d{10}$/'],
            'datos.contacto_emergencia_telefono' => ['nullable', 'regex:/^\d{10}$/'],
            'datos.correo_personal' => ['nullable', 'email', 'max:150'],
            'datos.domicilio_cp' => ['nullable', 'digits:5'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function mensajes(): array
    {
        return [
            'datos.array' => 'Solo puedes proponer datos personales; los laborales los captura Recursos Humanos.',
            'datos.curp.regex' => 'La CURP no tiene un formato válido (18 caracteres).',
            'datos.rfc.regex' => 'El RFC no tiene un formato válido.',
            'datos.nss.digits' => 'El NSS son 11 dígitos.',
            'datos.telefono.regex' => 'El teléfono son 10 dígitos.',
            'datos.contacto_emergencia_telefono.regex' => 'El teléfono de emergencia son 10 dígitos.',
            'datos.domicilio_cp.digits' => 'El código postal son 5 dígitos.',
            'datos.fecha_nacimiento.before' => 'Revisa la fecha de nacimiento.',
            'datos.correo_personal.email' => 'Escribe un correo válido.',
        ];
    }

    /**
     * Cada dato personal con su valor vigente, para el formulario mixto:
     * vacío → «Completar información»; con valor → «Solicitar cambio».
     *
     * @return list<array{campo: string, etiqueta: string, tipo: string, opciones?: list<array{value: string, label: string}>, actual: string|null, actual_legible: string|null}>
     */
    public function camposConValor(Colaborador $colaborador): array
    {
        return array_map(function (array $c) use ($colaborador): array {
            $actual = $this->comoTexto($colaborador->getAttribute($c['campo']));

            return [...$c, 'actual' => $actual, 'actual_legible' => $this->legible($c['campo'], $actual)];
        }, $this->camposCaptura());
    }

    /**
     * Normaliza lo propuesto: solo campos personales conocidos y con valor
     * (CURP/RFC en mayúsculas). Un valor igual al del expediente no es un
     * cambio y se descarta. Sin ningún dato, error claro.
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, string>
     *
     * @throws ValidationException
     */
    public function normalizar(array $datos, ?Colaborador $colaborador = null): array
    {
        $limpios = [];

        foreach (array_keys(DatosFaltantesService::PERSONALES) as $campo) {
            $valor = trim((string) ($datos[$campo] ?? ''));

            if ($valor === '') {
                continue;
            }

            $valor = in_array($campo, ['curp', 'rfc'], true) ? mb_strtoupper($valor) : $valor;

            if ($colaborador !== null && $valor === $this->comoTexto($colaborador->getAttribute($campo))) {
                continue;
            }

            $limpios[$campo] = $valor;
        }

        if ($limpios === []) {
            throw ValidationException::withMessages(['datos' => $datos === [] ? 'Captura al menos un dato para actualizar.' : 'Lo que escribiste ya está en tu expediente: no hay ningún dato que completar o cambiar.']);
        }

        return $limpios;
    }

    /**
     * Lo que había en el expediente AL SOLICITAR cada campo propuesto
     * (null = estaba vacío: es completar, no cambiar).
     *
     * @param  array<string, string>  $propuestos
     * @return array<string, string|null>
     */
    public function base(Colaborador $colaborador, array $propuestos): array
    {
        $base = [];

        foreach (array_keys($propuestos) as $campo) {
            $base[$campo] = $this->comoTexto($colaborador->getAttribute($campo));
        }

        return $base;
    }

    /**
     * «completar», «cambio» o «mixta» (unos campos estaban vacíos y otros
     * no). null si no es una actualización de datos estructurada.
     */
    public function clase(SolicitudInterna $solicitud): ?string
    {
        $filas = $this->comparativo($solicitud);

        if ($filas === null) {
            return null;
        }

        $clases = array_values(array_unique(array_column($filas, 'clase')));

        return count($clases) === 1 ? $clases[0] : 'mixta';
    }

    /** Nombre de la solicitud según lo que realmente pide. */
    public function etiqueta(SolicitudInterna $solicitud): ?string
    {
        return match ($this->clase($solicitud)) {
            'completar' => 'Completar dato faltante',
            'cambio' => 'Solicitar cambio de información',
            'mixta' => 'Completar y cambiar información',
            default => null,
        };
    }

    /**
     * Texto de la solicitud cuando el colaborador no escribió motivo.
     *
     * @param  array<string, string>  $datos
     * @param  array<string, string|null>  $base
     */
    public function motivoPorDefecto(array $datos, array $base = []): string
    {
        $completar = [];
        $cambiar = [];

        foreach (array_keys($datos) as $campo) {
            if (($base[$campo] ?? null) === null) {
                $completar[] = DatosFaltantesService::PERSONALES[$campo];
            } else {
                $cambiar[] = DatosFaltantesService::PERSONALES[$campo];
            }
        }

        return trim(implode(' ', array_filter([
            $completar !== [] ? sprintf('Completar: %s.', implode(', ', $completar)) : null,
            $cambiar !== [] ? sprintf('Cambiar: %s.', implode(', ', $cambiar)) : null,
        ])));
    }

    /**
     * Comparativo para quien revisa (web/app RH) y para el colaborador:
     * dato, valor en el expediente y valor propuesto. Ya aplicada, «actual»
     * es lo que había antes de aplicarla.
     *
     * @return list<array{campo: string, etiqueta: string, actual: string|null, propuesto: string, clase: string}>|null
     */
    public function comparativo(SolicitudInterna $solicitud): ?array
    {
        $propuestos = $solicitud->datos_propuestos ?? [];

        if ($solicitud->tipo !== TipoSolicitudInterna::ActualizacionDatos || $propuestos === []) {
            return null;
        }

        $colaborador = $solicitud->colaborador_id !== null ? Colaborador::query()->withTrashed()->where('id', $solicitud->colaborador_id)->first() : null;
        $filas = [];

        foreach ($propuestos as $campo => $valor) {
            $actual = $solicitud->datos_anteriores !== null
                ? ($solicitud->datos_anteriores[$campo] ?? null)
                : $this->comoTexto($colaborador?->getAttribute($campo));

            // Lo que había al solicitar decide si era completar o cambiar
            // (solicitudes previas a datos_base: lo que había al aplicarla).
            $alSolicitar = $solicitud->datos_base !== null ? ($solicitud->datos_base[$campo] ?? null) : $actual;

            $filas[] = [
                'campo' => $campo,
                'etiqueta' => DatosFaltantesService::PERSONALES[$campo] ?? $campo,
                'actual' => $this->legible($campo, $actual),
                'propuesto' => (string) $this->legible($campo, $valor),
                'clase' => $alSolicitar === null ? 'completar' : 'cambio',
            ];
        }

        return $filas;
    }

    /**
     * Aplica al expediente lo propuesto en una solicitud AUTORIZADA por RH.
     * Debe llamarse dentro de la transacción de la aprobación. Idempotente:
     * si ya se aplicó (`datos_anteriores` guardado), no hace nada.
     */
    public function aplicar(SolicitudInterna $solicitud, User $actor): void
    {
        $propuestos = $solicitud->datos_propuestos ?? [];

        if ($propuestos === [] || $solicitud->datos_anteriores !== null) {
            return;
        }

        $colaborador = Colaborador::query()->whereKey($solicitud->colaborador_id)->lockForUpdate()->first();

        if ($colaborador === null) {
            return;
        }

        $anteriores = [];
        $cambios = [];
        $base = $solicitud->datos_base;

        foreach ($propuestos as $campo => $valor) {
            if (! array_key_exists($campo, DatosFaltantesService::PERSONALES)) {
                continue;
            }

            // Nunca se sobrescribe en silencio: si el dato cambió en el
            // expediente desde que se pidió, RH debe rechazar y pedir una
            // solicitud nueva sobre el valor vigente.
            $vigente = $this->comoTexto($colaborador->getAttribute($campo));

            if ($base !== null && array_key_exists($campo, $base) && $base[$campo] !== $vigente) {
                throw ValidationException::withMessages(['datos' => sprintf(
                    '«%s» cambió en el expediente después de la solicitud (antes: %s; ahora: %s). Rechaza esta solicitud para que se pida de nuevo sobre el valor vigente.',
                    DatosFaltantesService::PERSONALES[$campo],
                    $this->legible($campo, $base[$campo]) ?? 'vacío',
                    $this->legible($campo, $vigente) ?? 'vacío',
                )]);
            }

            $anteriores[$campo] = $this->comoTexto($colaborador->getAttribute($campo));
            $cambios[$campo] = $valor;
        }

        $colaborador->update($cambios);
        $solicitud->update(['datos_anteriores' => $anteriores]);

        $this->auditoria->registrar('expediente_datos_actualizados', $colaborador, $actor, [
            'solicitud' => $solicitud->folio,
            'campos' => array_keys($cambios),
        ]);
    }

    private function comoTexto(mixed $valor): ?string
    {
        return match (true) {
            $valor instanceof BackedEnum => (string) $valor->value,
            $valor instanceof DateTimeInterface => $valor->format('Y-m-d'),
            $valor === null, $valor === '' => null,
            default => (string) $valor,
        };
    }

    /** Valor como lo lee una persona (etiqueta del enum, fecha dd/mm/aaaa). */
    private function legible(string $campo, ?string $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        return match ($campo) {
            'genero' => Genero::tryFrom($valor)?->etiqueta() ?? $valor,
            'estado_civil' => EstadoCivil::tryFrom($valor)?->etiqueta() ?? $valor,
            'fecha_nacimiento' => preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $valor, $m) === 1 ? sprintf('%s/%s/%s', $m[3], $m[2], $m[1]) : $valor,
            default => $valor,
        };
    }
}
