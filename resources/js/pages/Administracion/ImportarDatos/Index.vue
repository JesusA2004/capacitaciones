<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    DatabaseZap,
    FileSpreadsheet,
    ClipboardList,
    LayoutGrid,
    ReceiptText,
    Siren,
    TrendingDown,
    Upload,
    Terminal,
} from '@lucide/vue';
import { computed } from 'vue';
import type { Component } from 'vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { index as cargaInicialIndex } from '@/routes/administracion/importar-datos/carga-inicial';
import { index as rotacionIndex } from '@/routes/reportes/rotacion';
import { index as contactosEmergenciaIndex } from '@/routes/rh/contactos-emergencia';
import { index as migracionIndex } from '@/routes/rh/expedientes/migracion';
import { index as importacionesIndex } from '@/routes/rh/importaciones';
import { index as lotesNominaIndex } from '@/routes/rh/nomina/lotes';
import { importacion as perfilesImportacion } from '@/routes/rh/perfiles-puesto';

/**
 * HUB «Importar datos» (Administración → Configuración): índice único de las
 * importaciones administrativas que ya existen dispersas por el sistema. No
 * reimplementa ningún lector ni flujo: solo enlaza a cada pantalla real y
 * muestra su flujo de preview. Cada tarjeta se gatea por su permiso desde el
 * backend (prop `puede`); el acceso al hub ya lo valida el controlador.
 * Todas las importaciones son solo de super_admin (2026-10-20).
 */
const props = defineProps<{
    puede: {
        carga_inicial: boolean;
        migracion: boolean;
        headcount: boolean;
        salarios_vacaciones: boolean;
        rotacion: boolean;
        perfiles_puesto: boolean;
        contactos_emergencia: boolean;
        recibos_nomina: boolean;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Importar datos', href: '' },
        ],
    },
});

type Tarjeta = {
    clave: string;
    titulo: string;
    descripcion: string;
    flujo: string[];
    icono: Component;
    /** Pantalla de destino; `null` cuando el flujo solo corre por consola. */
    href: string | null;
    /** Nota para flujos sin pantalla (p. ej. headcount por comando). */
    nota?: string;
};

/**
 * Las importaciones, agrupadas: (1) base/reconciliación de colaboradores +
 * expedientes históricos, (2) headcount autorizado, (3) salarios y
 * vacaciones, (4) rotación, (5) perfiles de puesto. Cada tarjeta se muestra
 * solo si el usuario tiene el permiso correspondiente.
 */
const tarjetas = computed<Tarjeta[]>(() => {
    const items: (Tarjeta | null)[] = [
        props.puede.migracion
            ? {
                  clave: 'migracion',
                  titulo: 'Base de colaboradores y expedientes',
                  descripcion:
                      'Migración inicial: reconcilia la base general de colaboradores (formato SEMANA 41) y vincula los expedientes históricos del NAS.',
                  flujo: [
                      'Subir Excel',
                      'Analizar',
                      'Revisar y resolver conflictos',
                      'Confirmar',
                      'Aplicar',
                  ],
                  icono: DatabaseZap,
                  href: migracionIndex().url,
              }
            : null,
        props.puede.headcount
            ? {
                  clave: 'headcount',
                  titulo: 'Headcount autorizado',
                  descripcion:
                      'Plantilla autorizada por sucursal y puesto desde el Excel oficial de headcount. Sincroniza las vacantes automáticas al aplicarse.',
                  flujo: [
                      'Colocar el Excel oficial',
                      'Ejecutar el comando',
                      'Revisar los cambios reportados',
                  ],
                  icono: LayoutGrid,
                  href: null,
                  nota: 'Disponible por consola: php artisan headcount:importar',
              }
            : null,
        props.puede.salarios_vacaciones
            ? {
                  clave: 'salarios_vacaciones',
                  titulo: 'Salarios y vacaciones',
                  descripcion:
                      'Importa salarios y días de vacaciones históricos desde el reporte oficial de trabajadores (REPORTE_TRABAJADORES_VAC.xlsx).',
                  flujo: [
                      'Subir Excel',
                      'Analizar',
                      'Revisar advertencias',
                      'Confirmar',
                      'Aplicar',
                  ],
                  icono: FileSpreadsheet,
                  href: importacionesIndex().url,
              }
            : null,
        props.puede.rotacion
            ? {
                  clave: 'rotacion',
                  titulo: 'Rotación de personal',
                  descripcion:
                      'Carga el Excel de rotación de personal de RH (altas y bajas por mes), no el KPI en vivo del dashboard.',
                  flujo: [
                      'Subir Excel',
                      'Analizar',
                      'Revisar hallazgos',
                      'Confirmar',
                      'Aplicar',
                  ],
                  icono: TrendingDown,
                  href: rotacionIndex().url,
              }
            : null,
        props.puede.perfiles_puesto
            ? {
                  clave: 'perfiles_puesto',
                  titulo: 'Perfiles de puesto',
                  descripcion:
                      'Importa los descriptivos oficiales de puesto desde los PDF: se leen y RH confirma a qué puesto corresponde cada uno.',
                  flujo: [
                      'Elegir PDF',
                      'Leer descriptivo',
                      'Confirmar puesto',
                      'Importar',
                  ],
                  icono: ClipboardList,
                  href: perfilesImportacion().url,
              }
            : null,
        props.puede.contactos_emergencia
            ? {
                  clave: 'contactos_emergencia',
                  titulo: 'Contactos de emergencia',
                  descripcion:
                      'Sube el Excel de contactos de emergencia, teléfono personal, condición médica y alergias de los colaboradores (formato de Google Forms).',
                  flujo: [
                      'Subir Excel',
                      'Vista previa',
                      'Elegir colaborador si no cuadra',
                      'Confirmar',
                  ],
                  icono: Siren,
                  href: contactosEmergenciaIndex().url,
              }
            : null,
        props.puede.recibos_nomina
            ? {
                  clave: 'recibos_nomina',
                  titulo: 'Recibos de nómina por archivo',
                  descripcion:
                      'Crea un lote de recibos desde el archivo CSV o Excel de nómina. Generar recibos desde el sueldo de cada persona sigue siendo de RH.',
                  flujo: [
                      'Nuevo lote',
                      'Importar archivo',
                      'Revisar borradores',
                      'Emitir',
                  ],
                  icono: ReceiptText,
                  href: lotesNominaIndex().url,
              }
            : null,
    ];

    return items.filter((t): t is Tarjeta => t !== null);
});
</script>

<template>
    <Head title="Importar datos" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            titulo="Importar datos"
            descripcion="Punto único para las importaciones administrativas. Cada archivo se analiza primero (preview): nada se aplica hasta que lo confirmes."
            :icono="Upload"
            detalle
        />

        <!-- Carga inicial PEOPLE: la importación principal -->
        <Link
            v-if="puede.carga_inicial"
            :href="cargaInicialIndex().url"
            class="flex tarjeta-interactiva flex-col gap-4 rounded-2xl border border-primary/40 bg-[var(--mrl-superficie)] p-5 sm:flex-row sm:items-center sm:p-6"
        >
            <span
                class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary"
            >
                <DatabaseZap class="size-6" />
            </span>
            <div class="min-w-0 flex-1">
                <h2 class="text-lg font-semibold">Carga inicial PEOPLE</h2>
                <p class="mt-0.5 text-sm text-muted-foreground">
                    Importa en una sola operación colaboradores, estructura,
                    salarios, vacaciones, headcount, rotación y expedientes
                    históricos.
                </p>
            </div>
            <span
                class="inline-flex items-center gap-1.5 self-start rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-primary-foreground sm:self-auto"
            >
                Abrir
                <ArrowRight class="size-4" />
            </span>
        </Link>

        <h2
            v-if="tarjetas.length"
            class="text-sm font-semibold tracking-wide text-muted-foreground uppercase"
        >
            Actualizaciones posteriores
        </h2>

        <div class="rejilla-auto" style="--rejilla-min: 20rem">
            <article
                v-for="tarjeta in tarjetas"
                :key="tarjeta.clave"
                class="flex flex-col gap-4 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-5"
                :class="tarjeta.href ? 'tarjeta-interactiva' : ''"
            >
                <div class="flex items-start gap-3">
                    <span
                        class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary"
                    >
                        <component :is="tarjeta.icono" class="size-5" />
                    </span>
                    <div class="min-w-0">
                        <h2 class="font-semibold">{{ tarjeta.titulo }}</h2>
                        <p class="mt-0.5 text-sm text-muted-foreground">
                            {{ tarjeta.descripcion }}
                        </p>
                    </div>
                </div>

                <ol
                    class="flex flex-wrap items-center gap-x-1.5 gap-y-1 text-xs text-muted-foreground"
                    aria-label="Flujo de importación"
                >
                    <template
                        v-for="(paso, indice) in tarjeta.flujo"
                        :key="paso"
                    >
                        <li
                            class="rounded-full bg-[var(--mrl-fondo)] px-2 py-0.5 font-medium text-[var(--mrl-texto-suave)]"
                        >
                            {{ paso }}
                        </li>
                        <ArrowRight
                            v-if="indice < tarjeta.flujo.length - 1"
                            class="size-3 shrink-0 opacity-60"
                            aria-hidden="true"
                        />
                    </template>
                </ol>

                <div class="mt-auto flex items-center justify-between gap-3">
                    <p
                        v-if="tarjeta.nota"
                        class="flex items-center gap-1.5 text-xs text-muted-foreground"
                    >
                        <Terminal
                            class="size-3.5 shrink-0"
                            aria-hidden="true"
                        />
                        <span>{{ tarjeta.nota }}</span>
                    </p>
                    <Button
                        v-if="tarjeta.href"
                        as-child
                        size="sm"
                        class="ml-auto"
                    >
                        <Link :href="tarjeta.href">
                            Abrir
                            <ArrowRight class="size-4" />
                        </Link>
                    </Button>
                </div>
            </article>
        </div>
    </div>
</template>
