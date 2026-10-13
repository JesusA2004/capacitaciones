<script setup lang="ts">
import { computed } from 'vue';
import OrganigramaGeneralNodo from '@/components/Administracion/OrganigramaGeneralNodo.vue';
import type { CajaLamina, LaminasOrganigrama, NodoGeneral } from '@/types';

/**
 * Organigrama GENERAL = Corporativo + Dirección Comercial + Sucursal, armado
 * con los MISMOS datos de las láminas (una sola verdad: ninguna relación
 * puede contradecir a las láminas):
 *
 * Dirección General
 * ├── Dirección Comercial
 * │   ├── Asistente · Monitorista (apoyo)
 * │   ├── Regional Comercial → Sucursales → Gerente → Subgerente → Gestores
 * │   │                                          └── Coordinación (apoyo)
 * │   ├── Regional Administrativo → Coordinadoras de sucursal
 * │   ├── Gerencia Mesa de Control → Analistas · Auditoría
 * │   └── Gerencia de Auditoría → Auditores
 * └── RH · Contraloría · Jurídico · Sistemas (directos a DG)
 */
const props = defineProps<{ laminas: LaminasOrganigrama }>();

function hoja(
    clave: string,
    caja: CajaLamina,
    extra: Partial<NodoGeneral> = {},
): NodoGeneral {
    return {
        clave,
        titulo: caja.titulo,
        personas: caja.personas,
        hijos: [],
        ...extra,
    };
}

const raiz = computed<NodoGeneral>(() => {
    const { corporativo, comercial, sucursal } = props.laminas;
    const puestosSuc = sucursal.puestos;

    const sucursales: NodoGeneral[] = sucursal.sucursales.map((s) => ({
        clave: `suc-${s.id}`,
        titulo: s.nombre,
        personas: [],
        grupo: true,
        hijos: [
            {
                clave: `suc-${s.id}-gerente`,
                titulo: puestosSuc.gerente.titulo,
                personas: s.gerente,
                hijos: [
                    {
                        clave: `suc-${s.id}-subgerente`,
                        titulo: puestosSuc.subgerente.titulo,
                        personas: s.subgerente,
                        hijos: [
                            {
                                clave: `suc-${s.id}-gestores`,
                                titulo: `${puestosSuc.gestores.titulo} (${s.gestores.length})`,
                                personas: s.gestores,
                                hijos: [],
                            },
                        ],
                    },
                    {
                        clave: `suc-${s.id}-coordinacion`,
                        titulo: puestosSuc.coordinacion.titulo,
                        personas: s.coordinacion,
                        apoyo: true,
                        hijos: [],
                    },
                ],
            },
        ],
    }));

    const columnas: NodoGeneral[] = comercial.columnas.map((col, i) => {
        const clave = `col-${i}`;
        let hijos: NodoGeneral[];

        if (i === 0) {
            // Regional Comercial: su cadena real es cada sucursal.
            hijos = [
                {
                    clave: `${clave}-sucursales`,
                    titulo: `Sucursales (${sucursales.length})`,
                    personas: [],
                    grupo: true,
                    hijos: sucursales,
                },
            ];
        } else {
            hijos = [
                ...col.cadena.map((c, k) => hoja(`${clave}-cad-${k}`, c)),
                ...col.hijos.map((c, k) => hoja(`${clave}-hijo-${k}`, c)),
            ];
        }

        return { clave, titulo: col.titulo, personas: col.personas, hijos };
    });

    const dc = corporativo.areas.find((a) => a.sigla === 'DC');
    const otrasAreas = corporativo.areas.filter((a) => a.sigla !== 'DC');

    return {
        clave: 'dg',
        titulo: corporativo.direccion_general.titulo,
        personas: corporativo.direccion_general.personas,
        hijos: [
            {
                clave: 'dc',
                titulo: dc?.titulo ?? comercial.director.titulo,
                personas: dc?.personas ?? comercial.director.personas,
                hijos: [
                    ...comercial.apoyo.map((a, k) =>
                        hoja(`apoyo-${k}`, a, { apoyo: true }),
                    ),
                    ...columnas,
                ],
            },
            ...otrasAreas.map((a) => ({
                clave: `area-${a.sigla}`,
                titulo: a.titulo,
                personas: a.personas,
                hijos: [],
            })),
        ],
    };
});
</script>

<template>
    <div
        class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-crema)] p-4 sm:p-6"
    >
        <header class="mb-4">
            <h2 class="font-serif text-xl text-[var(--mrl-petroleo)]">
                Organigrama general
            </h2>
            <p class="text-xs tracking-widest text-muted-foreground uppercase">
                Corporativo · Dirección Comercial · Sucursales
            </p>
        </header>
        <ul class="flex flex-col gap-2">
            <OrganigramaGeneralNodo :nodo="raiz" :nivel="0" />
        </ul>
    </div>
</template>
