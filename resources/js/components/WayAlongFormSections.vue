<script setup lang="ts">
import { computed, ref } from 'vue';
const props = defineProps<{ sections: { id: string; title: string; description?: string; invalid?: boolean }[] }>();
const active = defineModel<string>('active', { default: '' });
const selected = computed(() => active.value || props.sections[0]?.id);
</script>
<template><div class="grid gap-5 md:grid-cols-[220px_minmax(0,1fr)]"><nav aria-label="Form sections" class="self-start rounded-xl border bg-card p-2"><button v-for="section in sections" :key="section.id" type="button" :aria-current="selected === section.id ? 'step' : undefined" class="mb-1 w-full rounded-lg px-3 py-3 text-left hover:bg-accent" :class="selected === section.id ? 'bg-primary text-primary-foreground hover:bg-primary' : ''" @click="active = section.id"><span class="flex items-center gap-2 text-sm font-medium"><span v-if="section.invalid" aria-label="Section has errors" class="text-destructive">●</span>{{ section.title }}</span><span v-if="section.description" class="block text-xs opacity-70">{{ section.description }}</span></button></nav><section class="min-w-0 rounded-xl border bg-card p-5"><slot :section="selected" /></section></div></template>
