<?php

namespace App\Http\Requests\Rh;

use App\Enums\EstadoCivil;
use App\Enums\Genero;
use App\Models\Colaborador;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Colaboradores\DomicilioService;
use App\Services\Expedientes\DatosExpedienteService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Edición COMPLETA de los datos personales desde el expediente (super admin
 * global, RH admin por alcance — permiso `expedientes.editar` + alcance del
 * módulo Expedientes). El Directorio sigue siendo la edición rápida.
 *
 * - Nadie edita su propio expediente (solicita una corrección).
 * - Formato de CURP / RFC / NSS / clave de elector: solo se exige cuando el
 *   valor CAMBIA, para no bloquear el guardado de otro dato por un valor
 *   legado que ya estaba así.
 * - Motivo OBLIGATORIO si cambia un dato crítico
 *   (DatosExpedienteService::CAMPOS_CRITICOS).
 * - Datos médicos: solo con `expedientes.datos_medicos.ver` (quien no los
 *   ve tampoco los cambia).
 */
class ActualizarDatosPersonalesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $colaborador = $this->route('colaborador');
        $usuario = $this->user();

        // Nadie edita su propio expediente, aunque sea RH o administrador:
        // la persona pide una "Actualización de datos" (solicitud) y otra
        // persona de RH la revisa y aplica.
        if (! $usuario instanceof User || ! $colaborador instanceof Colaborador || $usuario->colaborador_id === $colaborador->id) {
            return false;
        }

        // Permiso de acción + alcance del módulo «Expedientes» sobre ESTE colaborador.
        return $usuario->can('expedientes.editar')
            && app(AlcanceOrganizacionalService::class)->puedeVerExpediente($usuario, $colaborador);
    }

    protected function prepareForValidation(): void
    {
        $limpios = [];

        foreach (['curp', 'rfc', 'clave_elector'] as $campo) {
            if (is_string($this->input($campo))) {
                $limpios[$campo] = mb_strtoupper(trim((string) $this->input($campo)));
            }
        }

        if (is_string($this->input('nss'))) {
            $limpios['nss'] = trim((string) $this->input('nss'));
        }

        $this->merge($limpios);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $usuario = $this->user();
        $veMedicos = $usuario instanceof User && $usuario->can('expedientes.datos_medicos.ver');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'apellidos' => ['sometimes', 'nullable', 'string', 'max:150'],
            'genero' => ['sometimes', 'nullable', Rule::enum(Genero::class)],
            'fecha_nacimiento' => ['nullable', 'date', 'before:today'],
            'nacionalidad' => ['sometimes', 'nullable', 'string', 'max:80'],
            'estado_civil' => ['sometimes', 'nullable', Rule::enum(EstadoCivil::class)],
            'lugar_nacimiento' => ['sometimes', 'nullable', 'string', 'max:150'],
            'profesion' => ['sometimes', 'nullable', 'string', 'max:150'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'telefono_corporativo' => ['nullable', 'string', 'max:30'],
            'curp' => ['nullable', 'string', 'max:18', $this->formatoSiCambia('curp', '/^[A-Z]{4}\d{6}[HMX][A-Z]{5}[A-Z0-9]\d$/', 'La CURP no tiene un formato válido (18 caracteres, p. ej. PEPJ900101HMSRRN09).')],
            'rfc' => ['nullable', 'string', 'max:13', $this->formatoSiCambia('rfc', '/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/u', 'El RFC no tiene un formato válido (12 o 13 caracteres con homoclave).')],
            'nss' => ['nullable', 'string', 'max:11', $this->formatoSiCambia('nss', '/^\d{11}$/', 'El NSS son 11 dígitos.')],
            'clave_elector' => ['sometimes', 'nullable', 'string', 'max:18', $this->formatoSiCambia('clave_elector', '/^[A-Z0-9]{18}$/', 'La clave de elector son 18 letras y números.')],
            // Texto único legado: si vienen los campos estructurados, el
            // modelo lo recompone a partir de ellos (DomicilioService).
            'domicilio' => ['nullable', 'string', 'max:255'],
            ...app(DomicilioService::class)->reglas(),
            'correo_personal' => ['nullable', 'email', 'max:255'],
            'correo_corporativo' => ['nullable', 'email', 'max:255'],
            'contacto_emergencia_nombre' => ['nullable', 'string', 'max:150'],
            'contacto_emergencia_telefono' => ['nullable', 'string', 'max:30'],
            'contacto_emergencia_parentesco' => ['sometimes', 'nullable', 'string', 'max:80'],
            'contacto_emergencia_direccion' => ['sometimes', 'nullable', 'string', 'max:255'],
            'beneficiario_nombre' => ['sometimes', 'nullable', 'string', 'max:150'],
            'beneficiario_parentesco' => ['sometimes', 'nullable', 'string', 'max:80'],
            'condicion_medica' => $veMedicos ? ['sometimes', 'nullable', 'string', 'max:1000'] : ['prohibited'],
            'alergias' => $veMedicos ? ['sometimes', 'nullable', 'string', 'max:1000'] : ['prohibited'],
            'motivo' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<int, Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $colaborador = $this->route('colaborador');

                if ($validator->errors()->isNotEmpty() || ! $colaborador instanceof Colaborador) {
                    return;
                }

                $servicio = app(DatosExpedienteService::class);
                $cambios = $servicio->cambiosPersonales($colaborador, $this->datos());

                if ($servicio->cambiaCritico($cambios) && trim((string) $this->input('motivo')) === '') {
                    $criticos = array_column(array_filter($cambios, fn (array $c): bool => $c['critico']), 'etiqueta');
                    $validator->errors()->add('motivo', sprintf('Escribe el motivo del cambio: modificas un dato crítico (%s).', implode(', ', $criticos)));
                }
            },
        ];
    }

    /**
     * Datos a guardar (sin el motivo).
     *
     * @return array<string, mixed>
     */
    public function datos(): array
    {
        $datos = $this->validated();
        unset($datos['motivo']);

        return $datos;
    }

    public function motivo(): ?string
    {
        $motivo = trim((string) $this->validated('motivo'));

        return $motivo !== '' ? $motivo : null;
    }

    /**
     * Valida el formato solo si el valor es distinto al guardado.
     */
    private function formatoSiCambia(string $campo, string $patron, string $mensaje): Closure
    {
        return function (string $atributo, mixed $valor, Closure $fallar) use ($campo, $patron, $mensaje): void {
            $colaborador = $this->route('colaborador');
            $actual = $colaborador instanceof Colaborador ? mb_strtoupper(trim((string) $colaborador->getAttribute($campo))) : '';

            if (is_string($valor) && $valor !== '' && $valor !== $actual && preg_match($patron, $valor) !== 1) {
                $fallar($mensaje);
            }
        };
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...app(DomicilioService::class)->mensajes(),
            'name.required' => 'El nombre no puede quedar vacío.',
            'fecha_nacimiento.before' => 'Revisa la fecha de nacimiento.',
            'correo_personal.email' => 'Escribe un correo personal válido.',
            'correo_corporativo.email' => 'Escribe un correo corporativo válido.',
            'condicion_medica.prohibited' => 'No tienes permiso para modificar datos médicos.',
            'alergias.prohibited' => 'No tienes permiso para modificar datos médicos.',
        ];
    }
}
