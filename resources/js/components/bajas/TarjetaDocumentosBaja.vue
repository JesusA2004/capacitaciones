<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    CheckCircle2,
    CircleDashed,
    Download,
    Eye,
    FileText,
    Upload,
} from '@lucide/vue';
import { computed } from 'vue';
import ColaboradorAvatar from '@/components/Common/ColaboradorAvatar.vue';
import ChipEstado from '@/components/people/ChipEstado.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { claseForma, estatusBaja } from '@/lib/bajas';
import { formatearFecha } from '@/lib/fechas';
import { cn } from '@/lib/utils';
import rutasBajas from '@/routes/rh/bajas';
import { ciclo as cicloColaborador } from '@/routes/rh/colaboradores';
import type { DocumentoRecopilacion, TarjetaRecopilacion } from '@/types';

/**
 * Tarjeta de «Bajas y finiquitos» → Documentos: una persona dada de baja
 * con su forma · causal (color de la forma), estatus, avance de documentos
 * obligatorios (N/M) y la lista de TODOS sus documentos con su estado. El
 * botón grande abre el visor como galería (flechas para pasar) sin ir a
 * Expedientes; cada documento se ve, se descarga o —si falta— se adjunta
 * desde aquí. El backend autoriza cada archivo y cada carga.
 */
const props = defineProps<{
    tarjeta: TarjetaRecopilacion;
    /** Clave del documento que se está subiendo (spinner). */
    subiendo?: string | null;
}>();

const emit = defineEmits<{
    ver: [tarjeta: TarjetaRecopilacion, clave: string | null];
    adjuntar: [tarjeta: TarjetaRecopilacion, documento: DocumentoRecopilacion];
}>();

const forma = computed(() => claseForma(props.tarjeta.forma_token));
const estatus = computed(() => estatusBaja(props.tarjeta.estatus));
const conArchivo = computed(() =>
    props.tarjeta.documentos.filter((d) => d.url !== null),
);
const completo = computed(
    () => props.tarjeta.avance.completos >= props.tarjeta.avance.total,
);
const porcentaje = computed(() =>
    props.tarjeta.avance.total === 0
        ? 100
        : Math.round(
              (props.tarjeta.avance.completos / props.tarjeta.avance.total) *
                  100,
          ),
);

function iconoEstado(d: DocumentoRecopilacion) {
    return d.estado === 'firmado'
        ? CheckCircle2
        : d.estado === 'generado'
          ? FileText
          : CircleDashed;
}

function claseEstado(d: DocumentoRecopilacion): string {
    return d.estado === 'firmado'
        ? 'bg-success-soft text-success'
        : d.estado === 'generado'
          ? 'bg-info-soft text-info'
          : d.obligatorio
            ? 'bg-warning-soft text-warning'
            : 'bg-muted text-muted-foreground';
}

const puedeAdjuntarDoc = (d: DocumentoRecopilacion) =>
    props.tarjeta.puede_adjuntar && d.requerido_id !== null;
</script>

<template>
    <article
        :class="
            cn(
                'flex min-w-0 flex-col overflow-hidden rounded-2xl border border-l-4 border-border/60 bg-card shadow-sm',
                forma.bordeIzq,
            )
        "
        :aria-label="`Documentos de ${tarjeta.colaborador}`"
    >
        <header class="flex items-start gap-3 p-4 pb-3">
            <ColaboradorAvatar
                :nombre="tarjeta.colaborador"
                :foto-url="tarjeta.foto_url"
                tamano="lg"
            />
            <div class="flex min-w-0 flex-1 flex-col gap-1">
                <div class="flex items-start justify-between gap-2">
                    <h3 class="min-w-0 truncate text-base font-semibold">
                        {{ tarjeta.colaborador }}
                    </h3>
                    <ChipEstado
                        :etiqueta="tarjeta.estatus_etiqueta"
                        :tono="estatus.tono"
                        :icono="estatus.icono"
                    />
                </div>
                <p class="truncate text-sm text-muted-foreground">
                    {{ tarjeta.puesto ?? 'Sin puesto' }} ·
                    {{ tarjeta.sucursal ?? 'Sin sucursal' }}
                </p>
                <p class="flex min-w-0 items-center gap-2 text-sm">
                    <span
                        class="inline-flex shrink-0 items-center gap-1.5 font-medium whitespace-nowrap"
                    >
                        <span
                            :class="
                                cn(
                                    'size-2.5 shrink-0 rounded-full',
                                    forma.fondo,
                                )
                            "
                            aria-hidden="true"
                        />
                        {{ tarjeta.forma_etiqueta ?? 'Sin forma' }}
                    </span>
                    <span class="min-w-0 truncate text-muted-foreground">{{
                        tarjeta.causal ?? 'Causal pendiente'
                    }}</span>
                </p>
            </div>
        </header>

        <div
            class="grid grid-cols-2 gap-3 border-y border-border/60 bg-crema/40 px-4 py-3"
        >
            <div>
                <p class="text-xs text-muted-foreground">Último día</p>
                <p class="text-base font-semibold tabular-nums">
                    {{ formatearFecha(tarjeta.fecha_baja) }}
                </p>
            </div>
            <div>
                <p class="text-xs text-muted-foreground">Documentos</p>
                <p
                    :class="
                        cn(
                            'text-2xl leading-none font-bold tabular-nums',
                            completo ? 'text-success' : 'text-warning',
                        )
                    "
                >
                    <template v-if="tarjeta.avance.total > 0"
                        >{{ tarjeta.avance.completos
                        }}<span
                            class="text-base font-semibold text-muted-foreground"
                            >/{{ tarjeta.avance.total }}</span
                        ></template
                    >
                    <span
                        v-else
                        class="text-sm font-medium text-muted-foreground"
                        >No exige</span
                    >
                </p>
                <div
                    v-if="tarjeta.avance.total > 0"
                    class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-muted"
                    role="progressbar"
                    :aria-valuenow="porcentaje"
                    aria-valuemin="0"
                    aria-valuemax="100"
                    :aria-label="`Documentos obligatorios: ${tarjeta.avance.completos} de ${tarjeta.avance.total}`"
                >
                    <div
                        :class="
                            cn(
                                'h-full rounded-full',
                                completo ? 'bg-success' : 'bg-warning',
                            )
                        "
                        :style="{ width: `${porcentaje}%` }"
                    />
                </div>
            </div>
        </div>

        <ul class="flex flex-1 flex-col gap-1 p-2">
            <li
                v-for="d in tarjeta.documentos"
                :key="d.clave"
                class="flex min-w-0 items-center gap-2 rounded-xl px-2 py-1.5 hover:bg-muted/40"
            >
                <span
                    :class="
                        cn(
                            'flex size-8 shrink-0 items-center justify-center rounded-lg',
                            claseEstado(d),
                        )
                    "
                    aria-hidden="true"
                >
                    <component :is="iconoEstado(d)" class="size-4" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium" :title="d.nombre">
                        {{ d.nombre }}
                    </p>
                    <p class="truncate text-xs text-muted-foreground">
                        {{ d.estado_etiqueta
                        }}<template v-if="d.obligatorio">
                            · obligatorio</template
                        >
                    </p>
                </div>
                <div class="flex shrink-0 items-center gap-0.5">
                    <Button
                        v-if="d.url"
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        :aria-label="`Ver ${d.nombre}`"
                        :title="`Ver ${d.nombre}`"
                        @click="emit('ver', tarjeta, d.clave)"
                        ><Eye
                    /></Button>
                    <Button v-if="d.url" variant="ghost" size="icon-sm" as-child
                        ><a
                            :href="d.url_descarga ?? d.url"
                            download
                            :aria-label="`Descargar ${d.nombre}`"
                            :title="`Descargar ${d.nombre}`"
                            ><Download /></a
                    ></Button>
                    <Button
                        v-if="puedeAdjuntarDoc(d)"
                        type="button"
                        :variant="
                            d.estado === 'pendiente' ? 'outline' : 'ghost'
                        "
                        :size="d.estado === 'pendiente' ? 'sm' : 'icon-sm'"
                        :disabled="Boolean(subiendo)"
                        :aria-label="
                            d.estado === 'generado'
                                ? `Adjuntar ${d.nombre} firmado`
                                : `Adjuntar ${d.nombre}`
                        "
                        :title="
                            d.estado === 'generado'
                                ? 'Adjuntar el firmado'
                                : 'Adjuntar'
                        "
                        @click="emit('adjuntar', tarjeta, d)"
                    >
                        <Spinner v-if="subiendo === d.clave" /><Upload v-else />
                        <span v-if="d.estado === 'pendiente'">Adjuntar</span>
                    </Button>
                </div>
            </li>
            <li
                v-if="!tarjeta.documentos.length"
                class="px-2 py-3 text-sm text-muted-foreground"
            >
                Sin documentos para esta baja.
            </li>
        </ul>

        <footer
            class="flex flex-wrap items-center gap-2 border-t border-border/60 p-3"
        >
            <Button
                type="button"
                size="lg"
                class="min-w-0 flex-1"
                :disabled="!conArchivo.length"
                @click="emit('ver', tarjeta, null)"
            >
                <Eye />Vista previa
                <span v-if="conArchivo.length" class="tabular-nums"
                    >({{ conArchivo.length }})</span
                >
            </Button>
            <Button
                v-if="tarjeta.puede_ver_baja"
                variant="outline"
                size="lg"
                as-child
            >
                <Link :href="rutasBajas.show.url(tarjeta.id)"
                    >Baja<ArrowRight
                /></Link>
            </Button>
            <Button
                v-else-if="tarjeta.colaborador_id"
                variant="outline"
                size="lg"
                as-child
            >
                <Link :href="cicloColaborador.url(tarjeta.colaborador_id)"
                    >Ciclo<ArrowRight
                /></Link>
            </Button>
        </footer>
    </article>
</template>
