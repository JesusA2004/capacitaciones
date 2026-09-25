<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, RefreshCw, Save } from '@lucide/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { dashboard } from '@/routes';
import { index as indexAniversarios } from '@/routes/rh/aniversarios';
import { guardar, vistaPrevia } from '@/routes/rh/aniversarios/configuracion';

/**
 * Configuración de la tarjeta de aniversario (mensaje con {anios},
 * {nombre}, {sucursal}; fondo propio; activo; envío automático).
 */
const props = defineProps<{
    configuracion: { activo: boolean; mensaje: string | null; auto_enviar_colaborador: boolean; tiene_fondo: boolean };
    mensajePredeterminado: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Aniversarios', href: indexAniversarios() },
            { title: 'Configuración', href: '' },
        ],
    },
});

const form = useForm<{ activo: boolean; mensaje: string; auto_enviar_colaborador: boolean; fondo: File | null; quitar_fondo: boolean }>({
    activo: props.configuracion.activo,
    mensaje: props.configuracion.mensaje ?? props.mensajePredeterminado,
    auto_enviar_colaborador: props.configuracion.auto_enviar_colaborador,
    fondo: null,
    quitar_fondo: false,
});

const version = ref(Date.now());

function enviar() {
    form.post(guardar.url(), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.fondo = null;
            version.value = Date.now();
        },
    });
}
</script>

<template>
    <Head title="Configuración de aniversarios" />

    <div class="mx-auto grid w-full max-w-6xl grid-cols-1 gap-6 p-4 sm:p-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
        <form class="flex min-w-0 flex-col gap-4" @submit.prevent="enviar">
            <div class="flex items-center gap-2">
                <Button as-child variant="ghost" size="icon"><Link :href="indexAniversarios()" aria-label="Volver"><ArrowLeft class="size-4" /></Link></Button>
                <h1 class="text-xl font-semibold">Tarjeta de aniversario</h1>
            </div>

            <label class="flex items-center gap-2 text-sm">
                <Checkbox :model-value="form.activo" @update:model-value="(v) => (form.activo = !!v)" />
                Aniversarios activos (preparar tarjetas cada día)
            </label>
            <label class="flex items-center gap-2 text-sm">
                <Checkbox :model-value="form.auto_enviar_colaborador" @update:model-value="(v) => (form.auto_enviar_colaborador = !!v)" />
                Enviar automáticamente la felicitación al colaborador (nunca avisa a todos sin que RH lo decida)
            </label>

            <div class="grid gap-1.5">
                <Label for="mensaje">Mensaje institucional</Label>
                <Textarea id="mensaje" v-model="form.mensaje" rows="5" />
                <p class="text-xs text-muted-foreground">
                    Usa <code>{anios}</code> (se escribe «6 años»), <code>{nombre}</code> y <code>{sucursal}</code>.
                </p>
                <InputError :message="form.errors.mensaje" />
                <button type="button" class="w-fit text-xs text-primary underline" @click="form.mensaje = mensajePredeterminado">
                    Restaurar texto predeterminado
                </button>
            </div>

            <div class="grid gap-1.5">
                <Label for="fondo">Fondo propio (opcional, vertical 1080×1350)</Label>
                <Input id="fondo" type="file" accept="image/png,image/jpeg,image/webp" @change="(e: Event) => (form.fondo = (e.target as HTMLInputElement).files?.[0] ?? null)" />
                <InputError :message="form.errors.fondo" />
                <label v-if="configuracion.tiene_fondo" class="flex items-center gap-2 text-sm">
                    <Checkbox :model-value="form.quitar_fondo" @update:model-value="(v) => (form.quitar_fondo = !!v)" />
                    Quitar el fondo propio y usar el diseño de globos
                </label>
            </div>

            <div class="flex gap-2">
                <Button type="submit" :disabled="form.processing">
                    <Spinner v-if="form.processing" />
                    <Save v-else class="size-4" />
                    Guardar
                </Button>
            </div>
        </form>

        <aside class="flex flex-col gap-2">
            <div class="flex items-center justify-between">
                <p class="text-sm font-medium">Vista previa (datos de ejemplo)</p>
                <Button size="icon" variant="ghost" aria-label="Actualizar" @click="version = Date.now()"><RefreshCw class="size-4" /></Button>
            </div>
            <img :src="`${vistaPrevia.url()}?v=${version}`" alt="Vista previa de la tarjeta" class="w-full rounded-xl border" />
            <p class="text-xs text-muted-foreground">Guarda para ver los cambios del mensaje o del fondo.</p>
        </aside>
    </div>
</template>
