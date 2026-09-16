<script setup lang="ts">
import { Copy, KeyRound, ShieldAlert } from '@lucide/vue';
import { ref } from 'vue';
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
import { Spinner } from '@/components/ui/spinner';
import { useAlertas } from '@/composables/useAlertas';
import { postJson } from '@/lib/http';
import { establecerPassword } from '@/routes/administracion/usuarios';

const props = defineProps<{
    open: boolean;
    colaboradorId: number;
    colaboradorNombre: string;
}>();

const emit = defineEmits<{
    'update:open': [valor: boolean];
}>();

const { mostrarExito, mostrarError } = useAlertas();

const passwordPersonalizada = ref('');
const enviando = ref(false);
const passwordGenerada = ref<string | null>(null);

async function establecer() {
    enviando.value = true;

    try {
        const respuesta = await postJson<{ password: string }>(
            establecerPassword.url(props.colaboradorId),
            { password: passwordPersonalizada.value || null },
        );

        passwordGenerada.value = respuesta.password;
    } catch {
        mostrarError('No se pudo establecer la contraseña.');
    } finally {
        enviando.value = false;
    }
}

async function copiar() {
    if (!passwordGenerada.value) {
        return;
    }

    await navigator.clipboard.writeText(passwordGenerada.value);
    mostrarExito('Contraseña copiada al portapapeles.');
}

function cerrar() {
    passwordPersonalizada.value = '';
    passwordGenerada.value = null;
    emit('update:open', false);
}
</script>

<template>
    <Dialog :open="open" @update:open="(v) => (v ? emit('update:open', v) : cerrar())">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <KeyRound class="size-4 text-primary" />
                    Establecer contraseña
                </DialogTitle>
                <DialogDescription>
                    Para {{ colaboradorNombre }}. No es posible ver su
                    contraseña actual (se guarda cifrada); esto la
                    reemplaza por una nueva.
                </DialogDescription>
            </DialogHeader>

            <template v-if="!passwordGenerada">
                <div class="grid gap-2">
                    <Label for="password-personalizada"
                        >Contraseña (opcional)</Label
                    >
                    <Input
                        id="password-personalizada"
                        v-model="passwordPersonalizada"
                        type="text"
                        placeholder="Déjalo vacío para generar una automática"
                        autocomplete="off"
                    />
                    <p class="text-xs text-muted-foreground">
                        Si la dejas vacía, se genera una contraseña segura
                        aleatoria.
                    </p>
                </div>

                <DialogFooter>
                    <Button variant="outline" @click="cerrar">Cancelar</Button>
                    <Button :disabled="enviando" @click="establecer">
                        <Spinner v-if="enviando" />
                        Establecer contraseña
                    </Button>
                </DialogFooter>
            </template>

            <template v-else>
                <div
                    class="flex items-start gap-2 rounded-lg border border-warning/40 bg-warning/10 p-3 text-xs text-warning"
                >
                    <ShieldAlert class="size-4 shrink-0" />
                    Guarda esta contraseña ahora: no se volverá a mostrar.
                </div>

                <div class="flex items-center gap-2">
                    <Input :model-value="passwordGenerada" readonly class="font-mono" />
                    <Button variant="outline" size="icon" @click="copiar">
                        <Copy class="size-4" />
                    </Button>
                </div>

                <DialogFooter>
                    <Button @click="cerrar">Listo</Button>
                </DialogFooter>
            </template>
        </DialogContent>
    </Dialog>
</template>
