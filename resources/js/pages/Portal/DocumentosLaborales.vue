<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ChevronRight, FileCheck2, FileSignature, FileText } from '@lucide/vue';
import { computed } from 'vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import PaginacionTabla from '@/components/DataTable/PaginacionTabla.vue';
import ChipEstado from '@/components/people/ChipEstado.vue';
import { formatearFecha } from '@/lib/fechas';
import { tonoDocumentoLaboral } from '@/lib/misDocumentos';
import { cn } from '@/lib/utils';
import { contratos, index as indexPortal } from '@/routes/portal';
import {
    index as rutaIndex,
    show as rutaShow,
} from '@/routes/portal/documentos-laborales';
import type { DocumentoLaboralPropio, RespuestaPaginada } from '@/types';

/**
 * «Mis documentos laborales»: lo mismo que la app (contratos, constancias,
 * responsivas…). Los que piden tu firma digital van primero en «Por firmar».
 */
defineProps<{
    documentos: RespuestaPaginada<DocumentoLaboralPropio>;
    filtro: 'pendientes_firma' | null;
    porFirmar: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Mi portal', href: indexPortal() },
            { title: 'Mis documentos laborales', href: rutaIndex() },
        ],
    },
});

// Sin autoservicio documental (features.documentos_laborales_colaborador)
// aquí solo llega lo pendiente de firma digital: sin «Todos» ni contratos.
const page = usePage();
const autoservicio = computed(
    () => page.props.features.documentos_laborales_colaborador,
);
const titulo = computed(() =>
    autoservicio.value ? 'Mis documentos laborales' : 'Documentos por firmar',
);

const pestana = (activa: boolean) =>
    cn(
        'inline-flex items-center gap-2 rounded-full px-4 py-1.5 text-sm font-medium transicion-ui',
        activa
            ? 'bg-primary text-primary-foreground'
            : 'bg-muted text-muted-foreground hover:bg-salvia hover:text-primary',
    );
</script>

<template>
    <Head :title="titulo" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            :titulo="titulo"
            :descripcion="
                autoservicio
                    ? 'Consulta, descarga y firma tus documentos.'
                    : 'Revisa y firma los documentos que Recursos Humanos te envió.'
            "
            :icono="FileText"
        />

        <div v-if="autoservicio" class="flex flex-wrap items-center gap-2">
            <Link :href="rutaIndex()" :class="pestana(filtro === null)">
                Todos
            </Link>
            <Link
                :href="rutaIndex({ query: { estado: 'pendientes_firma' } })"
                :class="pestana(filtro === 'pendientes_firma')"
            >
                Por firmar
                <span
                    v-if="porFirmar > 0"
                    class="rounded-full bg-warning-soft px-2 text-xs font-semibold text-warning tabular-nums"
                    >{{ porFirmar }}</span
                >
            </Link>
            <Link
                :href="contratos()"
                class="ml-auto enlace-animado py-2 text-sm font-medium text-primary"
            >
                Mis contratos
            </Link>
        </div>

        <EmptyState
            v-if="documentos.data.length === 0"
            :icono="filtro || !autoservicio ? FileCheck2 : FileText"
            :titulo="
                filtro || !autoservicio
                    ? 'No tienes documentos por firmar'
                    : 'Todavía no hay documentos'
            "
            descripcion="Cuando Recursos Humanos te comparta uno, aparecerá aquí y te llegará un aviso."
            class="rounded-2xl border border-dashed border-border/60 bg-card"
        />

        <div
            v-else
            class="grid entrada-escalonada gap-3 md:grid-cols-2 xl:grid-cols-3"
        >
            <Link
                v-for="documento in documentos.data"
                :key="documento.id"
                :href="rutaShow(documento.id)"
                class="group flex tarjeta-interactiva items-start gap-3 rounded-2xl border border-border/60 bg-card p-4 shadow-sm"
            >
                <span
                    class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-salvia text-primary"
                >
                    <FileSignature
                        v-if="documento.puede_firmar"
                        class="size-5"
                    />
                    <FileText v-else class="size-5" />
                </span>
                <span class="flex min-w-0 flex-1 flex-col gap-1.5">
                    <span class="leading-tight font-semibold">{{
                        documento.titulo
                    }}</span>
                    <span class="text-xs text-muted-foreground">
                        {{ formatearFecha(documento.generado_en) }}
                    </span>
                    <ChipEstado
                        :etiqueta="
                            documento.puede_firmar
                                ? 'Por firmar'
                                : (documento.estado_etiqueta ?? 'Disponible')
                        "
                        :tono="tonoDocumentoLaboral(documento)"
                    />
                </span>
                <ChevronRight
                    class="mt-2 size-4 shrink-0 icono-avanza text-muted-foreground"
                    aria-hidden="true"
                />
            </Link>
        </div>

        <PaginacionTabla :datos="documentos" />
    </div>
</template>
