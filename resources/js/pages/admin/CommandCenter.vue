<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue';
import { ArrowUpRight, Building2, CheckCircle2, Clock3, Search, ShieldCheck, Users, X } from '@lucide/vue';

type Tool = { key: string; title: string; description: string; section: string; href: string; keywords: string[] };
const props = defineProps<{
    organization: { id: number; name: string };
    tools: Tool[];
    canManagePlatform?: boolean;
    stats: { facilities: number; activeFacilities: number; staff: number; activeStaff: number; customRoles: number; pendingInvitations: number };
}>();
const query = ref('');
const searchEl = ref<HTMLInputElement | null>(null);
const selected = ref(0);
const recent = ref<string[]>([]);
const favorites = ref<string[]>([]);
const favoriteKey = computed(() => `wayalong.admin.favorites.${props.organization.id}`);
const favoriteTools = computed(() => favorites.value.map(key => props.tools.find(tool => tool.key === key)).filter((tool): tool is Tool => !!tool));
const toggleFavorite = (tool: Tool) => { favorites.value = favorites.value.includes(tool.key) ? favorites.value.filter(key => key !== tool.key) : [...favorites.value, tool.key]; try { window.localStorage.setItem(favoriteKey.value, JSON.stringify(favorites.value)); } catch { /* optional */ } };
const recentKey = computed(() => `wayalong.admin.recent.${props.organization.id}`);
const filtered = computed(() => props.tools.filter(tool => [tool.title, tool.description, tool.section, ...tool.keywords].join(' ').toLowerCase().includes(query.value.trim().toLowerCase())));
const sections = computed(() => [...new Set(filtered.value.map(tool => tool.section))]);
const recentTools = computed(() => recent.value.map(key => props.tools.find(tool => tool.key === key)).filter((tool): tool is Tool => !!tool));
const remember = (tool: Tool) => {
    recent.value = [tool.key, ...recent.value.filter(key => key !== tool.key)].slice(0, 5);
    try { window.localStorage.setItem(recentKey.value, JSON.stringify(recent.value)); } catch { /* storage can be disabled */ }
};
const onKey = (event: KeyboardEvent) => {
    const target = event.target as HTMLElement | null;
    const isTyping = target?.matches('input,textarea,select,[contenteditable="true"]');
    if (event.key === '/' && !isTyping) { event.preventDefault(); searchEl.value?.focus(); }
    if (event.key === 'Escape' && document.activeElement === searchEl.value) { query.value = ''; searchEl.value?.blur(); }
    if (document.activeElement !== searchEl.value) return;
    if (event.key === 'ArrowDown') { event.preventDefault(); selected.value = Math.min(selected.value + 1, filtered.value.length - 1); }
    if (event.key === 'ArrowUp') { event.preventDefault(); selected.value = Math.max(selected.value - 1, 0); }
    if (event.key === 'Enter' && filtered.value[selected.value]) {
        event.preventDefault(); const tool = filtered.value[selected.value]; remember(tool); window.location.assign(tool.href);
    }
};
onMounted(() => {
    try { const parsed = JSON.parse(window.localStorage.getItem(recentKey.value) || '[]'); if (Array.isArray(parsed)) recent.value = parsed.filter((v): v is string => typeof v === 'string'); } catch { recent.value = []; }
    try { const parsed = JSON.parse(window.localStorage.getItem(favoriteKey.value) || '[]'); if (Array.isArray(parsed)) favorites.value = parsed.filter((v): v is string => typeof v === 'string'); } catch { favorites.value = []; }
    window.addEventListener('keydown', onKey);
});
onUnmounted(() => window.removeEventListener('keydown', onKey));
const focusSearch = async () => { await nextTick(); searchEl.value?.focus(); };
</script>

<template>
    <Head title="Admin Command Center" />
    <main class="mx-auto w-full max-w-6xl space-y-8 p-4 md:p-8">
        <header class="space-y-3">
            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-muted-foreground">WayAlong · Administration</p>
            <h1 class="text-3xl font-bold tracking-tight md:text-4xl">Admin Command Center</h1>
            <p class="text-sm text-muted-foreground">Manage {{ organization.name }}. Search for a tool or browse an area below.</p>
        </header>

        <div class="relative rounded-2xl border border-sky-100 bg-card p-2 shadow-sm dark:border-slate-700">
            <Search class="pointer-events-none absolute left-5 top-1/2 size-5 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
            <input ref="searchEl" v-model="query" @input="selected = 0" type="search" aria-label="Search admin tools"
                class="w-full rounded-lg bg-transparent py-3 pl-12 pr-20 text-base outline-none focus-visible:ring-2 focus-visible:ring-ring" placeholder="Search tools, tasks, settings…" />
            <button v-if="query" type="button" aria-label="Clear search" class="absolute right-5 top-1/2 -translate-y-1/2" @click="query = ''; focusSearch()"><X class="size-4" /></button>
            <span v-else class="pointer-events-none absolute right-5 top-1/2 -translate-y-1/2 rounded border px-2 py-0.5 text-xs text-muted-foreground">/</span>
        </div>

        <section aria-label="Organization snapshot" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-2xl border border-slate-200/90 bg-card p-5 shadow-sm dark:border-slate-700"><div class="flex items-center gap-2 text-sm text-muted-foreground"><Building2 class="size-4" /> Facilities</div><div class="mt-3 text-3xl font-bold tracking-tight">{{ stats.facilities }}</div><p class="mt-1 text-xs text-muted-foreground">{{ stats.activeFacilities }} active</p></div>
            <div class="rounded-2xl border border-slate-200/90 bg-card p-5 shadow-sm dark:border-slate-700"><div class="flex items-center gap-2 text-sm text-muted-foreground"><Users class="size-4" /> Staff memberships</div><div class="mt-3 text-3xl font-bold tracking-tight">{{ stats.staff }}</div><p class="mt-1 text-xs text-muted-foreground">{{ stats.activeStaff }} active</p></div>
            <div class="rounded-2xl border border-slate-200/90 bg-card p-5 shadow-sm dark:border-slate-700"><div class="flex items-center gap-2 text-sm text-muted-foreground"><ShieldCheck class="size-4" /> Custom roles</div><div class="mt-3 text-3xl font-bold tracking-tight">{{ stats.customRoles }}</div><p class="mt-1 text-xs text-muted-foreground">Organization-defined roles</p></div>
            <div class="rounded-2xl border border-slate-200/90 bg-card p-5 shadow-sm dark:border-slate-700"><div class="flex items-center gap-2 text-sm text-muted-foreground"><Clock3 class="size-4" /> Pending invitations</div><div class="mt-3 text-3xl font-bold tracking-tight">{{ stats.pendingInvitations }}</div><p class="mt-1 text-xs text-muted-foreground">Unaccepted, unexpired</p></div>
        </section>

        <section v-if="!query && favoriteTools.length" class="space-y-3" aria-label="Common access"><h2 class="text-lg font-semibold">Common access</h2><div class="flex flex-wrap gap-2"><Link v-for="tool in favoriteTools" :key="tool.key" :href="tool.href" @click="remember(tool)" class="rounded-full border px-4 py-2 text-sm hover:bg-accent">{{ tool.title }}</Link></div></section>
        <section v-if="!query && recentTools.length" aria-label="Recently used tools" class="space-y-3">
            <h2 class="text-lg font-semibold">Recently used</h2>
            <div class="flex flex-wrap gap-2"><Link v-for="tool in recentTools" :key="tool.key" :href="tool.href" @click="remember(tool)" class="rounded-full border px-4 py-2 text-sm hover:bg-accent">{{ tool.title }}</Link></div>
        </section>
        <div v-if="!filtered.length" class="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">No admin tools match “{{ query }}”. Try a different search.</div>
        <section v-for="section in sections" :key="section" class="space-y-3">
            <h2 class="text-lg font-semibold">{{ section }}</h2>
            <div class="grid gap-3 md:grid-cols-2">
                <div v-for="tool in filtered.filter(item => item.section === section)" :key="tool.key" class="relative">
                <button type="button" class="absolute right-3 top-3 z-10 rounded-md border bg-background px-2 py-1 text-xs hover:bg-accent" :aria-label="favorites.includes(tool.key) ? `Remove ${tool.title} from common access` : `Add ${tool.title} to common access`" @click="toggleFavorite(tool)">{{ favorites.includes(tool.key) ? "★" : "☆" }}</button>
                <Link :href="tool.href" @click="remember(tool)"
                    class="group flex min-h-32 items-start justify-between gap-4 rounded-2xl border border-slate-200/90 bg-card p-5 pr-16 shadow-sm transition-all hover:-translate-y-0.5 hover:border-sky-300 hover:shadow-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring dark:border-slate-700 dark:hover:border-sky-600"
                    :class="{ 'border-primary/60': query && filtered[selected]?.key === tool.key }">
                    <div class="space-y-1"><h3 class="font-semibold">{{ tool.title }}</h3><p class="text-sm text-muted-foreground">{{ tool.description }}</p></div><ArrowUpRight class="size-4 shrink-0 text-muted-foreground group-hover:text-foreground" />
                </Link>
                </div>
            </div>
        </section>
        <div v-if="canManagePlatform" class="rounded-xl border p-4"><a href="/platform/organizations" class="font-medium underline">Platform · Organization Management →</a><p class="mt-1 text-sm text-muted-foreground">Create, edit, deactivate, and safely delete organizations.</p></div>
        <footer class="flex items-center gap-2 border-t pt-4 text-xs text-muted-foreground"><CheckCircle2 class="size-4" /> Tools are filtered by your permissions in the selected organization. Additional modules will appear here as they are built.</footer>
    </main>
</template>
