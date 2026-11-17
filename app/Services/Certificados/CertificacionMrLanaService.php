<?php

namespace App\Services\Certificados;

use App\Enums\NivelCertificacion;
use App\Models\Certificado;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;

/**
 * «Certificación Mr. Lana» — BASE MÍNIMA (Fase 3, ver docs/CAPACITACION.md).
 *
 * El nivel de un colaborador sale SOLO de las constancias que ya emitió
 * CertificadoService al completar cursos con genera_constancia = true: no
 * hay tabla nueva ni regla oculta. Los umbrales por nivel viven en
 * config/capacitacion.php. Pendiente de negocio: rutas por puesto (qué
 * cursos exactos forman cada nivel) y vigencia/renovación.
 */
class CertificacionMrLanaService
{
    public function __construct(private readonly AlcanceOrganizacionalService $alcance) {}

    public function nivelPara(int $constancias): ?NivelCertificacion
    {
        $alcanzado = null;

        foreach (NivelCertificacion::cases() as $nivel) {
            if ($constancias >= $nivel->umbral()) {
                $alcanzado = $nivel;
            }
        }

        return $alcanzado;
    }

    public function siguienteNivel(int $constancias): ?NivelCertificacion
    {
        foreach (NivelCertificacion::cases() as $nivel) {
            if ($constancias < $nivel->umbral()) {
                return $nivel;
            }
        }

        return null;
    }

    /**
     * Catálogo de niveles para pintar las insignias.
     *
     * @return list<array{clave: string, etiqueta: string, descripcion: string, umbral: int}>
     */
    public function catalogo(): array
    {
        return array_map(fn (NivelCertificacion $nivel): array => [
            'clave' => $nivel->value,
            'etiqueta' => $nivel->etiqueta(),
            'descripcion' => $nivel->descripcion(),
            'umbral' => $nivel->umbral(),
        ], NivelCertificacion::cases());
    }

    /**
     * Resumen de la certificación de un colaborador (su propio Mi espacio).
     *
     * @return array{constancias: int, nivel: array{clave: string, etiqueta: string}|null, siguiente: array{clave: string, etiqueta: string, umbral: int, faltan: int}|null, progreso: int, niveles: list<array{clave: string, etiqueta: string, descripcion: string, umbral: int}>}
     */
    public function resumenUsuario(User $usuario): array
    {
        $constancias = Certificado::query()->where('user_id', $usuario->id)->count();

        return $this->resumenPorConteo($constancias);
    }

    /**
     * @return array{constancias: int, nivel: array{clave: string, etiqueta: string}|null, siguiente: array{clave: string, etiqueta: string, umbral: int, faltan: int}|null, progreso: int, niveles: list<array{clave: string, etiqueta: string, descripcion: string, umbral: int}>}
     */
    public function resumenPorConteo(int $constancias): array
    {
        $nivel = $this->nivelPara($constancias);
        $siguiente = $this->siguienteNivel($constancias);
        $base = $nivel?->umbral() ?? 0;

        $progreso = $siguiente === null
            ? 100
            : (int) round(100 * ($constancias - $base) / max(1, $siguiente->umbral() - $base));

        return [
            'constancias' => $constancias,
            'nivel' => $nivel === null ? null : ['clave' => $nivel->value, 'etiqueta' => $nivel->etiqueta()],
            'siguiente' => $siguiente === null ? null : [
                'clave' => $siguiente->value,
                'etiqueta' => $siguiente->etiqueta(),
                'umbral' => $siguiente->umbral(),
                'faltan' => $siguiente->umbral() - $constancias,
            ],
            'progreso' => max(0, min(100, $progreso)),
            'niveles' => $this->catalogo(),
        ];
    }

    /**
     * Tablero operativo: cuántas personas hay en cada nivel y quiénes
     * encabezan, acotado al alcance organizacional del usuario que consulta
     * (AlcanceOrganizacionalService), nunca toda la plantilla por defecto.
     *
     * @return array{distribucion: list<array{clave: string, etiqueta: string, umbral: int, personas: int}>, personas: list<array{id: int, nombre: string, constancias: int, nivel: array{clave: string, etiqueta: string}|null, ultima: string|null}>, total_constancias: int, total_certificados: int}
     */
    public function tablero(User $actor, int $limite = 50): array
    {
        // Misma regla de alcance que la calificación de capacitación: con
        // alcance de sucursal, solo su gente; instructor/administración de
        // capacitación/RH (null) ven a todos los alumnos.
        $idsAlcance = $this->alcance->idsColaboradoresParaRevision($actor);

        $conteos = Certificado::query()
            ->when($idsAlcance !== null, fn ($q) => $q->whereIn('user_id', $idsAlcance ?? []))
            ->selectRaw('user_id, COUNT(*) as total, MAX(emitido_en) as ultima')
            ->groupBy('user_id')
            ->orderByDesc('total')
            ->get();

        $porNivel = [];
        foreach (NivelCertificacion::cases() as $nivel) {
            $porNivel[$nivel->value] = 0;
        }

        $totalConstancias = 0;
        $certificados = 0;

        foreach ($conteos as $fila) {
            $total = (int) $fila->getAttribute('total');
            $totalConstancias += $total;
            $nivel = $this->nivelPara($total);

            if ($nivel !== null) {
                $porNivel[$nivel->value]++;
                $certificados++;
            }
        }

        $top = $conteos->take($limite);
        $nombres = User::query()
            ->whereIn('id', $top->pluck('user_id'))
            ->get(['id', 'name', 'apellidos'])
            ->keyBy('id');

        $personas = [];
        foreach ($top as $fila) {
            $usuario = $nombres->get((int) $fila->getAttribute('user_id'));

            if ($usuario === null) {
                continue;
            }

            $total = (int) $fila->getAttribute('total');
            $nivel = $this->nivelPara($total);
            $ultima = $fila->getAttribute('ultima');

            $personas[] = [
                'id' => $usuario->id,
                'nombre' => trim(sprintf('%s %s', $usuario->name, (string) $usuario->apellidos)),
                'constancias' => $total,
                'nivel' => $nivel === null ? null : ['clave' => $nivel->value, 'etiqueta' => $nivel->etiqueta()],
                'ultima' => $ultima === null ? null : (string) $ultima,
            ];
        }

        return [
            'distribucion' => array_map(fn (NivelCertificacion $nivel): array => [
                'clave' => $nivel->value,
                'etiqueta' => $nivel->etiqueta(),
                'umbral' => $nivel->umbral(),
                'personas' => $porNivel[$nivel->value],
            ], NivelCertificacion::cases()),
            'personas' => $personas,
            'total_constancias' => $totalConstancias,
            'total_certificados' => $certificados,
        ];
    }
}
