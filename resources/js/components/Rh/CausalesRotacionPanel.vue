<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowUp,
    FolderPlus,
    History,
    Pencil,
    Plus,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import Casilla from '@/components/Common/Casilla.vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import InputError from '@/components/InputError.vue';
import ChipEstado from '@/components/people/ChipEstado.vue';
import { Badge } from '@/components/ui/badge';
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
import { usePermisos } from '@/composables/usePermisos';
import { claseForma } from '@/lib/bajas';
import { formatearFechaHora } from '@/lib/fechas';
import { cn } from '@/lib/utils';
import { causalesExcel } from '@/routes/rh/bajas';
import causalesRotacion from '@/routes/rh/causales-rotacion';
import type {
    CausalGrupoAdmin,
    CausalRotacionAdmin,
    HistorialCatalogoBajas,
} from '@/types';

/**
 * Catálogo de causales de baja (RN-15, solo RH): dos niveles — forma de
 * salida (Renuncia voluntaria / No renovación / Rescisión) y su causal con
 * clave (RV-01…), ayuda «cuándo usarla», integrar expediente probatorio,
 * implica indemnización, comentario obligatorio y alerta. Las causales NUNCA se
 * borran: se desactivan y su uso se conserva. Es la pestaña «Catálogo de causales»
 * de Bajas. Cada forma admite subcatálogos que RH captura (p. ej. «Pensión» en
 * Otras terminaciones). La autorización real vive en CausalRotacionPolicy.
 */
const props = withDefaults(
    defineProps<{
        causales: CausalRotacionAdmin[];
        tipos: {
            value: string;
            label: string;
            forma?: string;
            token?: string;
        }[];
        grupos?: CausalGrupoAdmin[];
        documentosPorForma?: Record<string, { value: string; label: string }[]>;
        historial?: HistorialCatalogoBajas[];
    }>(),
    { grupos: () => [], documentosPorForma: () => ({}), historial: () => [] },
);

// Cada acción del catálogo de bajas con su permiso (el backend lo revalida).
const { tienePermiso } = usePermisos();
const puede = {
    crear: tienePermiso('causales_rotacion.crear'),
    editar: tienePermiso('causales_rotacion.editar'),
    activar: tienePermiso('causales_rotacion.activar'),
    ordenar: tienePermiso('causales_rotacion.ordenar'),
};

const grupos = computed(() =>
    props.tipos.map((t) => {
        const causales = props.causales.filter((c) => c.tipo === t.value);

        const subcatalogos = props.grupos
            .filter((g) => g.forma === t.forma)
            .map((g) => ({
                ...g,
                lista: causales.filter((c) => c.causal_grupo_id === g.id),
            }));

        return {
            ...t,
            causales,
            subcatalogos,
            sueltas: causales.filter((c) => c.causal_grupo_id === null),
            genera: props.documentosPorForma[t.forma ?? '']?.[0]?.label ?? null,
        };
    }),
);

const formaDelTipo = (tipo: string): string =>
    props.tipos.find((t) => t.value === tipo)?.forma ?? '';
const gruposDelForm = computed(() =>
    props.grupos
        .filter((g) => g.forma === formaDelTipo(form.tipo))
        .map((g) => ({ value: g.id, label: g.nombre })),
);
const documentosDelForm = computed(
    () => props.documentosPorForma[formaDelTipo(form.tipo)] ?? [],
);

const abierto = ref(false);
const editando = ref<CausalRotacionAdmin | null>(null);
const form = useForm({
    tipo: '',
    causal_grupo_id: null as number | null,
    documento_salida: '',
    nombre: '',
    descripcion: '',
    requiere_juridico: false,
    comentario_obligatorio: false,
    alerta: '',
    fundamento_legal: '',
    excluye_indemnizacion: false,
    implica_indemnizacion: false,
    activo: true,
});

function nueva(tipo: string, grupoId: number | null = null) {
    editando.value = null;
    form.clearErrors();
    form.tipo = tipo;
    form.causal_grupo_id = grupoId;
    form.documento_salida = '';
    form.fundamento_legal = '';
    form.nombre = '';
    form.descripcion = '';
    form.requiere_juridico = false;
    // Rescisión y otras terminaciones (casos sensibles) piden comentario.
    form.comentario_obligatorio =
        tipo === 'rescision' || tipo === 'otra_terminacion';
    form.alerta = '';
    form.excluye_indemnizacion = false;
    form.implica_indemnizacion = false;
    form.activo = true;
    abierto.value = true;
}

function editar(c: CausalRotacionAdmin) {
    editando.value = c;
    form.clearErrors();
    form.tipo = c.tipo;
    form.causal_grupo_id = c.causal_grupo_id;
    form.documento_salida = c.documento_salida ?? '';
    form.nombre = c.nombre;
    form.descripcion = c.descripcion ?? '';
    form.requiere_juridico = c.requiere_juridico;
    form.comentario_obligatorio = c.comentario_obligatorio;
    form.alerta = c.alerta ?? '';
    form.fundamento_legal = c.fundamento_legal ?? '';
    form.excluye_indemnizacion = c.excluye_indemnizacion;
    form.implica_indemnizacion = c.implica_indemnizacion;
    form.activo = c.activo;
    abierto.value = true;
}

function guardar() {
    const opciones = {
        preserveScroll: true,
        onSuccess: () => (abierto.value = false),
    };

    if (editando.value) {
        form.put(causalesRotacion.update.url(editando.value.id), opciones);

        return;
    }

    form.post(causalesRotacion.store.url(), opciones);
}

function alternar(c: CausalRotacionAdmin) {
    router.patch(
        causalesRotacion.activo.url(c.id),
        { activo: !c.activo },
        { preserveScroll: true },
    );
}

function mover(c: CausalRotacionAdmin, direccion: 'arriba' | 'abajo') {
    router.post(
        causalesRotacion.mover.url(c.id),
        { direccion },
        { preserveScroll: true },
    );
}

// ── Subcatálogos ──
const grupoAbierto = ref(false);
const grupoEditando = ref<CausalGrupoAdmin | null>(null);
const formGrupo = useForm({ forma_salida: '', nombre: '', descripcion: '' });

function nuevoGrupo(forma: string) {
    grupoEditando.value = null;
    formGrupo.clearErrors();
    formGrupo.forma_salida = forma;
    formGrupo.nombre = '';
    formGrupo.descripcion = '';
    grupoAbierto.value = true;
}

function editarGrupo(g: CausalGrupoAdmin) {
    grupoEditando.value = g;
    formGrupo.clearErrors();
    formGrupo.forma_salida = g.forma;
    formGrupo.nombre = g.nombre;
    formGrupo.descripcion = g.descripcion ?? '';
    grupoAbierto.value = true;
}

function guardarGrupo() {
    const opciones = {
        preserveScroll: true,
        onSuccess: () => (grupoAbierto.value = false),
    };

    if (grupoEditando.value) {
        formGrupo.put(
            causalesRotacion.grupos.update.url(grupoEditando.value.id),
            opciones,
        );

        return;
    }

    formGrupo.post(causalesRotacion.grupos.store.url(), opciones);
}

function alternarGrupo(g: CausalGrupoAdmin) {
    router.patch(
        causalesRotacion.grupos.activo.url(g.id),
        { activo: !g.activo },
        { preserveScroll: true },
    );
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <p class="text-sm text-[var(--mrl-texto-suave)]">
            Al registrar una baja se elige primero cómo se va (forma de salida)
            y después por qué (la causal de esa forma); la ayuda de cada causal
            se muestra en pantalla. Las causales nunca se borran: desactívala
            para que ya no se ofrezca; el historial la conserva.
        </p>

        <div class="flex justify-end">
            <Button variant="outline" size="sm" as-child>
                <a :href="causalesExcel.url()">Excel</a>
            </Button>
        </div>

        <div class="grid items-start gap-4 xl:grid-cols-2">
            <section
                v-for="g in grupos"
                :key="g.value"
                :class="
                    cn(
                        'rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)]',
                        g.forma === 'OT' && 'border-success',
                    )
                "
            >
                <header
                    class="flex flex-wrap items-center justify-between gap-2 border-b border-[var(--mrl-borde)] px-4 py-3"
                >
                    <h3 class="flex items-center gap-2 text-sm font-semibold">
                        <span
                            :class="
                                cn(
                                    'size-3 rounded-full',
                                    claseForma(g.token).fondo,
                                )
                            "
                            aria-hidden="true"
                        />
                        {{ g.forma ? `${g.forma} · ` : '' }}{{ g.label }}
                        <span class="font-normal text-[var(--mrl-texto-suave)]"
                            >· Genera: {{ g.genera ?? '—' }}</span
                        >
                    </h3>
                    <div v-if="puede.crear" class="flex flex-wrap gap-1">
                        <Button
                            size="sm"
                            variant="ghost"
                            @click="nuevoGrupo(g.forma ?? '')"
                        >
                            <FolderPlus class="size-4" />Subcatálogo
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            @click="nueva(g.value)"
                        >
                            <Plus class="size-4" />Agregar causal
                        </Button>
                    </div>
                </header>

                <p
                    v-if="!g.causales.length && !g.subcatalogos.length"
                    class="px-4 py-6 text-center text-sm text-[var(--mrl-texto-suave)]"
                >
                    Sin causales. Agrega al menos una para poder elegirla.
                </p>

                <template v-else>
                    <div
                        v-for="sub in g.subcatalogos"
                        :key="sub.id"
                        class="border-b border-[var(--mrl-borde)] last:border-b-0"
                    >
                        <div
                            class="flex flex-wrap items-center justify-between gap-2 bg-crema/40 px-4 py-2"
                        >
                            <p class="text-sm font-semibold">
                                {{ sub.nombre }}
                                <Badge v-if="!sub.activo" variant="secondary"
                                    >Inactivo</Badge
                                >
                                <span
                                    v-if="sub.descripcion"
                                    class="ml-1 text-xs font-normal text-[var(--mrl-texto-suave)]"
                                    >{{ sub.descripcion }}</span
                                >
                            </p>
                            <div class="flex flex-wrap items-center gap-1">
                                <Button
                                    v-if="puede.crear"
                                    size="sm"
                                    variant="ghost"
                                    @click="nueva(g.value, sub.id)"
                                    ><Plus class="size-4" />Causal</Button
                                >
                                <Button
                                    v-if="puede.activar"
                                    size="sm"
                                    variant="outline"
                                    @click="alternarGrupo(sub)"
                                    >{{
                                        sub.activo ? 'Desactivar' : 'Activar'
                                    }}</Button
                                >
                                <Button
                                    v-if="puede.editar"
                                    size="icon"
                                    variant="ghost"
                                    :aria-label="`Editar ${sub.nombre}`"
                                    @click="editarGrupo(sub)"
                                    ><Pencil class="size-4"
                                /></Button>
                            </div>
                        </div>
                        <ul
                            v-if="sub.lista.length"
                            class="divide-y divide-[var(--mrl-borde)]"
                        >
                            <template v-for="lista in [sub.lista]">
                                <li
                                    v-for="(c, i) in lista"
                                    :key="c.id"
                                    class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center"
                                >
                                    <div class="min-w-0 flex-1">
                                        <p
                                            class="flex flex-wrap items-center gap-2 text-sm font-medium"
                                            :class="
                                                !c.activo &&
                                                'text-[var(--mrl-texto-suave)]'
                                            "
                                        >
                                            <span
                                                v-if="c.clave"
                                                class="font-mono text-xs text-[var(--mrl-texto-suave)]"
                                                >{{ c.clave }}</span
                                            >
                                            {{ c.nombre }}
                                            <Badge
                                                v-if="!c.activo"
                                                variant="secondary"
                                                >Inactiva</Badge
                                            >
                                            <ChipEstado
                                                v-if="c.requiere_juridico"
                                                etiqueta="Integrar expediente probatorio"
                                                tono="oro"
                                            />
                                            <ChipEstado
                                                v-if="c.implica_indemnizacion"
                                                etiqueta="Implica indemnización"
                                                tono="peligro"
                                            />
                                            <ChipEstado
                                                v-if="c.comentario_obligatorio"
                                                etiqueta="Comentario obligatorio"
                                                tono="neutro"
                                            />
                                            <ChipEstado
                                                v-if="c.alerta"
                                                etiqueta="Alerta"
                                                tono="alerta"
                                            />
                                            <Badge
                                                v-if="c.excluye_indemnizacion"
                                                variant="outline"
                                                >Sin indemnización</Badge
                                            >
                                        </p>
                                        <p
                                            class="text-xs text-[var(--mrl-texto-suave)]"
                                        >
                                            {{
                                                c.usos
                                                    ? `Usada en ${c.usos} ${c.usos === 1 ? 'baja' : 'bajas'}`
                                                    : 'Sin usar'
                                            }}<template v-if="c.descripcion">
                                                · {{ c.descripcion }}</template
                                            >
                                        </p>
                                        <p
                                            v-if="c.alerta"
                                            class="text-xs text-warning"
                                        >
                                            {{ c.alerta }}
                                        </p>
                                        <p
                                            v-if="c.fundamento_legal"
                                            class="text-xs text-[var(--mrl-texto-suave)]"
                                        >
                                            Fundamento: {{ c.fundamento_legal }}
                                        </p>
                                    </div>
                                    <div
                                        class="flex flex-wrap items-center gap-1"
                                    >
                                        <Button
                                            v-if="puede.ordenar"
                                            size="icon"
                                            variant="ghost"
                                            :disabled="i === 0"
                                            aria-label="Subir"
                                            @click="mover(c, 'arriba')"
                                            ><ArrowUp class="size-4"
                                        /></Button>
                                        <Button
                                            v-if="puede.ordenar"
                                            size="icon"
                                            variant="ghost"
                                            :disabled="i === lista.length - 1"
                                            aria-label="Bajar"
                                            @click="mover(c, 'abajo')"
                                            ><ArrowDown class="size-4"
                                        /></Button>
                                        <Button
                                            v-if="puede.activar"
                                            size="sm"
                                            variant="outline"
                                            @click="alternar(c)"
                                            >{{
                                                c.activo
                                                    ? 'Desactivar'
                                                    : 'Activar'
                                            }}</Button
                                        >
                                        <Button
                                            v-if="puede.editar"
                                            size="icon"
                                            variant="ghost"
                                            aria-label="Editar"
                                            @click="editar(c)"
                                            ><Pencil class="size-4"
                                        /></Button>
                                    </div>
                                </li>
                            </template>
                        </ul>
                        <p
                            v-else
                            class="px-4 py-3 text-xs text-[var(--mrl-texto-suave)]"
                        >
                            Sin causales todavía.
                        </p>
                    </div>
                    <ul
                        v-if="g.sueltas.length"
                        class="divide-y divide-[var(--mrl-borde)]"
                    >
                        <template v-for="lista in [g.sueltas]">
                            <li
                                v-for="(c, i) in lista"
                                :key="c.id"
                                class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center"
                            >
                                <div class="min-w-0 flex-1">
                                    <p
                                        class="flex flex-wrap items-center gap-2 text-sm font-medium"
                                        :class="
                                            !c.activo &&
                                            'text-[var(--mrl-texto-suave)]'
                                        "
                                    >
                                        <span
                                            v-if="c.clave"
                                            class="font-mono text-xs text-[var(--mrl-texto-suave)]"
                                            >{{ c.clave }}</span
                                        >
                                        {{ c.nombre }}
                                        <Badge
                                            v-if="!c.activo"
                                            variant="secondary"
                                            >Inactiva</Badge
                                        >
                                        <ChipEstado
                                            v-if="c.requiere_juridico"
                                            etiqueta="Integrar expediente probatorio"
                                            tono="oro"
                                        />
                                        <ChipEstado
                                            v-if="c.implica_indemnizacion"
                                            etiqueta="Implica indemnización"
                                            tono="peligro"
                                        />
                                        <ChipEstado
                                            v-if="c.comentario_obligatorio"
                                            etiqueta="Comentario obligatorio"
                                            tono="neutro"
                                        />
                                        <ChipEstado
                                            v-if="c.alerta"
                                            etiqueta="Alerta"
                                            tono="alerta"
                                        />
                                        <Badge
                                            v-if="c.excluye_indemnizacion"
                                            variant="outline"
                                            >Sin indemnización</Badge
                                        >
                                    </p>
                                    <p
                                        class="text-xs text-[var(--mrl-texto-suave)]"
                                    >
                                        {{
                                            c.usos
                                                ? `Usada en ${c.usos} ${c.usos === 1 ? 'baja' : 'bajas'}`
                                                : 'Sin usar'
                                        }}<template v-if="c.descripcion">
                                            · {{ c.descripcion }}</template
                                        >
                                    </p>
                                    <p
                                        v-if="c.alerta"
                                        class="text-xs text-warning"
                                    >
                                        {{ c.alerta }}
                                    </p>
                                    <p
                                        v-if="c.fundamento_legal"
                                        class="text-xs text-[var(--mrl-texto-suave)]"
                                    >
                                        Fundamento: {{ c.fundamento_legal }}
                                    </p>
                                </div>
                                <div class="flex flex-wrap items-center gap-1">
                                    <Button
                                        v-if="puede.ordenar"
                                        size="icon"
                                        variant="ghost"
                                        :disabled="i === 0"
                                        aria-label="Subir"
                                        @click="mover(c, 'arriba')"
                                        ><ArrowUp class="size-4"
                                    /></Button>
                                    <Button
                                        v-if="puede.ordenar"
                                        size="icon"
                                        variant="ghost"
                                        :disabled="i === lista.length - 1"
                                        aria-label="Bajar"
                                        @click="mover(c, 'abajo')"
                                        ><ArrowDown class="size-4"
                                    /></Button>
                                    <Button
                                        v-if="puede.activar"
                                        size="sm"
                                        variant="outline"
                                        @click="alternar(c)"
                                        >{{
                                            c.activo ? 'Desactivar' : 'Activar'
                                        }}</Button
                                    >
                                    <Button
                                        v-if="puede.editar"
                                        size="icon"
                                        variant="ghost"
                                        aria-label="Editar"
                                        @click="editar(c)"
                                        ><Pencil class="size-4"
                                    /></Button>
                                </div>
                            </li>
                        </template>
                    </ul>
                </template>
            </section>
        </div>

        <section
            v-if="historial.length"
            class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4 text-sm"
            aria-label="Historial del catálogo"
        >
            <h3 class="mb-2 flex items-center gap-2 font-semibold">
                <History class="size-4" />Historial del catálogo
            </h3>
            <ul class="grid max-h-56 gap-1 overflow-y-auto text-xs">
                <li v-for="h in historial" :key="h.id">
                    {{ h.accion }} <strong>{{ h.nombre }}</strong>
                    <span class="text-[var(--mrl-texto-suave)]">
                        · {{ h.usuario ?? 'Sistema' }} ·
                        {{ formatearFechaHora(h.fecha) }}</span
                    >
                </li>
            </ul>
        </section>

        <section
            class="rounded-2xl border border-border/60 bg-menta/40 p-4 text-sm"
            aria-label="Etiquetas del catálogo"
        >
            <h3 class="mb-2 font-semibold">Etiquetas del catálogo</h3>
            <p class="mb-1">
                <ChipEstado
                    etiqueta="Integrar expediente probatorio"
                    tono="oro"
                />
                aviso para que RH resguarde evidencia por si hay demanda. No es
                una autorización.
            </p>
            <p class="mb-1">
                <ChipEstado etiqueta="Implica indemnización" tono="peligro" />
                el finiquito se propone como liquidación, con revisión de
                RH/Jurídico.
            </p>
            <p>
                <ChipEstado etiqueta="Comentario obligatorio" tono="neutro" />
                y
                <ChipEstado etiqueta="Alerta" tono="alerta" />
                se conservan como hoy.
            </p>
        </section>
    </div>

    <Dialog v-model:open="abierto">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{
                    editando
                        ? `Editar causal ${editando.clave ?? ''}`
                        : 'Nueva causal'
                }}</DialogTitle>
                <DialogDescription
                    >Se elige al registrar una baja de esta forma de
                    salida.</DialogDescription
                >
            </DialogHeader>

            <form class="grid gap-4" @submit.prevent="guardar">
                <div class="grid gap-1.5">
                    <Label for="causal-tipo">Forma de salida</Label>
                    <SelectSimple
                        id="causal-tipo"
                        v-model="form.tipo"
                        :opciones="tipos"
                        :disabled="Boolean(editando?.usos)"
                    />
                    <p
                        v-if="editando?.usos"
                        class="text-xs text-[var(--mrl-texto-suave)]"
                    >
                        Ya se usó en bajas: no cambia de forma de salida.
                    </p>
                    <InputError :message="form.errors.tipo" />
                </div>
                <div v-if="gruposDelForm.length" class="grid gap-1.5">
                    <Label for="causal-grupo">Subcatálogo (opcional)</Label>
                    <SelectSimple
                        id="causal-grupo"
                        v-model="form.causal_grupo_id"
                        :opciones="gruposDelForm"
                        numerico
                        opcion-vacia="Sin subcatálogo"
                        placeholder="Sin subcatálogo"
                    />
                    <InputError :message="form.errors.causal_grupo_id" />
                </div>
                <div v-if="documentosDelForm.length > 1" class="grid gap-1.5">
                    <Label for="causal-documento">Documento que genera</Label>
                    <SelectSimple
                        id="causal-documento"
                        v-model="form.documento_salida"
                        :opciones="documentosDelForm"
                        opcion-vacia="El de la forma de salida"
                        placeholder="El de la forma de salida"
                    />
                    <InputError :message="form.errors.documento_salida" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="causal-nombre">Nombre</Label>
                    <Input
                        id="causal-nombre"
                        v-model="form.nombre"
                        maxlength="80"
                    />
                    <InputError :message="form.errors.nombre" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="causal-descripcion"
                        >Cuándo usarla (ayuda en pantalla)</Label
                    >
                    <Textarea
                        id="causal-descripcion"
                        v-model="form.descripcion"
                        rows="2"
                        maxlength="300"
                    />
                    <InputError :message="form.errors.descripcion" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="causal-alerta">Alerta (opcional)</Label>
                    <Input
                        id="causal-alerta"
                        v-model="form.alerta"
                        maxlength="200"
                        placeholder="p. ej. Aviso a Contraloría"
                    />
                    <InputError :message="form.errors.alerta" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="causal-fundamento"
                        >Fundamento legal (opcional)</Label
                    >
                    <Input
                        id="causal-fundamento"
                        v-model="form.fundamento_legal"
                        maxlength="255"
                        placeholder="p. ej. Art. 47, fracción XIII, LFT"
                    />
                    <InputError :message="form.errors.fundamento_legal" />
                </div>
                <label class="flex items-start gap-2 text-sm">
                    <Casilla v-model="form.requiere_juridico" />
                    <span
                        >Integrar expediente probatorio (aviso para que RH
                        resguarde evidencia por si hay demanda; no es una
                        autorización de Jurídico)</span
                    >
                </label>
                <label class="flex items-start gap-2 text-sm">
                    <Casilla v-model="form.implica_indemnizacion" />
                    <span
                        >Implica indemnización: el finiquito se calcula como
                        liquidación (sujeto a validación jurídica de cada
                        caso)</span
                    >
                </label>
                <label class="flex items-start gap-2 text-sm">
                    <Casilla v-model="form.comentario_obligatorio" />
                    <span>Comentario obligatorio (mínimo 20 caracteres)</span>
                </label>
                <label class="flex items-start gap-2 text-sm">
                    <Casilla v-model="form.excluye_indemnizacion" />
                    <span
                        >No estimar indemnización en el finiquito (p. ej. faltas
                        o abandono). La renuncia nunca la estima.</span
                    >
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <Casilla v-model="form.activo" />
                    <span>Activa (se ofrece al registrar una baja)</span>
                </label>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="abierto = false"
                        >Cancelar</Button
                    >
                    <Button type="submit" :disabled="form.processing">{{
                        editando ? 'Guardar cambios' : 'Agregar causal'
                    }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="grupoAbierto">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{
                    grupoEditando ? 'Editar subcatálogo' : 'Nuevo subcatálogo'
                }}</DialogTitle>
                <DialogDescription
                    >Agrupa causales dentro de esta forma de salida, por ejemplo
                    «Pensión» o «Enfermedad» en Otras
                    terminaciones.</DialogDescription
                >
            </DialogHeader>
            <form class="grid gap-4" @submit.prevent="guardarGrupo">
                <div class="grid gap-1.5">
                    <Label for="grupo-nombre">Nombre</Label>
                    <Input
                        id="grupo-nombre"
                        v-model="formGrupo.nombre"
                        maxlength="80"
                    />
                    <InputError :message="formGrupo.errors.nombre" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="grupo-descripcion"
                        >Descripción (opcional)</Label
                    >
                    <Input
                        id="grupo-descripcion"
                        v-model="formGrupo.descripcion"
                        maxlength="300"
                    />
                    <InputError :message="formGrupo.errors.descripcion" />
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="grupoAbierto = false"
                        >Cancelar</Button
                    >
                    <Button type="submit" :disabled="formGrupo.processing">{{
                        grupoEditando ? 'Guardar' : 'Agregar'
                    }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
