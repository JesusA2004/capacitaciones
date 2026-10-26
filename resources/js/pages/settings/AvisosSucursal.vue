<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { BellOff, Search } from '@lucide/vue';
import { computed, ref } from 'vue';
import Casilla from '@/components/Common/Casilla.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { edit, update } from '@/routes/avisos-sucursal';

type SucursalAviso = {
    id: number;
    nombre: string;
    es_corporativo: boolean;
    silenciada: boolean;
};

const props = defineProps<{ sucursales: SucursalAviso[] }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Avisos por sucursal', href: edit() }],
    },
});

const form = useForm({
    sucursales: props.sucursales.filter((s) => s.silenciada).map((s) => s.id),
});

// Buscador: lista completa al enfocar, filtra mientras se escribe.
const busqueda = ref('');
const normalizar = (texto: string) =>
    texto.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
const visibles = computed(() => {
    const termino = normalizar(busqueda.value.trim());

    return termino === ''
        ? props.sucursales
        : props.sucursales.filter((s) =>
              normalizar(s.nombre).includes(termino),
          );
});

const silenciadas = computed(() => form.sucursales.length);

function silenciarVisibles(silenciar: boolean) {
    const ids = visibles.value.map((s) => s.id);
    const resto = form.sucursales.filter((id) => !ids.includes(id));
    form.sucursales = silenciar ? [...resto, ...ids] : resto;
}

function guardar() {
    form.put(update.url(), { preserveScroll: true });
}
</script>

<template>
    <Head title="Avisos por sucursal" />

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Avisos por sucursal"
            description="Marca las sucursales de las que NO quieres recibir avisos (campana y notificación push). Sin ninguna marcada, recibes de todas."
        />

        <p class="rounded-xl bg-salvia/50 p-3 text-sm text-muted-foreground">
            Silenciar no oculta nada: lo pendiente sigue en «Mis pendientes» y
            en cada bandeja. Siempre te llegan tus propias confirmaciones, lo
            que es sobre ti y lo de las personas de las que eres jefe directo.
        </p>

        <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
            <div class="relative flex-1">
                <Search
                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    v-model="busqueda"
                    class="pl-9"
                    placeholder="Buscar sucursal…"
                    aria-label="Buscar sucursal"
                />
            </div>
            <div class="flex gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="silenciarVisibles(true)"
                    >Silenciar las mostradas</Button
                >
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="silenciarVisibles(false)"
                    >Recibir de las mostradas</Button
                >
            </div>
        </div>

        <ul
            class="grid max-h-[28rem] gap-1 overflow-y-auto rounded-xl border border-border/60 p-2 sm:grid-cols-2"
        >
            <li v-for="sucursal in visibles" :key="sucursal.id">
                <label
                    class="flex min-h-10 cursor-pointer items-center gap-3 rounded-lg px-2 py-1.5 text-sm hover:bg-muted/60"
                >
                    <Casilla v-model="form.sucursales" :value="sucursal.id" />
                    <span class="min-w-0 flex-1 truncate">{{
                        sucursal.nombre
                    }}</span>
                    <span
                        v-if="sucursal.es_corporativo"
                        class="text-xs text-muted-foreground"
                        >Corporativo</span
                    >
                    <BellOff
                        v-if="form.sucursales.includes(sucursal.id)"
                        class="size-4 text-muted-foreground"
                        aria-label="Silenciada"
                    />
                </label>
            </li>
            <li
                v-if="visibles.length === 0"
                class="px-2 py-4 text-sm text-muted-foreground"
            >
                Ninguna sucursal coincide con «{{ busqueda }}».
            </li>
        </ul>
        <InputError :message="form.errors.sucursales" />

        <div class="flex flex-wrap items-center gap-3">
            <Button :disabled="form.processing" @click="guardar"
                >Guardar</Button
            >
            <span class="text-sm text-muted-foreground">
                {{
                    silenciadas === 0
                        ? 'Recibes avisos de todas las sucursales.'
                        : `${silenciadas} sucursal${silenciadas === 1 ? '' : 'es'} silenciada${silenciadas === 1 ? '' : 's'}.`
                }}
            </span>
        </div>
    </div>
</template>
