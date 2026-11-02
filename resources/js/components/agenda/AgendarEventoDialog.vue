<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import {
    BellRing,
    Briefcase,
    CalendarPlus,
    Eye,
    FileCheck2,
    House,
    Inbox,
    MapPin,
    MessagesSquare,
    TriangleAlert,
    UserPlus,
    Users,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import ColaboradorAvatar from '@/components/Common/ColaboradorAvatar.vue';
import DatePicker from '@/components/Common/DatePicker.vue';
import TimePicker from '@/components/Common/TimePicker.vue';
import InputError from '@/components/InputError.vue';
import ChipEstado from '@/components/people/ChipEstado.vue';
import PeopleFileDropzone from '@/components/people/PeopleFileDropzone.vue';
import BandejaCvSelector from '@/components/Rh/BandejaCvSelector.vue';
import { Button } from '@/components/ui/button';
import { ComboboxBuscable } from '@/components/ui/combobox-buscable';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import {
    empalmes as empalmesRuta,
    store as agendaStore,
} from '@/routes/agenda';
import type { AgendaCandidatoOpcion, TipoAgenda } from '@/types/agenda';

/**
 * «Agendar evento»: primero el candidato y, con él, lo que ya se sabe de su
 * proceso. Con puesto de sucursal (Gestor, Subgerente, Gerente) la sucursal
 * sale de su vacante y no se pregunta; solo regionales, corporativo o quien no
 * tiene sucursal la eligen. El CV que ya está en su expediente no se vuelve a
 * pedir. El backend aplica la misma regla (AgendaEventoService::resolverSucursal).
 */
const abierto = defineModel<boolean>('open', { required: true });

const props = defineProps<{
    sucursales: { id: number; nombre: string }[];
    candidatos: AgendaCandidatoOpcion[];
    puestos: { id: number; nombre: string }[];
    puede: { alta_candidato: boolean; bandeja_cv?: boolean };
    cvConfig?: { max_mb: number; extensiones: string[] } | null;
    candidatoInicialId?: number | null;
    sucursalSugerida?: number | null;
}>();

function claveNueva(): string {
    return crypto.randomUUID();
}

function hoyLocal(): string {
    const hoy = new Date();

    return `${hoy.getFullYear()}-${String(hoy.getMonth() + 1).padStart(2, '0')}-${String(hoy.getDate()).padStart(2, '0')}`;
}

const altaRapida = ref(false);
const empalmes = ref<string[]>([]);
const archivosCv = ref<File[]>([]);
const errorArchivo = ref('');

const formulario = useForm<{
    candidato_id: number | null;
    sucursal_id: number | null;
    tipo: TipoAgenda;
    fecha: string;
    hora: string;
    notas: string;
    domicilio: string;
    cv: File | null;
    cv_bandeja: string;
    clave_idempotencia: string;
}>({
    candidato_id: props.candidatoInicialId ?? null,
    sucursal_id: null,
    tipo: 'entrevista',
    fecha: '',
    hora: '',
    notas: '',
    domicilio: '',
    cv: null,
    cv_bandeja: '',
    clave_idempotencia: claveNueva(),
});

const candidatoNuevo = ref({
    nombre: '',
    apellidos: '',
    telefono: '',
    puesto_objetivo_id: null as number | null,
});

const candidato = computed<AgendaCandidatoOpcion | null>(() =>
    altaRapida.value
        ? null
        : (props.candidatos.find((c) => c.id === formulario.candidato_id) ??
          null),
);

/** La sucursal ya la definió su vacante: se muestra, no se pregunta. */
const sucursalFija = computed(
    () =>
        candidato.value !== null &&
        candidato.value.sucursal_fija &&
        candidato.value.sucursal_id !== null,
);

const sucursalEfectiva = computed<number | null>(() =>
    sucursalFija.value
        ? (candidato.value?.sucursal_id ?? null)
        : formulario.sucursal_id,
);

const nombreSucursal = computed(
    () =>
        props.sucursales.find((s) => s.id === sucursalEfectiva.value)?.nombre ??
        (sucursalFija.value ? candidato.value?.sucursal : null) ??
        null,
);

const motivoEleccion = computed(() => {
    const c = candidato.value;

    if (altaRapida.value || c === null) {
        return 'Elige dónde será la cita.';
    }

    if (c.sucursal_id === null) {
        return 'El candidato aún no tiene sucursal: elige dónde será la cita.';
    }

    return c.nivel === 'regional'
        ? 'Puesto regional: la cita puede ser en cualquier sucursal.'
        : 'Puesto corporativo: elige dónde será la cita.';
});

const pideCv = computed(
    () =>
        altaRapida.value ||
        (candidato.value !== null && !candidato.value.tiene_cv),
);

const avisoDestinatarios = computed(() => {
    const nivel = candidato.value?.nivel;

    if (
        formulario.tipo === 'entrevista' &&
        (nivel === 'gerencial' || nivel === 'regional')
    ) {
        return 'Se avisa en la app a quien aprueba esta entrevista (regionales o Dirección Comercial).';
    }

    return nombreSucursal.value
        ? `Se avisa en la app a Gerente y Subgerente de ${nombreSucursal.value}.`
        : 'Se avisa en la app a la gerencia de la sucursal.';
});

const opcionesCandidato = computed(() =>
    props.candidatos.map((c) => ({
        value: c.id,
        label: c.nombre,
        descripcion:
            [c.puesto ?? 'Sin puesto', c.sucursal]
                .filter(Boolean)
                .join(' · ') || undefined,
        alias: [c.vacante ?? '', c.estado_etiqueta].filter(Boolean),
        chip: c.tiene_cv ? 'Con CV' : undefined,
        chipTono: 'primario' as const,
    })),
);
const opcionesSucursal = computed(() =>
    props.sucursales.map((s) => ({ value: s.id, label: s.nombre })),
);
const opcionesPuesto = computed(() =>
    props.puestos.map((p) => ({ value: p.id, label: p.nombre })),
);

const extensionesCv = computed(() =>
    (
        props.cvConfig?.extensiones ?? [
            'pdf',
            'doc',
            'docx',
            'jpg',
            'jpeg',
            'png',
        ]
    )
        .map((e) => `.${e}`)
        .join(','),
);

const tiposEvento: {
    valor: TipoAgenda;
    etiqueta: string;
    detalle: string;
    icono: typeof MessagesSquare;
}[] = [
    {
        valor: 'entrevista',
        etiqueta: 'Entrevista',
        detalle: 'En sucursal, con quien la aprueba',
        icono: MessagesSquare,
    },
    {
        valor: 'socioeconomico',
        etiqueta: 'Estudio socioeconómico',
        detalle: 'Visita al domicilio del candidato',
        icono: House,
    },
];

// Al elegir candidato se precarga lo que ya se sabe de él.
watch(
    () => formulario.candidato_id,
    () => {
        const c = candidato.value;

        if (c !== null && !c.sucursal_fija) {
            formulario.sucursal_id =
                c.sucursal_id ??
                formulario.sucursal_id ??
                props.sucursalSugerida ??
                null;
        }

        if (c?.tiene_cv) {
            archivosCv.value = [];
            formulario.cv_bandeja = '';
        }
    },
    { immediate: true },
);

watch(archivosCv, (archivos) => {
    formulario.cv = archivos[0] ?? null;

    if (formulario.cv !== null) {
        formulario.cv_bandeja = '';
    }
});

watch(
    () => formulario.cv_bandeja,
    (archivo) => {
        if (archivo !== '') {
            archivosCv.value = [];
        }
    },
);

let consulta = 0;

watch(
    () => [sucursalEfectiva.value, formulario.fecha, formulario.hora] as const,
    async ([sucursal, fecha, hora]) => {
        empalmes.value = [];
        const actual = ++consulta;

        if (sucursal === null || fecha === '' || hora === '') {
            return;
        }

        try {
            const respuesta = await fetch(
                empalmesRuta.url({
                    query: { sucursal_id: sucursal, fecha, hora },
                }),
                { headers: { Accept: 'application/json' } },
            );

            if (respuesta.ok && actual === consulta) {
                const datos = (await respuesta.json()) as {
                    empalmes: string[];
                };
                empalmes.value = datos.empalmes;
            }
        } catch {
            // Es solo una advertencia: sin red no se bloquea nada.
        }
    },
);

function cambiarCandidato(): void {
    formulario.candidato_id = null;
}

function alternarAlta(): void {
    altaRapida.value = !altaRapida.value;
    formulario.clearErrors();
}

const listo = computed(
    () =>
        (altaRapida.value
            ? candidatoNuevo.value.nombre.trim() !== ''
            : formulario.candidato_id !== null) &&
        sucursalEfectiva.value !== null &&
        formulario.fecha !== '' &&
        formulario.hora !== '',
);

function limpiar(): void {
    formulario.reset();
    formulario.clearErrors();
    formulario.candidato_id = null;
    formulario.clave_idempotencia = claveNueva();
    archivosCv.value = [];
    candidatoNuevo.value = {
        nombre: '',
        apellidos: '',
        telefono: '',
        puesto_objetivo_id: null,
    };
    altaRapida.value = false;
    empalmes.value = [];
}

function agendar(): void {
    formulario
        .transform((datos) => ({
            ...datos,
            candidato_id: altaRapida.value ? null : datos.candidato_id,
            candidato_nuevo: altaRapida.value ? candidatoNuevo.value : null,
            // Con sucursal fija la define el backend con su vacante.
            sucursal_id: sucursalFija.value ? null : datos.sucursal_id,
            cv: pideCv.value ? datos.cv : null,
            cv_bandeja: pideCv.value ? datos.cv_bandeja : '',
        }))
        .post(agendaStore.url(), {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                abierto.value = false;
                limpiar();
            },
        });
}

const errores = computed(() => formulario.errors as Record<string, string>);
</script>

<template>
    <Dialog v-model:open="abierto">
        <DialogContent
            class="max-h-[92vh] gap-0 overflow-y-auto p-0 sm:max-w-2xl"
        >
            <DialogHeader class="border-b border-border bg-crema/60 px-6 py-5">
                <div class="flex items-center gap-3">
                    <span
                        class="flex size-11 shrink-0 items-center justify-center rounded-2xl bg-salvia text-primary"
                    >
                        <CalendarPlus class="size-5" />
                    </span>
                    <div class="min-w-0 text-left">
                        <DialogTitle class="text-lg"
                            >Agendar evento</DialogTitle
                        >
                        <DialogDescription>
                            Elige al candidato: lo que ya se sabe de su proceso
                            se completa solo.
                        </DialogDescription>
                    </div>
                </div>
            </DialogHeader>

            <form class="grid gap-6 px-6 py-5" @submit.prevent="agendar">
                <!-- 1 · Candidato -->
                <section class="grid gap-2.5" aria-labelledby="paso-candidato">
                    <div class="flex items-center justify-between gap-2">
                        <h3
                            id="paso-candidato"
                            class="flex items-center gap-2 text-sm font-semibold"
                        >
                            <span
                                class="flex size-6 items-center justify-center rounded-full bg-salvia text-xs text-primary"
                                >1</span
                            >
                            Candidato
                        </h3>
                        <Button
                            v-if="puede.alta_candidato"
                            type="button"
                            variant="ghost"
                            size="sm"
                            class="h-8 text-xs"
                            @click="alternarAlta"
                        >
                            <template v-if="altaRapida">
                                <Users class="size-3.5" /> Elegir de la lista
                            </template>
                            <template v-else>
                                <UserPlus class="size-3.5" /> Registrar
                                candidato nuevo
                            </template>
                        </Button>
                    </div>

                    <template v-if="!altaRapida">
                        <div
                            v-if="candidato"
                            class="flex items-center gap-3 rounded-2xl border border-primary/30 bg-salvia/40 p-3"
                        >
                            <ColaboradorAvatar
                                :nombre="candidato.nombre"
                                tamano="md"
                            />
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-semibold">
                                    {{ candidato.nombre }}
                                </p>
                                <p
                                    class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground"
                                >
                                    <span
                                        class="inline-flex items-center gap-1"
                                    >
                                        <Briefcase class="size-3.5" />
                                        {{ candidato.puesto ?? 'Sin puesto' }}
                                    </span>
                                    <ChipEstado
                                        :etiqueta="candidato.estado_etiqueta"
                                        tono="info"
                                    />
                                </p>
                            </div>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                class="shrink-0"
                                @click="cambiarCandidato"
                            >
                                <X class="size-4" /> Cambiar
                            </Button>
                        </div>
                        <ComboboxBuscable
                            v-else
                            v-model="formulario.candidato_id"
                            :opciones="opcionesCandidato"
                            numerico
                            placeholder="Busca por nombre, puesto o sucursal"
                            placeholder-busqueda="Nombre, puesto o sucursal…"
                            texto-vacio="Sin candidatos para agendar"
                            aria-label="Candidato"
                            clase-boton="h-11"
                        />
                    </template>

                    <div
                        v-else
                        class="grid gap-2 rounded-2xl border border-border p-3 sm:grid-cols-2"
                    >
                        <Input
                            v-model="candidatoNuevo.nombre"
                            placeholder="Nombre(s)"
                            aria-label="Nombre"
                        />
                        <Input
                            v-model="candidatoNuevo.apellidos"
                            placeholder="Apellidos"
                            aria-label="Apellidos"
                        />
                        <Input
                            v-model="candidatoNuevo.telefono"
                            placeholder="Teléfono"
                            inputmode="tel"
                            aria-label="Teléfono"
                        />
                        <ComboboxBuscable
                            v-model="candidatoNuevo.puesto_objetivo_id"
                            :opciones="opcionesPuesto"
                            numerico
                            limpiable
                            placeholder="Puesto al que aspira"
                            aria-label="Puesto"
                        />
                        <InputError
                            class="sm:col-span-2"
                            :message="errores['candidato_nuevo.nombre']"
                        />
                    </div>
                    <InputError :message="formulario.errors.candidato_id" />
                </section>

                <!-- 2 · Sucursal: solo se pregunta cuando no está definida -->
                <section
                    v-if="candidato || altaRapida"
                    class="grid gap-2.5"
                    aria-labelledby="paso-sucursal"
                >
                    <h3
                        id="paso-sucursal"
                        class="flex items-center gap-2 text-sm font-semibold"
                    >
                        <span
                            class="flex size-6 items-center justify-center rounded-full bg-salvia text-xs text-primary"
                            >2</span
                        >
                        Sucursal
                    </h3>
                    <div
                        v-if="sucursalFija && candidato"
                        class="flex items-start gap-3 rounded-2xl border border-border bg-crema/60 p-3"
                    >
                        <span
                            class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-card text-bronce"
                        >
                            <MapPin class="size-4" />
                        </span>
                        <div class="min-w-0">
                            <p class="font-semibold">
                                {{ candidato.sucursal }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                <template v-if="candidato.vacante">
                                    Viene por la vacante
                                    <strong
                                        class="font-medium text-foreground"
                                        >{{ candidato.vacante }}</strong
                                    >
                                </template>
                                <template v-else
                                    >Es la sucursal registrada del
                                    candidato.</template
                                >
                            </p>
                        </div>
                    </div>
                    <template v-else>
                        <ComboboxBuscable
                            v-model="formulario.sucursal_id"
                            :opciones="opcionesSucursal"
                            numerico
                            placeholder="Elige la sucursal"
                            aria-label="Sucursal"
                        />
                        <p class="text-xs text-muted-foreground">
                            {{ motivoEleccion }}
                        </p>
                    </template>
                    <InputError :message="formulario.errors.sucursal_id" />
                </section>

                <!-- 3 · Tipo de evento -->
                <section class="grid gap-2.5" aria-labelledby="paso-tipo">
                    <h3
                        id="paso-tipo"
                        class="flex items-center gap-2 text-sm font-semibold"
                    >
                        <span
                            class="flex size-6 items-center justify-center rounded-full bg-salvia text-xs text-primary"
                            >{{ candidato || altaRapida ? 3 : 2 }}</span
                        >
                        Tipo de evento
                    </h3>
                    <div
                        class="grid gap-2 sm:grid-cols-2"
                        role="radiogroup"
                        aria-label="Tipo de evento"
                    >
                        <button
                            v-for="opcion in tiposEvento"
                            :key="opcion.valor"
                            type="button"
                            role="radio"
                            :aria-checked="formulario.tipo === opcion.valor"
                            :class="
                                cn(
                                    'flex tarjeta-interactiva items-center gap-3 rounded-2xl border bg-card p-3 text-left',
                                    formulario.tipo === opcion.valor
                                        ? 'border-primary bg-salvia/50 ring-1 ring-primary/30'
                                        : 'border-border',
                                )
                            "
                            @click="formulario.tipo = opcion.valor"
                        >
                            <span
                                :class="
                                    cn(
                                        'flex size-10 shrink-0 items-center justify-center rounded-xl',
                                        formulario.tipo === opcion.valor
                                            ? 'bg-primary text-primary-foreground'
                                            : 'bg-muted text-muted-foreground',
                                    )
                                "
                            >
                                <component :is="opcion.icono" class="size-5" />
                            </span>
                            <span class="min-w-0">
                                <span class="block text-sm font-semibold">{{
                                    opcion.etiqueta
                                }}</span>
                                <span
                                    class="block text-xs text-muted-foreground"
                                    >{{ opcion.detalle }}</span
                                >
                            </span>
                        </button>
                    </div>
                </section>

                <!-- 4 · Fecha y hora -->
                <section class="grid gap-2.5" aria-labelledby="paso-fecha">
                    <h3
                        id="paso-fecha"
                        class="flex items-center gap-2 text-sm font-semibold"
                    >
                        <span
                            class="flex size-6 items-center justify-center rounded-full bg-salvia text-xs text-primary"
                            >{{ candidato || altaRapida ? 4 : 3 }}</span
                        >
                        Fecha y hora
                    </h3>
                    <div class="grid gap-2 sm:grid-cols-[1fr_12rem]">
                        <div class="grid gap-1">
                            <DatePicker
                                v-model="formulario.fecha"
                                :min-value="hoyLocal()"
                                placeholder="Elige el día"
                            />
                            <InputError :message="formulario.errors.fecha" />
                        </div>
                        <div class="grid gap-1">
                            <TimePicker
                                v-model="formulario.hora"
                                :desde="7"
                                :hasta="20"
                                placeholder="Hora"
                            />
                            <InputError :message="formulario.errors.hora" />
                        </div>
                    </div>
                    <div
                        v-if="empalmes.length"
                        class="grid gap-1 rounded-2xl border border-warning/40 bg-warning-soft p-3 text-xs"
                        role="status"
                    >
                        <p
                            class="flex items-center gap-1.5 font-semibold text-warning"
                        >
                            <TriangleAlert class="size-4" /> Hay citas cerca de
                            esa hora
                        </p>
                        <ul class="grid gap-0.5 pl-6 text-foreground/80">
                            <li v-for="aviso in empalmes" :key="aviso">
                                {{ aviso }}
                            </li>
                        </ul>
                        <p class="pl-6 text-muted-foreground">
                            Puedes agendar de todos modos.
                        </p>
                    </div>
                    <div
                        v-if="formulario.tipo === 'socioeconomico'"
                        class="grid gap-1"
                    >
                        <div class="relative">
                            <MapPin
                                class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                            />
                            <Input
                                v-model="formulario.domicilio"
                                class="pl-9"
                                placeholder="Domicilio de la visita"
                                aria-label="Domicilio de la visita"
                            />
                        </div>
                        <InputError :message="formulario.errors.domicilio" />
                    </div>
                </section>

                <!-- CV: el que ya está en el expediente no se vuelve a pedir -->
                <section
                    v-if="candidato?.tiene_cv"
                    class="flex items-center gap-3 rounded-2xl border border-success/30 bg-success-soft/60 p-3"
                    aria-label="CV del candidato"
                >
                    <span
                        class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-card text-success"
                    >
                        <FileCheck2 class="size-5" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold">CV ya cargado</p>
                        <p class="truncate text-xs text-muted-foreground">
                            {{ candidato.cv_nombre ?? 'En su expediente' }} · ya
                            está en su expediente, no hace falta subirlo otra
                            vez.
                        </p>
                    </div>
                    <Button
                        v-if="candidato.cv_url"
                        type="button"
                        variant="outline"
                        size="sm"
                        class="shrink-0"
                        as-child
                    >
                        <a
                            :href="candidato.cv_url"
                            target="_blank"
                            rel="noopener"
                        >
                            <Eye class="size-4" /> Ver
                        </a>
                    </Button>
                </section>
                <section
                    v-else-if="pideCv"
                    class="grid gap-2.5"
                    aria-labelledby="paso-cv"
                >
                    <h3 id="paso-cv" class="text-sm font-semibold">
                        CV del candidato
                        <span class="font-normal text-muted-foreground"
                            >(opcional)</span
                        >
                    </h3>
                    <PeopleFileDropzone
                        v-model="archivosCv"
                        :accept="extensionesCv"
                        :max-size-mb="cvConfig?.max_mb ?? 10"
                        label="Arrastra el CV aquí"
                        @error="(m) => (errorArchivo = m)"
                    />
                    <InputError
                        :message="errorArchivo || formulario.errors.cv"
                    />
                    <div
                        v-if="puede.bandeja_cv && archivosCv.length === 0"
                        class="grid gap-1.5 rounded-2xl bg-muted/50 p-3"
                    >
                        <p
                            class="flex items-center gap-1.5 text-xs font-medium"
                        >
                            <Inbox class="size-3.5" /> O tómalo de la bandeja de
                            WhatsApp
                        </p>
                        <BandejaCvSelector v-model="formulario.cv_bandeja" />
                        <InputError :message="errores.cv_bandeja" />
                    </div>
                </section>

                <div class="grid gap-1">
                    <label for="agenda-notas" class="text-sm font-semibold">
                        Notas para quien atiende
                        <span class="font-normal text-muted-foreground"
                            >(opcional)</span
                        >
                    </label>
                    <Textarea
                        id="agenda-notas"
                        v-model="formulario.notas"
                        rows="2"
                        placeholder="Ej. Trae INE y comprobante de domicilio"
                    />
                </div>
            </form>

            <DialogFooter
                class="sticky bottom-0 flex-col gap-3 border-t border-border bg-card px-6 py-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <p
                    class="flex items-start gap-1.5 text-xs text-muted-foreground"
                >
                    <BellRing class="mt-0.5 size-3.5 shrink-0" />
                    {{ avisoDestinatarios }}
                </p>
                <div class="flex shrink-0 gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        @click="abierto = false"
                    >
                        Cancelar
                    </Button>
                    <Button
                        type="button"
                        :disabled="formulario.processing || !listo"
                        @click="agendar"
                    >
                        <Spinner v-if="formulario.processing" class="size-4" />
                        <CalendarPlus v-else class="size-4" />
                        Agendar y notificar
                    </Button>
                </div>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
