<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { FileText, ScrollText } from '@lucide/vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import ChipEstado from '@/components/people/ChipEstado.vue';
import { Button } from '@/components/ui/button';
import { formatearFecha } from '@/lib/fechas';
import { estadoContrato } from '@/lib/misDocumentos';
import {
    contratos as rutaContratos,
    index as indexPortal,
} from '@/routes/portal';
import { show as verDocumento } from '@/routes/portal/documentos-laborales';
import type { ContratoPropio } from '@/types';

/**
 * «Mis contratos»: solo lectura, como en la app. Fechas y días para vencer
 * los calcula el backend (ContratoLaboralService::aArray).
 */
defineProps<{ contratos: ContratoPropio[] }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Mi portal', href: indexPortal() },
            { title: 'Mis contratos', href: rutaContratos() },
        ],
    },
});

const porVencer = (c: ContratoPropio) =>
    c.estado === 'vigente' &&
    c.dias_para_vencer !== null &&
    c.dias_para_vencer >= 0;
</script>

<template>
    <Head title="Mis contratos" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            titulo="Mis contratos"
            descripcion="Tipo y vigencia de cada contrato."
            :icono="ScrollText"
        />

        <EmptyState
            v-if="contratos.length === 0"
            :icono="ScrollText"
            titulo="Todavía no hay contratos"
            descripcion="Cuando Recursos Humanos registre el tuyo, verás aquí su tipo y vigencia."
            class="rounded-2xl border border-dashed border-border/60 bg-card"
        />

        <div v-else class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            <article
                v-for="contrato in contratos"
                :key="contrato.id"
                class="flex flex-col gap-3 rounded-2xl border border-border/60 bg-card p-4 shadow-sm"
            >
                <div class="flex items-start justify-between gap-2">
                    <p class="leading-tight font-semibold">
                        {{ contrato.tipo_etiqueta }}
                    </p>
                    <ChipEstado
                        :etiqueta="estadoContrato(contrato).etiqueta"
                        :tono="estadoContrato(contrato).tono"
                    />
                </div>

                <dl class="grid grid-cols-2 gap-2 text-sm">
                    <div>
                        <dt class="text-xs text-muted-foreground">Inicio</dt>
                        <dd>{{ formatearFecha(contrato.fecha_inicio) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">Fin</dt>
                        <dd>
                            {{
                                contrato.fecha_fin
                                    ? formatearFecha(contrato.fecha_fin)
                                    : contrato.tipo === 'indeterminado'
                                      ? 'Indeterminado'
                                      : '—'
                            }}
                        </dd>
                    </div>
                </dl>

                <ChipEstado
                    v-if="porVencer(contrato)"
                    :tono="
                        (contrato.dias_para_vencer ?? 0) <= 15
                            ? 'alerta'
                            : 'info'
                    "
                    :etiqueta="
                        contrato.dias_para_vencer === 0
                            ? 'Vence hoy'
                            : `Vence en ${contrato.dias_para_vencer} día(s)`
                    "
                />

                <div
                    v-if="contrato.documento_id"
                    class="mt-auto flex flex-wrap items-center justify-between gap-2"
                >
                    <ChipEstado
                        :tono="contrato.documento_firmado ? 'exito' : 'neutro'"
                        :etiqueta="
                            contrato.documento_firmado
                                ? 'Firmado'
                                : 'Firma pendiente'
                        "
                    />
                    <Button size="sm" variant="outline" as-child>
                        <Link :href="verDocumento(contrato.documento_id)">
                            <FileText class="size-3.5" />
                            Ver documento
                        </Link>
                    </Button>
                </div>
            </article>
        </div>
    </div>
</template>
