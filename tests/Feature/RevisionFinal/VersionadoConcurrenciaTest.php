<?php

use App\Enums\CategoriaDocumento;
use App\Enums\EstadoFlujoDocumento;
use App\Models\Colaborador;
use App\Models\GeneratedDocument;
use App\Models\User;
use App\Services\Documentos\Versiones\DocumentoRenderizado;
use App\Services\Documentos\Versiones\FuenteDocumento;
use App\Services\Documentos\Versiones\VersionadoDocumentoService;
use Database\Seeders\RolesYPermisosSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
| Hallazgo 4: dos emisiones simultáneas dejaban dos versiones vivas. SQLite no bloquea
| filas, así que la carrera se reproduce de forma determinista: la otra emisión ocurre
| mientras esta renderiza, que es justo el tramo que corre fuera de la transacción.
| El lockForUpdate real de MariaDB no se ejercita aquí.
*/

function rfFuenteDe(Colaborador $colaborador, array &$datos, ?Closure &$alRenderizar): FuenteDocumento
{
    return new class($colaborador, $datos, $alRenderizar) implements FuenteDocumento
    {
        public function __construct(private Colaborador $persona, private array &$datos, private ?Closure &$alRenderizar) {}

        public function colaborador(): Colaborador
        {
            return $this->persona;
        }

        public function registro(): Model
        {
            return $this->persona;
        }

        public function clave(): string
        {
            return 'rf_documento';
        }

        public function etiqueta(): string
        {
            return 'Documento';
        }

        public function puedeEditar(User $actor): bool
        {
            return true;
        }

        public function puedeVerVersiones(User $actor): bool
        {
            return true;
        }

        public function puedeRectificar(User $actor): bool
        {
            return true;
        }

        public function campos(User $actor): array
        {
            return [];
        }

        public function valoresHuella(array $correcciones, User $actor): array
        {
            return $this->datos;
        }

        public function vistaPrevia(array $correcciones, User $actor): string
        {
            return 'pdf';
        }

        public function renderizar(array $correcciones, User $actor): DocumentoRenderizado
        {
            $accion = $this->alRenderizar;
            $this->alRenderizar = null;

            if ($accion !== null) {
                $accion();
            }

            return new DocumentoRenderizado('pdf', 'Documento', CategoriaDocumento::Otros, ['categoria' => CategoriaDocumento::Otros]);
        }

        public function despuesDeEmitir(GeneratedDocument $documento, ?GeneratedDocument $anterior, array $correcciones, ?string $motivo, User $actor): void {}

        public function bloqueo(?GeneratedDocument $vigente, bool $rectificar): ?string
        {
            return null;
        }
    };
}

beforeEach(function () {
    $this->seed(RolesYPermisosSeeder::class);
    Notification::fake();
    $this->rh = clUsuario('rh_admin');
    $this->persona = Colaborador::factory()->create();
    $this->versionado = app(VersionadoDocumentoService::class);
    $this->datos = ['dato' => 'uno'];
    $this->alRenderizar = null;
    $this->fuente = rfFuenteDe($this->persona, $this->datos, $this->alRenderizar);
    $this->v1 = $this->versionado->emitir($this->fuente, [], null, $this->rh)->documento;
    $this->vivas = fn (): array => GeneratedDocument::query()
        ->where('clave_plantilla', 'rf_documento')
        ->where('estado_flujo', '!=', EstadoFlujoDocumento::Cancelado->value)
        ->pluck('id')->all();
});

test('dos correcciones distintas simultáneas no dejan dos versiones vivas', function () {
    $this->datos = ['dato' => 'corrección A'];
    $this->alRenderizar = function (): void {
        // La otra petición empezó después y terminó primero.
        $datosA = $this->datos;
        $this->datos = ['dato' => 'corrección B'];
        $this->versionado->emitir($this->fuente, [], 'Corrección de B', $this->rh);
        $this->datos = $datosA;
    };

    expect(fn () => $this->versionado->emitir($this->fuente, [], 'Corrección de A', $this->rh))
        ->toThrow(ValidationException::class);

    $vivas = ($this->vivas)();

    expect($vivas)->toHaveCount(1)
        ->and($this->v1->refresh()->estado_flujo)->toBe(EstadoFlujoDocumento::Cancelado)
        ->and(GeneratedDocument::query()->findOrFail($vivas[0])->revision_de_id)->toBe($this->v1->id);

    // Lo que renderizó la petición perdedora no queda como archivo huérfano.
    expect(collect(Storage::disk('nas')->allFiles())->filter(fn (string $f): bool => str_ends_with($f, '.pdf')))->toHaveCount(2);
});

test('el doble clic con los mismos datos devuelve la versión ya emitida', function () {
    $this->datos = ['dato' => 'corrección A'];
    $this->alRenderizar = fn () => $this->versionado->emitir($this->fuente, [], 'Corrección de A', $this->rh);

    $resultado = $this->versionado->emitir($this->fuente, [], 'Corrección de A', $this->rh);

    expect($resultado->creado)->toBeFalse()->and(($this->vivas)())->toBe([$resultado->documento->id]);
});

test('si la versión vigente se firma mientras se renderiza, no se cancela lo firmado', function () {
    $this->datos = ['dato' => 'corrección A'];
    $this->alRenderizar = fn () => $this->v1->update(['estado_flujo' => EstadoFlujoDocumento::FirmadoFisicamente->value]);

    expect(fn () => $this->versionado->emitir($this->fuente, [], 'Corrección de A', $this->rh))
        ->toThrow(ValidationException::class);

    expect($this->v1->refresh()->estado_flujo)->toBe(EstadoFlujoDocumento::FirmadoFisicamente)
        ->and(($this->vivas)())->toBe([$this->v1->id]);
});
