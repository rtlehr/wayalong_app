<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Building2, CheckCircle2, CirclePause, ArrowUpRight, Plus, Search, ShieldCheck } from '@lucide/vue';
import { computed, ref } from 'vue';

type Organization = { id: number; name: string; slug: string; status: string };
const props = defineProps<{
    stats: { organizations: number; activeOrganizations: number; inactiveOrganizations: number; facilities: number };
    organizations: Organization[];
}>();
const search = ref('');
const matches = computed(() => props.organizations.filter((org) =>
    `${org.name} ${org.slug}`.toLowerCase().includes(search.value.trim().toLowerCase()),
));
</script>

<template>
    <Head title="Platform Dashboard" />
    <main class="mx-auto w-full max-w-7xl space-y-6 p-4 md:p-8">
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div class="space-y-1">
                <p class="flex items-center gap-2 text-xs font-medium uppercase tracking-wide text-muted-foreground"><ShieldCheck class="size-4" /> Platform Administration</p>
                <h1 class="text-2xl font-semibold">WayAlong Platform Dashboard</h1>
                <p class="text-sm text-muted-foreground">Manage organizations and monitor platform enrollment across WayAlong.</p>
            </div>
            <Link href="/platform/organizations/create" class="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:opacity-90"><Plus class="size-4" /> Add organization</Link>
        </header>

        <section aria-label="Platform overview" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border bg-card p-5"><p class="flex items-center gap-2 text-sm text-muted-foreground"><Building2 class="size-4" /> Organizations</p><p class="mt-3 text-3xl font-semibold">{{ stats.organizations }}</p></div>
            <div class="rounded-xl border bg-card p-5"><p class="flex items-center gap-2 text-sm text-muted-foreground"><CheckCircle2 class="size-4" /> Active organizations</p><p class="mt-3 text-3xl font-semibold">{{ stats.activeOrganizations }}</p></div>
            <div class="rounded-xl border bg-card p-5"><p class="flex items-center gap-2 text-sm text-muted-foreground"><CirclePause class="size-4" /> Inactive organizations</p><p class="mt-3 text-3xl font-semibold">{{ stats.inactiveOrganizations }}</p></div>
            <div class="rounded-xl border bg-card p-5"><p class="flex items-center gap-2 text-sm text-muted-foreground"><Building2 class="size-4" /> Total facilities</p><p class="mt-3 text-3xl font-semibold">{{ stats.facilities }}</p></div>
        </section>

        <section class="space-y-4" aria-label="Platform admin command center">
            <div class="space-y-1"><h2 class="text-xl font-semibold">Admin Command Center</h2><p class="text-sm text-muted-foreground">Platform-wide tools available to your account.</p></div>
            <div class="rounded-xl border bg-card p-5">
                <div class="flex items-start justify-between gap-4">
                    <div class="space-y-1"><h3 class="font-semibold">Organization Management</h3><p class="text-sm text-muted-foreground">Create, update, deactivate, and safely remove customer organizations.</p></div>
                    <Link href="/platform/organizations" class="inline-flex shrink-0 items-center gap-1 rounded-md border px-3 py-2 text-sm hover:bg-accent">Open <ArrowUpRight class="size-4" /></Link>
                </div>
            </div>
        </section>

        <section class="space-y-4" aria-label="Organizations">
            <div class="flex flex-wrap items-center justify-between gap-3"><h2 class="text-xl font-semibold">Organizations</h2><Link href="/platform/organizations" class="text-sm underline underline-offset-4">Manage all organizations</Link></div>
            <label class="relative block max-w-lg"><Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" /><span class="sr-only">Search organizations</span><input v-model="search" type="search" placeholder="Search organizations" class="w-full rounded-md border bg-background py-2 pl-9 pr-3 text-sm" /></label>
            <div v-if="matches.length" class="grid gap-3 md:grid-cols-2">
                <Link v-for="org in matches" :key="org.id" :href="`/platform/organizations/${org.id}`" class="flex items-start justify-between gap-4 rounded-xl border bg-card p-4 transition-colors hover:bg-accent/40">
                    <div class="min-w-0 space-y-1"><h3 class="font-medium">{{ org.name }}</h3><p class="break-all text-xs text-muted-foreground">{{ org.slug }}</p><p class="text-xs capitalize text-muted-foreground">{{ org.status }}</p></div><ArrowUpRight class="size-4 shrink-0 text-muted-foreground" />
                </Link>
            </div>
            <p v-else class="rounded-xl border border-dashed p-6 text-sm text-muted-foreground">No organizations match your search.</p>
        </section>
    </main>
</template>
