<script setup lang="ts">
import { CheckCircle2, CircleDashed, ListChecks } from '@lucide/vue';
import { computed } from 'vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { OnboardingItem } from '@/types';

const props = defineProps<{
    onboarding: OnboardingItem[];
}>();

const onboardingPorcentaje = computed(() => {
    if (props.onboarding.length === 0) {
        return 0;
    }

    const completados = props.onboarding.filter(
        (item) => item.completado,
    ).length;

    return Math.round((completados / props.onboarding.length) * 100);
});
</script>

<template>
    <Card class="rounded-2xl border-border/60">
        <CardHeader>
            <CardTitle class="flex items-center gap-2 text-base">
                <ListChecks class="size-4" />
                Checklist de incorporación
            </CardTitle>
            <div class="flex items-center gap-2 pt-1">
                <div class="h-2 flex-1 overflow-hidden rounded-full bg-muted">
                    <div
                        class="h-full rounded-full bg-primary transition-[width] duration-(--mov-lento)"
                        :style="{
                            width: `${onboardingPorcentaje}%`,
                        }"
                    />
                </div>
                <span
                    class="text-xs font-semibold text-muted-foreground tabular-nums"
                    >{{ onboardingPorcentaje }}%</span
                >
            </div>
        </CardHeader>
        <CardContent class="flex flex-col gap-2">
            <div
                v-for="item in onboarding"
                :key="item.clave"
                class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm"
                :class="
                    item.completado ? 'bg-[var(--success)]/5' : 'bg-muted/40'
                "
            >
                <CheckCircle2
                    v-if="item.completado"
                    class="size-4 shrink-0 text-[var(--success)]"
                />
                <CircleDashed
                    v-else
                    class="size-4 shrink-0 text-muted-foreground"
                />
                <span
                    :class="{
                        'text-muted-foreground': !item.completado,
                    }"
                    >{{ item.etiqueta }}</span
                >
            </div>
        </CardContent>
    </Card>
</template>
