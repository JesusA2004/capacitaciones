<?php

namespace App\Services\Incorporacion;

use App\Enums\EstadoUsuario;
use App\Models\Colaborador;
use App\Models\ColaboradorDatosMedicos;
use App\Models\IncorporacionInvitacion;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Colaboradores\DomicilioService;
use App\Services\Expedientes\ActualizacionDatosService;
use App\Services\Expedientes\DatosFaltantesService;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * REGISTRO COMPLETO EN LA APP antes de entrar (docs/REGISTRO_APP.md).
 *
 * Quien crea su cuenta con un QR de incorporación captura en el mismo
 * flujo TODOS sus datos personales contractuales (los de
 * DatosFaltantesService::PERSONALES: CURP, RFC, NSS, nacimiento, género,
 * estado civil, nacionalidad, profesión, clave de elector, domicilio
 * estructurado, contacto de emergencia, beneficiario…), opcionalmente sus
 * datos médicos, y sube sus DOCUMENTOS REQUERIDOS del expediente; solo
 * entonces la app lo deja pasar al inicio.
 *
 * A QUIÉN APLICA (regla de bloqueo): cuenta cuyo colaborador está
 * `en_incorporacion`, sin decisión de RH sobre su incorporación, y que se
 * registró con una invitación QR que aún no marca
 * `metadata.registro_app_completado_en`. Nunca aplica a colaboradores
 * activos ni a cuentas creadas por RH/importación: ellos siguen con
 * «Completa tu información» y las correcciones de siempre.
 *
 * Captura inicial ≠ editar el expediente: durante el registro la persona
 * LLENA datos vacíos (igual que el registro por QR ya guardaba CURP/RFC/NSS
 * directo). Un dato que ya existía (capturado por RH en la contratación)
 * queda bloqueado: si está mal, se pide corrección como siempre. Lo que la
 * propia persona capturó en este registro sí puede corregirlo mientras el
 * registro no termina. Todo queda en la auditoría (sin valores sensibles).
 */
class RegistroAppService
{
    /** Cabecera con la que la app declara que entiende el registro completo (APKs viejas no la mandan). */
    public const CABECERA_CLIENTE = 'X-People-Registro';

    /** Documentos que todavía no cuentan como «subidos» para el registro. */
    private const ESTADOS_DOCUMENTO_FALTANTE = ['pendiente', 'rechazado', 'requiere_correccion', 'vencido', 'cambio_autorizado'];

    public function __construct(
        private readonly DatosFaltantesService $datosFaltantes,
        private readonly ActualizacionDatosService $actualizacion,
        private readonly IncorporacionService $incorporacion,
        private readonly DomicilioService $domicilios,
        private readonly AuditoriaService $auditoria,
    ) {}

    /** Invitación QR con la que esta cuenta se registró (la más reciente). */
    public function invitacionDe(User $usuario): ?IncorporacionInvitacion
    {
        return IncorporacionInvitacion::query()
            ->where(fn ($q) => $q->where('usado_por_id', $usuario->id)->orWhere('user_id', $usuario->id))
            ->whereNotNull('used_at')
            ->latest('id')
            ->first();
    }

    /** ¿Esta cuenta debe terminar su registro en la app antes de entrar? */
    public function aplica(User $usuario): bool
    {
        $colaborador = $usuario->colaborador;

        if ($colaborador === null || $colaborador->estatus !== EstadoUsuario::EnIncorporacion || $colaborador->incorporacion_decision !== null) {
            return false;
        }

        $invitacion = $this->invitacionDe($usuario);

        return $invitacion !== null && blank($invitacion->metadata['registro_app_completado_en'] ?? null);
    }

    public function registroCompleto(User $usuario): bool
    {
        return ! $this->aplica($usuario);
    }

    /**
     * Campos personales que el registro exige (todos los contractuales salvo
     * los que config('incorporacion.registro_app.campos_opcionales') libere).
     *
     * @return array<string, string>
     */
    public function camposObligatorios(): array
    {
        $opcionales = (array) config('incorporacion.registro_app.campos_opcionales', []);

        return array_diff_key(DatosFaltantesService::PERSONALES, array_flip($opcionales));
    }

    /**
     * Estado completo del registro para el asistente de la app.
     *
     * @return array<string, mixed>
     */
    public function estado(User $usuario): array
    {
        $colaborador = $this->colaboradorDe($usuario);
        $aplica = $this->aplica($usuario);
        $invitacion = $this->invitacionDe($usuario);
        $capturados = $this->camposCapturados($invitacion);
        $faltanDatos = $this->datosFaltantes($colaborador);
        $faltanDocumentos = $this->documentosFaltantes($colaborador);
        $documentos = $this->incorporacion->estadoIncorporacion($colaborador);

        return [
            'aplica' => $aplica,
            'registro_completo' => ! $aplica,
            'datos' => [
                'completo' => $faltanDatos === [],
                'faltan' => array_map(fn (string $campo): array => ['campo' => $campo, 'etiqueta' => $this->camposObligatorios()[$campo]], $faltanDatos),
                // Cada dato con su control de captura y su valor vigente.
                // `bloqueado`: ya lo registró RH (se corrige por solicitud).
                'campos' => array_map(fn (array $campo): array => [
                    ...$campo,
                    'obligatorio' => array_key_exists($campo['campo'], $this->camposObligatorios()),
                    'bloqueado' => $campo['actual'] !== null && ! in_array($campo['campo'], $capturados, true),
                ], $this->actualizacion->camposConValor($colaborador)),
            ],
            'datos_medicos' => [
                'capturados' => $this->datosMedicosDe($colaborador) !== null,
            ],
            'documentos' => [
                'completo' => $faltanDocumentos === [],
                'progreso' => $documentos['progreso'],
                'lista' => array_values(array_filter($documentos['documentos'], fn (array $d): bool => (bool) $d['obligatorio'])),
            ],
            'puede_finalizar' => $aplica && $faltanDatos === [] && $faltanDocumentos === [],
            'pendientes' => $this->pendientesLegibles($faltanDatos, $faltanDocumentos, $documentos['documentos']),
        ];
    }

    /**
     * Guarda los datos de un paso del asistente (captura inicial). Solo
     * mientras el registro está abierto.
     *
     * @param  array<string, mixed>  $datos  campo => valor (claves de ActualizacionDatosService::capturables()).
     * @param  array{condicion_medica?: string|null, alergias?: string|null}|null  $medicos
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function guardarDatos(User $usuario, array $datos, ?array $medicos = null): array
    {
        $this->exigirRegistroAbierto($usuario);

        $colaborador = $this->colaboradorDe($usuario);
        $invitacion = $this->invitacionDe($usuario);
        $capturados = $this->camposCapturados($invitacion);
        $cambios = [];
        $bloqueados = [];

        // Los datos médicos pueden llegar dentro de `datos` (mismas claves
        // que la solicitud de actualización) o en `datos_medicos`: van a su
        // tabla cifrada, nunca a colaboradores.
        foreach (array_keys(ActualizacionDatosService::MEDICOS) as $campoMedico) {
            if (array_key_exists($campoMedico, $datos)) {
                $medicos ??= [];
                $medicos[$campoMedico] = is_scalar($datos[$campoMedico]) ? (string) $datos[$campoMedico] : null;
            }
        }

        foreach (array_keys($this->actualizacion->capturables()) as $campo) {
            if (! array_key_exists($campo, $datos) || $this->actualizacion->esSensible($campo)) {
                continue;
            }

            $valor = $this->normalizar($campo, $datos[$campo]);
            $vigente = $this->comoTexto($colaborador->getAttribute($campo));

            if ($valor === $vigente) {
                continue;
            }

            if ($vigente !== null && ! in_array($campo, $capturados, true)) {
                $bloqueados[] = $this->actualizacion->capturables()[$campo];

                continue;
            }

            $cambios[$campo] = $valor;
        }

        if ($bloqueados !== []) {
            throw ValidationException::withMessages(['datos' => sprintf(
                'Recursos Humanos ya registró: %s. Si algo está mal, solicita una corrección cuando entres a la app.',
                implode(', ', $bloqueados),
            )]);
        }

        DB::transaction(function () use ($colaborador, $cambios, $medicos, $invitacion, $capturados, $usuario): void {
            if ($cambios !== []) {
                $colaborador->update($cambios);
            }

            $medicosGuardados = $medicos !== null && $this->guardarMedicos($colaborador, $medicos, $usuario);

            if ($invitacion !== null && ($cambios !== [] || $medicosGuardados)) {
                $nuevos = [...array_keys($cambios), ...($medicosGuardados ? array_keys(ActualizacionDatosService::MEDICOS) : [])];
                $invitacion->forceFill(['metadata' => [
                    ...($invitacion->metadata ?? []),
                    'registro_app_campos' => array_values(array_unique([...$capturados, ...$nuevos])),
                ]])->save();
            }

            if ($cambios !== [] || $medicosGuardados) {
                // Nunca los valores (CURP, NSS, datos médicos): solo qué se capturó.
                $this->auditoria->registrar('registro_app_datos_capturados', $colaborador, $usuario, [
                    'campos' => array_keys($cambios),
                    'datos_medicos' => $medicosGuardados,
                    'origen' => 'app',
                ]);
            }
        });

        return $this->estado($usuario);
    }

    /**
     * Cierra el registro: exige todos los datos obligatorios y todos los
     * documentos requeridos subidos (en revisión o aprobados). A partir de
     * aquí la persona entra a la app y cualquier cambio es por corrección.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function finalizar(User $usuario): array
    {
        $this->exigirRegistroAbierto($usuario);

        $colaborador = $this->colaboradorDe($usuario);
        $faltanDatos = $this->datosFaltantes($colaborador);
        $faltanDocumentos = $this->documentosFaltantes($colaborador);

        if ($faltanDatos !== [] || $faltanDocumentos !== []) {
            $documentos = $this->incorporacion->estadoIncorporacion($colaborador)['documentos'];

            throw ValidationException::withMessages([
                'registro' => sprintf('Todavía falta: %s.', implode('; ', $this->pendientesLegibles($faltanDatos, $faltanDocumentos, $documentos))),
            ]);
        }

        $invitacion = $this->invitacionDe($usuario);

        if ($invitacion !== null) {
            DB::transaction(function () use ($invitacion, $colaborador, $usuario): void {
                $invitacion->forceFill(['metadata' => [
                    ...($invitacion->metadata ?? []),
                    'registro_app_completado_en' => now()->toIso8601String(),
                ]])->save();

                $this->auditoria->registrar('registro_app_completado', $colaborador, $usuario, ['origen' => 'app']);
            });
        }

        return $this->estado($usuario);
    }

    /**
     * @return list<string> Campos obligatorios vacíos.
     */
    private function datosFaltantes(Colaborador $colaborador): array
    {
        $vacios = array_column($this->datosFaltantes->faltantes($colaborador)['personales'], 'campo');

        return array_values(array_filter($vacios, fn (string $campo): bool => array_key_exists($campo, $this->camposObligatorios())));
    }

    /**
     * @return list<string> Nombre de cada documento requerido sin archivo válido.
     */
    private function documentosFaltantes(Colaborador $colaborador): array
    {
        $documentos = $this->incorporacion->estadoIncorporacion($colaborador)['documentos'];

        return array_values(array_map(
            fn (array $d): string => (string) $d['nombre'],
            array_filter($documentos, fn (array $d): bool => (bool) $d['obligatorio'] && in_array($d['estado'], self::ESTADOS_DOCUMENTO_FALTANTE, true)),
        ));
    }

    /**
     * @param  list<string>  $faltanDatos
     * @param  list<string>  $faltanDocumentos
     * @param  array<int, array<string, mixed>>  $documentos
     * @return list<string>
     */
    private function pendientesLegibles(array $faltanDatos, array $faltanDocumentos, array $documentos): array
    {
        $pendientes = [];

        if ($faltanDatos !== []) {
            $etiquetas = array_map(fn (string $campo): string => $this->camposObligatorios()[$campo], $faltanDatos);
            $pendientes[] = sprintf('datos: %s', implode(', ', $etiquetas));
        }

        if ($faltanDocumentos !== []) {
            $pendientes[] = sprintf('documentos: %s', implode(', ', $faltanDocumentos));
        }

        return $pendientes;
    }

    /**
     * @param  array{condicion_medica?: string|null, alergias?: string|null}  $medicos
     */
    private function guardarMedicos(Colaborador $colaborador, array $medicos, User $usuario): bool
    {
        $condicion = trim((string) ($medicos['condicion_medica'] ?? ''));
        $alergias = trim((string) ($medicos['alergias'] ?? ''));
        $existente = $this->datosMedicosDe($colaborador);

        // Datos médicos que ya registró RH no se sobrescriben desde el registro.
        if ($existente !== null && $existente->fuente !== 'registro_app') {
            return false;
        }

        if ($existente === null && $condicion === '' && $alergias === '') {
            return false;
        }

        ColaboradorDatosMedicos::query()->updateOrCreate(
            ['colaborador_id' => $colaborador->id],
            [
                'condicion_medica' => $condicion !== '' ? $condicion : null,
                'alergias' => $alergias !== '' ? $alergias : null,
                'fuente' => 'registro_app',
                'actualizado_por' => $usuario->id,
            ],
        );

        return true;
    }

    private function datosMedicosDe(Colaborador $colaborador): ?ColaboradorDatosMedicos
    {
        return ColaboradorDatosMedicos::query()->where('colaborador_id', $colaborador->id)->first();
    }

    /**
     * @return list<string>
     */
    private function camposCapturados(?IncorporacionInvitacion $invitacion): array
    {
        $campos = $invitacion?->metadata['registro_app_campos'] ?? [];

        return is_array($campos) ? array_values(array_filter($campos, 'is_string')) : [];
    }

    private function exigirRegistroAbierto(User $usuario): void
    {
        if (! $this->aplica($usuario)) {
            throw ValidationException::withMessages([
                'registro' => 'Tu registro ya está completo. Si necesitas cambiar un dato, solicita una corrección desde la app.',
            ]);
        }
    }

    private function colaboradorDe(User $usuario): Colaborador
    {
        $colaborador = $usuario->colaborador;

        abort_if($colaborador === null, 422, 'Tu cuenta no tiene un colaborador enlazado.');

        return $colaborador;
    }

    private function normalizar(string $campo, mixed $valor): ?string
    {
        $texto = trim((string) (is_scalar($valor) ? $valor : ''));

        if ($texto === '') {
            return null;
        }

        return match (true) {
            in_array($campo, ['curp', 'rfc', 'clave_elector'], true) => mb_strtoupper($texto),
            in_array($campo, DomicilioService::CAMPOS, true) => $this->domicilios->normalizar($campo, $texto),
            default => $texto,
        };
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
}
