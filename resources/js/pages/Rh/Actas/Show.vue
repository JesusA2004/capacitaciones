<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    CircleCheck,
    FilePen,
    HandHelping,
    FileSearch,
    Paperclip,
    Pencil,
    TriangleAlert,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import ActaAnexos from '@/components/actas/ActaAnexos.vue';
import ActaCampos from '@/components/actas/ActaCampos.vue';
import ActaCircunstanciadaEditor from '@/components/actas/ActaCircunstanciadaEditor.vue';
import ActaCircunstanciadaResumen from '@/components/actas/ActaCircunstanciadaResumen.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import DocumentosProceso from '@/components/documentos/DocumentosProceso.vue';
import InputError from '@/components/InputError.vue';
import DocumentPreviewDialog from '@/components/people/DocumentPreviewDialog.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { formatearFecha } from '@/lib/fechas';
import { dashboard } from '@/routes';
import {
    cerrar,
    entrega,
    index,
    negativaFirma,
    seguimiento,
    update,
    vistaPrevia,
} from '@/routes/rh/actas';
import anexos from '@/routes/rh/actas/anexos';
import type {
    ActaDetalle,
    CatalogosActa,
    FormularioActa,
    PermisosActa,
} from '@/types';

const props = defineProps<{
    acta: ActaDetalle;
    puedeGestionar: boolean;
    puedeRegistrarEntrega: boolean;
    permisos: PermisosActa;
    faltaParaCerrar: string | null;
    catalogos: CatalogosActa;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Actas administrativas', href: index() },
            { title: 'Acta', href: '' },
        ],
    },
});

const editable = computed(
    () => props.permisos.editar && props.acta.estado === 'borrador',
);
const abierta = computed(
    () => !['cerrada', 'cancelada'].includes(props.acta.estado),
);

/**
 * Acta administrativa / de hechos: formato propio (BrowserShot). La vista
 * previa es el PDF REAL que se generará, sin guardarlo.
 */
const tieneVistaPrevia = computed(
    () =>
        props.permisos.descargar &&
        ['administrativa', 'hechos'].includes(props.acta.tipo),
);
const previaAbierta = ref(false);

/** Formato vigente; las actas históricas conservan su vista y su formato. */
const esCircunstanciada = computed(
    () => props.acta.formato === 'circunstanciada',
);
const pasoInicial = Number(
    new URL(usePage().url, 'http://people.local').searchParams.get('paso') ?? 1,
);

// ───── Constancia de entrega de copia (sección XII) ─────
const entregaForm = useForm<{
    estado: 'entregada' | 'no_posible' | '';
    motivo: string;
}>({ estado: '', motivo: '' });
const ESTADO_ENTREGA: Record<string, string> = {
    pendiente: 'Pendiente de formalización',
    entregada: 'Copia entregada',
    no_posible: 'No fue posible entregar la copia',
};

function registrarEntrega() {
    entregaForm.post(entrega.url(props.acta.id), {
        preserveScroll: true,
        onSuccess: () => entregaForm.reset(),
    });
}

function refrescar() {
    router.reload({ only: ['acta'] });
}

// ───── Editar (solo en borrador) ─────
const editando = ref(false);
const form = useForm<FormularioActa>({
    motivo: '',
    fecha: '',
    hora: '',
    lugar: '',
    hechos: '',
    testigos: [],
    declaraciones: [],
});

function abrirEdicion() {
    const a = props.acta;
    const testigos = a.testigos.map((t) => ({
        colaborador_id: t.colaborador_id ?? null,
        nombre: t.nombre,
        puesto: t.puesto ?? '',
    }));

    while (testigos.length < 2) {
        testigos.push({ colaborador_id: null, nombre: '', puesto: '' });
    }

    form.defaults({
        motivo: a.motivo ?? '',
        fecha: a.fecha,
        hora: a.hora ?? '',
        lugar: a.lugar ?? '',
        hechos: a.hechos,
        testigos,
        declaraciones: a.declaraciones.length
            ? a.declaraciones.map((d) => ({ ...d }))
            : [{ persona: a.colaborador.nombre, declaracion: '' }],
    });
    form.reset();
    form.clearErrors();
    editando.value = true;
}

// Dos testigos distintos entre sí y distintos del colaborador del acta.
const testigosValidos = computed(() => {
    const [a, b] = form.testigos;

    if (!a?.colaborador_id || !b?.colaborador_id) {
        return false;
    }

    if (a.colaborador_id === b.colaborador_id) {
        return false;
    }

    return ![a.colaborador_id, b.colaborador_id].includes(
        props.acta.colaborador.id,
    );
});

function guardar() {
    if (!testigosValidos.value) {
        return;
    }

    form.transform((datos) => ({
        ...datos,
        lugar: datos.lugar || null,
        hora: datos.hora || null,
        declaraciones: datos.declaraciones.filter(
            (d) => d.persona.trim() !== '' || d.declaracion.trim() !== '',
        ),
    })).put(update.url(props.acta.id), {
        preserveScroll: true,
        onSuccess: () => {
            editando.value = false;
        },
    });
}

// ───── Negativa a firmar ─────
const negativaAbierta = ref(false);
const negativa = useForm({ motivo: '' });

function registrarNegativa() {
    negativa.post(negativaFirma.url(props.acta.id), {
        preserveScroll: true,
        onSuccess: () => {
            negativaAbierta.value = false;
            negativa.reset();
        },
    });
}

// ───── Anexos ─────
const anexo = useForm<{ archivo: File | null; descripcion: string }>({
    archivo: null,
    descripcion: '',
});

function subirAnexo() {
    anexo.post(anexos.store.url(props.acta.id), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => anexo.reset(),
    });
}

// ───── Seguimiento y cierre ─────
const nota = useForm({ nota: '' });

function agregarNota() {
    nota.post(seguimiento.url(props.acta.id), {
        preserveScroll: true,
        onSuccess: () => nota.reset(),
    });
}

function cerrarActa() {
    if (
        !window.confirm(
            '¿Cerrar el acta? Ya no se podrá generar de nuevo el formato; el seguimiento queda como historial.',
        )
    ) {
        return;
    }

    router.post(cerrar.url(props.acta.id), {}, { preserveScroll: true });
}
</script>

<template>
    <Head :title="`Acta ${acta.folio ?? ''}`" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            :titulo="`${acta.tipo_etiqueta} ${acta.folio ?? ''}`"
            :descripcion="acta.motivo ? `Por ${acta.motivo}` : undefined"
            :icono="FilePen"
            detalle
        >
            <span
                class="rounded-full bg-[var(--mrl-fondo)] px-3 py-1 text-xs font-medium text-[var(--mrl-petroleo)]"
                >{{ acta.estado_etiqueta }}</span
            >
            <Button
                v-if="editable && !esCircunstanciada"
                variant="outline"
                @click="abrirEdicion"
            >
                <Pencil class="size-4" /> Editar datos
            </Button>
            <Button
                v-if="permisos.cerrar && acta.estado !== 'borrador' && abierta"
                :disabled="!!faltaParaCerrar"
                :title="faltaParaCerrar ?? undefined"
                @click="cerrarActa"
            >
                <CircleCheck class="size-4" /> Cerrar acta
            </Button>
        </CrudPageHeader>

        <p
            v-if="
                permisos.cerrar &&
                acta.estado !== 'borrador' &&
                abierta &&
                faltaParaCerrar
            "
            class="entrada-suave rounded-xl bg-crema/50 px-4 py-2 text-sm text-muted-foreground"
        >
            Para cerrar el acta: {{ faltaParaCerrar }}
        </p>

        <ActaCircunstanciadaEditor
            v-if="esCircunstanciada && editable"
            :acta="acta"
            :catalogos="catalogos"
            :paso-inicial="pasoInicial"
        />

        <template v-else-if="esCircunstanciada">
            <div class="grid gap-5 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                <section
                    class="flex flex-col gap-4 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-5"
                >
                    <ActaCircunstanciadaResumen
                        :acta="acta"
                        :catalogos="catalogos"
                    />
                    <p
                        v-if="acta.negativa_firma"
                        class="flex items-start gap-2 rounded-xl bg-warning-soft p-3 text-sm"
                    >
                        <TriangleAlert class="mt-0.5 size-4 shrink-0" />
                        <span>
                            Se registró que el trabajador no firmó (no implica
                            aceptación ni reconocimiento):
                            {{ acta.motivo_negativa }}
                        </span>
                    </p>
                </section>

                <div class="flex flex-col gap-5">
                    <DocumentosProceso
                        tipo="acta"
                        :id="acta.id"
                        @actualizado="refrescar"
                    />

                    <section
                        class="flex flex-col gap-3 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-5"
                    >
                        <h2
                            class="flex items-center gap-1.5 text-sm font-semibold"
                        >
                            <HandHelping
                                class="size-4 text-[var(--mrl-petroleo)]"
                            />
                            Entrega de copia al trabajador
                        </h2>
                        <p class="text-sm">
                            {{
                                ESTADO_ENTREGA[
                                    acta.circunstanciada?.entrega?.estado ??
                                        'pendiente'
                                ]
                            }}
                            <span
                                v-if="acta.circunstanciada?.entrega?.en"
                                class="block text-xs text-muted-foreground"
                            >
                                {{
                                    formatearFecha(
                                        acta.circunstanciada.entrega.en,
                                    )
                                }}<template
                                    v-if="acta.circunstanciada.entrega.por"
                                >
                                    ·
                                    {{
                                        acta.circunstanciada.entrega.por
                                    }}</template
                                >
                            </span>
                            <span
                                v-if="acta.circunstanciada?.entrega?.motivo"
                                class="block text-xs"
                                >{{ acta.circunstanciada.entrega.motivo }}</span
                            >
                        </p>
                        <form
                            v-if="
                                puedeRegistrarEntrega &&
                                abierta &&
                                acta.estado !== 'borrador' &&
                                (acta.circunstanciada?.entrega?.estado ??
                                    'pendiente') === 'pendiente'
                            "
                            class="flex flex-col gap-2"
                            @submit.prevent="registrarEntrega"
                        >
                            <div class="flex flex-wrap gap-2">
                                <Button
                                    type="button"
                                    size="sm"
                                    :variant="
                                        entregaForm.estado === 'entregada'
                                            ? 'default'
                                            : 'outline'
                                    "
                                    @click="entregaForm.estado = 'entregada'"
                                    >Se entregó la copia</Button
                                >
                                <Button
                                    type="button"
                                    size="sm"
                                    :variant="
                                        entregaForm.estado === 'no_posible'
                                            ? 'default'
                                            : 'outline'
                                    "
                                    @click="entregaForm.estado = 'no_posible'"
                                    >No fue posible</Button
                                >
                            </div>
                            <Textarea
                                v-if="entregaForm.estado === 'no_posible'"
                                v-model="entregaForm.motivo"
                                rows="2"
                                aria-label="Circunstancia por la que no fue posible"
                                placeholder="Describe la circunstancia real"
                            />
                            <InputError
                                :message="
                                    entregaForm.errors.estado ??
                                    entregaForm.errors.motivo
                                "
                            />
                            <Button
                                v-if="entregaForm.estado"
                                type="submit"
                                size="sm"
                                class="self-start"
                                :disabled="entregaForm.processing"
                                >Registrar constancia</Button
                            >
                        </form>
                    </section>

                    <Button
                        v-if="
                            permisos.registrar_firma &&
                            abierta &&
                            acta.estado !== 'borrador' &&
                            !acta.negativa_firma
                        "
                        variant="outline"
                        class="self-start"
                        @click="negativaAbierta = true"
                    >
                        <TriangleAlert class="size-4" /> El trabajador se negó a
                        firmar
                    </Button>
                </div>
            </div>

            <div class="grid gap-5 lg:grid-cols-2">
                <section
                    class="flex flex-col gap-3 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-5"
                >
                    <h2 class="text-sm font-semibold">
                        Anexos relacionados en el acta
                    </h2>
                    <ActaAnexos :acta="acta" :editable="false" />
                </section>
                <section
                    class="flex flex-col gap-3 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-5"
                >
                    <h2 class="text-sm font-semibold">Seguimiento</h2>
                    <ol
                        v-if="acta.seguimiento.length"
                        class="flex flex-col gap-2 text-sm"
                    >
                        <li
                            v-for="(s, i) in acta.seguimiento"
                            :key="i"
                            class="border-l-2 border-[var(--mrl-dorado)]/60 pl-3"
                        >
                            <span class="block text-xs text-muted-foreground">{{
                                formatearFecha(s.fecha)
                            }}</span>
                            {{ s.nota }}
                        </li>
                    </ol>
                    <p v-else class="text-sm text-muted-foreground italic">
                        Sin notas todavía.
                    </p>
                    <form
                        v-if="permisos.seguimiento && abierta"
                        class="flex flex-col gap-2"
                        @submit.prevent="agregarNota"
                    >
                        <Textarea
                            v-model="nota.nota"
                            rows="2"
                            placeholder="Nota de seguimiento (RH / Jurídico)"
                            aria-label="Nota de seguimiento"
                        />
                        <InputError :message="nota.errors.nota" />
                        <Button
                            type="submit"
                            variant="outline"
                            class="self-start"
                            :disabled="!nota.nota.trim() || nota.processing"
                            >Agregar nota</Button
                        >
                    </form>
                </section>
            </div>
        </template>

        <template v-else>
            <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                <section
                    class="flex flex-col gap-4 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-5"
                >
                    <dl class="grid gap-3 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Colaborador
                            </dt>
                            <dd class="font-medium">
                                {{ acta.colaborador.nombre }}
                                <span
                                    v-if="acta.colaborador.numero_empleado"
                                    class="text-xs font-normal text-muted-foreground"
                                    >·
                                    {{ acta.colaborador.numero_empleado }}</span
                                >
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Fecha y hora
                            </dt>
                            <dd>
                                {{ formatearFecha(acta.fecha) }}
                                <template v-if="acta.hora">
                                    · {{ acta.hora }} h</template
                                >
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Ciudad
                            </dt>
                            <dd>{{ acta.lugar || 'La de su sucursal' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Testigos
                            </dt>
                            <dd>
                                <template v-if="acta.testigos.length">
                                    <span
                                        v-for="(t, i) in acta.testigos"
                                        :key="i"
                                        class="block"
                                        >{{ t.nombre
                                        }}<template v-if="t.puesto">
                                            · {{ t.puesto }}</template
                                        ></span
                                    >
                                </template>
                                <span
                                    v-else
                                    class="text-muted-foreground italic"
                                    >Sin capturar</span
                                >
                            </dd>
                        </div>
                    </dl>

                    <div>
                        <h3 class="mb-1 text-xs text-muted-foreground">
                            Hechos
                        </h3>
                        <p class="text-sm whitespace-pre-line">
                            {{ acta.hechos }}
                        </p>
                    </div>

                    <div>
                        <h3 class="mb-1 text-xs text-muted-foreground">
                            Manifestaciones
                        </h3>
                        <ul
                            v-if="acta.declaraciones.length"
                            class="flex flex-col gap-2 text-sm"
                        >
                            <li v-for="(d, i) in acta.declaraciones" :key="i">
                                <p class="font-medium">{{ d.persona }}</p>
                                <p class="whitespace-pre-line">
                                    {{ d.declaracion }}
                                </p>
                            </li>
                        </ul>
                        <p v-else class="text-sm text-muted-foreground italic">
                            Sin capturar
                        </p>
                    </div>

                    <p
                        v-if="acta.negativa_firma"
                        class="flex items-start gap-2 rounded-xl bg-warning/10 p-3 text-sm text-warning"
                    >
                        <TriangleAlert class="mt-0.5 size-4 shrink-0" />
                        <span>
                            El colaborador se negó a firmar:
                            {{ acta.motivo_negativa }}
                        </span>
                    </p>
                </section>

                <div class="flex flex-col gap-5">
                    <Button
                        v-if="tieneVistaPrevia && abierta"
                        variant="outline"
                        class="self-start"
                        @click="previaAbierta = true"
                    >
                        <FileSearch class="size-4" /> Vista previa PDF
                    </Button>

                    <DocumentosProceso
                        tipo="acta"
                        :id="acta.id"
                        @actualizado="refrescar"
                    />

                    <Button
                        v-if="
                            permisos.registrar_firma &&
                            abierta &&
                            acta.estado !== 'borrador' &&
                            !acta.negativa_firma
                        "
                        variant="outline"
                        class="self-start"
                        @click="negativaAbierta = true"
                    >
                        <TriangleAlert class="size-4" /> El colaborador se negó
                        a firmar
                    </Button>
                </div>
            </div>

            <div class="grid gap-5 lg:grid-cols-2">
                <section
                    class="flex flex-col gap-3 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-5"
                >
                    <h2 class="text-sm font-semibold">Anexos (evidencias)</h2>
                    <ul
                        v-if="acta.anexos.length"
                        class="flex flex-col gap-1 text-sm"
                    >
                        <li v-for="a in acta.anexos" :key="a.id">
                            <a
                                :href="
                                    anexos.show.url({
                                        acta: acta.id,
                                        anexo: a.id,
                                    })
                                "
                                target="_blank"
                                rel="noopener"
                                class="inline-flex items-center gap-1.5 text-[var(--mrl-petroleo)] hover:underline"
                            >
                                <Paperclip class="size-4" /> {{ a.nombre }}
                            </a>
                            <span
                                v-if="a.descripcion"
                                class="text-xs text-muted-foreground"
                            >
                                · {{ a.descripcion }}</span
                            >
                        </li>
                    </ul>
                    <p v-else class="text-sm text-muted-foreground italic">
                        Sin anexos.
                    </p>

                    <form
                        v-if="permisos.subir_anexo && abierta"
                        class="flex flex-col gap-2"
                        @submit.prevent="subirAnexo"
                    >
                        <Input
                            type="file"
                            aria-label="Archivo del anexo"
                            @change="
                                anexo.archivo =
                                    ($event.target as HTMLInputElement)
                                        .files?.[0] ?? null
                            "
                        />
                        <Input
                            v-model="anexo.descripcion"
                            placeholder="Descripción (opcional)"
                            aria-label="Descripción del anexo"
                        />
                        <InputError :message="anexo.errors.archivo" />
                        <Button
                            type="submit"
                            variant="outline"
                            class="self-start"
                            :disabled="!anexo.archivo || anexo.processing"
                        >
                            Subir anexo
                        </Button>
                    </form>
                </section>

                <section
                    class="flex flex-col gap-3 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-5"
                >
                    <h2 class="text-sm font-semibold">Seguimiento</h2>
                    <ol
                        v-if="acta.seguimiento.length"
                        class="flex flex-col gap-2 text-sm"
                    >
                        <li
                            v-for="(s, i) in acta.seguimiento"
                            :key="i"
                            class="border-l-2 border-[var(--mrl-dorado)]/60 pl-3"
                        >
                            <span class="block text-xs text-muted-foreground">{{
                                formatearFecha(s.fecha)
                            }}</span>
                            {{ s.nota }}
                        </li>
                    </ol>
                    <p v-else class="text-sm text-muted-foreground italic">
                        Sin notas todavía.
                    </p>

                    <form
                        v-if="permisos.seguimiento && abierta"
                        class="flex flex-col gap-2"
                        @submit.prevent="agregarNota"
                    >
                        <Textarea
                            v-model="nota.nota"
                            rows="2"
                            placeholder="Nota de seguimiento (RH / Jurídico)"
                            aria-label="Nota de seguimiento"
                        />
                        <InputError :message="nota.errors.nota" />
                        <Button
                            type="submit"
                            variant="outline"
                            class="self-start"
                            :disabled="!nota.nota.trim() || nota.processing"
                        >
                            Agregar nota
                        </Button>
                    </form>
                </section>
            </div>
        </template>

        <Link
            :href="index()"
            class="text-sm text-[var(--mrl-petroleo)] hover:underline"
        >
            ← Volver a actas
        </Link>
    </div>

    <Dialog v-model:open="editando">
        <DialogContent class="max-h-[92vh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>Editar acta</DialogTitle>
                <DialogDescription>
                    Solo mientras está en borrador. Después, los cambios se
                    registran como seguimiento.
                </DialogDescription>
            </DialogHeader>
            <form class="flex flex-col gap-4" @submit.prevent="guardar">
                <ActaCampos
                    :model-value="form"
                    :errores="form.errors"
                    :pide-motivo="acta.tipo === 'administrativa'"
                    :sujeto-id="acta.colaborador.id"
                />
                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        @click="editando = false"
                        >Cancelar</Button
                    >
                    <Button
                        type="submit"
                        :disabled="!testigosValidos || form.processing"
                        >Guardar</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="negativaAbierta">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Negativa a firmar</DialogTitle>
                <DialogDescription>
                    Se asienta en el acta y en su seguimiento. Los testigos
                    firman igual.
                </DialogDescription>
            </DialogHeader>
            <form
                class="flex flex-col gap-3"
                @submit.prevent="registrarNegativa"
            >
                <Label for="negativa-motivo">¿Qué pasó?</Label>
                <Textarea
                    id="negativa-motivo"
                    v-model="negativa.motivo"
                    rows="3"
                    placeholder="Ej. Se le leyó el acta y manifestó no estar de acuerdo; se negó a firmar."
                />
                <InputError :message="negativa.errors.motivo" />
                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        @click="negativaAbierta = false"
                        >Cancelar</Button
                    >
                    <Button type="submit" :disabled="negativa.processing"
                        >Registrar negativa</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <DocumentPreviewDialog
        v-if="tieneVistaPrevia && !esCircunstanciada"
        v-model:open="previaAbierta"
        :preview-url="vistaPrevia.url(acta.id)"
        :download-url="vistaPrevia.url(acta.id)"
        :nombre="`Vista previa ${acta.folio ?? ''}`"
        tipo="pdf"
    />
</template>
