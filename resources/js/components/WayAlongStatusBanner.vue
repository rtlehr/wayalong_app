<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { X } from '@lucide/vue';

type StatusBanner = {
    success?: string | null;
    error?: string | null;
    warning?: string | null;
    info?: string | null;
};

const page = usePage();
const dismissed = ref(false);
const messages = computed(() => (page.props as { statusBanner?: StatusBanner }).statusBanner ?? {});
const selected = computed(() => {
    for (const type of ['error', 'warning', 'success', 'info'] as const) {
        const message = messages.value[type];
        if (typeof message === 'string' && message.trim()) return { type, message };
    }
    return null;
});

// A new page response (including a repeated identical message) must be shown again.
watch(() => page.props.statusBanner, () => { dismissed.value = false; });

const styles = {
    success: 'border-green-200 bg-green-50 text-green-800 dark:border-green-800 dark:bg-green-950 dark:text-green-200',
    error: 'border-red-200 bg-red-50 text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-200',
    warning: 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200',
    info: 'border-blue-200 bg-blue-50 text-blue-800 dark:border-blue-800 dark:bg-blue-950 dark:text-blue-200',
};
</script>

<template>
    <div
        v-if="selected && !dismissed"
        role="status"
        aria-live="polite"
        :class="['mx-4 mt-3 flex items-center justify-between gap-4 rounded-md border px-4 py-4 text-sm', styles[selected.type]]"
    >
        <span>{{ selected.message }}</span>
        <button
            type="button"
            class="shrink-0 rounded p-1 focus-visible:outline-2 focus-visible:outline-offset-2"
            aria-label="Dismiss status message"
            @click="dismissed = true"
        >
            <X class="size-4" />
        </button>
    </div>
</template>
