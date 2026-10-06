<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Eye, FileText, Pencil, Receipt } from '@lucide/vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import SeccionesDocumentosMaestros from '@/components/documentos/maestros/SeccionesDocumentosMaestros.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatearFecha } from '@/lib/fechas';
import { dashboard } from '@/routes';
import { index as indexMaestros } from '@/routes/rh/documentos-maestros';
import {
    editar,
    index,
    vistaPrevia,
} from '@/routes/rh/documentos-maestros/administrativos';
import type { FamiliaAdministrativaResumen } from '@/types';

/**
 * Documentos administrativos (HTML → PDF): recibo de nómina, finiquito,
 * comprobante de solicitud y constancia. Aquí solo se administra el
 * DISEÑO versionado; los datos los calcula cada proceso y nunca cambian
 * por un cambio de diseño (PlantillasAdministrativasService).
 */
defineProps<{ familias: FamiliaAdministrativaResumen[] }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Documentos maestros', href: indexMaestros.url() },
            { title: 'Documentos administrativos', href: index.url() },
        ],
    },
});

const ESTADOS: Record<
    FamiliaAdministrativaResumen['estado'],
    { texto: string; clase: string }
> = {
    por_defecto: {
        texto: 'Diseño de fábrica',
        clase: 'bg-muted text-muted-foreground',
    },
    borrador: {
        texto: 'Borrador sin activar',
        clase: 'bg-amber-500/10 text-amber-700 dark:text-amber-400',
    },
    activa: {
        texto: 'Activa',
        clase: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
    },
    activa_con_borrador: {
        texto: 'Activa · borrador en edición',
        clase: 'bg-sky-500/10 text-sky-700 dark:text-sky-400',
    },
};
</script>

<template>
    <Head title="Documentos administrativos" />

    <div class="pagina-ancha flex flex-col gap-6">
        <CrudPageHeader
            titulo="Documentos maestros"
            descripcion="Diseño de los PDF administrativos que genera PEOPLE. Cambiar el diseño nunca altera documentos ya generados."
            :icono="Receipt"
        />

        <SeccionesDocumentosMaestros actual="administrativos" />

        <ul class="grid gap-4 md:grid-cols-2">
            <li
                v-for="familia in familias"
                :key="familia.familia"
                class="group flex flex-col gap-4 rounded-2xl border bg-card p-5 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:border-primary/30 hover:shadow-md"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="flex items-start gap-3">
                        <span
                            class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary transition-colors group-hover:bg-primary group-hover:text-primary-foreground"
                        >
                            <FileText class="size-5" />
                        </span>
                        <div>
                            <p class="font-semibold">{{ familia.nombre }}</p>
                            <p class="text-sm text-muted-foreground">
                                {{ familia.descripcion }}
                            </p>
                        </div>
                    </div>
                    <span
                        class="shrink-0 rounded-full px-2.5 py-1 text-xs font-medium"
                        :class="ESTADOS[familia.estado].clase"
                        >{{ ESTADOS[familia.estado].texto }}</span
                    >
                </div>

                <dl class="grid grid-cols-3 gap-3 text-sm">
                    <div>
                        <dt class="text-xs text-muted-foreground">
                            Versión activa
                        </dt>
                        <dd class="font-medium">
                            {{
                                familia.version_activa
                                    ? `v${familia.version_activa}`
                                    : 'De fábrica'
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">Motor</dt>
                        <dd>
                            <Badge variant="outline">{{
                                familia.motor_etiqueta
                            }}</Badge>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">Generados</dt>
                        <dd class="font-medium tabular-nums">
                            {{ familia.documentos_generados }}
                        </dd>
                    </div>
                </dl>
                <p
                    v-if="familia.activada_en"
                    class="text-xs text-muted-foreground"
                >
                    Activada el {{ formatearFecha(familia.activada_en) }}
                </p>

                <div class="mt-auto flex flex-wrap gap-2">
                    <Button as-child size="sm">
                        <Link :href="editar.url(familia.familia)">
                            <Pencil class="size-4" />
                            Editar diseño
                        </Link>
                    </Button>
                    <Button as-child size="sm" variant="outline">
                        <a
                            :href="vistaPrevia.url(familia.familia)"
                            target="_blank"
                            rel="noopener"
                        >
                            <Eye class="size-4" />
                            Vista previa
                        </a>
                    </Button>
                </div>
            </li>
        </ul>
    </div>
</template>
