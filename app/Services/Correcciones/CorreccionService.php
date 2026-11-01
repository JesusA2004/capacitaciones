<?php

namespace App\Services\Correcciones;

use App\Enums\ModoCorreccion;
use App\Exceptions\CorreccionConflictoException;
use App\Models\Colaborador;
use App\Models\Correccion;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Correcciones\Contracts\DominioCorreccion;
use App\Services\Correcciones\Dominios\AgendaDominio;
use App\Services\Correcciones\Dominios\ContratacionDominio;
use App\Services\Correcciones\Dominios\FiniquitoDominio;
use App\Services\Correcciones\Dominios\IncapacidadDominio;
use App\Services\Correcciones\Dominios\LaboralDominio;
use App\Services\Correcciones\Dominios\PersonalDominio;
use App\Services\Correcciones\Dominios\ReciboNominaDominio;
use App\Services\Correcciones\Dominios\SalarioDominio;
use App\Services\Salarios\AccesoSueldoService;
use App\Services\Tareas\NotificadorRhService;
use App\Support\OrigenPeticion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Orquesta una corrección: autoriza, compara la huella, exige motivo, delega en
 * el dominio y deja el historial inmutable, todo en una sola transacción. Web y
 * API usan este mismo servicio. Las reglas de cada dato siguen en sus servicios.
 */
class CorreccionService
{
    private const DOMINIOS = [
        'salario' => SalarioDominio::class,
        'laboral' => LaboralDominio::class,
        'personal' => PersonalDominio::class,
        'contratacion' => ContratacionDominio::class,
        'incapacidad' => IncapacidadDominio::class,
        'recibo_nomina' => ReciboNominaDominio::class,
        'finiquito' => FiniquitoDominio::class,
        'agenda' => AgendaDominio::class,
    ];

    public function __construct(
        private readonly AuditoriaService $auditoria,
        private readonly NotificadorRhService $notificador,
        private readonly AccesoSueldoService $accesoSueldo,
    ) {}

    /**
     * Dato vigente, modo de cada campo y huella para abrir el diálogo.
     *
     * @return array<string, mixed>
     */
    public function describir(string $dominio, int $id, User $actor): array
    {
        $manejador = $this->dominio($dominio);
        $sujeto = $this->sujetoAutorizado($manejador, $id, $actor);

        return $this->descripcion($manejador, $sujeto, $actor);
    }

    /**
     * Antes y después con sus afectados. No escribe nada.
     *
     * @param  array<string, mixed>  $valores
     * @param  array{tipo?: string|null, vigente_desde?: string|null}  $opciones
     * @return array{huella: string, huella_afectados: string, cambios: list<array<string, mixed>>, afectados: list<array<string, string>>}
     */
    public function previsualizar(string $dominio, int $id, array $valores, array $opciones, User $actor): array
    {
        $manejador = $this->dominio($dominio);
        $sujeto = $this->sujetoAutorizado($manejador, $id, $actor);
        $normalizados = $this->valoresValidos($manejador, $sujeto, $valores, $opciones, $actor);
        $afectados = $manejador->afectados($sujeto, $normalizados, $opciones);

        return [
            'huella' => $this->huella($manejador, $sujeto),
            'huella_afectados' => $this->huellaAfectados($afectados),
            'cambios' => $this->cambios($manejador, $sujeto, $normalizados, $actor),
            'afectados' => $afectados,
        ];
    }

    /**
     * @param  array<string, mixed>  $valores
     * @param  array{tipo?: string|null, vigente_desde?: string|null, huella_afectados?: string|null}  $opciones
     *
     * @throws ValidationException
     * @throws CorreccionConflictoException
     */
    public function aplicar(string $dominio, int $id, array $valores, string $motivo, string $huella, string $clave, array $opciones, User $actor): Correccion
    {
        $manejador = $this->dominio($dominio);
        $motivo = trim($motivo);
        $minimo = (int) config('correcciones.motivo_minimo', 5);

        // Primero el permiso: quien no puede corregir no recibe más información.
        $this->sujetoAutorizado($manejador, $id, $actor);

        if (mb_strlen($motivo) < $minimo || mb_strlen($motivo) > (int) config('correcciones.motivo_maximo', 500)) {
            throw ValidationException::withMessages(['motivo' => sprintf('Explica el motivo de la corrección (de %d a %d caracteres): queda en el historial.', $minimo, (int) config('correcciones.motivo_maximo', 500))]);
        }

        $previa = Correccion::query()->where('clave_idempotencia', $clave)->first();

        if ($previa !== null) {
            return $this->repetida($previa, $dominio, $id, $actor);
        }

        try {
            $correccion = DB::transaction(function () use ($manejador, $dominio, $id, $valores, $motivo, $huella, $clave, $opciones, $actor): Correccion {
                $sujeto = $manejador->sujeto($id, true) ?? throw new NotFoundHttpException('No se encontró el registro.');

                if (! $manejador->puedeEscribir($actor, $sujeto)) {
                    throw new AccessDeniedHttpException('No tienes permiso para corregir este registro.');
                }

                $huellaAnterior = $this->huella($manejador, $sujeto);

                if (! hash_equals($huellaAnterior, $huella)) {
                    throw new CorreccionConflictoException($this->descripcion($manejador, $sujeto, $actor));
                }

                $normalizados = $this->valoresValidos($manejador, $sujeto, $valores, $opciones, $actor);
                $afectados = $manejador->afectados($sujeto, $normalizados, $opciones);

                if (($opciones['huella_afectados'] ?? null) !== null && ! hash_equals($this->huellaAfectados($afectados), (string) $opciones['huella_afectados'])) {
                    throw new CorreccionConflictoException($this->descripcion($manejador, $sujeto, $actor), 'Los registros afectados cambiaron desde la vista previa. Revísalos y confirma de nuevo.');
                }

                $cambios = $this->cambios($manejador, $sujeto, $normalizados, $actor);
                $resultado = $manejador->aplicar($sujeto, $normalizados, $motivo, $opciones, $actor);
                $actualizado = $manejador->sujeto($id) ?? $sujeto;
                $contexto = OrigenPeticion::contexto();
                $colaborador = $manejador->colaboradorDe($sujeto);
                $modo = $resultado['modo'] ?? ModoCorreccion::Directo->value;
                $objeto = $resultado['resultado'] ?? null;

                $correccion = Correccion::query()->create([
                    'clave_idempotencia' => $clave,
                    'dominio' => $dominio,
                    'sujeto_type' => $sujeto->getMorphClass(),
                    'sujeto_id' => $sujeto->getKey(),
                    'colaborador_id' => $colaborador?->id,
                    'version' => Correccion::query()->where('sujeto_type', $sujeto->getMorphClass())->where('sujeto_id', $sujeto->getKey())->count() + 1,
                    'modo' => $modo,
                    'tipo' => $resultado['tipo'] ?? null,
                    'huella_anterior' => $huellaAnterior,
                    'huella_nueva' => $this->huella($manejador, $actualizado),
                    'motivo' => $motivo,
                    'vigente_desde' => $resultado['vigente_desde'] ?? null,
                    'afectados' => $afectados,
                    'resultado_type' => $objeto?->getMorphClass(),
                    'resultado_id' => $objeto?->getKey(),
                    'origen' => $contexto['origen'],
                    'ip' => $contexto['ip'],
                    'user_agent' => $contexto['user_agent'],
                    'actor_id' => $actor->id,
                ]);

                foreach ($cambios as $cambio) {
                    $correccion->campos()->create([
                        'campo' => $cambio['campo'],
                        'etiqueta' => $cambio['etiqueta'],
                        'valor_anterior' => $this->paraGuardar($cambio['antes_crudo'], $cambio['confidencial']),
                        'valor_nuevo' => $this->paraGuardar($cambio['despues_crudo'], $cambio['confidencial']),
                        'confidencial' => $cambio['confidencial'],
                    ]);
                }

                return $correccion;
            });
        } catch (UniqueConstraintViolationException) {
            // Doble clic o dos personas a la vez: el índice único detectó la carrera.
            $previa = Correccion::query()->where('clave_idempotencia', $clave)->first();

            if ($previa !== null) {
                return $this->repetida($previa, $dominio, $id, $actor);
            }

            $sujeto = $this->sujetoAutorizado($manejador, $id, $actor);

            throw new CorreccionConflictoException($this->descripcion($manejador, $sujeto, $actor));
        }

        $this->avisar($manejador, $correccion, $actor);

        return $correccion->load(['campos', 'actor']);
    }

    /**
     * Historial de un registro.
     *
     * @return list<array<string, mixed>>
     */
    public function historial(string $dominio, int $id, User $actor): array
    {
        $manejador = $this->dominio($dominio);
        $sujeto = $manejador->sujeto($id) ?? throw new NotFoundHttpException('No se encontró el registro.');

        if (! $manejador->puedeLeer($actor, $sujeto)) {
            throw new AccessDeniedHttpException('No tienes permiso para consultar este historial.');
        }

        return array_values(Correccion::query()
            ->with(['campos', 'actor'])
            ->where('dominio', $dominio)
            ->where('sujeto_id', $sujeto->getKey())
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (Correccion $c): array => $this->aArray($c, $actor))
            ->all());
    }

    /**
     * Historial de una persona: solo los dominios que este usuario puede leer.
     *
     * @return list<array<string, mixed>>
     */
    public function historialDeColaborador(Colaborador $colaborador, User $actor): array
    {
        if (! $this->dominio('personal')->puedeLeer($actor, $colaborador)) {
            throw new AccessDeniedHttpException('No tienes permiso para consultar este historial.');
        }

        $lecturas = [];
        $filas = [];

        foreach (Correccion::query()->with(['campos', 'actor'])->where('colaborador_id', $colaborador->id)->orderByDesc('id')->limit(100)->get() as $correccion) {
            $llave = $correccion->dominio.':'.$correccion->sujeto_id;

            if (! array_key_exists($llave, $lecturas)) {
                $lecturas[$llave] = $this->puedeLeerRegistro($correccion, $actor);
            }

            if ($lecturas[$llave]) {
                $filas[] = $this->aArray($correccion, $actor);
            }
        }

        return $filas;
    }

    /**
     * @return array<string, mixed>
     */
    public function aArray(Correccion $correccion, User $actor): array
    {
        return [
            'id' => $correccion->id,
            'dominio' => $correccion->dominio,
            'dominio_etiqueta' => (string) config(sprintf('correcciones.dominios.%s.etiqueta', $correccion->dominio), $correccion->dominio),
            'version' => $correccion->version,
            'modo' => $correccion->modo,
            'modo_etiqueta' => ModoCorreccion::tryFrom($correccion->modo)?->etiqueta() ?? $correccion->modo,
            'tipo' => $correccion->tipo,
            'motivo' => $correccion->motivo,
            'vigente_desde' => $correccion->vigente_desde?->toDateString(),
            'fecha' => $correccion->created_at->toIso8601String(),
            'actor' => trim($correccion->actor->name.' '.$correccion->actor->apellidos),
            'origen' => OrigenPeticion::etiqueta($correccion->origen) ?? $correccion->origen,
            'huella' => $correccion->huella_nueva,
            'afectados' => $correccion->afectados ?? [],
            'campos' => $correccion->campos->map(function ($campo) use ($correccion, $actor): array {
                $legible = ! $campo->confidencial || $this->puedeVerConfidencial($correccion->dominio, $campo->campo, $actor);

                return [
                    'campo' => $campo->campo,
                    'etiqueta' => $campo->etiqueta,
                    'confidencial' => $campo->confidencial,
                    'visible' => $legible,
                    'anterior' => $legible ? $this->paraLeer($campo->valor_anterior, $campo->confidencial) : null,
                    'nuevo' => $legible ? $this->paraLeer($campo->valor_nuevo, $campo->confidencial) : null,
                ];
            })->values()->all(),
        ];
    }

    private function dominio(string $clave): DominioCorreccion
    {
        $clase = self::DOMINIOS[$clave] ?? null;

        if ($clase === null || (request()->is('api/*') && config(sprintf('correcciones.dominios.%s.api', $clave), true) === false)) {
            throw new NotFoundHttpException('Ese dato no se corrige por esta vía.');
        }

        return app($clase);
    }

    private function sujetoAutorizado(DominioCorreccion $manejador, int $id, User $actor): Model
    {
        $sujeto = $manejador->sujeto($id) ?? throw new NotFoundHttpException('No se encontró el registro.');

        if (! $manejador->puedeEscribir($actor, $sujeto)) {
            throw new AccessDeniedHttpException('No tienes permiso para corregir este registro.');
        }

        return $sujeto;
    }

    /**
     * @return array<string, mixed>
     */
    private function descripcion(DominioCorreccion $manejador, Model $sujeto, User $actor): array
    {
        $campos = $manejador->campos($sujeto, $actor);

        return [
            'dominio' => $manejador->clave(),
            'dominio_etiqueta' => (string) config(sprintf('correcciones.dominios.%s.etiqueta', $manejador->clave()), ''),
            'sujeto_id' => $sujeto->getKey(),
            'sujeto' => $manejador->etiquetaSujeto($sujeto),
            'huella' => $this->huella($manejador, $sujeto),
            'clave_idempotencia' => (string) Str::uuid(),
            'tipos_cambio' => $manejador->tiposDeCambio($sujeto),
            'campos' => array_map(fn (CampoCorregible $c): array => $c->aArray(), $campos),
        ];
    }

    /**
     * Filtra a los campos que de verdad cambian y aplica permiso y modo.
     *
     * @param  array<string, mixed>  $valores
     * @param  array{tipo?: string|null, vigente_desde?: string|null}  $opciones
     * @return array<string, string|null>
     */
    private function valoresValidos(DominioCorreccion $manejador, Model $sujeto, array $valores, array $opciones, User $actor): array
    {
        $campos = [];

        foreach ($manejador->campos($sujeto, $actor) as $campo) {
            $campos[$campo->campo] = $campo;
        }

        $utiles = [];

        foreach ($valores as $nombre => $valor) {
            $campo = $campos[(string) $nombre] ?? null;

            if ($campo === null) {
                throw ValidationException::withMessages(['valores' => 'Uno de los datos no se puede corregir.']);
            }

            if (trim((string) (is_scalar($valor) ? $valor : '')) === (string) $campo->valor) {
                continue;
            }

            if (! $campo->permitido) {
                throw new AccessDeniedHttpException('No tienes permiso para corregir este dato.');
            }

            if (! $campo->modo->admiteCaptura()) {
                throw ValidationException::withMessages(['valores.'.$nombre => $campo->explicacion ?? sprintf('%s no se puede corregir.', $campo->etiqueta)]);
            }

            $utiles[(string) $nombre] = $valor;
        }

        $normalizados = $utiles !== [] ? $manejador->normalizar($sujeto, $utiles, $opciones, $actor) : [];
        $reales = [];

        foreach ($normalizados as $nombre => $valor) {
            if ($valor !== $campos[$nombre]->valor) {
                $reales[$nombre] = $valor;
            }
        }

        if ($reales === []) {
            throw ValidationException::withMessages(['valores' => 'No hay cambios: el dato ya tiene ese valor.']);
        }

        return $reales;
    }

    /**
     * @param  array<string, string|null>  $valores
     * @return list<array{campo: string, etiqueta: string, antes: string|null, despues: string|null, antes_crudo: string|null, despues_crudo: string|null, confidencial: bool}>
     */
    private function cambios(DominioCorreccion $manejador, Model $sujeto, array $valores, User $actor): array
    {
        $definidos = [];

        foreach ($manejador->campos($sujeto, $actor) as $campo) {
            $definidos[$campo->campo] = $campo;
        }

        $lista = [];

        foreach ($valores as $nombre => $valor) {
            $campo = $definidos[$nombre];
            $lista[] = [
                'campo' => $nombre,
                'etiqueta' => $campo->etiqueta,
                'antes' => $campo->legible,
                'despues' => $manejador->legible($nombre, $valor),
                'antes_crudo' => $campo->legible,
                'despues_crudo' => $manejador->legible($nombre, $valor),
                'confidencial' => $campo->confidencial,
            ];
        }

        foreach ($manejador->derivados($sujeto, $valores) as $derivado) {
            $lista[] = [...$derivado, 'antes_crudo' => $derivado['antes'], 'despues_crudo' => $derivado['despues']];
        }

        return $lista;
    }

    /**
     * SHA-256 con llave de la aplicación: el valor no se puede deducir de la huella.
     */
    private function huella(DominioCorreccion $manejador, Model $sujeto): string
    {
        $valores = $manejador->valoresActuales($sujeto);
        ksort($valores);

        return hash_hmac('sha256', (string) json_encode($valores), (string) config('app.key'));
    }

    /**
     * @param  list<array<string, string>>  $afectados
     */
    private function huellaAfectados(array $afectados): string
    {
        return hash('sha256', (string) json_encode($afectados));
    }

    private function repetida(Correccion $previa, string $dominio, int $id, User $actor): Correccion
    {
        if ($previa->dominio !== $dominio || $previa->sujeto_id !== $id || $previa->actor_id !== $actor->id) {
            throw ValidationException::withMessages(['clave_idempotencia' => 'Esta clave ya se usó en otra corrección.']);
        }

        return $previa->load(['campos', 'actor']);
    }

    private function puedeLeerRegistro(Correccion $correccion, User $actor): bool
    {
        try {
            $manejador = $this->dominio($correccion->dominio);
            $sujeto = $manejador->sujeto($correccion->sujeto_id);

            return $sujeto !== null && $manejador->puedeLeer($actor, $sujeto);
        } catch (NotFoundHttpException) {
            return false;
        }
    }

    private function puedeVerConfidencial(string $dominio, string $campo, User $actor): bool
    {
        return in_array($campo, ['condicion_medica', 'alergias'], true)
            ? $actor->can('expedientes.datos_medicos.ver')
            : $this->accesoSueldo->puedeVer($actor);
    }

    private function paraGuardar(?string $valor, bool $confidencial): ?string
    {
        return $valor !== null && $confidencial ? Crypt::encryptString($valor) : $valor;
    }

    private function paraLeer(?string $valor, bool $confidencial): ?string
    {
        if ($valor === null || ! $confidencial) {
            return $valor;
        }

        try {
            return Crypt::decryptString($valor);
        } catch (Throwable $e) {
            Log::warning('CorreccionService: valor confidencial ilegible.', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Fuera de la transacción: ni la bitácora ni un aviso deshacen la corrección.
     * El aviso nunca incluye el valor.
     */
    private function avisar(DominioCorreccion $manejador, Correccion $correccion, User $actor): void
    {
        $this->auditoria->registrar('correccion_aplicada', $correccion, $actor, [
            'dominio' => $correccion->dominio,
            'colaborador_id' => $correccion->colaborador_id,
            'motivo' => $correccion->motivo,
            'campos' => $correccion->campos()->pluck('campo')->all(),
        ]);

        if ($correccion->colaborador_id === null) {
            return;
        }

        try {
            $usuario = Colaborador::query()->withTrashed()->find($correccion->colaborador_id)?->user;

            if ($usuario !== null) {
                $this->notificador->notificar([$usuario], 'correccion_datos', 'Se corrigió un dato tuyo', sprintf('RH corrigió %s. Si tienes dudas, acércate a RH.', mb_strtolower((string) config(sprintf('correcciones.dominios.%s.etiqueta', $correccion->dominio), 'un dato'))), null, 'ver', 'baja');
            }
        } catch (Throwable $e) {
            Log::warning('CorreccionService: no se pudo avisar de la corrección.', ['correccion_id' => $correccion->id, 'error' => $e->getMessage()]);
        }
    }
}
