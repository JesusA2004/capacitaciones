<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Info, Upload } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import PeopleFileDropzone from '@/components/people/PeopleFileDropzone.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { dashboard } from '@/routes';
import { index as indexFormatos, store } from '@/routes/rh/formatos-oficiales';
import type { OpcionCatalogo } from '@/types';

const props = defineProps<{
    categorias: OpcionCatalogo[];
    aplicaA: OpcionCatalogo[];
    empresas: { id: number; nombre: string }[];
    maxMb: number;
    conversionWordFiel: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Formatos', href: indexFormatos() },
            { title: 'Nueva plantilla', href: '' },
        ],
    },
});

const form = useForm<{
    nombre: string;
    tipo: string;
    aplica_a: string;
    empresa_id: string;
    descripcion: string;
    archivo: File | null;
}>({
    nombre: '',
    tipo: props.categorias[0]?.value ?? 'otro',
    aplica_a: 'colaborador',
    empresa_id: '',
    descripcion: '',
    archivo: null,
});

function enviar() {
    form.transform((datos) => ({ ...datos, empresa_id: datos.empresa_id || null })).post(store.url(), { forceFormData: true });
}
</script>

<template>
    <Head title="Nueva plantilla" />

    <div class="mx-auto flex w-full max-w-3xl flex-col gap-5 p-4 sm:p-6">
        <div class="flex items-center gap-2">
            <Button as-child variant="ghost" size="icon"><Link :href="indexFormatos()" aria-label="Volver"><ArrowLeft class="size-4" /></Link></Button>
            <h1 class="text-xl font-semibold tracking-tight">Nueva plantilla</h1>
        </div>

        <form class="flex flex-col gap-4 rounded-2xl border p-4 sm:p-6" @submit.prevent="enviar">
            <div class="grid gap-1.5">
                <Label for="nombre">Nombre</Label>
                <Input id="nombre" v-model="form.nombre" placeholder="Contrato individual de trabajo" required />
                <InputError :message="form.errors.nombre" />
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div class="grid gap-1.5">
                    <Label>Categoría</Label>
                    <NativeSelect v-model="form.tipo">
                        <option v-for="c in categorias" :key="c.value" :value="c.value">{{ c.etiqueta }}</option>
                    </NativeSelect>
                    <InputError :message="form.errors.tipo" />
                </div>
                <div class="grid gap-1.5">
                    <Label>Aplica a</Label>
                    <NativeSelect v-model="form.aplica_a">
                        <option v-for="a in aplicaA" :key="a.value" :value="a.value">{{ a.etiqueta }}</option>
                    </NativeSelect>
                </div>
                <div class="grid gap-1.5">
                    <Label>Empresa</Label>
                    <NativeSelect v-model="form.empresa_id">
                        <option value="">Todas</option>
                        <option v-for="e in empresas" :key="e.id" :value="String(e.id)">{{ e.nombre }}</option>
                    </NativeSelect>
                </div>
            </div>

            <div class="grid gap-1.5">
                <Label for="descripcion">Cuándo se usa (opcional)</Label>
                <Textarea id="descripcion" v-model="form.descripcion" rows="2" />
            </div>

            <div class="grid gap-1.5">
                <Label>Archivo base</Label>
                <PeopleFileDropzone
                    :model-value="form.archivo ? [form.archivo] : []"
                    accept=".pdf,.docx,.png,.jpg,.jpeg,.webp"
                    :max-size-mb="maxMb"
                    hint="PDF, Word (DOCX) o imagen PNG/JPG/WEBP"
                    @update:model-value="(archivos: File[]) => (form.archivo = archivos[0] ?? null)"
                />
                <InputError :message="form.errors.archivo" />
            </div>

            <div class="flex items-start gap-2 rounded-xl bg-muted/50 p-3 text-sm text-muted-foreground">
                <Info class="mt-0.5 size-4 shrink-0" />
                <span>
                    Al subirlo, el sistema analiza el documento y sugiere qué dato va en cada lugar. Tú confirmas el mapeo y publicas la
                    versión antes de que se pueda usar. Un Word con marcadores <code v-pre>{{curp}}</code> se rellena dentro del propio Word.
                    <template v-if="!conversionWordFiel"> Para Word sin marcadores, la vista puede variar un poco: si necesitas copia exacta, súbelo como PDF.</template>
                </span>
            </div>

            <div class="flex justify-end">
                <Button type="submit" :disabled="form.processing || !form.archivo || !form.nombre">
                    <Spinner v-if="form.processing" />
                    <Upload v-else class="size-4" />
                    Subir y analizar
                </Button>
            </div>
        </form>
    </div>
</template>
