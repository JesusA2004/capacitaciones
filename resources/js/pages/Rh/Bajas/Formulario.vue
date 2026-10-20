<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { CalendarDays, MapPin, Send, Save, UserMinus } from '@lucide/vue';
import { computed, ref } from 'vue';
import CamposBaja from '@/components/bajas/CamposBaja.vue';
import DocumentosBaja from '@/components/bajas/DocumentosBaja.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import BuscadorColaborador from '@/components/documentos/BuscadorColaborador.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { formatearFecha } from '@/lib/fechas';
import { dashboard } from '@/routes';
import bajas from '@/routes/rh/bajas';
import type {
    BajaFormulario,
    ColaboradorBaja,
    ColaboradorBusqueda,
    DocumentoCatalogoBaja,
    EspacioDocumentoBaja,
    FormaSalidaClave,
    FormaSalidaOpcion,
    LimitesBaja,
} from '@/types';

/**
 * Solicitud de baja del gerente (especificación de RH §5): corta, guiada y
 * en 4 bloques — 1 Colaborador (solo su personal), 2 ¿Cómo se va? (tres
 * tarjetas), 3 ¿Por qué se va?, 4 Documentos (un espacio por documento).
 * «Guardar borrador» no afecta a nadie; «Enviar a RH» suspende el acceso de
 * la persona de inmediato y aplica el cierre laboral. Puede enviarse aunque
 * falten documentos: RH no la cierra hasta que estén completos.
 */
const props = defineProps<{
    baja: BajaFormulario | null;
    colaborador: ColaboradorBaja | null;
    formas: FormaSalidaOpcion[];
    documentos_catalogo: DocumentoCatalogoBaja[];
    recontratable: { value: string; label: string }[];
    entregas: { value: string; label: string }[];
    es_rh: boolean;
    limites: LimitesBaja;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Solicitudes de baja', href: bajas.index() },
            { title: 'Solicitud', href: '' },
        ],
    },
});

const persona = ref<ColaboradorBusqueda | null>(
    props.baja?.colaborador ?? props.colaborador ?? null,
);

const form = useForm({
    colaborador_id: (props.baja?.colaborador.id ??
        props.colaborador?.id ??
        null) as number | null,
    forma_salida: (props.baja?.forma_salida ?? null) as FormaSalidaClave | null,
    causal_rotacion_id: props.baja?.causal_rotacion_id ?? null,
    fecha_baja: props.baja?.fecha_baja ?? '',
    recontratable: props.baja?.recontratable ?? '',
    comentarios: props.baja?.comentarios ?? '',
    fecha_conocimiento_falta: props.baja?.fecha_conocimiento_falta ?? '',
    entrega_aviso_rescision: props.baja?.entrega_aviso_rescision ?? '',
    fecha_entrega_aviso: props.baja?.fecha_entrega_aviso ?? '',
    enviar: false,
});

const buscarUrl = (texto: string) =>
    bajas.colaboradores.url({ query: { q: texto } });

function elegirPersona(c: ColaboradorBusqueda | null) {
    persona.value = c;
    form.colaborador_id = c?.id ?? null;
}

// Bloque 4: espacios según forma + causal (los adjuntos ya subidos se
// conservan aunque cambie la causal; el backend los muestra en «Otros»).
const espacios = computed<EspacioDocumentoBaja[]>(() => {
    if (!form.forma_salida) {
        return [];
    }

    const previos = new Map(
        (props.baja?.documentos ?? []).map((e) => [e.id, e]),
    );

    return props.documentos_catalogo
        .filter(
            (d) =>
                d.forma === form.forma_salida ||
                (d.causal_id !== null &&
                    d.causal_id === form.causal_rotacion_id),
        )
        .sort(
            (a, b) =>
                Number(a.causal_id !== null) - Number(b.causal_id !== null),
        )
        .map((d) => {
            const previo = previos.get(d.id);

            return {
                id: d.id,
                clave: null,
                nombre: d.nombre,
                obligatorio: d.obligatorio,
                de_causal: d.causal_id !== null,
                cumplido: previo?.cumplido ?? false,
                cumplido_por_sistema: previo?.cumplido_por_sistema ?? null,
                archivos: previo?.archivos ?? [],
            };
        });
});

function guardar(enviar: boolean) {
    form.enviar = enviar;
    const opciones = { preserveScroll: true };

    if (props.baja) {
        // El colaborador no cambia al editar (el backend lo prohíbe).
        form.transform((d) => ({ ...d, colaborador_id: undefined })).put(
            bajas.update.url(props.baja.id),
            opciones,
        );

        return;
    }

    form.post(bajas.store.url(), opciones);
}

const editable = computed(
    () => !props.baja || ['borrador', 'rechazada'].includes(props.baja.estatus),
);
</script>

<template>
    <Head :title="baja ? `Baja ${baja.folio}` : 'Solicitar baja'" />

    <div class="pagina-ancha flex flex-col gap-6">
        <CrudPageHeader
            :titulo="baja ? `Baja ${baja.folio}` : 'Solicitar baja'"
            descripcion="Cuatro pasos: colaborador, cómo se va, por qué se va y documentos. Al enviarla a RH, los accesos de la persona se suspenden de inmediato."
            :icono="UserMinus"
        >
            <Button v-if="baja" variant="outline" as-child
                ><Link :href="bajas.show.url(baja.id)"
                    >Ver expediente</Link
                ></Button
            >
        </CrudPageHeader>

        <p
            v-if="baja?.estatus === 'rechazada' && baja.motivo_rechazo"
            class="rounded-2xl bg-danger-soft px-4 py-3 text-sm text-destructive"
        >
            <strong>Rechazada:</strong> {{ baja.motivo_rechazo }} Corrígela y
            vuelve a enviarla.
        </p>

        <form
            class="grid gap-6 xl:grid-cols-[2fr_1fr]"
            @submit.prevent="guardar(false)"
        >
            <div class="grid content-start gap-6">
                <section
                    class="grid gap-3 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-5"
                >
                    <h3 class="text-base font-semibold">1 · Colaborador</h3>
                    <BuscadorColaborador
                        v-if="!baja"
                        :model-value="persona"
                        :buscar-url="buscarUrl"
                        @update:model-value="elegirPersona"
                    />
                    <div
                        v-if="persona"
                        class="grid gap-2 text-sm sm:grid-cols-3"
                    >
                        <p class="flex items-center gap-1.5">
                            <MapPin class="size-4 text-primary" />
                            {{ persona.sucursal ?? 'Sin sucursal' }}
                        </p>
                        <p>{{ persona.puesto ?? 'Sin puesto' }}</p>
                        <p class="flex items-center gap-1.5">
                            <CalendarDays class="size-4 text-primary" />
                            Ingreso:
                            {{ formatearFecha(persona.fecha_ingreso ?? null) }}
                        </p>
                    </div>
                    <p v-if="baja" class="text-sm font-medium">
                        {{ persona?.nombre }}
                        <span
                            v-if="persona?.numero_empleado"
                            class="text-[var(--mrl-texto-suave)]"
                            >· #{{ persona.numero_empleado }}</span
                        >
                    </p>
                    <InputError :message="form.errors.colaborador_id" />
                </section>

                <div
                    class="grid gap-6 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-5"
                >
                    <CamposBaja
                        v-model="form"
                        :formas="formas"
                        :recontratable="recontratable"
                        :entregas="entregas"
                        :limites="limites"
                        :errores="form.errors"
                        :fecha-ingreso="persona?.fecha_ingreso ?? null"
                        :es-rh="es_rh"
                        numerar
                        :disabled="!editable"
                    />
                </div>
            </div>

            <div class="grid content-start gap-6">
                <section
                    class="grid gap-3 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-5"
                >
                    <h3 class="text-base font-semibold">4 · Documentos</h3>
                    <DocumentosBaja
                        :espacios="espacios"
                        :baja-id="baja?.id ?? null"
                        :puede-adjuntar="editable"
                        :limites="limites"
                    />
                </section>

                <div
                    v-if="editable"
                    class="flex flex-col gap-2 rounded-2xl border border-[var(--mrl-borde)] bg-crema/40 p-5"
                >
                    <InputError
                        :message="
                            (form.errors as Record<string, string>).estatus
                        "
                    />
                    <Button
                        type="submit"
                        variant="outline"
                        :disabled="form.processing || !form.colaborador_id"
                        ><Save class="size-4" />Guardar borrador</Button
                    >
                    <Button
                        type="button"
                        :disabled="form.processing || !form.colaborador_id"
                        @click="guardar(true)"
                        ><Send class="size-4" />Enviar a RH</Button
                    >
                    <p class="text-xs text-[var(--mrl-texto-suave)]">
                        Para enviar se necesitan forma, causal, último día
                        laborado y ¿recontratable?. Al enviarla, sus accesos se
                        suspenden de inmediato.
                    </p>
                </div>
            </div>
        </form>
    </div>
</template>
