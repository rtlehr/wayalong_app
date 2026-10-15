<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Building2, Check, ChevronsUpDown, LoaderCircle } from '@lucide/vue';
import { computed, ref } from 'vue';
import {
    DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel,
    DropdownMenuSeparator, DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';

type OrganizationOption = { id: number; name: string; slug: string; role: string };
type OrganizationContextProps = {
    active: { id: number; name: string; slug: string } | null;
    available: OrganizationOption[];
};

const page = usePage();
const context = computed<OrganizationContextProps>(() =>
    (page.props.organizationContext as OrganizationContextProps | undefined) ?? { active: null, available: [] },
);
const switching = ref(false);
const active = computed(() => context.value.active);
const available = computed(() => context.value.available);

function switchOrganization(id: number): void {
    if (switching.value || id === active.value?.id || !available.value.some((organization) => organization.id === id)) {
        return;
    }
    switching.value = true;
    router.post('/organizations/switch', { organization_id: id }, {
        preserveScroll: false,
        onFinish: () => { switching.value = false; },
    });
}
</script>

<template>
    <SidebarMenu>
        <SidebarMenuItem>
            <DropdownMenu v-if="available.length > 1">
                <DropdownMenuTrigger as-child>
                    <SidebarMenuButton size="lg" :disabled="switching" tooltip="Switch organization" data-test="organization-switcher">
                        <Building2 class="size-4" />
                        <span class="min-w-0 flex-1 truncate text-left">{{ active?.name ?? 'Select organization' }}</span>
                        <LoaderCircle v-if="switching" class="ml-auto size-4 animate-spin" />
                        <ChevronsUpDown v-else class="ml-auto size-4" />
                    </SidebarMenuButton>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start" class="min-w-56 max-w-80">
                    <DropdownMenuLabel>Working in organization</DropdownMenuLabel>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        v-for="organization in available"
                        :key="organization.id"
                        :disabled="switching"
                        :data-test="`organization-option-${organization.id}`"
                        @select="switchOrganization(organization.id)"
                    >
                        <span class="min-w-0 flex-1 truncate">{{ organization.name }}</span>
                        <Check v-if="organization.id === active?.id" class="ml-auto size-4" aria-label="Current organization" />
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
            <SidebarMenuButton v-else size="lg" :disabled="true" data-test="organization-current">
                <Building2 class="size-4" />
                <span class="min-w-0 flex-1 truncate text-left">{{ active?.name ?? 'No active organization' }}</span>
            </SidebarMenuButton>
        </SidebarMenuItem>
    </SidebarMenu>
</template>
