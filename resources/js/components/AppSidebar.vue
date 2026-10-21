<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { BookOpen, FolderGit2, LayoutGrid, Building2, PanelsTopLeft, ShieldCheck, Mail } from '@lucide/vue';
import AppLogo from '@/components/AppLogo.vue';
import OrganizationSwitcher from '@/components/OrganizationSwitcher.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

const page = usePage();
const isPlatformAdmin = computed(() => (page.props.auth as { user?: { is_platform_admin?: boolean } } | undefined)?.user?.is_platform_admin === true);

const mainNavItems = computed<NavItem[]>(() => [
    {
        title: isPlatformAdmin.value ? 'Platform Dashboard' : 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    { title: 'Message Center', href: '/messages', icon: Mail },
    ...(isPlatformAdmin.value ? [{ title: 'Organization Management', href: '/platform/organizations', icon: Building2 }] : []),
    ...(['owner', 'admin'].includes(((page.props.organizationContext as { active?: { id: number } | null; available?: { id: number; role: string }[] } | undefined)?.available ?? []).find((org) => org.id === (page.props.organizationContext as { active?: { id: number } | null } | undefined)?.active?.id)?.role ?? '') ? [{ title: 'Admin Command Center', href: '/admin', icon: PanelsTopLeft }, { title: 'Organization Settings', href: '/organization/admin', icon: Building2 }] : []),
]);

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/laravel/vue-starter-kit',
        icon: FolderGit2,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#vue',
        icon: BookOpen,
    },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <OrganizationSwitcher />
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
