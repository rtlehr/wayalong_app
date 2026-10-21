<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import WayAlongRecordTable from '@/components/WayAlongRecordTable.vue';

type LinkItem = { url: string | null; label: string; active: boolean };
type Page<T> = { data: T[]; total: number; links: LinkItem[]; from: number | null; to: number | null };
type DocumentRow = { id: number; title: string; category: string; original_filename: string; review_status: string; expires_on: string | null; created_at: string; enrollment_id: number };
type EventRow = { id: number; action: string; document_title: string; actor: string; created_at: string; enrollment_id: number };
const props = defineProps<{
    facility: { id: number; name: string };
    documents: Page<DocumentRow>;
    events: Page<EventRow>;
    filters: { search: string; expiration: string };
}>();
const search = ref(props.filters.search);
const expiration = ref(props.filters.expiration);
const documentColumns = [
    { key: 'title', label: 'Title' }, { key: 'category', label: 'Category' },
    { key: 'original_filename', label: 'Filename' }, { key: 'review_status', label: 'Review status' },
    { key: 'expires_on', label: 'Expires' }, { key: 'created_at', label: 'Uploaded' },
];
const eventColumns = [
    { key: 'created_at', label: 'Date & time' }, { key: 'actor', label: 'User' },
    { key: 'action', label: 'Action' }, { key: 'document_title', label: 'Document' },
];
function applyFilters(): void {
    router.get('/facility/documents/audit', { search: search.value, expiration: expiration.value }, { preserveState: true, replace: true });
}
</script>

<template>
    <Head :title="`${facility.name} — Document Audit`" />
    <main class="mx-auto w-full max-w-7xl space-y-6 p-4 md:p-8">
        <header class="space-y-2">
            <Link href="/dashboard" class="text-sm underline underline-offset-4">← My WayAlong</Link>
            <h1 class="text-2xl font-semibold">Document Administration &amp; Audit</h1>
            <p class="text-sm text-muted-foreground">{{ facility.name }} · Client documents only. Access is restricted to authorized facility staff.</p>
        </header>
        <section class="space-y-4 rounded-xl border p-5">
            <h2 class="text-lg font-semibold">Client documents</h2>
            <form class="flex flex-wrap items-end gap-3" @submit.prevent="applyFilters">
                <label class="grid min-w-48 flex-1 gap-1 text-sm">Search title or category
                    <input v-model="search" type="search" maxlength="100" class="rounded-md border bg-background p-2" />
                </label>
                <label class="grid gap-1 text-sm">Expiration status
                    <select v-model="expiration" class="rounded-md border bg-background p-2">
                        <option value="all">All documents</option><option value="expired">Expired</option>
                        <option value="upcoming">Due within 30 days</option><option value="current">Not due within 30 days</option>
                    </select>
                </label>
                <button type="submit" class="rounded-md bg-primary px-4 py-2 text-sm text-primary-foreground">Apply filters</button>
            </form>
            <WayAlongRecordTable :rows="documents.data" :columns="documentColumns" storage-key="facility-document-audit" :client-search="false">
                <template #cell-expires_on="{ row }">{{ row.expires_on?.slice(0, 10) || '—' }}</template>
                <template #cell-created_at="{ row }">{{ row.created_at?.slice(0, 10) || '—' }}</template>
                <template #actions="{ row }"><Link :href="`/facility/clients/${row.enrollment_id}`" class="text-sm underline underline-offset-4">View client</Link></template>
            </WayAlongRecordTable>
            <p class="text-xs text-muted-foreground">Showing {{ documents.from ?? 0 }}–{{ documents.to ?? 0 }} of {{ documents.total }} authorized document records. Sorting and CSV export apply to the displayed page.</p>
            <nav aria-label="Document pages" class="flex flex-wrap gap-2"><template v-for="(link, i) in documents.links" :key="i"><Link v-if="link.url" :href="link.url" preserve-scroll class="rounded border px-3 py-1 text-sm" :class="{ 'bg-muted font-semibold': link.active }"><span v-html="link.label" /></Link></template></nav>
        </section>
        <section class="space-y-4 rounded-xl border p-5">
            <h2 class="text-lg font-semibold">Download audit history</h2>
            <p class="text-sm text-muted-foreground">Successful document downloads by authorized users. Denied attempts are not included in this log.</p>
            <WayAlongRecordTable :rows="events.data" :columns="eventColumns" storage-key="facility-document-download-history" :client-search="false">
                <template #cell-created_at="{ row }">{{ row.created_at }}</template>
                <template #actions="{ row }"><Link :href="`/facility/clients/${row.enrollment_id}`" class="text-sm underline underline-offset-4">View client</Link></template>
            </WayAlongRecordTable>
            <p class="text-xs text-muted-foreground">Showing {{ events.from ?? 0 }}–{{ events.to ?? 0 }} of {{ events.total }} access events. CSV export applies to the displayed page.</p>
            <nav aria-label="Audit event pages" class="flex flex-wrap gap-2"><template v-for="(link, i) in events.links" :key="i"><Link v-if="link.url" :href="link.url" preserve-scroll class="rounded border px-3 py-1 text-sm" :class="{ 'bg-muted font-semibold': link.active }"><span v-html="link.label" /></Link></template></nav>
        </section>
    </main>
</template>
