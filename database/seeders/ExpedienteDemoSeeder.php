<?php

namespace Database\Seeders;

use App\Enums\EstadoDocumento;
use App\Models\Colaborador;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Services\Expedientes\DocumentoStorageService;
use App\Services\Solicitudes\BajaColaboradorService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Completa datos personales/laborales de los colaboradores demo
 * (UsuarioDemoSeeder) y les genera documentos de expediente reales — PDFs
 * "DOCUMENTO DE DEMOSTRACIÓN — SIN VALIDEZ" guardados en el mismo disco
 * privado que un documento real (config('expedientes.disk')), subidos
 * siempre vía DocumentoStorageService::subirVersion() (nunca insertando la
 * fila a mano) para que versionado/extracción/estado se comporten igual que
 * con un archivo real.
 *
 * Idempotente: no genera un documento de un tipo que el colaborador ya
 * tenga vigente (status != archivado).
 */
class ExpedienteDemoSeeder extends Seeder
{
    private DocumentoStorageService $storage;

    private User $actor;

    private const TEXTO_PLANO_POR_INDICE = [
        'Av. Reforma 123, Col. Centro, CDMX',
        'Calle Juárez 456, Col. Roma Norte, CDMX',
        'Blvd. Independencia 789, Col. Del Valle, Monterrey',
        'Av. Universidad 321, Col. Chapultepec, Cuernavaca',
        'Calle Hidalgo 654, Col. Centro, Cuernavaca',
    ];

    public function run(): void
    {
        // Resuelto aquí (no por constructor): Seeder::call() no siempre
        // instancia vía el contenedor, así que un __construct() con
        // dependencias truena con "Too few arguments" en ciertos contextos
        // (ver DatabaseSeederProduccionTest). Mismo patrón que
        // SolicitudesDemoSeeder.
        $this->storage = app(DocumentoStorageService::class);

        // Este seeder es el único dueño de expedientes/ en datos demo (ver
        // docblock de clase) y siempre corre contra una BD recién migrada
        // (migrate:fresh --seed / DemoSeeder solo en local/testing), pero el
        // disco NAS físico NO se limpia con la BD: los colaboradores demo
        // son deterministas (mismo empresa/sucursal/numero/nombre en cada
        // corrida), así que sin este purge una segunda corrida en la misma
        // máquina de desarrollo chocaría con el guard "nunca sobrescribir"
        // de DocumentoStorageService::guardar() al intentar regenerar el
        // mismo v1 de siempre.
        $this->storage->disco()->deleteDirectory('expedientes');

        // colaborador3..colaborador10 los crea DashboardDemoSeeder (no
        // duplicar sus datos aquí, ver UsuarioDemoSeeder). colaborador10
        // (Pablo Serrano Vega) ya queda "inactivo" ahí mismo vía
        // BajaColaboradorService (baja por solicitud aprobada — nunca hace
        // soft-delete). colaborador11/colaborador12 son exclusivos de este
        // seeder para el otro camino de baja (administrativa directa, con
        // soft-delete real — escenarios F/G).
        $correos = [
            'superadmin@mrlana.test', 'admin.capacitacion@mrlana.test', 'instructor@mrlana.test',
            'gerente.sucursal@mrlana.test', 'supervisor@mrlana.test',
            'colaborador1@mrlana.test', 'colaborador2@mrlana.test', 'colaborador3@mrlana.test',
            'colaborador4@mrlana.test', 'colaborador5@mrlana.test', 'colaborador10@mrlana.test',
            'colaborador11@mrlana.test', 'colaborador12@mrlana.test',
        ];

        // Persona/empleo (curp, rfc, documentos, baja...) vive en Colaborador
        // (separación Usuario/Colaborador) — se resuelve vía el User de cada
        // correo demo, no por un campo propio de Colaborador.
        $colaboradores = Colaborador::withTrashed()
            ->whereHas('user', fn ($q) => $q->whereIn('email', $correos))
            ->with('user:id,email,colaborador_id')
            ->get()
            ->keyBy(fn (Colaborador $c) => $c->user->email);

        if ($colaboradores->isEmpty()) {
            return;
        }

        $this->actor = User::where('email', 'superadmin@mrlana.test')->firstOrFail();

        foreach ($colaboradores as $colaborador) {
            $this->completarDatosPersonales($colaborador);
        }

        // Los expedientes completos de TODO colaborador activo los deja
        // CompletarExpedientesDemoSeeder al final de DemoSeeder (aquí solo
        // quedan los escenarios de baja, que necesitan su historial antes de
        // darse de baja).

        // Bono: colaborador10 (Pablo Serrano Vega) ya lo da de baja
        // DashboardDemoSeeder por el camino de solicitud aprobada
        // (BajaColaboradorService) — "inactivo" pero sin soft-delete. Aquí
        // solo se le completa el expediente para poder abrirlo y ver que
        // una baja conserva todo su historial documental.
        if ($cual = $colaboradores->get('colaborador10@mrlana.test')) {
            $this->completarTodos($cual, EstadoDocumento::Aprobado);
        }

        // F) Baja administrativa directa (soft-delete real, ver
        // Administracion\UsuarioController::destroy()): se queda así, con el
        // botón "Reactivar" visible y funcional en su Expediente.
        if ($cual = $colaboradores->get('colaborador11@mrlana.test')) {
            $this->completarTodos($cual, EstadoDocumento::Aprobado);
            $this->darDeBajaDemo($cual);
        }

        // G) Mismo camino que F, pero de vuelta a activo — para comprobar
        // que reactivar() realmente restaura el soft-delete y el estatus.
        if ($cual = $colaboradores->get('colaborador12@mrlana.test')) {
            $this->completarTodos($cual, EstadoDocumento::Aprobado);
            $this->darDeBajaDemo($cual);
            $this->reactivarDemo($cual);
        }
    }

    private function completarDatosPersonales(Colaborador $colaborador): void
    {
        $indice = $colaborador->id;
        $cambios = [];

        if ($colaborador->curp === null) {
            $cambios['curp'] = sprintf('DEMO%06dHDFRRN%02d', $indice, $indice % 100);
        }

        if ($colaborador->rfc === null) {
            $cambios['rfc'] = sprintf('DEMO%06dAB%d', $indice, $indice % 10);
        }

        if ($colaborador->nss === null) {
            $cambios['nss'] = sprintf('%011d', 10000000000 + $indice);
        }

        if ($colaborador->domicilio === null) {
            $cambios['domicilio'] = self::TEXTO_PLANO_POR_INDICE[$indice % count(self::TEXTO_PLANO_POR_INDICE)];
        }

        if ($colaborador->correo_personal === null) {
            $cambios['correo_personal'] = mb_strtolower($colaborador->name).'.personal'.$indice.'@demo.test';
        }

        if ($colaborador->fecha_alta_imss === null && $colaborador->estatus_imss->value === 'con_imss') {
            $cambios['fecha_alta_imss'] = $colaborador->fecha_ingreso;
        }

        if ($cambios !== []) {
            $colaborador->update($cambios);
        }
    }

    /**
     * Todos los documentos REQUERIDOS del catálogo (el expediente nunca pide
     * opcionales).
     */
    private function completarTodos(Colaborador $colaborador, EstadoDocumento $estado): void
    {
        foreach (DocumentType::query()->where('requerido', true)->where('activo', true)->pluck('clave') as $clave) {
            $this->subirSiFalta($colaborador, (string) $clave, $estado);
        }
    }

    private function subirSiFalta(
        Colaborador $colaborador,
        string $claveTipo,
        EstadoDocumento $estado,
        ?string $comentario = null,
        ?string $motivoRechazo = null,
    ): void {
        $tipo = DocumentType::where('clave', $claveTipo)->first();

        if ($tipo === null) {
            return;
        }

        $yaVigente = EmployeeDocument::where('colaborador_id', $colaborador->id)
            ->where('document_type_id', $tipo->id)
            ->where('status', '!=', EstadoDocumento::Archivado->value)
            ->exists();

        if ($yaVigente) {
            return;
        }

        $documento = $this->crearDocumentoDemo($colaborador, $tipo, "Documento demo: {$tipo->nombre}");

        $documento->update([
            'status' => $estado->value,
            'comments' => $comentario,
            'rejection_reason' => $motivoRechazo,
            'reviewed_at' => in_array($estado, [EstadoDocumento::Aprobado, EstadoDocumento::Rechazado, EstadoDocumento::RequiereCorreccion], true) ? now() : null,
        ]);
    }

    private function crearDocumentoDemo(Colaborador $colaborador, DocumentType $tipo, string $texto): EmployeeDocument
    {
        $pdf = Pdf::loadHTML(
            '<h1>DOCUMENTO DE DEMOSTRACIÓN</h1><h2>SIN VALIDEZ</h2><p>'.e($texto).'</p>'
            .'<p>Colaborador: '.e($colaborador->nombreCompleto()).'</p>'
            .'<p>Generado: '.now()->toDateTimeString().'</p>',
        )->output();

        $archivo = UploadedFile::fake()->createWithContent(
            str($tipo->clave)->slug().'-demo.pdf',
            $pdf,
        );

        try {
            return $this->storage->subirVersion($colaborador, $tipo, $archivo, $colaborador->user->id ?? $this->actor->id);
        } catch (Throwable $e) {
            Log::warning('ExpedienteDemoSeeder: no se pudo generar un documento demo.', [
                'colaborador_id' => $colaborador->id,
                'tipo' => $tipo->clave,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Mismo flujo real que Rh\ExpedienteController::darDeBaja() (usa
     * App\Services\Solicitudes\BajaColaboradorService): estatus inactivo +
     * revoca acceso si tiene cuenta. Nunca hace soft-delete — ver docblock
     * de BajaColaboradorService.
     */
    private function darDeBajaDemo(Colaborador $colaborador): void
    {
        if ($colaborador->estatus->value === 'inactivo') {
            return;
        }

        app(BajaColaboradorService::class)->ejecutar($colaborador, $this->actor, 'Baja administrativa (dato de demostración).');
    }

    /**
     * Mismo flujo real que Rh\ExpedienteController::reactivar().
     */
    private function reactivarDemo(Colaborador $colaborador): void
    {
        app(BajaColaboradorService::class)->reactivar($colaborador);
    }
}
