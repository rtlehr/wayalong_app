<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import WayAlongStatusBanner from '@/components/WayAlongStatusBanner.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import { Toaster } from '@/components/ui/sonner';
import type { BreadcrumbItem } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

const page = usePage();
const isAdminWorkspace = computed(() => /^(?:\/admin(?:\/|$)|\/organization\/admin(?:\/|$)|\/platform(?:\/|$))/.test(page.url.split('?')[0]));

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <AppContent variant="sidebar" :class="isAdminWorkspace ? 'min-w-0 overflow-x-clip wayalong-workspace wayalong-admin-workspace' : 'min-w-0 overflow-x-clip wayalong-workspace'">
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <WayAlongStatusBanner />
            <slot />
        </AppContent>
        <Toaster />
    </AppShell>
</template>
