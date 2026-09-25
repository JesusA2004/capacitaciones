<?php

namespace Database\Seeders;

use App\Enums\EstadoAltaDigital;
use App\Enums\EstadoCandidato;
use App\Enums\EstadoInvitacionIncorporacion;
use App\Enums\FuenteCandidato;
use App\Models\AltaDigital;
use App\Models\Candidato;
use App\Models\Departamento;
use App\Models\HeadcountTarget;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Vacante;
use App\Services\AltaDigital\ConversionColaboradorService;
use App\Services\Incorporacion\IncorporacionInvitacionService;
use App\Services\Vacantes\VacanteAutoGenerationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Plantilla, vacantes derivadas, candidatos e incorporación QR de
 * demostración (docs/HEADCOUNT_Y_VACANTES.md, docs/RECLUTAMIENTO.md,
 * docs/ALTA_DIGITAL_COLABORADOR.md). Vacantes es 100% informativo — nace de
 * HeadcountTarget vs. colaboradores activos, nunca se crea a mano (ver
 * App\Services\Vacantes\VacanteAutoGenerationService) — así que este seeder
 * solo siembra plantilla autorizada y dejar que el servicio real derive las
 * vacantes, igual que en producción.
 *
 * Idempotente por el apellido característico "(demo-recl)".
 */
class ReclutamientoDemoSeeder extends Seeder
{
    private const MARCA = '(demo-recl)';

    private ConversionColaboradorService $conversion;

    private IncorporacionInvitacionService $invitaciones;

    public function run(): void
    {
        // Resuelto aquí (no por constructor): Seeder::call() no siempre
        // instancia vía el contenedor, así que un __construct() con
        // dependencias truena con "Too few arguments" en ciertos contextos
        // (ver DatabaseSeederProduccionTest). Mismo patrón que
        // SolicitudesDemoSeeder.
        $this->conversion = app(ConversionColaboradorService::class);
        $this->invitaciones = app(IncorporacionInvitacionService::class);

        if (Candidato::where('apellidos', 'like', '%'.self::MARCA)->exists()) {
            return;
        }

        $sucursalUno = Sucursal::where('clave', 'IXT01')->first();
        $sucursalDos = Sucursal::where('clave', 'CUE01')->first();
        $departamento = Departamento::where('nombre', 'Operaciones')->first();
        $gestorFijo = Puesto::where('nombre', 'Gestor')->first();
        $gestorVolante = Puesto::where('nombre', 'Gestor volante')->first();
        $rhAdmin = User::where('email', 'rh.admin@mrlana.test')->first();
        $gerente = User::where('email', 'gerente.sucursal@mrlana.test')->first();

        if ($sucursalUno === null || $sucursalDos === null || $gestorFijo === null || $gestorVolante === null || $rhAdmin === null) {
            return;
        }

        // --- 1) Plantilla autorizada por encima de la actual: dos pares
        // (sucursal, puesto) que UsuarioDemoSeeder ya cubrió parcialmente
        // (2 colaboradores activos cada uno), para que el faltante derivado
        // sea real y VacanteAutoGenerationService abra vacantes de verdad. ---
        $parA = ['sucursal' => $sucursalUno, 'puesto' => $gestorFijo, 'autorizada' => 5];
        $parB = ['sucursal' => $sucursalDos, 'puesto' => $gestorVolante, 'autorizada' => 4];

        foreach ([$parA, $parB] as $par) {
            HeadcountTarget::updateOrCreate(
                ['sucursal_id' => $par['sucursal']->id, 'puesto_id' => $par['puesto']->id],
                [
                    'empresa_id' => $par['sucursal']->empresa_id,
                    'departamento_id' => $departamento?->id,
                    'plantilla_autorizada' => $par['autorizada'],
                    'fuente' => 'manual',
                    'fecha_corte' => now()->toDateString(),
                    'editable' => true,
                    'created_by_id' => $rhAdmin->id,
                ],
            );
        }

        // --- 2) Vacantes: 100% derivadas, nunca creadas a mano aquí. ---
        app(VacanteAutoGenerationService::class)->sincronizarTodo();

        $vacanteA = Vacante::where('sucursal_id', $sucursalUno->id)->where('puesto_id', $gestorFijo->id)
            ->where('generada_automaticamente', true)->first();
        $vacanteB = Vacante::where('sucursal_id', $sucursalDos->id)->where('puesto_id', $gestorVolante->id)
            ->where('generada_automaticamente', true)->first();

        // Presupuesto salarial de demostración: VacanteAutoGenerationService
        // no lo fija (no es un dato que se derive del headcount), así que se
        // completa aquí para que el KPI de costo mensual en Vacantes no
        // quede vacío.
        $vacanteA?->update(['sueldo_mensual' => 9500]);
        $vacanteB?->update(['sueldo_mensual' => 10200]);

        // --- 3) Candidatos en las 10 fases del pipeline + las 4 salidas
        // (App\Enums\EstadoCandidato), repartidos entre los dos pares para
        // que ambas vacantes muestren candidatos activos/finalistas. ---
        $fuentes = FuenteCandidato::cases();
        $indiceFuente = 0;
        $indiceTelefono = 0;
        $siguienteTelefono = function () use (&$indiceTelefono): string {
            $indiceTelefono++;

            return sprintf('55%08d', 30000000 + $indiceTelefono);
        };
        $siguienteFuente = function () use ($fuentes, &$indiceFuente): FuenteCandidato {
            return $fuentes[$indiceFuente++ % count($fuentes)];
        };

        $crearCandidato = function (
            string $nombre,
            EstadoCandidato $estado,
            array $par,
            ?Vacante $vacante,
        ) use ($departamento, $rhAdmin, $gerente, $siguienteTelefono, $siguienteFuente): Candidato {
            $slug = Str::slug($nombre, '.');

            return Candidato::create([
                'empresa_id' => $par['sucursal']->empresa_id,
                'sucursal_id' => $par['sucursal']->id,
                'departamento_id' => $departamento?->id,
                'puesto_objetivo_id' => $par['puesto']->id,
                'vacante_id' => $vacante?->id,
                'nombre' => $nombre,
                'apellidos' => 'Reclutamiento '.self::MARCA,
                'telefono' => $siguienteTelefono(),
                'correo' => $slug.'.demo@example.test',
                'fuente' => $siguienteFuente()->value,
                'estado' => $estado->value,
                'observaciones' => 'Candidato de demostración en fase «'.$estado->etiqueta().'» '.self::MARCA,
                'responsable_rh_id' => $rhAdmin->id,
                'gerente_involucrado_id' => $gerente?->id,
                'creado_por' => $rhAdmin->id,
            ]);
        };

        $fases = [
            'Alejandra Nuñez' => [EstadoCandidato::Recibidos, $parA],
            'Brandon Reyes' => [EstadoCandidato::Preseleccion, $parB],
            'Citlali Moreno' => [EstadoCandidato::Entrevista, $parA],
            'Diego Salcedo' => [EstadoCandidato::Psicometricos, $parB],
            'Estefania Rangel' => [EstadoCandidato::EstudioSocioeconomico, $parA],
            'Fernando Cabrera' => [EstadoCandidato::Pruebas, $parB],
            'Gabriela Solis' => [EstadoCandidato::ValidacionDocumental, $parA],
            'Hugo Betancourt' => [EstadoCandidato::OfertaAprobacion, $parB],
        ];

        foreach ($fases as $nombre => [$estado, $par]) {
            $vacante = $par === $parA ? $vacanteA : $vacanteB;
            $crearCandidato($nombre, $estado, $par, $vacante);
        }

        $salidas = [
            'Itzel Guzman' => [EstadoCandidato::NoSeleccionado, $parA],
            'Jorge Villagran' => [EstadoCandidato::NoViable, $parB],
            'Karla Espinoza' => [EstadoCandidato::NoRespondio, $parA],
            'Luis Olvera' => [EstadoCandidato::Desistio, $parB],
        ];

        foreach ($salidas as $nombre => [$estado, $par]) {
            $vacante = $par === $parA ? $vacanteA : $vacanteB;
            $crearCandidato($nombre, $estado, $par, $vacante);
        }

        // --- 4) "Listo para contratación" limpio (sección 5 del encargo):
        // sin QR activo, sin alta digital, sin colaborador — debe aparecer
        // en el selector de Alta Digital QR. ---
        $candidatoParaQr = $crearCandidato('Melissa Cordova', EstadoCandidato::ListoParaContratacion, $parA, $vacanteA);

        // --- 5) "Listo para contratación" con invitación QR activa (sin
        // usar todavía): demuestra el estado "Activo" del módulo de
        // incorporación. ---
        $candidatoConInvitacionActiva = $crearCandidato('Nestor Aviles', EstadoCandidato::ListoParaContratacion, $parB, $vacanteB);

        $this->invitaciones->crear([
            'candidato_id' => $candidatoConInvitacionActiva->id,
            'email' => $candidatoConInvitacionActiva->correo,
            'telefono' => $candidatoConInvitacionActiva->telefono,
            'nombre_prellenado' => $candidatoConInvitacionActiva->nombreCompleto(),
            'empresa_id' => $candidatoConInvitacionActiva->empresa_id,
            'sucursal_id' => $candidatoConInvitacionActiva->sucursal_id,
            'departamento_id' => $candidatoConInvitacionActiva->departamento_id,
            'puesto_id' => $candidatoConInvitacionActiva->puesto_objetivo_id,
            'observaciones' => 'Invitación activa de demostración '.self::MARCA,
        ], $rhAdmin);

        // --- 6) "Listo para contratación" que ya usó su QR y su alta
        // digital está en revisión de RH. ---
        $candidatoEnRevision = $crearCandidato('Ociel Terrazas', EstadoCandidato::ListoParaContratacion, $parA, $vacanteA);

        ['invitacion' => $invitacionUsada] = $this->invitaciones->crear([
            'candidato_id' => $candidatoEnRevision->id,
            'nombre_prellenado' => $candidatoEnRevision->nombreCompleto(),
            'empresa_id' => $candidatoEnRevision->empresa_id,
            'sucursal_id' => $candidatoEnRevision->sucursal_id,
        ], $rhAdmin);

        if ($gerente !== null) {
            $this->invitaciones->marcarUsada($invitacionUsada, $gerente);
        }

        AltaDigital::create([
            'candidato_id' => $candidatoEnRevision->id,
            'vacante_id' => $vacanteA?->id,
            'empresa_id' => $candidatoEnRevision->empresa_id,
            'sucursal_id' => $candidatoEnRevision->sucursal_id,
            'departamento_id' => $candidatoEnRevision->departamento_id,
            'puesto_id' => $candidatoEnRevision->puesto_objetivo_id,
            'token' => Str::random(48),
            'token_expira_en' => now()->addDays(7),
            'estado' => EstadoAltaDigital::EnRevisionRh->value,
            'nombre' => $candidatoEnRevision->nombre,
            'apellidos' => $candidatoEnRevision->apellidos,
            'telefono' => $candidatoEnRevision->telefono,
            'correo' => $candidatoEnRevision->correo,
            'aviso_privacidad_aceptado' => true,
            'aviso_privacidad_aceptado_en' => now()->subDay(),
            'consentimiento_datos_aceptado' => true,
            'consentimiento_datos_aceptado_en' => now()->subDay(),
            'enviada_en' => now()->subDay(),
        ]);

        // --- 7) "Listo para contratación" cuya alta digital requiere
        // corrección. ---
        $candidatoRequiereCorreccion = $crearCandidato('Paola Higuera', EstadoCandidato::ListoParaContratacion, $parB, $vacanteB);

        ['invitacion' => $invitacionParaCorreccion] = $this->invitaciones->crear([
            'candidato_id' => $candidatoRequiereCorreccion->id,
            'nombre_prellenado' => $candidatoRequiereCorreccion->nombreCompleto(),
            'empresa_id' => $candidatoRequiereCorreccion->empresa_id,
            'sucursal_id' => $candidatoRequiereCorreccion->sucursal_id,
        ], $rhAdmin);

        if ($gerente !== null) {
            $this->invitaciones->marcarUsada($invitacionParaCorreccion, $gerente);
        }

        AltaDigital::create([
            'candidato_id' => $candidatoRequiereCorreccion->id,
            'vacante_id' => $vacanteB?->id,
            'empresa_id' => $candidatoRequiereCorreccion->empresa_id,
            'sucursal_id' => $candidatoRequiereCorreccion->sucursal_id,
            'departamento_id' => $candidatoRequiereCorreccion->departamento_id,
            'puesto_id' => $candidatoRequiereCorreccion->puesto_objetivo_id,
            'token' => Str::random(48),
            'token_expira_en' => now()->addDays(7),
            'estado' => EstadoAltaDigital::RequiereCorreccion->value,
            'nombre' => $candidatoRequiereCorreccion->nombre,
            'apellidos' => $candidatoRequiereCorreccion->apellidos,
            'telefono' => $candidatoRequiereCorreccion->telefono,
            'correo' => $candidatoRequiereCorreccion->correo,
            'comentarios' => 'Falta la fotografía y la CURP legible '.self::MARCA,
            'aviso_privacidad_aceptado' => true,
            'aviso_privacidad_aceptado_en' => now()->subDays(2),
            'consentimiento_datos_aceptado' => true,
            'consentimiento_datos_aceptado_en' => now()->subDays(2),
            'enviada_en' => now()->subDays(2),
            'revisado_por' => $rhAdmin->id,
            'revisado_en' => now()->subDay(),
        ]);

        // --- 8) Invitaciones QR sueltas en otros estados administrativos
        // (vencida, revocada), sin candidato ligado — mismo criterio que la
        // pantalla de Invitaciones necesita para mostrar el filtro completo. ---
        ['invitacion' => $invitacionVencida] = $this->invitaciones->crear([
            'nombre_prellenado' => 'Invitación vencida demo '.self::MARCA,
            'empresa_id' => $sucursalUno->empresa_id,
            'sucursal_id' => $sucursalUno->id,
            'duracion_horas' => 1,
        ], $rhAdmin);
        // `crear()` siempre nace "activo"; el paso a "vencido" normalmente lo
        // hace `validar()` al detectar expires_at pasado (nunca hay un cron
        // aparte, ver IncorporacionInvitacionService::sincronizarVencimiento()).
        // Para que la demo lo muestre correctamente sin depender de que
        // alguien escanee el QR, se replica aquí el mismo efecto.
        $invitacionVencida->update([
            'expires_at' => now()->subDays(3),
            'estado' => EstadoInvitacionIncorporacion::Vencido->value,
        ]);

        ['invitacion' => $invitacionARevocar] = $this->invitaciones->crear([
            'nombre_prellenado' => 'Invitación revocada demo '.self::MARCA,
            'empresa_id' => $sucursalDos->empresa_id,
            'sucursal_id' => $sucursalDos->id,
        ], $rhAdmin);
        $this->invitaciones->revocar($invitacionARevocar);

        // --- 9) Flujo completo hasta contratado: candidato -> "listo para
        // contratación" -> alta digital aprobada -> ConversionColaboradorService
        // -> Colaborador real (misma vía que AltaDigitalController::aprobar()).
        // Al terminar, la plantilla cubierta de $parA sube y su vacante
        // automática se sincroniza sola (no se ajusta nada a mano aquí). ---
        $candidatoContratado = $crearCandidato('Oscar Beltran', EstadoCandidato::ListoParaContratacion, $parA, $vacanteA);

        $altaAprobada = AltaDigital::create([
            'candidato_id' => $candidatoContratado->id,
            'vacante_id' => $vacanteA?->id,
            'empresa_id' => $candidatoContratado->empresa_id,
            'sucursal_id' => $candidatoContratado->sucursal_id,
            'departamento_id' => $candidatoContratado->departamento_id,
            'puesto_id' => $candidatoContratado->puesto_objetivo_id,
            'token' => Str::random(48),
            'token_expira_en' => now()->addDays(7),
            'estado' => EstadoAltaDigital::Aprobada->value,
            'nombre' => $candidatoContratado->nombre,
            'apellidos' => $candidatoContratado->apellidos,
            'telefono' => $candidatoContratado->telefono,
            'correo' => 'oscar.beltran.colaborador.demo@example.test',
            'fecha_nacimiento' => now()->subYears(29)->toDateString(),
            'curp' => 'BEQO970101HDFLRS08',
            'rfc' => 'BEQO970101ABC',
            'nss' => '12345678901',
            'domicilio' => 'Calle Demostración 123, Ixtapaluca, Edo. Méx.',
            'contacto_emergencia_nombre' => 'Rosa Beltrán '.self::MARCA,
            'contacto_emergencia_telefono' => '5599990099',
            'fecha_ingreso_propuesta' => now()->toDateString(),
            'aviso_privacidad_aceptado' => true,
            'aviso_privacidad_aceptado_en' => now()->subDays(2),
            'consentimiento_datos_aceptado' => true,
            'consentimiento_datos_aceptado_en' => now()->subDays(2),
            'enviada_en' => now()->subDays(2),
            'revisado_por' => $rhAdmin->id,
            'revisado_en' => now()->subDay(),
            'aprobado_por' => $rhAdmin->id,
            'aprobado_en' => now(),
        ]);

        $this->conversion->convertir($altaAprobada, $rhAdmin);

        // Refleja el impacto real de la contratación en la plantilla/vacante
        // (ver comentario del punto 9): el colaborador recién creado ya
        // cuenta como "actual", así que se vuelve a sincronizar para que la
        // vacante automática de $parA baje su faltante sin esperar a otro
        // trigger.
        app(VacanteAutoGenerationService::class)->sincronizar($sucursalUno->id, $gestorFijo->id);
    }
}
