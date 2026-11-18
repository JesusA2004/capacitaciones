<?php

namespace App\Services\Certificados;

use App\Enums\CategoriaDocumento;
use App\Enums\EstadoCertificacionMrLana;
use App\Enums\EstadoDocumento;
use App\Enums\EstadoUsuario;
use App\Models\CertificacionMrLana;
use App\Models\Colaborador;
use App\Models\DocumentType;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Colaboradores\FotoColaboradorService;
use App\Services\Expedientes\DocumentoStorageService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Certificación Mr. Lana por formato oficial (RH-CC-R-001):
 *
 *  1. «Descargar formato»: el PDF original con el encabezado ya lleno
 *     (FormatoCertificacionPdfService). Queda registrado quién y cuándo.
 *  2. Se llena y firma a pluma (temas, fechas, evaluación, promedio).
 *  3. «Subir formato firmado» + promedio: el escaneo entra al expediente
 *     como documento APROBADO (`certificacion_mr_lana_firmada`, no
 *     exigible), la persona queda «Certificada» con folio verificable y se
 *     emite el certificado «Cuentas con tu Certificación Mr. Lana»
 *     (DiplomaCertificacionPdfService), guardado también en su expediente.
 *
 * Si el certificado no se puede imprimir en ese momento (Chrome/NAS), la
 * certificación NO se deshace: queda en el log y se genera al descargarlo.
 * La autorización vive en CertificacionMrLanaPolicy; el listado se acota con
 * AlcanceOrganizacionalService.
 */
class CertificacionFormatoService
{
    public const CLAVE_TIPO = 'certificacion_mr_lana_firmada';

    public function __construct(
        private readonly FormatoCertificacionPdfService $formato,
        private readonly DiplomaCertificacionPdfService $diploma,
        private readonly DocumentoStorageService $storage,
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly AuditoriaService $auditoria,
        private readonly FotoColaboradorService $fotos,
    ) {}

    public function promedioMinimo(): float
    {
        return (float) config('capacitacion.certificacion.promedio_minimo', 80);
    }

    /**
     * Personas del alcance (activas, o ya certificadas aunque hoy no lo
     * estén) con el estado de su certificación. Nunca la propia.
     *
     * @return array{personas: list<array<string, mixed>>, resumen: array{total: int, sin_formato: int, formato_descargado: int, certificados: int}, promedio_minimo: float, formato_clave: string, formato_disponible: bool}
     */
    public function listado(User $actor): array
    {
        $consulta = Colaborador::query()
            ->with(['sucursalPrincipal:id,nombre', 'puesto:id,nombre'])
            ->where(function (Builder $q): void {
                $q->where('estatus', EstadoUsuario::Activo->value)
                    ->orWhereIn('id', CertificacionMrLana::query()->whereNotNull('certificado_en')->select('colaborador_id'));
            })
            ->when($actor->colaborador_id !== null, fn (Builder $q) => $q->where('id', '!=', $actor->colaborador_id))
            ->orderBy('name')
            ->orderBy('apellidos');

        $colaboradores = $this->alcance->limitarColaboradoresPorAlcance($consulta, $actor)
            ->get(['id', 'name', 'apellidos', 'numero_empleado', 'fecha_ingreso', 'estatus', 'sucursal_principal_id', 'puesto_id', 'foto_path']);

        $certificaciones = CertificacionMrLana::query()
            ->whereIn('colaborador_id', $colaboradores->pluck('id'))
            ->get()
            ->keyBy('colaborador_id');

        $resumen = ['total' => 0, 'sin_formato' => 0, 'formato_descargado' => 0, 'certificados' => 0];
        $personas = [];

        foreach ($colaboradores as $colaborador) {
            $certificacion = $certificaciones->get($colaborador->id);
            $estado = $certificacion?->estado() ?? EstadoCertificacionMrLana::SinFormato;
            $resumen['total']++;
            $resumen[$estado === EstadoCertificacionMrLana::Certificado ? 'certificados' : $estado->value]++;

            $personas[] = [
                'id' => $colaborador->id,
                'nombre' => $colaborador->nombreCompleto(),
                'numero_empleado' => $colaborador->numero_empleado,
                'foto_url' => $this->fotos->urlPara($colaborador, $actor),
                'puesto' => $colaborador->puesto->nombre ?? null,
                'sucursal' => $colaborador->sucursalPrincipal->nombre ?? null,
                'fecha_ingreso' => $colaborador->fecha_ingreso?->toDateString(),
                'activo' => $colaborador->estatus === EstadoUsuario::Activo,
                'estado' => $estado->value,
                'estado_etiqueta' => $estado->etiqueta(),
                'formato_descargado_en' => $certificacion?->formato_descargado_en?->toIso8601String(),
                'certificado_en' => $certificacion?->certificado_en?->toIso8601String(),
                'promedio' => $certificacion?->promedio !== null ? (float) $certificacion->promedio : null,
                'folio' => $certificacion?->folio,
                'tiene_firmado' => $certificacion?->firmado_document_id !== null,
            ];
        }

        return [
            'personas' => $personas,
            'resumen' => $resumen,
            'promedio_minimo' => $this->promedioMinimo(),
            'formato_clave' => (string) config('capacitacion.certificacion.formato.clave', 'RH-CC-R-001'),
            'formato_disponible' => $this->formato->disponible(),
        ];
    }

    /**
     * PDF del formato con el encabezado lleno; registra la descarga (la
     * primera marca «Formato descargado»; las siguientes solo suman).
     *
     * @return array{pdf: string, nombre: string}
     */
    public function descargarFormato(Colaborador $colaborador, User $actor): array
    {
        $pdf = $this->formato->rellenar($colaborador);

        DB::transaction(function () use ($colaborador, $actor): void {
            $certificacion = CertificacionMrLana::query()->lockForUpdate()->firstOrNew(['colaborador_id' => $colaborador->id]);
            $certificacion->formato_clave = (string) config('capacitacion.certificacion.formato.clave', 'RH-CC-R-001');
            $certificacion->formato_descargado_en ??= now();
            $certificacion->formato_descargado_por ??= $actor->id;
            $certificacion->descargas = $certificacion->descargas + 1;
            $certificacion->save();

            $this->auditoria->registrar('certificacion_formato_descargado', $certificacion, $actor, [
                'colaborador_id' => $colaborador->id,
                'descargas' => $certificacion->descargas,
            ]);
        });

        return ['pdf' => $pdf, 'nombre' => sprintf('Certificacion Mr Lana - %s.pdf', $this->nombreArchivo($colaborador))];
    }

    /**
     * Sube el formato firmado, marca a la persona como certificada y emite
     * su certificado.
     *
     * @throws ValidationException
     */
    public function subirFirmado(Colaborador $colaborador, UploadedFile $archivo, float $promedio, User $actor): CertificacionMrLana
    {
        $minimo = $this->promedioMinimo();

        if ($promedio < $minimo) {
            throw ValidationException::withMessages([
                'promedio' => sprintf('Con promedio menor a %s no se asume la certificación (nota del formato). Guarda el formato en papel y vuelve a evaluar.', $this->numero($minimo)),
            ]);
        }

        $certificacion = DB::transaction(function () use ($colaborador, $archivo, $promedio, $actor): CertificacionMrLana {
            $certificacion = CertificacionMrLana::query()->lockForUpdate()->firstOrNew(['colaborador_id' => $colaborador->id]);

            if ($certificacion->certificada()) {
                throw ValidationException::withMessages(['archivo' => 'Esta persona ya cuenta con su Certificación Mr. Lana.']);
            }

            $documento = $this->storage->subirVersion($colaborador, $this->tipo(), $archivo, $actor->id, EstadoDocumento::Aprobado);

            $certificacion->fill([
                'formato_clave' => (string) config('capacitacion.certificacion.formato.clave', 'RH-CC-R-001'),
                'firmado_document_id' => $documento->id,
                'promedio' => $promedio,
                'folio' => $this->generarFolio(),
                'certificado_en' => now(),
                'emitido_por' => $actor->id,
            ]);
            $certificacion->save();

            $this->auditoria->registrar('certificacion_mr_lana_emitida', $certificacion, $actor, [
                'colaborador_id' => $colaborador->id,
                'folio' => $certificacion->folio,
                'promedio' => $promedio,
                'employee_document_id' => $documento->id,
            ]);

            return $certificacion;
        });

        try {
            $this->guardarDiploma($certificacion);
        } catch (Throwable $e) {
            Log::warning('Certificación Mr. Lana: no se pudo generar el certificado al subir el formato firmado; se generará al descargarlo.', [
                'certificacion_id' => $certificacion->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $certificacion->refresh();
    }

    public function de(Colaborador $colaborador): ?CertificacionMrLana
    {
        return CertificacionMrLana::query()->where('colaborador_id', $colaborador->id)->first();
    }

    public function respuestaFirmado(Colaborador $colaborador): StreamedResponse
    {
        $documento = $this->de($colaborador)?->documentoFirmado;
        abort_if($documento === null, 404, 'Esta persona todavía no tiene su formato firmado.');

        return $this->storage->respuesta($documento->path, [
            'Content-Type' => (string) ($documento->mime ?: 'application/octet-stream'),
            'Content-Disposition' => sprintf('inline; filename="Formato firmado - %s.%s"', $this->nombreArchivo($colaborador), $documento->extension ?: 'pdf'),
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Certificado en PDF; si aún no está guardado (falló al emitirse), se
     * genera y guarda ahora.
     */
    public function respuestaDiploma(Colaborador $colaborador, bool $descargar = false): Response
    {
        $certificacion = $this->de($colaborador);
        abort_if($certificacion === null || ! $certificacion->certificada(), 404, 'Esta persona todavía no cuenta con su certificación.');

        $pdf = null;

        if ($certificacion->certificado_ruta !== null && $this->storage->existe($certificacion->certificado_ruta)) {
            $pdf = (string) $this->storage->disco()->get($certificacion->certificado_ruta);
        }

        if ($pdf === null || $pdf === '') {
            $pdf = $this->guardarDiploma($certificacion);
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('%s; filename="Certificado Mr Lana - %s.pdf"', $descargar ? 'attachment' : 'inline', $this->nombreArchivo($colaborador)),
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Resumen para el expediente (insignia «Certificado Mr. Lana»).
     *
     * @return array{certificado_en: string, folio: string|null}|null
     */
    public function resumenExpediente(Colaborador $colaborador): ?array
    {
        $certificacion = CertificacionMrLana::query()
            ->where('colaborador_id', $colaborador->id)
            ->whereNotNull('certificado_en')
            ->first(['id', 'certificado_en', 'folio']);

        if ($certificacion === null || $certificacion->certificado_en === null) {
            return null;
        }

        return ['certificado_en' => $certificacion->certificado_en->toDateString(), 'folio' => $certificacion->folio];
    }

    /** Imprime el certificado y lo guarda en el expediente; devuelve los bytes. */
    private function guardarDiploma(CertificacionMrLana $certificacion): string
    {
        $pdf = $this->diploma->renderizar($certificacion);
        $ruta = $this->storage->guardarContenidoEnExpediente(
            $certificacion->colaborador,
            CategoriaDocumento::Otros,
            sprintf('Certificado Mr Lana - %s.pdf', $certificacion->folio),
            $pdf,
        );

        $certificacion->forceFill(['certificado_disk' => (string) config('expedientes.disk'), 'certificado_ruta' => $ruta])->save();

        return $pdf;
    }

    private function tipo(): DocumentType
    {
        return DocumentType::query()->firstOrCreate(
            ['clave' => self::CLAVE_TIPO],
            [
                'nombre' => 'Certificación Mr. Lana (formato firmado)',
                'categoria' => CategoriaDocumento::Otros->value,
                'requerido' => false,
                'aplica_alta' => false,
                'activo' => true,
            ],
        );
    }

    private function generarFolio(): string
    {
        do {
            $folio = sprintf('MRLC-%s', strtoupper(Str::random(8)));
        } while (CertificacionMrLana::query()->where('folio', $folio)->exists());

        return $folio;
    }

    private function nombreArchivo(Colaborador $colaborador): string
    {
        return trim((string) preg_replace('/[^A-Za-z0-9 ]+/', '', Str::ascii($colaborador->nombreCompleto())));
    }

    private function numero(float $valor): string
    {
        return rtrim(rtrim(number_format($valor, 2, '.', ''), '0'), '.');
    }
}
