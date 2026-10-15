<script setup lang="ts">
import {
    FilePlus2,
    FileSignature,
    Loader2,
    PackageCheck,
    Printer,
    Undo2,
    Upload,
    XCircle,
} from '@lucide/vue';
import { onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import DatePicker from '@/components/Common/DatePicker.vue';
import PeopleFileDropzone from '@/components/people/PeopleFileDropzone.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { formatearFecha } from '@/lib/fechas';
import { enviarJson, leerCookie } from '@/lib/http';
import {
    cancelar as cancelarResponsiva,
    devolucion as devolucionResponsiva,
    firmada as firmadaResponsiva,
    index as indexResponsivas,
    pdf as pdfResponsiva,
    subirFirmada as subirFirmadaResponsiva,
} from '@/routes/rh/responsivas';
import type {
    EstadoResponsiva,
    ResponsivaItem,
    ResponsivasColaboradorRespuesta,
} from '@/types';

/**
 * Responsivas de equipo del colaborador (ficha / expediente). Solo la ven el
 * gerente con alcance y RH: si el backend responde 403 el bloque no se
 * muestra. «Generar responsiva» abre ResponsivaFormDialog (en la página).
 */
const props = defineProps<{
    colaboradorId: number;
    /** Cambia cuando la página generó una responsiva: recargar la lista. */
    version?: number;
}>();

const emit = defineEmits<{ generar: [] }>();

const visible = ref(true);
const cargando = ref(true);
const responsivas = ref<ResponsivaItem[]>([]);
const puedeGenerar = ref(false);

type Accion = 'firmada' | 'devolucion' | 'cancelar';
const accion = ref<Accion | null>(null);
const seleccionada = ref<ResponsivaItem | null>(null);
const archivos = ref<File[]>([]);
const fechaDevolucion = ref('');
const texto = ref('');
const enviando = ref(false);
const hoy = new Date().toLocaleDateString('en-CA');

const variantes: Record<
    EstadoResponsiva,
    'warning' | 'success' | 'info' | 'destructive'
> = {
    generada: 'warning',
    firmada: 'success',
    devuelta: 'info',
    cancelada: 'destructive',
};

async function cargar() {
    cargando.value = true;

    try {
        const respuesta = await fetch(
            indexResponsivas.url(props.colaboradorId),
            {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            },
        );

        if (respuesta.status === 403) {
            visible.value = false;

            return;
        }

        if (!respuesta.ok) {
            throw new Error();
        }

        const datos =
            (await respuesta.json()) as ResponsivasColaboradorRespuesta;
        responsivas.value = datos.responsivas;
        puedeGenerar.value = datos.puede_generar;
    } catch {
        toast.error('No se pudieron cargar las responsivas.');
    } finally {
        cargando.value = false;
    }
}

onMounted(cargar);
watch(
    () => props.version,
    () => void cargar(),
);

function abrir(tipo: Accion, responsiva: ResponsivaItem) {
    seleccionada.value = responsiva;
    accion.value = tipo;
    archivos.value = [];
    fechaDevolucion.value = hoy;
    texto.value = '';
}

function reemplazar(actualizada: ResponsivaItem) {
    responsivas.value = responsivas.value.map((r) =>
        r.id === actualizada.id ? actualizada : r,
    );
}

async function confirmar() {
    const responsiva = seleccionada.value;

    if (responsiva === null || accion.value === null) {
        return;
    }

    enviando.value = true;

    try {
        let respuesta: { message: string; responsiva: ResponsivaItem };

        if (accion.value === 'firmada') {
            const cuerpo = new FormData();
            cuerpo.append('archivo', archivos.value[0]);
            const http = await fetch(
                subirFirmadaResponsiva.url(responsiva.id),
                {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-XSRF-TOKEN': leerCookie('XSRF-TOKEN') ?? '',
                    },
                    credentials: 'same-origin',
                    body: cuerpo,
                },
            );
            const datos = (await http.json().catch(() => null)) as
                | (typeof respuesta & {
                      errors?: Record<string, string[]>;
                  })
                | null;

            if (!http.ok || datos === null) {
                throw new Error(
                    (datos?.errors
                        ? Object.values(datos.errors)[0]?.[0]
                        : undefined) ??
                        datos?.message ??
                        'No se pudo subir el archivo.',
                );
            }

            respuesta = datos;
        } else if (accion.value === 'devolucion') {
            respuesta = await enviarJson(
                'POST',
                devolucionResponsiva.url(responsiva.id),
                {
                    devuelta_en: fechaDevolucion.value,
                    observaciones: texto.value,
                },
            );
        } else {
            respuesta = await enviarJson(
                'POST',
                cancelarResponsiva.url(responsiva.id),
                {
                    motivo: texto.value,
                },
            );
        }

        reemplazar(respuesta.responsiva);
        toast.success(respuesta.message);
        accion.value = null;
    } catch (e) {
        toast.error(e instanceof Error ? e.message : 'No se pudo completar.');
    } finally {
        enviando.value = false;
    }
}

const titulos: Record<Accion, string> = {
    firmada: 'Subir responsiva firmada',
    devolucion: 'Registrar devolución del equipo',
    cancelar: 'Cancelar responsiva',
};
</script>

<template>
    <Card v-if="visible" class="rounded-2xl border-border/60">
        <CardHeader
            class="flex flex-row flex-wrap items-start justify-between gap-3"
        >
            <div class="space-y-1">
                <CardTitle class="flex items-center gap-2 text-base">
                    <PackageCheck class="size-4 text-primary" />
                    Responsivas de equipo
                </CardTitle>
                <CardDescription>
                    Equipo entregado bajo resguardo (laptop, casco, celular…).
                    Solo lo ven el gerente y RH.
                </CardDescription>
            </div>
            <Button v-if="puedeGenerar" size="sm" @click="emit('generar')">
                <FilePlus2 class="size-4" />
                Generar responsiva
            </Button>
        </CardHeader>
        <CardContent>
            <div
                v-if="cargando"
                class="flex items-center gap-2 py-6 text-sm text-muted-foreground"
            >
                <Loader2 class="size-4 animate-spin" />
                Cargando responsivas…
            </div>

            <p
                v-else-if="responsivas.length === 0"
                class="rounded-xl bg-crema/40 px-4 py-6 text-center text-sm text-muted-foreground"
            >
                Aún no hay responsivas. Cuando entregues equipo, genérala aquí
                para imprimirla y firmarla.
            </p>

            <ul v-else class="flex flex-col gap-3">
                <li
                    v-for="r in responsivas"
                    :key="r.id"
                    class="rounded-2xl border border-border/60 bg-card p-4"
                    :class="{ 'opacity-70': r.estado === 'cancelada' }"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-3"
                    >
                        <div class="min-w-0 space-y-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-semibold tabular-nums">{{
                                    r.folio
                                }}</span>
                                <Badge :variant="variantes[r.estado]">{{
                                    r.estado_etiqueta
                                }}</Badge>
                                <span class="text-xs text-muted-foreground"
                                    >{{ r.tipo_etiqueta }} ·
                                    {{ formatearFecha(r.fecha_entrega) }}</span
                                >
                            </div>
                            <p class="text-xs text-muted-foreground">
                                Entregó {{ r.entregado_por }}
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <Button
                                v-if="r.tiene_pdf"
                                size="sm"
                                variant="outline"
                                as-child
                            >
                                <a
                                    :href="pdfResponsiva.url(r.id)"
                                    target="_blank"
                                    rel="noopener"
                                >
                                    <Printer class="size-3.5" />
                                    Imprimir
                                </a>
                            </Button>
                            <Button
                                v-if="r.tiene_firmada"
                                size="sm"
                                variant="outline-success"
                                as-child
                            >
                                <a
                                    :href="firmadaResponsiva.url(r.id)"
                                    target="_blank"
                                    rel="noopener"
                                >
                                    <FileSignature class="size-3.5" />
                                    Ver firmada
                                </a>
                            </Button>
                            <Button
                                v-if="r.puede_operar"
                                size="sm"
                                variant="secondary"
                                @click="abrir('firmada', r)"
                            >
                                <Upload class="size-3.5" />
                                {{
                                    r.tiene_firmada
                                        ? 'Reemplazar firmada'
                                        : 'Subir firmada'
                                }}
                            </Button>
                            <Button
                                v-if="r.puede_operar"
                                size="sm"
                                variant="ghost"
                                @click="abrir('devolucion', r)"
                            >
                                <Undo2 class="size-3.5" />
                                Devolución
                            </Button>
                            <Button
                                v-if="r.puede_cancelar"
                                size="sm"
                                variant="ghost"
                                class="text-destructive"
                                @click="abrir('cancelar', r)"
                            >
                                <XCircle class="size-3.5" />
                                Cancelar
                            </Button>
                        </div>
                    </div>

                    <ul class="mt-3 flex flex-wrap gap-2">
                        <li
                            v-for="(a, i) in r.activos"
                            :key="i"
                            class="rounded-xl bg-salvia/40 px-3 py-1.5 text-xs"
                        >
                            <span class="font-medium">{{ a.descripcion }}</span>
                            <span v-if="a.cantidad > 1">
                                × {{ a.cantidad }}</span
                            >
                            <span
                                v-if="a.detalle"
                                class="text-muted-foreground"
                            >
                                · {{ a.detalle }}</span
                            >
                        </li>
                    </ul>

                    <p
                        v-if="r.estado === 'devuelta'"
                        class="mt-2 text-xs text-muted-foreground"
                    >
                        Equipo devuelto el {{ formatearFecha(r.devuelta_en) }}.
                        {{ r.observaciones_devolucion ?? '' }}
                    </p>
                    <p
                        v-if="r.estado === 'cancelada'"
                        class="mt-2 text-xs text-destructive"
                    >
                        Cancelada: {{ r.motivo_cancelacion }}
                    </p>
                </li>
            </ul>
        </CardContent>

        <Dialog
            :open="accion !== null"
            @update:open="(v: boolean) => (accion = v ? accion : null)"
        >
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{{
                        accion ? titulos[accion] : ''
                    }}</DialogTitle>
                    <DialogDescription>
                        Responsiva {{ seleccionada?.folio }}
                    </DialogDescription>
                </DialogHeader>

                <PeopleFileDropzone
                    v-if="accion === 'firmada'"
                    v-model="archivos"
                    accept=".pdf,.jpg,.jpeg,.png"
                    label="Arrastra el escaneo firmado (PDF o foto)"
                    hint="Se guarda en el expediente, carpeta Actas y responsivas."
                />

                <div v-else-if="accion === 'devolucion'" class="grid gap-4">
                    <div class="grid gap-1.5">
                        <Label for="responsiva-devuelta"
                            >Fecha de devolución</Label
                        >
                        <DatePicker
                            id="responsiva-devuelta"
                            v-model="fechaDevolucion"
                            :max-value="hoy"
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="responsiva-devolucion-obs"
                            >Observaciones (opcional)</Label
                        >
                        <Textarea
                            id="responsiva-devolucion-obs"
                            v-model="texto"
                            rows="3"
                            maxlength="2000"
                            placeholder="Estado en que se recibió el equipo…"
                        />
                    </div>
                </div>

                <div v-else-if="accion === 'cancelar'" class="grid gap-1.5">
                    <Label for="responsiva-motivo"
                        >Motivo de la cancelación</Label
                    >
                    <Textarea
                        id="responsiva-motivo"
                        v-model="texto"
                        rows="3"
                        maxlength="500"
                        placeholder="Se capturó mal el número de serie…"
                    />
                    <p class="text-xs text-muted-foreground">
                        La responsiva no se borra: queda en el historial como
                        cancelada.
                    </p>
                </div>

                <DialogFooter class="gap-2">
                    <Button variant="ghost" @click="accion = null"
                        >Cerrar</Button
                    >
                    <Button
                        :variant="
                            accion === 'cancelar' ? 'destructive' : 'default'
                        "
                        :disabled="
                            enviando ||
                            (accion === 'firmada' && archivos.length === 0) ||
                            (accion === 'devolucion' &&
                                fechaDevolucion === '') ||
                            (accion === 'cancelar' && texto.trim().length < 5)
                        "
                        @click="confirmar"
                    >
                        <Loader2 v-if="enviando" class="size-4 animate-spin" />
                        {{
                            accion === 'cancelar'
                                ? 'Cancelar responsiva'
                                : accion === 'firmada'
                                  ? 'Subir'
                                  : 'Registrar devolución'
                        }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </Card>
</template>
