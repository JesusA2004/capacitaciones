<?php

namespace App\Services\Expedientes\MigracionInicial;

use App\Enums\EstadoAltaColaborador;
use App\Enums\EstadoUsuario;
use App\Enums\Genero;
use App\Models\Colaborador;
use App\Models\ColaboradorDatosMedicos;
use App\Models\ExpedienteHistorico;
use App\Models\MigracionExpedientes;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Colaboradores\NumeroEmpleadoService;
use App\Services\Expedientes\DocumentoStorageService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Ejecuta un plan ya analizado (y las decisiones manuales de RH).
 *
 * Orden y checkpoints (una transacción SQL no deshace el NAS):
 *   1. colaborador: alta/actualización en su propia transacción;
 *   2. por cada PDF: copiar → verificar SHA-256 → registrar en BD →
 *      SOLO ENTONCES, y solo en modo «mover», borrar el origen.
 * Un fallo deja: la copia sin fila se borra (nunca un registro apuntando a
 * un archivo inexistente) y el origen intacto. Todo queda en el manifiesto
 * (se escribe incrementalmente) para auditar y reintentar. Reintentar es
 * seguro: lo ya registrado (mismo origen) o el destino idéntico (mismo
 * SHA-256) se marcan duplicado; un destino distinto es conflicto y no se toca.
 */
class EjecutorMigracion
{
    public const MODO_COPIAR = 'copiar';

    public const MODO_MOVER = 'mover';

    /** @var list<array<string, mixed>> */
    private array $manifiesto = [];

    /** @var array<string, int> */
    private array $conteo = [];

    /** @var array<int, int> colaborador_id => fila del Excel */
    private array $procesados = [];

    public function __construct(
        private readonly DocumentoStorageService $storage,
        private readonly InventarioNasHistorico $inventario,
        private readonly NumeroEmpleadoService $numeros,
    ) {}

    /**
     * @return array<string, int>
     */
    public function ejecutar(MigracionExpedientes $migracion, User $actor, string $modo = self::MODO_COPIAR): array
    {
        $plan = $migracion->planArray();
        $decisiones = $migracion->decisiones ?? [];
        $this->manifiesto = [];
        $this->conteo = array_fill_keys(['creados', 'actualizados', 'sin_cambios', 'conflictos', 'omitidos', 'pdfs_registrados', 'pdfs_copiados', 'pdfs_duplicados', 'pdfs_conflicto', 'historicos_creados', 'pendientes_vincular', 'sin_match', 'cuentas_creadas', 'cuentas_existentes', 'cuentas_sin_correo', 'cuentas_conflicto', 'errores'], 0);
        $this->procesados = [];
        $migracion->update(['estado' => 'aplicando', 'etapa' => 'Colaboradores y expedientes', 'modo' => $modo, 'iniciada_en' => now(), 'progreso' => 0, 'progreso_total' => count($plan['filas'] ?? []) + count($plan['carpetas_sin_persona'] ?? []), 'error' => null]);
        $rutasDecididas = [];

        foreach ((array) ($decisiones['filas'] ?? []) as $d) {
            if (! empty($d['ruta'])) {
                $rutasDecididas[(string) $d['ruta']] = true;
            }
        }

        foreach ($plan['filas'] ?? [] as $fila) {
            $this->paso($migracion);

            if (in_array($fila['operacion'], ['conflicto', 'omitir'], true)) {
                $this->conteo[$fila['operacion'] === 'conflicto' ? 'conflictos' : 'omitidos']++;

                continue;
            }

            try {
                $colaborador = $this->guardarColaborador($fila, $actor);
                $this->procesados[$colaborador->id] = (int) $fila['fila'];
            } catch (Throwable $e) {
                $this->error('colaborador', ['fila' => $fila['fila'], 'nombre' => $fila['nombre_completo']], $e);

                continue;
            }

            $decision = $decisiones['filas'][(string) $fila['fila']] ?? null;
            $ruta = is_array($decision) ? ($decision['ruta'] ?? null) : (in_array($fila['nas']['tipo'], ['exacto', 'alto'], true) ? ($fila['nas']['ruta'] ?? null) : null);

            if ($ruta === null || $ruta === '') {
                $this->conteo['sin_match']++;

                continue;
            }

            $this->copiarCarpeta($colaborador, (string) $ruta, $fila['nas']['sucursal_carpeta'] ?? null, $migracion, $actor, $modo);
        }

        foreach ($plan['carpetas_sin_persona'] ?? [] as $carpeta) {
            $this->paso($migracion);

            if (isset($rutasDecididas[$carpeta['ruta']])) {
                continue;
            }

            $decision = $decisiones['carpetas'][$carpeta['ruta']] ?? [];
            $accion = (string) ($decision['accion'] ?? $carpeta['accion']);

            try {
                match ($accion) {
                    'vincular' => $this->vincularAExistente((int) ($decision['colaborador_id'] ?? $carpeta['colaborador_id'] ?? 0), $carpeta, $migracion, $actor, $modo),
                    'historico' => $this->crearHistorico($carpeta, $migracion, $actor, $modo),
                    'pendiente' => $this->guardarPendiente($carpeta, $migracion, $actor, $modo),
                    default => null,
                };
            } catch (Throwable $e) {
                $this->error('carpeta', ['ruta' => $carpeta['ruta']], $e);
            }
        }

        $migracion->update(['etapa' => 'Creando cuentas de acceso']);
        $credenciales = $this->crearCuentas($this->procesados, $actor);
        $rutaCredenciales = sprintf('migraciones-expedientes/credenciales-%d.enc', $migracion->id);
        Storage::disk('local')->put($rutaCredenciales, Crypt::encryptString((string) json_encode($credenciales, JSON_UNESCAPED_UNICODE)));

        $manifiesto = $this->escribirManifiesto($migracion);
        $migracion->update([
            'etapa' => 'Terminado',
            'credenciales_path' => $rutaCredenciales,
            'estado' => $this->conteo['errores'] > 0 ? 'completado_con_errores' : 'completado',
            'resultado' => $this->conteo,
            'manifiesto_path' => $manifiesto,
            'terminada_en' => now(),
        ]);

        return $this->conteo;
    }

    /**
     * Cuenta de acceso para cada colaborador ACTIVO procesado que no tenga
     * una. Usuario = su correo REAL del Excel (el login es por correo); sin
     * correo no se fabrica uno: queda en la lista como «sin correo» para
     * darle acceso después por el flujo normal. La contraseña es temporal y
     * aleatoria; solo vive cifrada en la lista de credenciales.
     *
     * @param  array<int, int>  $colaboradorIds  colaborador_id => fila
     * @return list<array{persona: string, numero_empleado: string|null, sucursal: string|null, puesto: string|null, usuario: string|null, contrasena: string|null, estado: string}>
     */
    private function crearCuentas(array $colaboradorIds, User $actor): array
    {
        $lista = [];

        foreach (array_keys($colaboradorIds) as $id) {
            $c = Colaborador::withTrashed()->with(['user', 'sucursalPrincipal:id,nombre', 'puesto:id,nombre'])->where('id', $id)->first();

            if ($c === null || $c->estatus !== EstadoUsuario::Activo) {
                continue;
            }

            $base = ['persona' => $c->nombreCompleto(), 'numero_empleado' => $c->numero_empleado, 'sucursal' => $c->sucursalPrincipal?->nombre, 'puesto' => $c->puesto?->nombre];

            if ($c->user !== null) {
                $lista[] = [...$base, 'usuario' => $c->user->email, 'contrasena' => null, 'estado' => 'Ya tenía cuenta (no se cambió su contraseña)'];
                $this->conteo['cuentas_existentes']++;

                continue;
            }

            $correo = $c->correo_personal !== null ? Normalizador::correo($c->correo_personal) : null;

            if ($correo === null) {
                $lista[] = [...$base, 'usuario' => null, 'contrasena' => null, 'estado' => 'Sin correo en el Excel: dar acceso después'];
                $this->conteo['cuentas_sin_correo']++;

                continue;
            }

            if (User::withTrashed()->where('email', $correo)->exists()) {
                $lista[] = [...$base, 'usuario' => $correo, 'contrasena' => null, 'estado' => 'El correo ya lo usa otra cuenta: revisar'];
                $this->conteo['cuentas_conflicto']++;

                continue;
            }

            $contrasena = $this->contrasenaTemporal();

            try {
                DB::transaction(function () use ($c, $correo, $contrasena): void {
                    $usuario = User::query()->create([
                        'colaborador_id' => $c->id,
                        'name' => $c->name,
                        'apellidos' => $c->apellidos,
                        'email' => $correo,
                        'password' => Hash::make($contrasena),
                    ]);
                    $usuario->forceFill(['email_verified_at' => now()])->save();
                    $usuario->assignRole('colaborador');
                });
            } catch (Throwable $e) {
                $this->error('cuenta', ['colaborador_id' => $c->id], $e);
                $lista[] = [...$base, 'usuario' => $correo, 'contrasena' => null, 'estado' => 'Error al crear la cuenta'];

                continue;
            }

            $lista[] = [...$base, 'usuario' => $correo, 'contrasena' => $contrasena, 'estado' => 'Cuenta creada'];
            $this->conteo['cuentas_creadas']++;
            $this->registrar('cuenta_creada', ['colaborador_id' => $c->id, 'usuario' => $correo, 'por' => $actor->id]);
        }

        usort($lista, fn ($a, $b) => [$a['sucursal'], $a['persona']] <=> [$b['sucursal'], $b['persona']]);

        return $lista;
    }

    /** Legible y sin caracteres ambiguos (0/O, 1/l/I): «Lana-7K4P-2931». */
    private function contrasenaTemporal(): string
    {
        $letras = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $bloque = '';

        for ($i = 0; $i < 4; $i++) {
            $bloque .= $letras[random_int(0, strlen($letras) - 1)];
        }

        return sprintf('Lana-%s-%04d', $bloque, random_int(0, 9999));
    }

    /**
     * @param  array<string, mixed>  $fila
     */
    private function guardarColaborador(array $fila, User $actor): Colaborador
    {
        $datos = array_filter($fila['datos'], fn ($v) => $v !== null && $v !== '');

        return DB::transaction(function () use ($fila, $datos, $actor): Colaborador {
            $colaborador = $fila['colaborador_id'] !== null ? Colaborador::withTrashed()->where('id', $fila['colaborador_id'])->lockForUpdate()->first() : null;
            $activo = ($datos['estatus'] ?? 'activo') === 'activo';
            $datos['estatus'] = $activo ? EstadoUsuario::Activo : EstadoUsuario::Inactivo;

            if (isset($datos['genero'])) {
                $datos['genero'] = Genero::from((string) $datos['genero']);
            }

            if ($colaborador === null) {
                $colaborador = Colaborador::query()->create([
                    ...$datos,
                    'numero_empleado' => $this->numeros->siguiente(),
                    'estado_alta' => $activo ? EstadoAltaColaborador::Activo : EstadoAltaColaborador::Baja,
                    'activado_en' => $activo ? ($datos['fecha_ingreso'] ?? now()) : null,
                    'importado_de' => 'excel_base_general',
                    'alta_registrada_por' => $actor->id,
                ]);
                $this->conteo['creados']++;
                $this->registrar('colaborador_creado', ['fila' => $fila['fila'], 'colaborador_id' => $colaborador->id, 'numero_empleado' => $colaborador->numero_empleado]);
            } else {
                $cambios = [];

                foreach ($datos as $campo => $valor) {
                    $actual = $colaborador->getAttribute($campo);
                    $comparable = $actual instanceof \BackedEnum ? $actual->value : ($actual instanceof \DateTimeInterface ? $actual->format('Y-m-d') : $actual);
                    $nuevo = $valor instanceof \BackedEnum ? $valor->value : $valor;

                    if ((string) $comparable !== (string) $nuevo) {
                        $cambios[$campo] = $valor;
                    }
                }

                if ($cambios !== []) {
                    $colaborador->update($cambios);
                    $this->conteo['actualizados']++;
                    $this->registrar('colaborador_actualizado', ['fila' => $fila['fila'], 'colaborador_id' => $colaborador->id, 'campos' => array_keys($cambios)]);
                } else {
                    $this->conteo['sin_cambios']++;
                }
            }

            if (($fila['medicos'] ?? []) !== []) {
                ColaboradorDatosMedicos::query()->updateOrCreate(['colaborador_id' => $colaborador->id], [...$fila['medicos'], 'fuente' => 'excel_base_general', 'actualizado_por' => $actor->id]);
            }

            $colaborador->load('sucursalPrincipal.empresa');

            return $colaborador;
        });
    }

    /**
     * @param  array<string, mixed>  $carpeta
     */
    private function vincularAExistente(int $colaboradorId, array $carpeta, MigracionExpedientes $migracion, User $actor, string $modo): void
    {
        $colaborador = Colaborador::withTrashed()->with('sucursalPrincipal.empresa')->where('id', $colaboradorId)->first();

        if ($colaborador === null) {
            $this->guardarPendiente($carpeta, $migracion, $actor, $modo);

            return;
        }

        $this->copiarCarpeta($colaborador, (string) $carpeta['ruta'], $carpeta['sucursal_carpeta'] ?? null, $migracion, $actor, $modo);
    }

    /**
     * Carpeta de alguien que ya no está en el Excel (normalmente una baja):
     * colaborador de baja con SOLO el nombre de la carpeta y la sucursal.
     * Nada más se inventa. Reutiliza el histórico si ya se creó antes.
     *
     * @param  array<string, mixed>  $carpeta
     */
    private function crearHistorico(array $carpeta, MigracionExpedientes $migracion, User $actor, string $modo): void
    {
        $tokens = Normalizador::tokensNombre((string) $carpeta['carpeta']);
        $titulo = fn (array $t) => mb_convert_case(mb_strtolower(implode(' ', $t)), MB_CASE_TITLE, 'UTF-8');
        $nombre = $titulo(array_slice($tokens, 0, -2));
        $apellidos = $titulo(array_slice($tokens, -2));

        $colaborador = Colaborador::withTrashed()
            ->where('importado_de', 'nas_historico')
            ->where('sucursal_principal_id', $carpeta['sucursal_id'])
            ->get()
            ->first(fn (Colaborador $c) => Normalizador::clave($c->nombreCompleto()) === Normalizador::clave($nombre.' '.$apellidos));

        if ($colaborador === null) {
            $colaborador = DB::transaction(fn () => Colaborador::query()->create([
                'name' => $nombre,
                'apellidos' => $apellidos,
                'sucursal_principal_id' => $carpeta['sucursal_id'],
                'estatus' => EstadoUsuario::Inactivo,
                'estado_alta' => EstadoAltaColaborador::Baja,
                'numero_empleado' => $this->numeros->siguiente(),
                'importado_de' => 'nas_historico',
                'alta_registrada_por' => $actor->id,
            ]));
            $this->conteo['historicos_creados']++;
            $this->registrar('historico_creado', ['colaborador_id' => $colaborador->id, 'carpeta' => $carpeta['ruta']]);
        }

        $colaborador->load('sucursalPrincipal.empresa');
        $this->copiarCarpeta($colaborador, (string) $carpeta['ruta'], $carpeta['sucursal_carpeta'] ?? null, $migracion, $actor, $modo);
    }

    /**
     * @param  array<string, mixed>  $carpeta
     */
    private function guardarPendiente(array $carpeta, MigracionExpedientes $migracion, User $actor, string $modo): void
    {
        $sucursal = isset($carpeta['sucursal_id']) ? Sucursal::query()->with('empresa')->where('id', $carpeta['sucursal_id'])->first() : null;
        $empresa = $sucursal?->empresa->nombre ?? (string) config('expedientes.migracion_inicial.empresa', 'Mr. Lana');

        foreach ($this->inventario->pdfsDe((string) $carpeta['ruta']) as $pdf) {
            if ($this->inventario->enSitio()) {
                $this->registrarEnSitio(null, $pdf, $carpeta, $sucursal?->id, $migracion, $actor, ExpedienteHistorico::PENDIENTE);

                continue;
            }

            if (ExpedienteHistorico::query()->where('source_path', $pdf)->exists()) {
                $this->conteo['pdfs_duplicados']++;

                continue;
            }

            $destino = $this->storage->rutaPendienteVincular($empresa, $sucursal->nombre ?? (string) $carpeta['sucursal_carpeta'], (string) $carpeta['carpeta'], basename($pdf));
            $this->copiarPdf(null, $pdf, $destino, $carpeta, $sucursal?->id, $migracion, $actor, $modo, ExpedienteHistorico::PENDIENTE);
        }

        $this->conteo['pendientes_vincular']++;
    }

    private function copiarCarpeta(Colaborador $colaborador, string $ruta, ?string $sucursalCarpeta, MigracionExpedientes $migracion, User $actor, string $modo): void
    {
        $carpeta = ['ruta' => $ruta, 'carpeta' => basename($ruta), 'sucursal_carpeta' => $sucursalCarpeta];

        if ($this->inventario->enSitio()) {
            // Ya está en su ubicación definitiva: esa carpeta ES el
            // expediente del colaborador (no se crea otra «EMP-… - Nombre»).
            $this->fijarCarpetaExpediente($colaborador, $ruta);

            foreach ($this->inventario->pdfsDe($ruta) as $pdf) {
                $this->registrarEnSitio($colaborador, $pdf, $carpeta, $colaborador->sucursal_principal_id, $migracion, $actor, ExpedienteHistorico::VINCULADO);
            }

            return;
        }

        foreach ($this->inventario->pdfsDe($ruta) as $pdf) {
            if (ExpedienteHistorico::query()->where('source_path', $pdf)->exists()) {
                $this->conteo['pdfs_duplicados']++;

                continue;
            }

            $indice = ExpedienteHistorico::query()->where('colaborador_id', $colaborador->id)->count() + 1;
            $this->copiarPdf($colaborador, $pdf, $this->storage->rutaHistorico($colaborador, $indice), $carpeta, $colaborador->sucursal_principal_id, $migracion, $actor, $modo, ExpedienteHistorico::VINCULADO);
        }
    }

    /**
     * copiar → verificar → registrar BD → (mover) borrar origen.
     *
     * @param  array<string, mixed>  $carpeta
     */
    private function copiarPdf(?Colaborador $colaborador, string $origen, string $destino, array $carpeta, ?int $sucursalId, MigracionExpedientes $migracion, User $actor, string $modo, string $estado): void
    {
        $existiaDestino = $this->storage->existe($destino);

        try {
            $copia = $this->storage->copiarDesdeDisco($this->inventario->disco(), $origen, $destino);
        } catch (Throwable $e) {
            $this->error('copia', ['origen' => $origen, 'destino' => $destino], $e);

            return;
        }

        if ($copia['estado'] === 'conflicto') {
            $this->conteo['pdfs_conflicto']++;
            $this->registrar('pdf_conflicto', ['origen' => $origen, 'destino' => $destino, 'hash_origen' => $copia['hash']]);

            return;
        }

        try {
            ExpedienteHistorico::query()->create([
                'colaborador_id' => $colaborador?->id,
                'estado' => $estado,
                'disk' => (string) config('expedientes.disk'),
                'path' => $destino,
                'original_name' => basename($origen),
                'stored_name' => basename($destino),
                'mime' => 'application/pdf',
                'extension' => 'pdf',
                'size' => $copia['size'],
                'hash' => $copia['hash'],
                'source_disk' => $this->inventario->nombreDisco(),
                'source_path' => $origen,
                'source_sucursal' => $carpeta['sucursal_carpeta'] ?? null,
                'source_carpeta' => $carpeta['carpeta'] ?? null,
                'nombre_detectado' => implode(' ', Normalizador::tokensNombre((string) ($carpeta['carpeta'] ?? ''))) ?: null,
                'sucursal_id' => $sucursalId,
                'migracion_id' => $migracion->id,
                'migrated_at' => now(),
                'migrated_by' => $actor->id,
                'vinculado_en' => $colaborador !== null ? now() : null,
                'vinculado_por' => $colaborador !== null ? $actor->id : null,
            ]);
        } catch (Throwable $e) {
            // Sin fila no debe quedar una copia nueva suelta (la que ya
            // existía idéntica antes de esta corrida no se toca).
            if (! $existiaDestino && $copia['estado'] === 'copiado') {
                $this->storage->eliminar($destino);
            }

            $this->error('registro', ['origen' => $origen, 'destino' => $destino], $e);

            return;
        }

        $this->conteo[$copia['estado'] === 'copiado' ? 'pdfs_copiados' : 'pdfs_duplicados']++;
        $this->registrar('pdf_'.$copia['estado'], ['colaborador_id' => $colaborador?->id, 'origen' => $origen, 'destino' => $destino, 'hash' => $copia['hash']]);

        if ($modo === self::MODO_MOVER) {
            try {
                $this->inventario->disco()->delete($origen);
                $this->registrar('origen_borrado', ['origen' => $origen]);
            } catch (Throwable $e) {
                $this->error('borrar_origen', ['origen' => $origen], $e);
            }
        }
    }

    /**
     * El PDF ya está donde debe: solo hash + metadata en BD. No se copia,
     * no se renombra, no se borra. Idempotente por ruta.
     *
     * @param  array<string, mixed>  $carpeta
     */
    private function registrarEnSitio(?Colaborador $colaborador, string $pdf, array $carpeta, ?int $sucursalId, MigracionExpedientes $migracion, User $actor, string $estado): void
    {
        $existente = ExpedienteHistorico::query()->where('path', $pdf)->first();

        if ($existente !== null) {
            // Ya registrado: si estaba pendiente y ahora sí hay persona, se vincula.
            if ($existente->colaborador_id === null && $colaborador !== null) {
                $existente->update(['colaborador_id' => $colaborador->id, 'estado' => ExpedienteHistorico::VINCULADO, 'vinculado_por' => $actor->id, 'vinculado_en' => now()]);
                $this->registrar('pdf_vinculado', ['colaborador_id' => $colaborador->id, 'ruta' => $pdf]);
            }

            $this->conteo['pdfs_duplicados']++;

            return;
        }

        try {
            $disco = $this->inventario->disco();
            ExpedienteHistorico::query()->create([
                'colaborador_id' => $colaborador?->id,
                'estado' => $estado,
                'disk' => $this->inventario->nombreDisco(),
                'path' => $pdf,
                'original_name' => basename($pdf),
                'stored_name' => basename($pdf),
                'mime' => 'application/pdf',
                'extension' => 'pdf',
                'size' => (int) $disco->size($pdf),
                'hash' => $this->storage->hashEnDisco($disco, $pdf),
                'source_disk' => $this->inventario->nombreDisco(),
                'source_path' => $pdf,
                'source_sucursal' => $carpeta['sucursal_carpeta'] ?? null,
                'source_carpeta' => $carpeta['carpeta'] ?? null,
                'nombre_detectado' => implode(' ', Normalizador::tokensNombre((string) ($carpeta['carpeta'] ?? ''))) ?: null,
                'sucursal_id' => $sucursalId,
                'migracion_id' => $migracion->id,
                'migrated_at' => now(),
                'migrated_by' => $actor->id,
                'vinculado_en' => $colaborador !== null ? now() : null,
                'vinculado_por' => $colaborador !== null ? $actor->id : null,
            ]);
        } catch (Throwable $e) {
            $this->error('registro', ['ruta' => $pdf], $e);

            return;
        }

        $this->conteo['pdfs_registrados']++;
        $this->registrar('pdf_registrado_en_sitio', ['colaborador_id' => $colaborador?->id, 'ruta' => $pdf]);
    }

    /**
     * La carpeta existente pasa a ser la ruta del expediente del colaborador
     * (los documentos nuevos del checklist se guardarán ahí). Si ya tenía
     * otra carpeta asignada no se mueve nada: se reporta.
     */
    private function fijarCarpetaExpediente(Colaborador $colaborador, string $ruta): void
    {
        $carpeta = strtolower(pathinfo($ruta, PATHINFO_EXTENSION)) === 'pdf' ? dirname($ruta) : $ruta;
        $actual = $colaborador->expediente_storage_path;

        if ($actual === null || trim($actual) === '') {
            // Un PDF suelto en la carpeta de sucursal no da carpeta propia.
            if ($carpeta !== $ruta) {
                return;
            }

            $colaborador->forceFill(['expediente_storage_path' => $carpeta])->save();

            return;
        }

        if ($actual !== $carpeta) {
            $this->registrar('carpeta_distinta', ['colaborador_id' => $colaborador->id, 'actual' => $actual, 'encontrada' => $carpeta]);
        }
    }

    private function paso(MigracionExpedientes $migracion): void
    {
        $migracion->increment('progreso');

        if ($migracion->progreso % 25 === 0) {
            $this->escribirManifiesto($migracion);
        }
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function registrar(string $evento, array $datos): void
    {
        $this->manifiesto[] = ['evento' => $evento, 'en' => now()->toIso8601String(), ...$datos];
    }

    /**
     * @param  array<string, mixed>  $contexto
     */
    private function error(string $etapa, array $contexto, Throwable $e): void
    {
        $this->conteo['errores']++;
        $this->registrar('error_'.$etapa, [...$contexto, 'error' => $e->getMessage()]);
        Log::warning('Migración inicial de expedientes: error.', [...$contexto, 'etapa' => $etapa, 'error' => $e->getMessage()]);
    }

    private function escribirManifiesto(MigracionExpedientes $migracion): string
    {
        $ruta = sprintf('expedientes-migrations/%s-inicial-%d.json', ($migracion->iniciada_en ?? now())->format('Y-m-d-His'), $migracion->id);
        Storage::disk('local')->put($ruta, (string) json_encode([
            'migracion_id' => $migracion->id,
            'archivo' => $migracion->archivo_nombre,
            'archivo_hash' => $migracion->archivo_hash,
            'modo' => $migracion->modo,
            'conteo' => $this->conteo,
            'eventos' => $this->manifiesto,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $ruta;
    }
}
