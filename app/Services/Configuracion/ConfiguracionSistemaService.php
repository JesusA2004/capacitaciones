<?php

namespace App\Services\Configuracion;

use App\Enums\GrupoPuestoIndicador;
use App\Models\ConfiguracionSistema;
use App\Models\DocumentType;
use App\Models\Puesto;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use BackedEnum;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Única fuente de los valores administrables del sistema (colores
 * institucionales, parámetros de RH). El catálogo de claves válidas vive en
 * config/configuracion_sistema.php; la tabla configuraciones_sistema solo
 * guarda lo que se cambió. Los valores con 'config' reemplazan su clave de
 * config() al arrancar, así el resto del código no cambia.
 *
 * Todo cambio queda auditado (actor, antes, después, fecha).
 */
class ConfiguracionSistemaService
{
    private const CACHE = 'configuracion_sistema.valores';

    public function __construct(private readonly AuditoriaService $auditoria) {}

    /**
     * @return array<string, array<string, mixed>>
     */
    public function catalogo(?string $grupo = null): array
    {
        /** @var array<string, array<string, mixed>> $parametros */
        $parametros = config('configuracion_sistema.parametros', []);

        return $grupo === null ? $parametros : array_filter($parametros, fn (array $p) => ($p['grupo'] ?? null) === $grupo);
    }

    public function valor(string $clave): mixed
    {
        $guardados = $this->guardados();

        return array_key_exists($clave, $guardados) ? $guardados[$clave] : ($this->catalogo()[$clave]['defecto'] ?? null);
    }

    /**
     * Parámetros de un grupo listos para la pantalla.
     *
     * @return list<array<string, mixed>>
     */
    public function grupo(string $grupo): array
    {
        $guardados = $this->guardados();
        $filas = ConfiguracionSistema::query()->where('grupo', $grupo)->with('actualizadoPor:id,name')->get()->keyBy('clave');
        $resultado = [];

        foreach ($this->catalogo($grupo) as $clave => $p) {
            $fila = $filas->get($clave);
            $opciones = null;

            if (isset($p['opciones_enum']) && is_string($p['opciones_enum']) && enum_exists($p['opciones_enum'])) {
                /** @var class-string<BackedEnum> $enum */
                $enum = $p['opciones_enum'];
                $opciones = array_map(fn (BackedEnum $c) => ['value' => $c->value, 'etiqueta' => method_exists($c, 'etiqueta') ? $c->etiqueta() : (string) $c->value], $enum::cases());
            }

            $resultado[] = [
                'clave' => $clave,
                'etiqueta' => $p['etiqueta'] ?? $clave,
                'descripcion' => $p['descripcion'] ?? null,
                'tipo' => $p['tipo'],
                'valor' => array_key_exists($clave, $guardados) ? $guardados[$clave] : $p['defecto'],
                'defecto' => $p['defecto'],
                'personalizado' => array_key_exists($clave, $guardados),
                'opciones' => $opciones,
                'css' => $p['css'] ?? null,
                'seccion' => $p['seccion'] ?? null,
                'actualizado_por' => $fila?->actualizadoPor?->name,
                'actualizado_en' => $fila?->updated_at?->toIso8601String(),
            ];
        }

        return $resultado;
    }

    /**
     * Guarda los valores enviados de un grupo. Un valor igual al de fábrica
     * borra la personalización (vuelve a seguir el default versionado).
     *
     * @param  array<string, mixed>  $valores  clave => valor
     */
    public function guardar(string $grupo, array $valores, User $actor): void
    {
        $catalogo = $this->catalogo($grupo);
        $desconocidas = array_diff(array_keys($valores), array_keys($catalogo));

        if ($desconocidas !== []) {
            throw ValidationException::withMessages(['valores' => sprintf('Claves no configurables en «%s»: %s.', $grupo, implode(', ', $desconocidas))]);
        }

        $reglas = [];
        $nombres = [];

        foreach ($valores as $clave => $valor) {
            $campo = str_replace('.', '__', $clave);
            $reglas[$campo] = $catalogo[$clave]['reglas'] ?? ['nullable'];
            $nombres[$campo] = mb_strtolower((string) ($catalogo[$clave]['etiqueta'] ?? $clave));

            if (isset($catalogo[$clave]['opciones_enum']) && is_string($catalogo[$clave]['opciones_enum'])) {
                /** @var class-string<BackedEnum> $enum */
                $enum = $catalogo[$clave]['opciones_enum'];
                $reglas[$campo.'.*'] = ['string', 'in:'.implode(',', array_map(fn (BackedEnum $c) => (string) $c->value, $enum::cases()))];
            }
        }

        $datos = [];

        foreach ($valores as $clave => $valor) {
            $datos[str_replace('.', '__', $clave)] = $valor;
        }

        $validador = Validator::make($datos, $reglas, [], $nombres);

        if ($validador->fails()) {
            $errores = [];

            foreach ($validador->errors()->messages() as $campo => $mensajes) {
                $errores['valores.'.str_replace('__', '.', explode('.', $campo)[0])] = $mensajes;
            }

            throw ValidationException::withMessages($errores);
        }

        $cambios = [];

        DB::transaction(function () use ($valores, $catalogo, $grupo, $actor, &$cambios): void {
            foreach ($valores as $clave => $valor) {
                $p = $catalogo[$clave];
                $nuevo = $this->normalizar($p['tipo'], $valor);
                $antes = $this->valor($clave);

                if ($this->codificar($p['tipo'], $antes) === $this->codificar($p['tipo'], $nuevo)) {
                    continue;
                }

                $cambios[$clave] = ['antes' => $antes, 'despues' => $nuevo];

                if ($this->codificar($p['tipo'], $nuevo) === $this->codificar($p['tipo'], $p['defecto'])) {
                    ConfiguracionSistema::query()->where('clave', $clave)->delete();

                    continue;
                }

                ConfiguracionSistema::query()->updateOrCreate(['clave' => $clave], [
                    'valor' => $this->codificar($p['tipo'], $nuevo),
                    'tipo' => $p['tipo'],
                    'grupo' => $grupo,
                    'descripcion' => $p['etiqueta'] ?? null,
                    'actualizado_por' => $actor->id,
                ]);
            }
        });

        $this->refrescar();

        if ($cambios !== []) {
            $this->auditoria->registrar('configuracion_actualizada', null, $actor, ['grupo' => $grupo, 'cambios' => $cambios]);
        }
    }

    public function restaurar(string $clave, User $actor): void
    {
        $p = $this->catalogo()[$clave] ?? null;

        if ($p === null) {
            throw ValidationException::withMessages(['clave' => 'Ese parámetro no existe.']);
        }

        $this->guardar((string) $p['grupo'], [$clave => $p['defecto']], $actor);
    }

    /**
     * Duración del contrato de capacitación/inducción y grupo para
     * indicadores de un puesto (auditado).
     *
     * @param  array{meses_periodo_prueba: int|null, grupo_indicador: string|null, grupo_documental?: string|null}  $datos
     */
    public function actualizarPuesto(Puesto $puesto, array $datos, User $actor): void
    {
        $antes = ['meses_periodo_prueba' => $puesto->meses_periodo_prueba, 'grupo_indicador' => $puesto->grupo_indicador?->value, 'grupo_documental' => $puesto->grupo_documental];
        $grupo = array_key_exists('grupo_documental', $datos) ? $datos['grupo_documental'] : $puesto->grupo_documental;

        // Un puesto nunca queda ambiguo en silencio: quitarle el grupo
        // documental sin marcarlo como "no requiere documentos" se rechaza
        // (esa decisión, con motivo, vive en Cobertura documental).
        if ($grupo === null && $puesto->grupo_documental !== null && ! $puesto->no_requiere_documentos_laborales) {
            throw ValidationException::withMessages(['grupo_documental' => sprintf('«%s» quedaría sin grupo documental. Elige uno, o márcalo como "no requiere documentos laborales" (con motivo) en Documentos maestros → Cobertura por puesto.', $puesto->nombre)]);
        }

        $puesto->update([
            'meses_periodo_prueba' => $datos['meses_periodo_prueba'],
            'grupo_indicador' => $datos['grupo_indicador'] !== null ? GrupoPuestoIndicador::from($datos['grupo_indicador']) : null,
            'grupo_documental' => $grupo,
            // Con grupo documental, el puesto sí firma documentos laborales.
            ...($grupo !== null ? ['no_requiere_documentos_laborales' => false, 'motivo_sin_documentos' => null] : []),
        ]);

        $despues = ['meses_periodo_prueba' => $puesto->meses_periodo_prueba, 'grupo_indicador' => $puesto->grupo_indicador?->value, 'grupo_documental' => $puesto->grupo_documental];

        if ($antes !== $despues) {
            $this->auditoria->registrar('configuracion_puesto_actualizada', $puesto, $actor, ['antes' => $antes, 'despues' => $despues]);
        }
    }

    /**
     * Vigencia (meses) de un tipo documental: un documento vencido se pide
     * de nuevo (p. ej. en un reingreso). null = no vence.
     */
    public function actualizarVigenciaDocumento(DocumentType $tipo, ?int $meses, User $actor): void
    {
        $antes = $tipo->vigencia_meses;
        $tipo->update(['vigencia_meses' => $meses]);

        if ($antes !== $tipo->vigencia_meses) {
            $this->auditoria->registrar('configuracion_vigencia_documento', $tipo, $actor, ['antes' => $antes, 'despues' => $tipo->vigencia_meses]);
        }
    }

    /**
     * Reemplaza en config() los valores personalizados. Se llama al arrancar
     * la aplicación y después de cada cambio.
     */
    public function aplicarAConfig(): void
    {
        foreach ($this->guardados() as $clave => $valor) {
            $destino = $this->catalogo()[$clave]['config'] ?? null;

            if (is_string($destino)) {
                config([$destino => $valor]);
            }
        }
    }

    /**
     * Colores con su nombre semántico para la app móvil (y cualquier
     * cliente): primary, accent, danger, background...
     *
     * @return array<string, string>
     */
    public function tema(): array
    {
        $tema = [];

        foreach ($this->catalogo('apariencia') as $clave => $p) {
            if (isset($p['tema'])) {
                $tema[(string) $p['tema']] = (string) $this->valor($clave);
            }
        }

        return $tema;
    }

    /**
     * Bloque CSS con las variables institucionales personalizadas más los
     * colores de cada gráfica (el sistema es solo claro).
     */
    public function cssVariables(): string
    {
        $declaraciones = [];
        $guardados = $this->guardados();

        $graficas = [];

        foreach ($this->catalogo('apariencia') as $clave => $p) {
            // Colores de gráficas: siempre se declaran (con su valor de
            // fábrica si no se cambió).
            if (($p['seccion'] ?? null) === 'graficas' && isset($p['css'])) {
                $valor = (string) $this->valor($clave);

                if (preg_match('/^#[0-9A-Fa-f]{6}$/', $valor) === 1) {
                    $graficas[] = sprintf('%s:%s', $p['css'], $valor);
                }

                continue;
            }

            if (isset($p['css'], $guardados[$clave]) && is_string($guardados[$clave]) && preg_match('/^#[0-9A-Fa-f]{6}$/', $guardados[$clave]) === 1) {
                $declaraciones[] = sprintf('%s:%s', $p['css'], $guardados[$clave]);
            }
        }

        $css = $graficas === [] ? '' : sprintf(':root{%s}', implode(';', $graficas));

        return $declaraciones === [] ? $css : $css.sprintf(':root{%s}', implode(';', $declaraciones));
    }

    /**
     * Valores personalizados (decodificados por tipo). Si la tabla aún no
     * existe (instalación/migraciones) regresa vacío sin cachearlo.
     *
     * @return array<string, mixed>
     */
    private function guardados(): array
    {
        try {
            /** @var array<string, mixed> $valores */
            $valores = Cache::rememberForever(self::CACHE, function (): array {
                $resultado = [];

                foreach (ConfiguracionSistema::query()->get(['clave', 'valor', 'tipo']) as $fila) {
                    $resultado[$fila->clave] = $this->decodificar($fila->tipo, $fila->valor);
                }

                return $resultado;
            });

            return $valores;
        } catch (Throwable $e) {
            Log::debug('ConfiguracionSistemaService: configuración no disponible todavía.', ['error' => $e->getMessage()]);

            return [];
        }
    }

    private function refrescar(): void
    {
        Cache::forget(self::CACHE);
        $this->aplicarAConfig();
    }

    private function normalizar(string $tipo, mixed $valor): mixed
    {
        return match ($tipo) {
            'color' => mb_strtoupper((string) $valor),
            'entero' => (int) $valor,
            'decimal' => round((float) $valor, 2),
            'lista' => $this->ordenada(array_values(array_unique(array_map('strval', (array) $valor)))),
            'booleano' => (bool) $valor,
            default => (string) $valor,
        };
    }

    /**
     * @param  list<string>  $valores
     * @return list<string>
     */
    private function ordenada(array $valores): array
    {
        sort($valores);

        return $valores;
    }

    private function codificar(string $tipo, mixed $valor): string
    {
        $valor = $this->normalizar($tipo, $valor);

        return match ($tipo) {
            'lista' => (string) json_encode($valor),
            'booleano' => $valor ? '1' : '0',
            default => is_scalar($valor) ? (string) $valor : '',
        };
    }

    private function decodificar(string $tipo, ?string $valor): mixed
    {
        return match ($tipo) {
            'entero' => (int) $valor,
            'decimal' => (float) $valor,
            'lista' => (array) json_decode((string) $valor, true),
            'booleano' => $valor === '1',
            default => $valor,
        };
    }
}
