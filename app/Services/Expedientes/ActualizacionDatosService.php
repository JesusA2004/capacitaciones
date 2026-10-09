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
 * «Completar mis datos»: el colaborador NUNCA edita su expediente directo
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
     * Normaliza lo propuesto: solo campos personales conocidos y con valor
     * (CURP/RFC en mayúsculas). Sin ningún dato, error claro.
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, string>
     *
     * @throws ValidationException
     */
    public function normalizar(array $datos): array
    {
        $limpios = [];

        foreach (array_keys(DatosFaltantesService::PERSONALES) as $campo) {
            $valor = trim((string) ($datos[$campo] ?? ''));

            if ($valor === '') {
                continue;
            }

            $limpios[$campo] = in_array($campo, ['curp', 'rfc'], true) ? mb_strtoupper($valor) : $valor;
        }

        if ($limpios === []) {
            throw ValidationException::withMessages(['datos' => 'Captura al menos un dato para actualizar.']);
        }

        return $limpios;
    }

    /**
     * Texto de la solicitud cuando el colaborador no escribió motivo.
     *
     * @param  array<string, string>  $datos
     */
    public function motivoPorDefecto(array $datos): string
    {
        $etiquetas = array_map(fn (string $campo) => DatosFaltantesService::PERSONALES[$campo], array_keys($datos));

        return sprintf('Actualizar: %s.', implode(', ', $etiquetas));
    }

    /**
     * Comparativo para quien revisa (web/app RH) y para el colaborador:
     * dato, valor en el expediente y valor propuesto. Ya aplicada, «actual»
     * es lo que había antes de aplicarla.
     *
     * @return list<array{campo: string, etiqueta: string, actual: string|null, propuesto: string}>|null
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

            $filas[] = [
                'campo' => $campo,
                'etiqueta' => DatosFaltantesService::PERSONALES[$campo] ?? $campo,
                'actual' => $this->legible($campo, $actual),
                'propuesto' => (string) $this->legible($campo, $valor),
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

        foreach ($propuestos as $campo => $valor) {
            if (! array_key_exists($campo, DatosFaltantesService::PERSONALES)) {
                continue;
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
