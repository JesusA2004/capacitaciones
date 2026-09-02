<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Download, IdCard, Pencil, Upload, UserRound } from '@lucide/vue';
import { ref } from 'vue';
import EstadoBadge from '@/components/Common/EstadoBadge.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import CandidatoFormDialog from '@/components/Rh/CandidatoFormDialog.vue';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useAlertas } from '@/composables/useAlertas';
import { dashboard } from '@/routes';
import { store as generarAlta } from '@/routes/rh/altas';
import { cv, estado as estadoUrl, index } from '@/routes/rh/candidatos';
import { descargar as descargarCv } from '@/routes/rh/candidatos/cv';
import seguimientos from '@/routes/rh/candidatos/seguimientos';
import type { CandidatoDetalle, OpcionesReclutamiento } from '@/types';

const props = defineProps<{
    candidato: CandidatoDetalle;
    opciones: OpcionesReclutamiento;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Candidatos', href: index.url() },
            { title: props.candidato.nombre, href: '' },
        ],
    },
});

const { mostrarError } = useAlertas();
const dialogoAbierto = ref(false);

const formEstado = useForm({ estado: props.candidato.estado, nota: '' });

function cambiarEstado() {
    formEstado.put(estadoUrl.url(props.candidato.id), {
        preserveScroll: true,
        onSuccess: () => (formEstado.nota = ''),
        onError: () =>
            mostrarError(
                'No tienes permiso para aplicar ese cambio de estado.',
            ),
    });
}

const formSeguimiento = useForm({ tipo: 'nota', nota: '', fecha: '' });

function agregarSeguimiento() {
    formSeguimiento.post(seguimientos.store.url(props.candidato.id), {
        preserveScroll: true,
        onSuccess: () => formSeguimiento.reset(),
    });
}

const formCv = useForm({ cv: null as File | null });

function subirCv(event: Event) {
    const input = event.target as HTMLInputElement;
    formCv.cv = input.files?.[0] ?? null;

    if (!formCv.cv) {
        return;
    }

    formCv.post(cv.url(props.candidato.id), {
        preserveScroll: true,
        forceFormData: true,
    });
}

const formAlta = useForm({ candidato_id: props.candidato.id });

function generarAltaDigital() {
    formAlta.post(generarAlta.url());
}
</script>

<template>
    <Head :title="candidato.nombre" />

    <div class="flex flex-col gap-6 p-4">
        <CrudPageHeader
            :titulo="`${candidato.nombre} ${candidato.apellidos ?? ''}`"
            :descripcion="
                candidato.puesto_objetivo?.nombre ?? 'Sin puesto objetivo'
            "
            :icono="UserRound"
        >
            <Button
                v-if="candidato.estado === 'aprobado_rh'"
                :disabled="formAlta.processing"
                @click="generarAltaDigital"
            >
                <IdCard class="size-4" />
                Generar alta digital
            </Button>
            <Button variant="secondary" @click="dialogoAbierto = true">
                <Pencil class="size-4" />
                Editar
            </Button>
        </CrudPageHeader>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="flex flex-col gap-4 lg:col-span-2">
                <div class="rounded-2xl border border-border/60 bg-card p-4">
                    <h2 class="mb-3 text-sm font-semibold">
                        Datos de contacto
                    </h2>
                    <dl class="grid grid-cols-2 gap-3 text-sm">
                        <div>
                            <dt class="text-muted-foreground">Teléfono</dt>
                            <dd>{{ candidato.telefono ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Correo</dt>
                            <dd>{{ candidato.correo ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Sucursal</dt>
                            <dd>{{ candidato.sucursal?.nombre ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">
                                Fuente de reclutamiento
                            </dt>
                            <dd>{{ candidato.fuente ?? '—' }}</dd>
                        </div>
                        <div class="col-span-2">
                            <dt class="text-muted-foreground">Observaciones</dt>
                            <dd>{{ candidato.observaciones ?? '—' }}</dd>
                        </div>
                    </dl>
                </div>

                <div class="rounded-2xl border border-border/60 bg-card p-4">
                    <h2 class="mb-3 text-sm font-semibold">Currículum (CV)</h2>
                    <div class="flex items-center gap-3">
                        <a
                            v-if="candidato.tiene_cv"
                            :href="descargarCv.url(candidato.id)"
                            class="inline-flex items-center gap-1.5 text-sm text-[var(--brand-primary)] hover:underline"
                        >
                            <Download class="size-4" />
                            {{ candidato.cv_original_name ?? 'Descargar CV' }}
                        </a>
                        <p v-else class="text-sm text-muted-foreground">
                            Sin CV cargado.
                        </p>

                        <label
                            class="ml-auto inline-flex cursor-pointer items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                        >
                            <Upload class="size-4" />
                            {{ candidato.tiene_cv ? 'Reemplazar' : 'Subir CV' }}
                            <input
                                type="file"
                                accept=".pdf,.doc,.docx"
                                class="hidden"
                                @change="subirCv"
                            />
                        </label>
                    </div>
                </div>

                <div class="rounded-2xl border border-border/60 bg-card p-4">
                    <h2 class="mb-3 text-sm font-semibold">Seguimiento</h2>

                    <form
                        class="mb-4 flex flex-col gap-2"
                        @submit.prevent="agregarSeguimiento"
                    >
                        <Textarea
                            v-model="formSeguimiento.nota"
                            placeholder="Agregar una nota de seguimiento..."
                            rows="2"
                        />
                        <Button
                            type="submit"
                            size="sm"
                            class="w-fit"
                            :disabled="formSeguimiento.processing"
                            >Agregar nota</Button
                        >
                    </form>

                    <ol class="flex flex-col gap-3 border-l pl-4">
                        <li
                            v-for="item in candidato.seguimientos"
                            :key="item.id"
                            class="text-sm"
                        >
                            <p class="text-xs text-muted-foreground">
                                {{ new Date(item.fecha).toLocaleString() }} ·
                                {{ item.registrado_por?.name ?? 'Sistema' }}
                            </p>
                            <p v-if="item.estado_nuevo">
                                Cambio de estado:
                                <strong>{{ item.estado_nuevo }}</strong>
                            </p>
                            <p v-if="item.nota">{{ item.nota }}</p>
                        </li>
                    </ol>
                </div>
            </div>

            <div class="flex flex-col gap-4">
                <div class="rounded-2xl border border-border/60 bg-card p-4">
                    <h2 class="mb-3 text-sm font-semibold">Estado actual</h2>
                    <EstadoBadge :estado="candidato.estado" />

                    <form
                        class="mt-4 flex flex-col gap-2"
                        @submit.prevent="cambiarEstado"
                    >
                        <Select v-model="formEstado.estado">
                            <SelectTrigger class="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="opcion in opciones.estados"
                                    :key="opcion.value"
                                    :value="opcion.value"
                                    >{{ opcion.etiqueta }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                        <Textarea
                            v-model="formEstado.nota"
                            placeholder="Nota del cambio (opcional)"
                            rows="2"
                        />
                        <Button
                            type="submit"
                            size="sm"
                            :disabled="formEstado.processing"
                            >Actualizar estado</Button
                        >
                    </form>
                </div>
            </div>
        </div>
    </div>

    <CandidatoFormDialog
        v-if="dialogoAbierto"
        v-model:open="dialogoAbierto"
        :candidato="candidato"
        :opciones="opciones"
    />
</template>
