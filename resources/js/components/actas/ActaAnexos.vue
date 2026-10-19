<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { FileUp, Paperclip } from '@lucide/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import anexos from '@/routes/rh/actas/anexos';
import type { ActaDetalle } from '@/types';

/** Anexos (evidencias) del acta: se relacionan en el PDF tal como se guardan aquí. */
const props = defineProps<{
    acta: ActaDetalle;
    editable: boolean;
}>();

const anexo = useForm<{
    archivo: File | null;
    descripcion: string;
    fecha_identificacion: string;
    relacion_hechos: string;
}>({
    archivo: null,
    descripcion: '',
    fecha_identificacion: '',
    relacion_hechos: '',
});
// Cambiar la llave vacía el input de archivo después de subir.
const llaveArchivo = ref(0);

function subir() {
    anexo.post(anexos.store.url(props.acta.id), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            anexo.reset();
            llaveArchivo.value++;
        },
    });
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <ol v-if="acta.anexos.length" class="flex flex-col gap-2">
            <li v-for="(a, i) in acta.anexos" :key="a.id">
                <a
                    :href="anexos.show.url({ acta: acta.id, anexo: a.id })"
                    target="_blank"
                    rel="noopener"
                    class="flex tarjeta-interactiva items-start gap-3 rounded-xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-3 text-sm"
                >
                    <span
                        class="flex size-7 shrink-0 items-center justify-center rounded-full bg-crema text-xs font-semibold text-[var(--mrl-petroleo)]"
                        >{{ i + 1 }}</span
                    >
                    <span class="min-w-0 flex-1">
                        <span class="block font-medium">{{
                            a.descripcion || a.nombre
                        }}</span>
                        <span
                            class="flex items-center gap-1 text-xs text-muted-foreground"
                            ><Paperclip class="size-3" /> {{ a.nombre }}</span
                        >
                        <span
                            v-if="a.fecha_identificacion"
                            class="block text-xs text-muted-foreground"
                            >{{ a.fecha_identificacion }}</span
                        >
                        <span v-if="a.relacion_hechos" class="block text-xs">{{
                            a.relacion_hechos
                        }}</span>
                    </span>
                </a>
            </li>
        </ol>
        <p v-else class="text-sm text-muted-foreground italic">
            No se incorporaron anexos.
        </p>

        <form
            v-if="editable"
            class="flex flex-col gap-3 rounded-xl border border-dashed border-[var(--mrl-borde)] p-4 transition-colors hover:border-oro/60"
            @submit.prevent="subir"
        >
            <p class="flex items-center gap-1.5 text-sm font-medium">
                <FileUp class="size-4 text-[var(--mrl-petroleo)]" /> Agregar
                anexo
            </p>
            <div class="flex flex-col gap-1.5">
                <Label for="anexo-archivo">Archivo</Label>
                <Input
                    id="anexo-archivo"
                    :key="llaveArchivo"
                    type="file"
                    @change="
                        anexo.archivo =
                            ($event.target as HTMLInputElement).files?.[0] ??
                            null
                    "
                />
                <InputError :message="anexo.errors.archivo" />
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="flex flex-col gap-1.5">
                    <Label for="anexo-descripcion">Descripción</Label>
                    <Input
                        id="anexo-descripcion"
                        v-model="anexo.descripcion"
                        maxlength="255"
                        placeholder="Ej. Reporte de arqueo de caja"
                    />
                    <InputError :message="anexo.errors.descripcion" />
                </div>
                <div class="flex flex-col gap-1.5">
                    <Label for="anexo-fecha">Fecha o identificación</Label>
                    <Input
                        id="anexo-fecha"
                        v-model="anexo.fecha_identificacion"
                        maxlength="160"
                        placeholder="Ej. 13/10/2026 · folio ARQ-0451"
                    />
                    <InputError :message="anexo.errors.fecha_identificacion" />
                </div>
            </div>
            <div class="flex flex-col gap-1.5">
                <Label for="anexo-relacion">Relación con los hechos</Label>
                <Input
                    id="anexo-relacion"
                    v-model="anexo.relacion_hechos"
                    maxlength="500"
                    placeholder="Qué acredita o documenta"
                />
                <InputError :message="anexo.errors.relacion_hechos" />
            </div>
            <Button
                type="submit"
                variant="outline"
                class="self-start"
                :disabled="!anexo.archivo || anexo.processing"
            >
                <Spinner v-if="anexo.processing" class="size-4" /> Subir anexo
            </Button>
        </form>
    </div>
</template>
