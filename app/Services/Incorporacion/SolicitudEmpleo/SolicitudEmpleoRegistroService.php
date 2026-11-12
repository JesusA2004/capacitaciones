<?php

namespace App\Services\Incorporacion\SolicitudEmpleo;

use App\Models\Colaborador;
use App\Models\ColaboradorPerfilIngreso;
use App\Models\User;
use App\Services\Incorporacion\RegistroAppService;
use App\Services\Tareas\NotificadorRhService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * La Solicitud de empleo + Estudio socioeconómico dentro del registro en la
 * app (RegistroAppService::estado()/finalizar()): bloque de avance por
 * sección y exigencia de completitud al cerrar.
 *
 * Compatibilidad: con config incorporacion.registro_app.exigir_solicitud_empleo
 * = «cliente» (por defecto) solo se exige a la app que declara implementar
 * estas pantallas (cabecera X-People-Solicitud-Empleo: 1). Las versiones ya
 * instaladas cierran su registro como siempre y RH completa lo faltante en
 * la web; el contrato nunca se genera sin ambos documentos
 * (RequisitoDocumentosIngresoService).
 */
class SolicitudEmpleoRegistroService
{
    public const CABECERA_CLIENTE = 'X-People-Solicitud-Empleo';

    /** Llave interna de `secciones`: cuándo y quién de RH pidió completarla. */
    private const PEDIDO_RH = '_pedido_rh';

    public function __construct(
        private readonly PerfilIngresoService $perfil,
        private readonly RegistroAppService $registro,
        private readonly DatosPersonalesSolicitudService $personales,
    ) {}

    /**
     * Modo de captura de la PROPIA persona (web y app, mismo criterio):
     * durante su registro captura y ajusta todo; después solo llena lo que
     * sigue vacío (lo ya capturado se corrige por solicitud de corrección).
     */
    public function modoPropio(User $usuario): string
    {
        return $this->registro->aplica($usuario) ? PerfilIngresoService::ORIGEN_APP : PerfilIngresoService::ORIGEN_COMPLETAR;
    }

    /**
     * Estado por sección para la propia persona (GET web y API). Además de
     * lo de PerfilIngresoService::estado() trae lo que la persona necesita
     * ver (DatosPersonalesSolicitudService::decorar): los datos personales
     * que faltan como campos por llenar en su sección, lo que está «En
     * revisión por RH» y `avance` (fuente única de «te faltan N datos»).
     *
     * @return array<string, mixed>
     */
    public function estadoPropio(User $usuario, Colaborador $colaborador): array
    {
        $modo = $this->modoPropio($usuario);

        return [
            'registro_abierto' => $modo === PerfilIngresoService::ORIGEN_APP,
            'exigida' => $this->exigidaEnRegistro(),
            'modo' => $modo,
            ...$this->personales->decorar($this->perfil->estado($colaborador, $modo), $colaborador),
        ];
    }

    /**
     * Guarda una sección de la propia persona con el modo que le toca. Los
     * datos personales que capturó ahí mismo (domicilio, teléfono…) se
     * mandan a Recursos Humanos para revisión (o se guardan directo durante
     * su registro): todo en la misma pantalla y en el mismo guardado.
     *
     * @param  array<string, mixed>  $datos
     * @param  list<string>|null  $noAplica
     * @return int Datos personales enviados a revisión (o guardados en el registro).
     */
    public function guardarPropio(User $usuario, Colaborador $colaborador, string $seccion, array $datos, ?array $noAplica): int
    {
        $modo = $this->modoPropio($usuario);

        return DB::transaction(function () use ($usuario, $colaborador, $seccion, $datos, $noAplica, $modo): int {
            $personales = $this->personales->guardar($usuario, $colaborador, $seccion, $datos, $modo);
            $this->perfil->guardarSeccion($colaborador, $seccion, $datos, $noAplica, $usuario, $modo);

            return $personales;
        });
    }

    /** «Sección guardada…» con lo que pasó con sus datos personales. */
    public function mensajeGuardado(User $usuario, int $personales): string
    {
        if ($personales === 0 || $this->modoPropio($usuario) === PerfilIngresoService::ORIGEN_APP) {
            return 'Sección guardada.';
        }

        return sprintf(
            'Sección guardada. %s a Recursos Humanos para revisión; no tienes que capturarlo otra vez.',
            $personales === 1 ? 'Enviamos 1 dato personal' : sprintf('Enviamos %d datos personales', $personales),
        );
    }

    /**
     * Avance de la PERSONA (lo que ella puede llenar): mismos números para
     * Mis pendientes, el encabezado de «Mi solicitud de empleo» y cada paso.
     *
     * @return array{por_llenar: int, en_revision: int, secciones_pendientes: int, total: int, porcentaje: int, listo: bool, completo: bool, frase: string}
     */
    public function avancePropio(Colaborador $colaborador): array
    {
        return $this->personales->avance($colaborador);
    }

    /**
     * RH «Pedir información al colaborador»: aviso (campana web + push de la
     * app) con cuántos datos le faltan y marca para que el pendiente salga en
     * su portal aunque ya esté activo. Un aviso por hora como máximo.
     *
     * @return int Datos que le faltan a la persona.
     *
     * @throws ValidationException
     */
    public function pedirAlColaborador(Colaborador $colaborador, User $rh): int
    {
        $porLlenar = $this->datosPorLlenar($colaborador);

        if ($porLlenar === 0) {
            throw ValidationException::withMessages(['solicitud' => 'A esta persona no le falta ningún dato que ella pueda llenar.']);
        }

        $colaborador->loadMissing('user');

        if ($colaborador->user === null) {
            throw ValidationException::withMessages(['solicitud' => 'La persona todavía no tiene cuenta para recibir el aviso.']);
        }

        $perfil = ColaboradorPerfilIngreso::query()->firstOrNew(['colaborador_id' => $colaborador->id]);
        $secciones = (array) ($perfil->secciones ?? []);
        $ultimo = is_array($secciones[self::PEDIDO_RH] ?? null) ? (string) ($secciones[self::PEDIDO_RH]['en'] ?? '') : '';

        if ($ultimo !== '' && CarbonImmutable::parse($ultimo)->gt(now()->subHour())) {
            throw ValidationException::withMessages(['solicitud' => 'Ya se le pidió hace menos de una hora.']);
        }

        $this->marcarPedido($colaborador, $rh);

        try {
            app(NotificadorRhService::class)->notificar(
                [$colaborador->user],
                'solicitud_empleo_faltante',
                'Completa tu solicitud de empleo',
                sprintf('Recursos Humanos te pide completar tu solicitud de empleo y estudio socioeconómico: %s. Entra a Mi portal → Mi solicitud de empleo.', $porLlenar === 1 ? 'falta 1 dato' : sprintf('faltan %d datos', $porLlenar)),
                $colaborador,
                'completar_solicitud_empleo',
                'alta',
                [],
                // Cada pedido es un aviso nuevo: sin esto, volver a pedir lo
                // mismo el mismo día no le llegaba (se tomaba por duplicado).
                sprintf('pedido-%s', now()->format('YmdHi')),
            );
        } catch (Throwable $e) {
            Log::warning('No se pudo avisar la solicitud de empleo faltante.', ['colaborador_id' => $colaborador->id, 'error' => $e->getMessage()]);
        }

        return $porLlenar;
    }

    /** Registra que RH le pidió completarla (el pendiente sale en su portal). */
    public function marcarPedido(Colaborador $colaborador, User $rh): void
    {
        $perfil = ColaboradorPerfilIngreso::query()->firstOrNew(['colaborador_id' => $colaborador->id]);
        $secciones = (array) ($perfil->secciones ?? []);
        $secciones[self::PEDIDO_RH] = ['en' => now()->toIso8601String(), 'por' => $rh->id];
        $perfil->colaborador_id = $colaborador->id;
        $perfil->secciones = $secciones;
        $perfil->save();
    }

    /** ¿RH le pidió completar su solicitud? (el pendiente sale aunque ya esté activo). */
    public function pedidoPorRh(Colaborador $colaborador): bool
    {
        $secciones = (array) ($this->perfil->perfil($colaborador)->secciones ?? []);

        return is_array($secciones[self::PEDIDO_RH] ?? null);
    }

    /**
     * Datos que la persona misma puede llenar en «Mi solicitud de empleo»,
     * incluidos los personales que faltan en su expediente (se capturan ahí
     * mismo). Lo que ya envió y RH revisa no cuenta. Es el mismo número del
     * encabezado y de los pasos de esa pantalla.
     */
    public function datosPorLlenar(Colaborador $colaborador): int
    {
        return $this->personales->avance($colaborador)['por_llenar'];
    }

    /**
     * Lo mismo SIN los datos personales: para «Pedir información al
     * colaborador», que ya los cuenta en su propio renglón.
     */
    public function datosPorLlenarSinPersonales(Colaborador $colaborador): int
    {
        return $this->personales->porLlenarSinPersonales($colaborador);
    }

    /**
     * ¿A la persona le falta algo que ELLA puede llenar? (pendiente del portal).
     */
    public function tienePendientePropio(Colaborador $colaborador): bool
    {
        return $this->datosPorLlenar($colaborador) > 0;
    }

    public function exigidaEnRegistro(): bool
    {
        return match ((string) config('incorporacion.registro_app.exigir_solicitud_empleo', 'cliente')) {
            'siempre' => true,
            'nunca' => false,
            default => request()->header(self::CABECERA_CLIENTE) === '1',
        };
    }

    /**
     * Bloque `solicitud_empleo` de RegistroAppService::estado().
     *
     * @return array<string, mixed>
     */
    public function resumen(Colaborador $colaborador): array
    {
        $completitud = $this->perfil->completitud($colaborador);

        return [
            'exigida' => $this->exigidaEnRegistro(),
            'completo' => $completitud['completo'],
            'porcentaje' => $completitud['porcentaje'],
            'secciones' => array_map(fn (array $s): array => [
                'clave' => $s['clave'],
                'titulo' => $s['titulo'],
                'completa' => $s['completa'],
                'porcentaje' => $s['porcentaje'],
                'faltantes' => $s['faltantes'],
            ], $completitud['secciones']),
            'documentos' => array_values(array_map(fn (array $d): array => [
                'clave' => $d['clave'],
                'nombre' => $d['nombre'],
                'completo' => $d['completo'],
                'mensaje' => $d['mensaje'],
            ], $completitud['documentos'])),
            'pendientes' => $this->pendientes($completitud['secciones']),
        ];
    }

    /**
     * ¿Puede cerrar el registro respecto a la solicitud? (true si no se exige).
     */
    public function completaParaRegistro(Colaborador $colaborador): bool
    {
        return ! $this->exigidaEnRegistro() || $this->perfil->completitud($colaborador)['completo'];
    }

    /**
     * @throws ValidationException Con lo que falta, sección por sección.
     */
    public function exigirParaRegistro(Colaborador $colaborador): void
    {
        if (! $this->exigidaEnRegistro()) {
            return;
        }

        $completitud = $this->perfil->completitud($colaborador);

        if ($completitud['completo']) {
            return;
        }

        throw ValidationException::withMessages([
            'solicitud_empleo' => sprintf('Completa tu solicitud de empleo y estudio socioeconómico. Falta: %s.', implode('; ', $this->pendientes($completitud['secciones']))),
        ]);
    }

    /**
     * «Domicilio: Entre calle, Y calle».
     *
     * @param  list<array{clave: string, titulo: string, completa: bool, faltantes: list<string>}>  $secciones
     * @return list<string>
     */
    private function pendientes(array $secciones): array
    {
        $pendientes = [];

        foreach ($secciones as $seccion) {
            if (! $seccion['completa']) {
                $pendientes[] = sprintf('%s: %s', $seccion['titulo'], implode(', ', $seccion['faltantes']));
            }
        }

        return $pendientes;
    }
}
