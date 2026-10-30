<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    CalendarDays,
    CircleCheck,
    FileCheck2,
    MapPin,
    ShieldOff,
    UserMinus,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import CamposBaja from '@/components/bajas/CamposBaja.vue';
import VistaPreviaBaja from '@/components/bajas/VistaPreviaBaja.vue';
import ColaboradorAvatar from '@/components/Common/ColaboradorAvatar.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import BuscadorColaborador from '@/components/documentos/BuscadorColaborador.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { claseForma } from '@/lib/bajas';
import { formatearFecha } from '@/lib/fechas';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import bajas from '@/routes/rh/bajas';
import type {
    ColaboradorBaja,
    ColaboradorBusqueda,
    DocumentoCatalogoBaja,
    FormaSalidaClave,
    FormaSalidaOpcion,
    LimitesBaja,
} from '@/types';

/**
 * Registrar baja (gerente de su personal o RH). La baja es DIRECTA: al
 * confirmar queda aplicada de inmediato —cierre laboral, acceso suspendido,
 * documentos de la forma/causal generados y finiquito propuesto—, sin
 * borrador ni aprobación. Antes de confirmar se ve, en el mismo formulario,
 * la vista previa de lo que se generará (renuncia / formato y finiquito).
 * Después siguen: completar documentos, el documento de salida,
 * finiquito y el cierre por RH.
 */
const props = defineProps<{
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
            { title: 'Bajas', href: bajas.index() },
            { title: 'Registrar baja', href: '' },
        ],
    },
});

const persona = ref<ColaboradorBusqueda | null>(props.colaborador ?? null);

const hoy = new Date();
const hoyIso = `${hoy.getFullYear()}-${String(hoy.getMonth() + 1).padStart(2, '0')}-${String(hoy.getDate()).padStart(2, '0')}`;

const form = useForm({
    colaborador_id: (props.colaborador?.id ?? null) as number | null,
    forma_salida: null as FormaSalidaClave | null,
    causal_rotacion_id: null as number | null,
    fecha_baja: hoyIso,
    recontratable: '',
    comentarios: '',
    fecha_conocimiento_falta: '',
    entrega_aviso_rescision: '',
    fecha_entrega_aviso: '',
});

const buscarUrl = (texto: string) =>
    bajas.colaboradores.url({ query: { q: texto } });

function elegirPersona(c: ColaboradorBusqueda | null) {
    persona.value = c;
    form.colaborador_id = c?.id ?? null;
}

const forma = computed(
    () => props.formas.find((f) => f.value === form.forma_salida) ?? null,
);
const causal = computed(
    () =>
        forma.value?.causales.find((c) => c.id === form.causal_rotacion_id) ??
        null,
);

// Documentos que pide el cierre (forma + adicionales de la causal, RN-05).
const documentosCierre = computed(() =>
    form.forma_salida
        ? props.documentos_catalogo
              .filter(
                  (d) =>
                      d.forma === form.forma_salida ||
                      (d.causal_id !== null &&
                          d.causal_id === form.causal_rotacion_id),
              )
              .sort(
                  (a, b) =>
                      Number(a.causal_id !== null) -
                      Number(b.causal_id !== null),
              )
        : [],
);

const completo = computed(
    () =>
        Boolean(form.colaborador_id) &&
        Boolean(form.forma_salida) &&
        Boolean(form.causal_rotacion_id) &&
        Boolean(form.fecha_baja) &&
        Boolean(form.recontratable),
);

const confirmando = ref(false);

function registrar() {
    form.transform((d) => ({
        ...d,
        recontratable: d.recontratable || null,
        entrega_aviso_rescision: d.entrega_aviso_rescision || null,
        fecha_conocimiento_falta: d.fecha_conocimiento_falta || null,
        fecha_entrega_aviso: d.fecha_entrega_aviso || null,
    })).post(bajas.store.url(), {
        preserveScroll: true,
        onFinish: () => (confirmando.value = false),
    });
}
</script>

<template>
    <Head title="Registrar baja" />

    <div class="pagina-ancha flex flex-col gap-6">
        <CrudPageHeader
            titulo="Registrar baja"
            descripcion="Se aplica al confirmar: accesos suspendidos, documentos generados y finiquito propuesto."
            :icono="UserMinus"
        >
            <Button variant="outline" as-child
                ><Link :href="bajas.index()">Ver bajas</Link></Button
            >
        </CrudPageHeader>

        <form
            class="grid gap-6 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]"
            @submit.prevent="confirmando = true"
        >
            <div class="grid min-w-0 content-start gap-6">
                <section
                    class="grid gap-3 rounded-2xl border border-border/60 bg-card p-4 shadow-sm sm:p-5"
                >
                    <h3 class="text-base font-semibold">1 · ¿Quién se va?</h3>
                    <BuscadorColaborador
                        :model-value="persona"
                        :buscar-url="buscarUrl"
                        @update:model-value="elegirPersona"
                    />
                    <div
                        v-if="persona"
                        class="flex items-center gap-3 rounded-xl bg-crema/60 p-3"
                    >
                        <ColaboradorAvatar
                            :nombre="persona.nombre"
                            :foto-url="persona.foto_url"
                            tamano="xl"
                        />
                        <div class="grid min-w-0 gap-1 text-sm">
                            <p class="truncate text-base font-semibold">
                                {{ persona.nombre }}
                                <span
                                    v-if="persona.numero_empleado"
                                    class="text-sm font-normal text-muted-foreground"
                                    >#{{ persona.numero_empleado }}</span
                                >
                            </p>
                            <p
                                class="flex flex-wrap items-center gap-x-3 gap-y-1 text-muted-foreground"
                            >
                                <span>{{
                                    persona.puesto ?? 'Sin puesto'
                                }}</span>
                                <span class="inline-flex items-center gap-1"
                                    ><MapPin class="size-3.5" />{{
                                        persona.sucursal ?? 'Sin sucursal'
                                    }}</span
                                >
                                <span class="inline-flex items-center gap-1"
                                    ><CalendarDays class="size-3.5" />Ingreso
                                    {{
                                        formatearFecha(
                                            persona.fecha_ingreso ?? null,
                                        )
                                    }}</span
                                >
                            </p>
                        </div>
                    </div>
                    <InputError :message="form.errors.colaborador_id" />
                </section>

                <div
                    class="grid gap-6 rounded-2xl border border-border/60 bg-card p-4 shadow-sm sm:p-5"
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
                    />
                </div>
            </div>

            <div class="grid min-w-0 content-start gap-4 xl:sticky xl:top-4">
                <VistaPreviaBaja
                    :colaborador-id="form.colaborador_id"
                    :campos="form"
                    :tipo-baja="forma?.tipo_baja ?? ''"
                    :disabled="form.processing"
                />

                <section
                    v-if="documentosCierre.length"
                    class="grid gap-2 rounded-2xl border border-border/60 bg-card p-4 shadow-sm"
                >
                    <h3 class="flex items-center gap-2 text-sm font-semibold">
                        <FileCheck2 class="size-4 text-primary" />Para cerrar la
                        baja se necesitan
                    </h3>
                    <ul class="grid gap-1.5 text-sm">
                        <li
                            v-for="d in documentosCierre"
                            :key="d.id"
                            class="flex items-start gap-2"
                        >
                            <CircleCheck
                                class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                            />
                            <span
                                >{{ d.nombre
                                }}<span
                                    v-if="!d.obligatorio"
                                    class="text-muted-foreground"
                                >
                                    (opcional)</span
                                ></span
                            >
                        </li>
                    </ul>
                    <p class="text-xs text-muted-foreground">
                        Los adjuntas después, en el expediente de la baja.
                    </p>
                </section>

                <div
                    class="grid gap-3 rounded-2xl border border-border/60 bg-card p-4 shadow-sm"
                >
                    <p
                        class="flex items-start gap-2 rounded-xl bg-warning-soft px-3 py-2 text-sm text-warning"
                    >
                        <ShieldOff class="mt-0.5 size-4 shrink-0" />
                        Al registrar, sus accesos (web y app) se suspenden de
                        inmediato.
                    </p>
                    <InputError
                        :message="
                            (form.errors as Record<string, string>).estatus
                        "
                    />
                    <Button
                        type="submit"
                        size="lg"
                        class="w-full"
                        :disabled="form.processing || !completo"
                        ><UserMinus class="size-4" />Registrar baja</Button
                    >
                    <p
                        v-if="!completo"
                        class="text-center text-xs text-muted-foreground"
                    >
                        Falta: persona, cómo se va, causal, último día y
                        ¿recontratable?
                    </p>
                </div>
            </div>
        </form>
    </div>

    <Dialog v-model:open="confirmando">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>¿Registrar la baja?</DialogTitle>
                <DialogDescription
                    >Se aplica en este momento. RH puede revertirla («No
                    procede»).</DialogDescription
                >
            </DialogHeader>
            <div class="grid gap-3 text-sm">
                <div class="flex items-center gap-3 rounded-xl bg-crema/60 p-3">
                    <ColaboradorAvatar
                        :nombre="persona?.nombre ?? ''"
                        :foto-url="persona?.foto_url"
                        tamano="lg"
                    />
                    <div class="min-w-0">
                        <p class="truncate font-semibold">
                            {{ persona?.nombre }}
                        </p>
                        <p
                            class="flex items-center gap-1.5 text-muted-foreground"
                        >
                            <span
                                :class="
                                    cn(
                                        'size-2.5 shrink-0 rounded-full',
                                        claseForma(forma?.token).fondo,
                                    )
                                "
                            />
                            {{ forma?.etiqueta }} · {{ causal?.clave }}
                            {{ causal?.nombre }}
                        </p>
                        <p class="text-muted-foreground">
                            Último día: {{ formatearFecha(form.fecha_baja) }}
                        </p>
                    </div>
                </div>
                <ul class="grid gap-1.5">
                    <li class="flex items-start gap-2">
                        <ShieldOff
                            class="mt-0.5 size-4 shrink-0 text-warning"
                        />
                        Sus accesos se suspenden.
                    </li>
                    <li class="flex items-start gap-2">
                        <FileCheck2
                            class="mt-0.5 size-4 shrink-0 text-primary"
                        />
                        Se generan sus documentos y el finiquito propuesto.
                    </li>
                    <li class="flex items-start gap-2">
                        <CircleCheck
                            class="mt-0.5 size-4 shrink-0 text-primary"
                        />
                        Se avisa a RH, a su regional y a Sistemas.
                    </li>
                </ul>
                <InputError
                    v-for="(e, campo) in form.errors"
                    :key="campo"
                    :message="e"
                />
            </div>
            <DialogFooter class="gap-2">
                <Button
                    type="button"
                    variant="outline"
                    @click="confirmando = false"
                    >Volver</Button
                >
                <Button
                    type="button"
                    variant="destructive"
                    :disabled="form.processing"
                    @click="registrar"
                    ><Spinner v-if="form.processing" /><UserMinus
                        v-else
                        class="size-4"
                    />Sí, registrar baja</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
