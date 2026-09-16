<?php

namespace Database\Seeders;

use App\Enums\EstadoDocumento;
use App\Enums\EstadoUsuario;
use App\Models\DocumentType;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Services\Expedientes\DocumentoStorageService;
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
        $colaboradores = User::whereIn('email', [
            'superadmin@mrlana.test', 'admin.capacitacion@mrlana.test', 'instructor@mrlana.test',
            'gerente.sucursal@mrlana.test', 'supervisor@mrlana.test',
            'colaborador1@mrlana.test', 'colaborador2@mrlana.test', 'colaborador3@mrlana.test',
            'colaborador4@mrlana.test', 'colaborador5@mrlana.test', 'colaborador10@mrlana.test',
            'colaborador11@mrlana.test', 'colaborador12@mrlana.test',
        ])->withTrashed()->get()->keyBy('email');

        if ($colaboradores->isEmpty()) {
            return;
        }

        $jefe = User::where('email', 'jefe.directo@mrlana.test')->first();

        foreach ($colaboradores as $colaborador) {
            $this->completarDatosPersonales($colaborador, $jefe);
        }

        // A) Expediente 100% completo: todos los requeridos + contrato aprobados.
        if ($cual = $colaboradores->get('superadmin@mrlana.test')) {
            $this->completarTodos($cual, EstadoDocumento::Aprobado);
        }

        // B) ~90%: todo aprobado salvo un opcional pendiente (no se sube).
        if ($cual = $colaboradores->get('admin.capacitacion@mrlana.test')) {
            $this->completarTodos($cual, EstadoDocumento::Aprobado, omitir: ['cv']);
        }

        // C) Varios documentos pendientes: solo la mitad de los requeridos.
        if ($cual = $colaboradores->get('colaborador1@mrlana.test')) {
            $this->subirSiFalta($cual, 'ine', EstadoDocumento::Aprobado);
            $this->subirSiFalta($cual, 'curp', EstadoDocumento::Aprobado);
            $this->subirSiFalta($cual, 'fotografia', EstadoDocumento::EnRevision);
            $this->subirSiFalta($cual, 'contrato', EstadoDocumento::Aprobado);
            // El resto de requeridos (rfc, nss, acta_nacimiento,
            // comprobante_domicilio) se deja sin subir a propósito.

            // Versionado real: v1 rechazado, v2 aprobado, mismo tipo.
            $this->versionadoComprobanteDomicilio($cual);
        }

        // D) Documento requiere corrección.
        if ($cual = $colaboradores->get('colaborador2@mrlana.test')) {
            $this->completarTodos($cual, EstadoDocumento::Aprobado, omitir: ['comprobante_domicilio']);
            $this->subirSiFalta($cual, 'comprobante_domicilio', EstadoDocumento::RequiereCorreccion, comentario: 'La dirección en el comprobante no coincide con la capturada.');
        }

        // E) Documento rechazado.
        if ($cual = $colaboradores->get('colaborador3@mrlana.test')) {
            $this->completarTodos($cual, EstadoDocumento::Aprobado, omitir: ['ine']);
            $this->subirSiFalta($cual, 'ine', EstadoDocumento::Rechazado, motivoRechazo: 'La identificación está vencida.');
        }

        // Contrato pendiente / en revisión para variar el KPI de contratos.
        if ($cual = $colaboradores->get('colaborador4@mrlana.test')) {
            $this->completarTodos($cual, EstadoDocumento::Aprobado, omitir: ['contrato']);
        }

        if ($cual = $colaboradores->get('colaborador5@mrlana.test')) {
            $this->completarTodos($cual, EstadoDocumento::Aprobado, omitir: ['contrato']);
            $this->subirSiFalta($cual, 'contrato', EstadoDocumento::EnRevision);
        }

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

    private function completarDatosPersonales(User $colaborador, ?User $jefe): void
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

        if ($colaborador->jefe_id === null && $jefe !== null && $colaborador->id !== $jefe->id) {
            $cambios['jefe_id'] = $jefe->id;
        }

        if ($cambios !== []) {
            $colaborador->update($cambios);
        }
    }

    /**
     * @param  array<int, string>  $omitir  Claves de DocumentType que NO se suben (para simular un expediente incompleto).
     */
    private function completarTodos(User $colaborador, EstadoDocumento $estado, array $omitir = []): void
    {
        $claves = ['ine', 'curp', 'rfc', 'nss', 'acta_nacimiento', 'comprobante_domicilio', 'fotografia', 'contrato', 'aviso_privacidad'];

        foreach ($claves as $clave) {
            if (in_array($clave, $omitir, true)) {
                continue;
            }

            $this->subirSiFalta($colaborador, $clave, $estado);
        }
    }

    private function subirSiFalta(
        User $colaborador,
        string $claveTipo,
        EstadoDocumento $estado,
        ?string $comentario = null,
        ?string $motivoRechazo = null,
    ): void {
        $tipo = DocumentType::where('clave', $claveTipo)->first();

        if ($tipo === null) {
            return;
        }

        $yaVigente = EmployeeDocument::where('user_id', $colaborador->id)
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

    /**
     * Escenario de versionado real (sección 23 del encargo): v1 rechazado
     * por "Documento vencido", v2 aprobado, con previous_version_id.
     */
    private function versionadoComprobanteDomicilio(User $colaborador): void
    {
        $tipo = DocumentType::where('clave', 'comprobante_domicilio')->first();

        if ($tipo === null) {
            return;
        }

        $existente = EmployeeDocument::where('user_id', $colaborador->id)
            ->where('document_type_id', $tipo->id)
            ->exists();

        if ($existente) {
            return;
        }

        $v1 = $this->crearDocumentoDemo($colaborador, $tipo, 'Comprobante de domicilio v1 (demo)');
        $v1->update([
            'status' => EstadoDocumento::Rechazado->value,
            'rejection_reason' => 'Documento vencido.',
            'reviewed_at' => now()->subDays(10),
        ]);

        $v2 = $this->crearDocumentoDemo($colaborador, $tipo, 'Comprobante de domicilio v2 (demo)');
        $v2->update([
            'status' => EstadoDocumento::Aprobado->value,
            'reviewed_at' => now(),
        ]);

        // subirVersion() ya archiva la versión anterior y encadena
        // previous_version_id automáticamente (ver DocumentoStorageService),
        // así que no hay nada más que enlazar aquí a mano.
    }

    private function crearDocumentoDemo(User $colaborador, DocumentType $tipo, string $texto): EmployeeDocument
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
            return $this->storage->subirVersion($colaborador, $tipo, $archivo, $colaborador->id);
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
     * Mismo efecto que Administracion\UsuarioController::destroy(): estatus
     * inactivo + soft delete. No se llama al controlador (es HTTP) pero es
     * exactamente la misma escritura que hace, para no divergir del
     * comportamiento real.
     */
    private function darDeBajaDemo(User $colaborador): void
    {
        if ($colaborador->trashed()) {
            return;
        }

        $colaborador->update(['estatus' => EstadoUsuario::Inactivo]);
        $colaborador->delete();
    }

    /**
     * Mismo efecto que Administracion\UsuarioController::reactivar().
     */
    private function reactivarDemo(User $colaborador): void
    {
        $colaborador->restore();
        $colaborador->update(['estatus' => EstadoUsuario::Activo]);
    }
}
