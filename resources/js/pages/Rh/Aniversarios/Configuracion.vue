<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Save } from '@lucide/vue';
import { ref } from 'vue';
import CelebracionConfiguracionLayout from '@/components/Celebraciones/CelebracionConfiguracionLayout.vue';
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
 * {nombre}, {sucursal}; fondo propio; activo; envío automático). Mismo
 * diseño que la configuración de Cumpleaños.
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
            form.quitar_fondo = false;
            version.value = Date.now();
        },
    });
}
</script>

<template>
    <Head title="Configuración de aniversarios" />

    <CelebracionConfiguracionLayout titulo="Tarjeta de aniversario" :volver-url="indexAniversarios.url()" :vista-previa-url="vistaPrevia.url()" :version="version">
        <form class="flex flex-col gap-6" @submit.prevent="enviar">
            <section class="flex flex-col gap-3" aria-label="Envío">
                <label class="flex items-start gap-2 text-sm">
                    <Checkbox class="mt-0.5" :model-value="form.activo" @update:model-value="(v) => (form.activo = !!v)" />
                    Aniversarios activos (preparar las tarjetas cada día)
                </label>
                <label class="flex items-start gap-2 text-sm">
                    <Checkbox class="mt-0.5" :model-value="form.auto_enviar_colaborador" @update:model-value="(v) => (form.auto_enviar_colaborador = !!v)" />
                    Enviar automáticamente la felicitación al colaborador (nunca avisa a todos sin que RH lo decida)
                </label>
            </section>

            <section class="grid gap-1.5 border-t pt-6">
                <Label for="mensaje">Mensaje institucional</Label>
                <Textarea id="mensaje" v-model="form.mensaje" rows="5" />
                <p class="text-xs text-muted-foreground">
                    Usa <code>{anios}</code> (se escribe «6 años»), <code>{nombre}</code> y <code>{sucursal}</code>.
                    <button type="button" class="ml-1 text-primary underline" @click="form.mensaje = mensajePredeterminado">Restaurar predeterminado</button>
                </p>
                <InputError :message="form.errors.mensaje" />
            </section>

            <section class="grid gap-1.5 border-t pt-6">
                <Label for="fondo">Fondo propio <span class="font-normal text-muted-foreground">(opcional, vertical 1080×1350)</span></Label>
                <Input id="fondo" type="file" accept="image/png,image/jpeg,image/webp" @change="(e: Event) => (form.fondo = (e.target as HTMLInputElement).files?.[0] ?? null)" />
                <InputError :message="form.errors.fondo" />
                <label v-if="configuracion.tiene_fondo" class="flex items-center gap-2 text-sm">
                    <Checkbox :model-value="form.quitar_fondo" @update:model-value="(v) => (form.quitar_fondo = !!v)" />
                    Quitar el fondo propio y usar el diseño de globos
                </label>
            </section>

            <div class="border-t pt-4">
                <Button type="submit" :disabled="form.processing">
                    <Spinner v-if="form.processing" />
                    <Save v-else class="size-4" />
                    Guardar
                </Button>
            </div>
        </form>
    </CelebracionConfiguracionLayout>
</template>
