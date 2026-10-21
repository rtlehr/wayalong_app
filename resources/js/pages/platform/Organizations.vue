<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import WayAlongRecordTable from '@/components/WayAlongRecordTable.vue';
import WayAlongConfirmDialog from '@/components/WayAlongConfirmDialog.vue';

type Organization = { id: number; name: string; slug: string; status: string; timezone: string; facilities_count: number; users_count: number };
type PaginationLink = { label: string; url: string | null; active: boolean };
const props = defineProps<{ organizations: { data: Organization[]; links: PaginationLink[] }; search: string }>();
const search = ref(props.search);
const deleteTarget = ref<Organization | null>(null);
const columns = [
    { key: 'name', label: 'Organization' },
    { key: 'slug', label: 'Login identifier' },
    { key: 'status', label: 'Status' },
    { key: 'facilities_count', label: 'Facilities' },
    { key: 'users_count', label: 'Users' },
];
const base = '/platform/organizations';
const selectAction = (event: Event, organization: Organization) => {
    const select = event.target as HTMLSelectElement;
    const action = select.value;
    select.value = '';
    if (action === 'delete') deleteTarget.value = organization;
    else if (action) router.get(`${base}/${organization.id}${action === 'edit' ? '/edit' : ''}`);
};
const remove = () => {
    if (!deleteTarget.value) return;
    router.delete(`${base}/${deleteTarget.value.id}`, { onFinish: () => { deleteTarget.value = null; } });
};
</script>
<template>
    <Head title="Platform Organizations" />
    <main class="mx-auto w-full max-w-7xl space-y-6 p-4 md:p-8">
        <header class="space-y-2"><a href="/dashboard" class="text-sm underline">← Platform Dashboard</a><h1 class="text-2xl font-semibold">Organization Management</h1><p class="text-sm text-muted-foreground">Platform administration · Each organization owns its facilities and account access.</p></header>
        <section class="space-y-4 rounded-xl border p-5">
            <form class="flex items-end gap-3" @submit.prevent="router.get(base, { search }, { preserveState: true })"><label class="grid flex-1 gap-1 text-sm">Search organizations<input v-model="search" class="rounded-md border bg-background p-2" placeholder="Name or login identifier" /></label><button type="submit" class="rounded-md bg-primary px-4 py-2 text-primary-foreground">Search</button><button type="button" class="rounded-md border px-4 py-2" @click="search = ''; router.get(base)">Clear</button></form>
            <WayAlongRecordTable :rows="organizations.data" :columns="columns" storage-key="platform-organizations" :client-search="false" :can-add="true" add-label="Add organization" @add="router.get(`${base}/create`)">
                <template #actions="{ row }"><select aria-label="Organization actions" class="rounded-md border bg-background px-3 py-2 text-sm" value="" @change="selectAction($event, row)"><option value="">Actions ▾</option><option value="view">View</option><option value="edit">Edit</option><option value="delete">Delete</option></select></template>
            </WayAlongRecordTable>
            <nav v-if="organizations.links.length > 3" class="flex flex-wrap gap-2" aria-label="Organization pages"><template v-for="(link, index) in organizations.links" :key="index"><a v-if="link.url" :href="link.url" class="rounded-md border px-3 py-1 text-sm" :class="link.active ? 'bg-primary text-primary-foreground' : ''" v-html="link.label" /><span v-else class="rounded-md border px-3 py-1 text-sm opacity-50" v-html="link.label" /></template></nav>
            <p class="text-sm text-muted-foreground">Organizations with facilities, users, staff assignments, invitations, or custom roles cannot be deleted. Deactivate them instead.</p>
        </section>
        <p v-if="$page.props.errors?.organization" role="alert" class="text-sm text-destructive">{{ $page.props.errors.organization }}</p>
        <WayAlongConfirmDialog  :open="!!deleteTarget" @update:open="(open: boolean) => { if (!open) deleteTarget = null; }" title="Delete organization?" :description="`Permanently delete ${deleteTarget?.name ?? 'this organization'}? This is only allowed after every dependent record has been removed. Consider deactivation instead.`" confirm-label="Delete organization" destructive @confirm="remove" />
    </main>
</template>
