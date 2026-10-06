<?php

namespace App\Services\DocumentosAdministrativos;

use App\Enums\FamiliaAdministrativa;
use App\Enums\MotorPdf;
use App\Models\GeneratedDocument;
use App\Models\PlantillaAdministrativa;
use App\Models\User;
use App\Services\Auditoria\AuditoriaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Versiones del diseño de los documentos administrativos (Documentos
 * maestros → Documentos administrativos):
 *
 *   borrador  se edita libremente (diseño, motor, notas);
 *   activa    la que usa el sistema al generar; nunca se edita;
 *   archivada versión anterior, consultable y reactivable.
 *
 * Una familia sin versiones usa el diseño por defecto (equivalente al
 * formato que tenía el sistema); la primera vez que RH edita se crea la
 * versión 1 en borrador. Cada cambio queda en la auditoría.
 */
class PlantillasAdministrativasService
{
    public function __construct(
        private readonly DisenoAdministrativoService $disenos,
        private readonly AuditoriaService $auditoria,
    ) {}

    public function activa(FamiliaAdministrativa $familia): ?PlantillaAdministrativa
    {
        return PlantillaAdministrativa::query()->where('familia', $familia->value)->where('estado', PlantillaAdministrativa::ACTIVA)->latest('version')->first();
    }

    /**
     * Diseño y motor con los que se genera HOY un documento de la familia.
     *
     * @return array{plantilla: PlantillaAdministrativa|null, version: int, diseno: array<string, mixed>, motor: MotorPdf}
     */
    public function vigente(FamiliaAdministrativa $familia): array
    {
        $activa = $this->activa($familia);

        return [
            'plantilla' => $activa,
            'version' => $activa->version ?? 0,
            'diseno' => $activa !== null ? $this->disenos->normalizar($familia, $activa->diseno) : $this->disenos->porDefecto($familia),
            'motor' => $activa?->motorEfectivo() ?? MotorPdf::porDefecto(),
        ];
    }

    /**
     * Resumen por familia para el listado.
     *
     * @return list<array<string, mixed>>
     */
    public function resumen(): array
    {
        return array_map(function (FamiliaAdministrativa $familia): array {
            $activa = $this->activa($familia);
            $borrador = PlantillaAdministrativa::query()->where('familia', $familia->value)->where('estado', PlantillaAdministrativa::BORRADOR)->latest('version')->first();
            $generados = GeneratedDocument::query()->where('master_familia', $this->claveSnapshot($familia))->count();

            return [
                'familia' => $familia->value,
                'nombre' => $familia->etiqueta(),
                'descripcion' => $familia->descripcion(),
                'estado' => match (true) {
                    $activa !== null && $borrador !== null => 'activa_con_borrador',
                    $activa !== null => 'activa',
                    $borrador !== null => 'borrador',
                    default => 'por_defecto',
                },
                'version_activa' => $activa?->version,
                'borrador_version' => $borrador?->version,
                'motor' => ($activa?->motorEfectivo() ?? MotorPdf::porDefecto())->value,
                'motor_etiqueta' => ($activa?->motorEfectivo() ?? MotorPdf::porDefecto())->etiqueta(),
                'activada_en' => $activa?->activado_en?->toIso8601String(),
                'documentos_generados' => $generados,
            ];
        }, FamiliaAdministrativa::cases());
    }

    /**
     * Borrador editable de la familia: el existente, o uno nuevo copiado de
     * la versión activa (o del diseño por defecto).
     */
    public function borrador(FamiliaAdministrativa $familia, User $actor): PlantillaAdministrativa
    {
        return DB::transaction(function () use ($familia, $actor): PlantillaAdministrativa {
            $existente = PlantillaAdministrativa::query()->where('familia', $familia->value)->where('estado', PlantillaAdministrativa::BORRADOR)->lockForUpdate()->latest('version')->first();

            if ($existente !== null) {
                return $existente;
            }

            $vigente = $this->vigente($familia);
            $siguiente = (int) PlantillaAdministrativa::query()->where('familia', $familia->value)->lockForUpdate()->max('version') + 1;

            $borrador = PlantillaAdministrativa::query()->create([
                'familia' => $familia->value,
                'version' => $siguiente,
                'estado' => PlantillaAdministrativa::BORRADOR,
                'motor' => $vigente['plantilla']?->motor?->value,
                'diseno' => $vigente['diseno'],
                'hash' => $this->disenos->hash($vigente['diseno']),
                'creado_por' => $actor->id,
            ]);

            $this->auditoria->registrar('plantilla_administrativa_borrador', $borrador, $actor, ['familia' => $familia->value, 'version' => $siguiente, 'basada_en' => $vigente['version']]);

            return $borrador;
        });
    }

    /**
     * Guarda diseño/motor/notas de un BORRADOR (las versiones activas o
     * archivadas no se editan).
     *
     * @param  array<string, mixed>  $diseno
     */
    public function guardar(PlantillaAdministrativa $plantilla, array $diseno, ?string $motor, ?string $notas, User $actor): PlantillaAdministrativa
    {
        if ($plantilla->estado !== PlantillaAdministrativa::BORRADOR) {
            throw ValidationException::withMessages(['plantilla' => 'Solo se edita un borrador. Crea un borrador nuevo a partir de esta versión.']);
        }

        $normalizado = $this->disenos->normalizar($plantilla->familia, $diseno);
        $antes = ['diseno' => $plantilla->diseno, 'motor' => $plantilla->motor?->value, 'hash' => $plantilla->hash];
        $motorEnum = $motor !== null && $motor !== '' ? MotorPdf::tryFrom($motor) : null;

        if ($motor !== null && $motor !== '' && $motorEnum === null) {
            throw ValidationException::withMessages(['motor' => 'Motor de PDF no válido.']);
        }

        $plantilla->update([
            'diseno' => $normalizado,
            'motor' => $motorEnum?->value,
            'notas' => $notas !== null ? mb_substr(trim($notas), 0, 500) : $plantilla->notas,
            'hash' => $this->disenos->hash($normalizado),
        ]);

        $this->auditoria->registrar('plantilla_administrativa_diseno', $plantilla, $actor, [
            'familia' => $plantilla->familia->value,
            'version' => $plantilla->version,
            'antes' => $antes,
            'despues' => ['diseno' => $normalizado, 'motor' => $motorEnum?->value, 'hash' => $plantilla->hash],
            'cambio_fondo' => data_get($antes, 'diseno.background.asset_id') !== data_get($normalizado, 'background.asset_id'),
        ]);

        return $plantilla->refresh();
    }

    /**
     * Activa una versión (borrador o archivada); la activa anterior pasa a
     * archivada. Los documentos ya generados no cambian.
     */
    public function activar(PlantillaAdministrativa $plantilla, User $actor): PlantillaAdministrativa
    {
        $anterior = DB::transaction(function () use ($plantilla, $actor): ?int {
            $activa = PlantillaAdministrativa::query()->where('familia', $plantilla->familia->value)->where('estado', PlantillaAdministrativa::ACTIVA)->lockForUpdate()->first();

            if ($activa?->id === $plantilla->id) {
                return $activa->version;
            }

            $activa?->update(['estado' => PlantillaAdministrativa::ARCHIVADA]);
            $plantilla->update(['estado' => PlantillaAdministrativa::ACTIVA, 'activado_por' => $actor->id, 'activado_en' => now()]);

            return $activa?->version;
        });

        $this->auditoria->registrar('plantilla_administrativa_activada', $plantilla, $actor, [
            'familia' => $plantilla->familia->value,
            'version' => $plantilla->version,
            'version_anterior' => $anterior,
            'hash' => $plantilla->hash,
        ]);

        return $plantilla->refresh();
    }

    /**
     * Descarta un borrador que nunca se activó.
     */
    public function descartar(PlantillaAdministrativa $plantilla, User $actor): void
    {
        if ($plantilla->estado !== PlantillaAdministrativa::BORRADOR) {
            throw ValidationException::withMessages(['plantilla' => 'Solo se descarta un borrador.']);
        }

        $this->auditoria->registrar('plantilla_administrativa_descartada', $plantilla, $actor, ['familia' => $plantilla->familia->value, 'version' => $plantilla->version]);
        $plantilla->delete();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function historial(FamiliaAdministrativa $familia): array
    {
        return array_values(PlantillaAdministrativa::query()->with(['creadoPor:id,name,apellidos', 'activadoPor:id,name,apellidos'])
            ->where('familia', $familia->value)->orderByDesc('version')->get()
            ->map(fn (PlantillaAdministrativa $p) => [
                'id' => $p->id,
                'version' => $p->version,
                'estado' => $p->estado,
                'motor' => $p->motorEfectivo()->value,
                'motor_etiqueta' => $p->motorEfectivo()->etiqueta(),
                'notas' => $p->notas,
                'hash' => substr($p->hash, 0, 12),
                'creado_por' => $p->creadoPor !== null ? trim($p->creadoPor->name.' '.$p->creadoPor->apellidos) : null,
                'creado_en' => $p->created_at->toIso8601String(),
                'activado_por' => $p->activadoPor !== null ? trim($p->activadoPor->name.' '.$p->activadoPor->apellidos) : null,
                'activado_en' => $p->activado_en?->toIso8601String(),
                'documentos_generados' => GeneratedDocument::query()->where('master_familia', $this->claveSnapshot($familia))->where('master_version', $p->version)->count(),
            ])->all());
    }

    /** Valor de generated_documents.master_familia para esta familia. */
    public function claveSnapshot(FamiliaAdministrativa $familia): string
    {
        return 'administrativo.'.$familia->value;
    }
}
