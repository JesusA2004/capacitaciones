<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CheckCircle2,
    Fingerprint,
    HeartPulse,
    Home,
    Lock,
    Mail,
    Pencil,
    PhoneCall,
    ShieldAlert,
    UserRound,
    Users,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, ref, shallowRef } from 'vue';
import CamposDomicilio from '@/components/Common/CamposDomicilio.vue';
import DatePicker from '@/components/Common/DatePicker.vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import InputError from '@/components/InputError.vue';
import ChipEstado from '@/components/people/ChipEstado.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { formatearFecha } from '@/lib/fechas';
import { update as actualizarDatosPersonales } from '@/routes/rh/expedientes/datos-personales';
import type { ExpedienteColaborador } from '@/types';

/**
 * Datos personales del expediente: lectura en tarjetas por sección y
 * edición COMPLETA (super admin global, RH admin por alcance). Los datos
 * críticos exigen motivo y todo cambio queda en el historial del
 * expediente (el backend vuelve a validar: permiso, alcance, «nadie edita
 * lo suyo», formato de CURP/RFC/NSS y motivo).
 */
const props = defineProps<{
    colaborador: ExpedienteColaborador;
    puedeEditar: boolean;
    esPropio: boolean;
    puedeEditarDatosMedicos: boolean;
    datosMedicos?: {
        condicion_medica: string | null;
        alergias: string | null;
    } | null;
    camposCriticos: string[];
    opcionesDatos: {
        genero: { value: string; label: string }[];
        estado_civil: { value: string; label: string }[];
    };
    urlSolicitarCorreccion: string;
}>();

type Campo = {
    campo: CampoForm;
    etiqueta: string;
    tipo?: 'texto' | 'fecha' | 'email' | 'tel' | 'genero' | 'estado_civil' | 'largo';
    max?: number;
    mayusculas?: boolean;
    ayuda?: string;
    /** No cuenta como faltante (dato opcional). */
    opcional?: boolean;
};

type Seccion = {
    clave: string;
    titulo: string;
    icono: Component;
    campos: Campo[];
    confidencial?: boolean;
};

const SECCIONES: Seccion[] = [
    {
        clave: 'identidad',
        titulo: 'Identidad',
        icono: UserRound,
        campos: [
            { campo: 'name', etiqueta: 'Nombre(s)', max: 150 },
            { campo: 'apellidos', etiqueta: 'Apellidos', max: 150 },
            { campo: 'fecha_nacimiento', etiqueta: 'Fecha de nacimiento', tipo: 'fecha' },
            { campo: 'genero', etiqueta: 'Sexo', tipo: 'genero' },
            { campo: 'nacionalidad', etiqueta: 'Nacionalidad', max: 80 },
            { campo: 'estado_civil', etiqueta: 'Estado civil', tipo: 'estado_civil' },
            { campo: 'lugar_nacimiento', etiqueta: 'Lugar de nacimiento', max: 150 },
            { campo: 'profesion', etiqueta: 'Profesión u oficio', max: 150 },
        ],
    },
    {
        clave: 'fiscal',
        titulo: 'Identificación oficial',
        icono: Fingerprint,
        campos: [
            { campo: 'curp', etiqueta: 'CURP', max: 18, mayusculas: true, ayuda: '18 caracteres' },
            { campo: 'rfc', etiqueta: 'RFC', max: 13, mayusculas: true, ayuda: 'Con homoclave' },
            { campo: 'nss', etiqueta: 'NSS', max: 11, ayuda: '11 dígitos' },
            { campo: 'clave_elector', etiqueta: 'Clave de elector (INE)', max: 18, mayusculas: true },
        ],
    },
    {
        clave: 'contacto',
        titulo: 'Contacto',
        icono: Mail,
        campos: [
            { campo: 'telefono', etiqueta: 'Teléfono personal', tipo: 'tel', max: 30 },
            { campo: 'telefono_corporativo', etiqueta: 'Teléfono corporativo', tipo: 'tel', max: 30, opcional: true },
            { campo: 'correo_personal', etiqueta: 'Correo personal', tipo: 'email' },
            { campo: 'correo_corporativo', etiqueta: 'Correo corporativo', tipo: 'email', opcional: true },
        ],
    },
    {
        clave: 'emergencia',
        titulo: 'Contacto de emergencia',
        icono: PhoneCall,
        campos: [
            { campo: 'contacto_emergencia_nombre', etiqueta: 'Nombre', max: 150 },
            { campo: 'contacto_emergencia_parentesco', etiqueta: 'Parentesco', max: 80 },
            { campo: 'contacto_emergencia_telefono', etiqueta: 'Teléfono', tipo: 'tel', max: 30 },
            { campo: 'contacto_emergencia_direccion', etiqueta: 'Dirección', max: 255, opcional: true },
        ],
    },
    {
        clave: 'beneficiario',
        titulo: 'Beneficiario',
        icono: Users,
        campos: [
            { campo: 'beneficiario_nombre', etiqueta: 'Nombre', max: 150 },
            { campo: 'beneficiario_parentesco', etiqueta: 'Parentesco', max: 80 },
        ],
    },
];

const SECCION_MEDICA: Seccion = {
    clave: 'medicos',
    titulo: 'Datos médicos',
    icono: HeartPulse,
    confidencial: true,
    campos: [
        { campo: 'condicion_medica', etiqueta: 'Condición médica', tipo: 'largo', max: 1000 },
        { campo: 'alergias', etiqueta: 'Alergias', tipo: 'largo', max: 1000 },
    ],
};

const CAMPOS_DOMICILIO = [
    'domicilio_calle',
    'domicilio_numero_exterior',
    'domicilio_numero_interior',
    'domicilio_colonia',
    'domicilio_cp',
    'domicilio_municipio',
    'domicilio_estado',
] as const;

const ETIQUETAS_DOMICILIO: Record<(typeof CAMPOS_DOMICILIO)[number], string> = {
    domicilio_calle: 'Calle',
    domicilio_numero_exterior: 'Número exterior',
    domicilio_numero_interior: 'Número interior',
    domicilio_colonia: 'Colonia',
    domicilio_cp: 'Código postal',
    domicilio_municipio: 'Municipio o alcaldía',
    domicilio_estado: 'Estado',
};

function valoresIniciales() {
    const c = props.colaborador;

    return {
        name: c.name ?? '',
        apellidos: c.apellidos ?? '',
        fecha_nacimiento: c.fecha_nacimiento ?? '',
        genero: c.genero ?? '',
        nacionalidad: c.nacionalidad ?? '',
        estado_civil: c.estado_civil ?? '',
        lugar_nacimiento: c.lugar_nacimiento ?? '',
        profesion: c.profesion ?? '',
        curp: c.curp ?? '',
        rfc: c.rfc ?? '',
        nss: c.nss ?? '',
        clave_elector: c.clave_elector ?? '',
        telefono: c.telefono ?? '',
        telefono_corporativo: c.telefono_corporativo ?? '',
        correo_personal: c.correo_personal ?? '',
        correo_corporativo: c.correo_corporativo ?? '',
        contacto_emergencia_nombre: c.contacto_emergencia_nombre ?? '',
        contacto_emergencia_parentesco: c.contacto_emergencia_parentesco ?? '',
        contacto_emergencia_telefono: c.contacto_emergencia_telefono ?? '',
        contacto_emergencia_direccion: c.contacto_emergencia_direccion ?? '',
        beneficiario_nombre: c.beneficiario_nombre ?? '',
        beneficiario_parentesco: c.beneficiario_parentesco ?? '',
        domicilio_calle: c.domicilio_calle ?? '',
        domicilio_numero_exterior: c.domicilio_numero_exterior ?? '',
        domicilio_numero_interior: c.domicilio_numero_interior ?? '',
        domicilio_colonia: c.domicilio_colonia ?? '',
        domicilio_cp: c.domicilio_cp ?? '',
        domicilio_municipio: c.domicilio_municipio ?? '',
        domicilio_estado: c.domicilio_estado ?? '',
        condicion_medica: props.datosMedicos?.condicion_medica ?? '',
        alergias: props.datosMedicos?.alergias ?? '',
    };
}

type Valores = ReturnType<typeof valoresIniciales>;
type CampoForm = keyof Valores;

const form = useForm({ ...valoresIniciales(), motivo: '' });
const editando = ref(false);
const originales = shallowRef<Valores>(valoresIniciales());

const verMedicos = computed(() => props.datosMedicos != null);
const secciones = computed(() =>
    verMedicos.value ? [...SECCIONES, SECCION_MEDICA] : SECCIONES,
);

function esCritico(campo: string): boolean {
    return props.camposCriticos.includes(campo);
}

function normalizar(campo: CampoForm, valor: string): string {
    const limpio = (valor ?? '').trim();

    return ['curp', 'rfc', 'clave_elector'].includes(campo)
        ? limpio.toUpperCase()
        : limpio;
}

/** Cambios contra lo guardado (para el resumen y el motivo obligatorio). */
const cambios = computed(() =>
    (Object.keys(originales.value) as CampoForm[])
        .filter((campo) => {
            if (
                (campo === 'condicion_medica' || campo === 'alergias') &&
                !props.puedeEditarDatosMedicos
            ) {
                return false;
            }

            return (
                normalizar(campo, String(form[campo] ?? '')) !==
                normalizar(campo, String(originales.value[campo] ?? ''))
            );
        })
        .map((campo) => ({
            campo,
            etiqueta: etiquetaDe(campo),
            critico: esCritico(campo),
        })),
);

const cambiosCriticos = computed(() => cambios.value.filter((c) => c.critico));

function etiquetaDe(campo: CampoForm): string {
    for (const seccion of [...SECCIONES, SECCION_MEDICA]) {
        const encontrado = seccion.campos.find((c) => c.campo === campo);

        if (encontrado) {
            return encontrado.etiqueta;
        }
    }

    return (
        ETIQUETAS_DOMICILIO[campo as (typeof CAMPOS_DOMICILIO)[number]] ?? campo
    );
}

function iniciarEdicion() {
    originales.value = valoresIniciales();
    form.defaults({ ...originales.value, motivo: '' });
    form.reset();
    form.clearErrors();
    editando.value = true;
}

function cancelar() {
    form.reset();
    form.clearErrors();
    editando.value = false;
}

function guardar() {
    form.transform((datos) => {
        const envio: Record<string, string | null> = {};

        for (const c of cambios.value) {
            envio[c.campo] = normalizar(c.campo, String(datos[c.campo] ?? '')) || null;
        }

        // El domicilio se manda completo si cambió alguna parte: el
        // backend recompone el texto a partir de las 7 piezas.
        if (cambios.value.some((c) => c.campo.startsWith('domicilio_'))) {
            for (const campo of CAMPOS_DOMICILIO) {
                envio[campo] = String(datos[campo] ?? '').trim() || null;
            }
        }

        envio.motivo = datos.motivo.trim() || null;

        return envio;
    }).put(actualizarDatosPersonales.url(props.colaborador.id), {
        preserveScroll: true,
        onSuccess: () => {
            editando.value = false;
        },
    });
}

function valorLegible(campo: CampoForm): string | null {
    const c = props.colaborador;

    switch (campo) {
        case 'fecha_nacimiento':
            return c.fecha_nacimiento ? formatearFecha(c.fecha_nacimiento) : null;
        case 'genero':
            return c.genero_etiqueta ?? null;
        case 'estado_civil':
            return c.estado_civil_etiqueta ?? null;
        case 'condicion_medica':
            return props.datosMedicos?.condicion_medica ?? null;
        case 'alergias':
            return props.datosMedicos?.alergias ?? null;
        default: {
            const valor = (c as unknown as Record<string, unknown>)[campo];

            return typeof valor === 'string' && valor !== '' ? valor : null;
        }
    }
}

function faltantesDe(seccion: Seccion): number {
    return seccion.campos.filter((c) => !c.opcional && !valorLegible(c.campo)).length;
}

const domicilioTexto = computed(() => {
    const c = props.colaborador;
    const partes = [
        [c.domicilio_calle, c.domicilio_numero_exterior].filter(Boolean).join(' '),
        c.domicilio_numero_interior ? `Int. ${c.domicilio_numero_interior}` : '',
        c.domicilio_colonia ? `Col. ${c.domicilio_colonia}` : '',
        c.domicilio_cp ? `C.P. ${c.domicilio_cp}` : '',
        [c.domicilio_municipio, c.domicilio_estado].filter(Boolean).join(', '),
    ].filter(Boolean);

    return partes.length > 0 ? partes.join(' · ') : c.domicilio;
});

const domicilioFaltantes = computed(
    () =>
        CAMPOS_DOMICILIO.filter(
            (campo) =>
                campo !== 'domicilio_numero_interior' &&
                !props.colaborador[campo],
        ).length,
);

defineExpose({ iniciarEdicion });

const errores = computed(
    () => form.errors as Partial<Record<string, string>>,
);
</script>

<template>
    <div class="flex flex-col gap-4">
        <!-- Barra de acción -->
        <div
            class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-border/60 bg-card px-4 py-3"
        >
            <div class="min-w-0">
                <p class="text-sm font-semibold">Datos personales</p>
                <p class="text-xs text-muted-foreground">
                    <template v-if="editando">
                        Edita lo necesario. Los datos
                        <span class="font-medium text-warning">críticos</span>
                        piden motivo.
                    </template>
                    <template v-else-if="puedeEditar">
                        Cada cambio queda en el historial con quién, cuándo y
                        por qué.
                    </template>
                    <template v-else-if="esPropio">
                        ¿Algo cambió? Solicita la corrección y RH la revisa.
                    </template>
                    <template v-else>Solo lectura.</template>
                </p>
            </div>
            <Button
                v-if="puedeEditar && !editando"
                size="sm"
                data-accion="editar-datos"
                @click="iniciarEdicion"
            >
                <Pencil class="size-3.5" /> Editar datos
            </Button>
            <Button v-else-if="esPropio" size="sm" variant="secondary" as-child>
                <Link :href="urlSolicitarCorreccion">Solicitar corrección</Link>
            </Button>
        </div>

        <!-- Lectura: tarjetas por sección -->
        <div
            v-if="!editando"
            class="grid grid-cols-1 gap-4 md:grid-cols-2 2xl:grid-cols-3"
        >
            <section
                v-for="seccion in secciones"
                :key="seccion.clave"
                class="flex flex-col gap-3 rounded-2xl border border-border/60 bg-card p-4"
                :data-seccion="seccion.clave"
            >
                <header class="flex items-center justify-between gap-2">
                    <h3 class="flex items-center gap-2 text-sm font-semibold">
                        <span
                            class="flex size-8 items-center justify-center rounded-xl bg-salvia text-primary"
                        >
                            <component :is="seccion.icono" class="size-4" />
                        </span>
                        {{ seccion.titulo }}
                    </h3>
                    <ChipEstado
                        v-if="seccion.confidencial"
                        etiqueta="Confidencial"
                        tono="neutro"
                        :icono="Lock"
                    />
                    <ChipEstado
                        v-else-if="faltantesDe(seccion) === 0"
                        etiqueta="Completo"
                        tono="exito"
                        :icono="CheckCircle2"
                    />
                    <ChipEstado
                        v-else
                        :etiqueta="`Faltan ${faltantesDe(seccion)}`"
                        tono="alerta"
                        :icono="AlertTriangle"
                    />
                </header>
                <dl class="grid grid-cols-1 gap-x-4 gap-y-2 sm:grid-cols-2">
                    <div
                        v-for="c in seccion.campos"
                        :key="c.campo"
                        class="min-w-0"
                        :class="c.tipo === 'largo' ? 'sm:col-span-2' : ''"
                    >
                        <dt class="text-xs text-muted-foreground">
                            {{ c.etiqueta }}
                        </dt>
                        <dd
                            class="text-sm font-medium break-words"
                            :class="
                                valorLegible(c.campo)
                                    ? ''
                                    : 'text-muted-foreground/70 italic'
                            "
                        >
                            {{ valorLegible(c.campo) ?? 'Sin capturar' }}
                        </dd>
                    </div>
                </dl>
            </section>

            <section
                class="flex flex-col gap-3 rounded-2xl border border-border/60 bg-card p-4 md:col-span-2 2xl:col-span-1"
                data-seccion="domicilio"
            >
                <header class="flex items-center justify-between gap-2">
                    <h3 class="flex items-center gap-2 text-sm font-semibold">
                        <span
                            class="flex size-8 items-center justify-center rounded-xl bg-salvia text-primary"
                        >
                            <Home class="size-4" />
                        </span>
                        Domicilio
                    </h3>
                    <ChipEstado
                        v-if="domicilioFaltantes === 0"
                        etiqueta="Completo"
                        tono="exito"
                        :icono="CheckCircle2"
                    />
                    <ChipEstado
                        v-else
                        :etiqueta="`Faltan ${domicilioFaltantes}`"
                        tono="alerta"
                        :icono="AlertTriangle"
                    />
                </header>
                <p
                    class="text-sm font-medium break-words"
                    :class="domicilioTexto ? '' : 'text-muted-foreground/70 italic'"
                >
                    {{ domicilioTexto ?? 'Sin capturar' }}
                </p>
            </section>
        </div>

        <!-- Edición completa -->
        <form
            v-else
            class="flex flex-col gap-4"
            data-formulario="datos-personales"
            @submit.prevent="guardar"
        >
            <fieldset
                v-for="seccion in secciones"
                :key="seccion.clave"
                class="rounded-2xl border border-border/60 bg-card p-4"
                :disabled="seccion.confidencial && !puedeEditarDatosMedicos"
            >
                <legend
                    class="flex items-center gap-2 px-1 text-sm font-semibold"
                >
                    <component :is="seccion.icono" class="size-4 text-primary" />
                    {{ seccion.titulo }}
                    <span
                        v-if="seccion.confidencial"
                        class="text-xs font-normal text-muted-foreground"
                        >· confidencial</span
                    >
                </legend>
                <div
                    class="mt-2 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4"
                >
                    <div
                        v-for="c in seccion.campos"
                        :key="c.campo"
                        class="grid content-start gap-1.5"
                        :class="c.tipo === 'largo' ? 'sm:col-span-2' : ''"
                    >
                        <Label
                            :for="`dp_${c.campo}`"
                            class="flex flex-wrap items-center gap-1.5"
                        >
                            {{ c.etiqueta }}
                            <span
                                v-if="esCritico(c.campo)"
                                class="inline-flex items-center gap-0.5 rounded-full bg-warning-soft px-1.5 py-px text-[10px] font-semibold text-warning uppercase"
                                title="Dato crítico: cambiarlo pide motivo"
                            >
                                <ShieldAlert class="size-3" /> Crítico
                            </span>
                        </Label>
                        <DatePicker
                            v-if="c.tipo === 'fecha'"
                            :id="`dp_${c.campo}`"
                            v-model="form[c.campo]"
                        />
                        <SelectSimple
                            v-else-if="c.tipo === 'genero'"
                            :id="`dp_${c.campo}`"
                            v-model="form.genero"
                            :opciones="opcionesDatos.genero"
                            opcion-vacia="Sin capturar"
                        />
                        <SelectSimple
                            v-else-if="c.tipo === 'estado_civil'"
                            :id="`dp_${c.campo}`"
                            v-model="form.estado_civil"
                            :opciones="opcionesDatos.estado_civil"
                            opcion-vacia="Sin capturar"
                        />
                        <Textarea
                            v-else-if="c.tipo === 'largo'"
                            :id="`dp_${c.campo}`"
                            v-model="form[c.campo]"
                            :maxlength="c.max"
                            rows="2"
                        />
                        <Input
                            v-else
                            :id="`dp_${c.campo}`"
                            v-model="form[c.campo]"
                            :type="c.tipo === 'email' ? 'email' : c.tipo === 'tel' ? 'tel' : 'text'"
                            :maxlength="c.max"
                            :class="c.mayusculas ? 'uppercase' : ''"
                            :inputmode="c.campo === 'nss' ? 'numeric' : undefined"
                        />
                        <p
                            v-if="c.ayuda && !errores[c.campo]"
                            class="text-xs text-muted-foreground"
                        >
                            {{ c.ayuda }}
                        </p>
                        <InputError :message="errores[c.campo]" />
                    </div>
                </div>
            </fieldset>

            <fieldset class="rounded-2xl border border-border/60 bg-card p-4">
                <legend
                    class="flex items-center gap-2 px-1 text-sm font-semibold"
                >
                    <Home class="size-4 text-primary" /> Domicilio
                </legend>
                <div class="mt-2">
                    <CamposDomicilio
                        v-model:calle="form.domicilio_calle"
                        v-model:numero-exterior="form.domicilio_numero_exterior"
                        v-model:numero-interior="form.domicilio_numero_interior"
                        v-model:colonia="form.domicilio_colonia"
                        v-model:cp="form.domicilio_cp"
                        v-model:municipio="form.domicilio_municipio"
                        v-model:estado="form.domicilio_estado"
                        :errores="errores"
                        :texto-anterior="colaborador.domicilio"
                        prefijo="expediente_domicilio"
                    />
                </div>
            </fieldset>

            <!-- Resumen + motivo + guardar (fijo abajo) -->
            <div
                class="sticky bottom-[calc(4.5rem+env(safe-area-inset-bottom))] z-10 sm:bottom-2 flex flex-col gap-3 rounded-2xl border border-border/70 bg-card/95 p-4 shadow-lg backdrop-blur"
                data-resumen-cambios
            >
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <span class="font-semibold">
                        {{
                            cambios.length === 0
                                ? 'Sin cambios todavía'
                                : `${cambios.length} cambio${cambios.length === 1 ? '' : 's'}`
                        }}
                    </span>
                    <span
                        v-for="c in cambios"
                        :key="c.campo"
                        class="rounded-full px-2 py-0.5 text-xs"
                        :class="
                            c.critico
                                ? 'bg-warning-soft text-warning'
                                : 'bg-muted text-muted-foreground'
                        "
                        >{{ c.etiqueta }}</span
                    >
                </div>
                <div
                    v-if="cambiosCriticos.length > 0 || form.errors.motivo"
                    class="grid gap-1.5"
                >
                    <Label for="dp_motivo" class="text-warning">
                        Motivo del cambio (obligatorio para datos críticos)
                    </Label>
                    <Textarea
                        id="dp_motivo"
                        v-model="form.motivo"
                        rows="2"
                        maxlength="500"
                        placeholder="Ej. Corrección según acta de nacimiento / constancia del SAT"
                    />
                    <InputError :message="form.errors.motivo" />
                </div>
                <div class="flex flex-wrap justify-end gap-2">
                    <Button type="button" variant="outline" @click="cancelar">
                        Cancelar
                    </Button>
                    <Button
                        type="submit"
                        :disabled="
                            form.processing ||
                            cambios.length === 0 ||
                            (cambiosCriticos.length > 0 &&
                                form.motivo.trim() === '')
                        "
                    >
                        <Spinner v-if="form.processing" />
                        Guardar cambios
                    </Button>
                </div>
            </div>
        </form>
    </div>
</template>
