<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import WayAlongRecordTable from '@/components/WayAlongRecordTable.vue';
import WayAlongConfirmDialog from '@/components/WayAlongConfirmDialog.vue';

type Person = { id: number; first_name: string; last_name: string; person_type: string; facility: { name: string } | null; user_id: number | null };
type Link = { url: string | null; label: string; active: boolean };
const props = defineProps<{ organization: { id: number; name: string }; people: { data: Person[]; total: number; links: Link[] }; search: string }>();
const base = '/organization/admin/people';
const search = ref(props.search);
const selected = ref<Person | null>(null);
const deleting = ref(false);
const rows = () => props.people.data.map(p => ({ ...p, name: `${p.first_name} ${p.last_name}`, facility_name: p.facility?.name ?? 'Unassigned', login: p.user_id ? 'Linked' : 'No login' }));
const columns = [{ key: 'name', label: 'Name' }, { key: 'person_type', label: 'Type' }, { key: 'facility_name', label: 'Facility' }, { key: 'login', label: 'Login' }];
function action(event: Event, row: Person) {
    const el = event.target as HTMLSelectElement;
    const choice = el.value;
    el.value = '';
    if (choice === 'delete') selected.value = row;
    else if (choice) router.get(`${base}/${row.id}${choice === 'edit' ? '/edit' : ''}`);
}
function remove() {
    if (!selected.value) return;
    deleting.value = true;
    router.delete(`${base}/${selected.value.id}`, { onFinish: () => { deleting.value = false; selected.value = null; } });
}
</script>
<template>
    <Head title="Person Directory" />
    <main class="mx-auto w-full max-w-7xl space-y-6 p-4 md:p-8">
        <header class="space-y-2"><a href="/admin" class="text-sm underline">← Admin Command Center</a><h1 class="text-2xl font-semibold">Person Directory</h1><p class="text-sm text-muted-foreground">{{ organization.name }} · People may be entered without an email address or login. Client and guardian access can be established separately.</p></header>
        <section class="space-y-4 rounded-xl border p-5">
            <form class="flex items-end gap-3" @submit.prevent="router.get(base, { search }, { preserveState: true })"><label class="grid flex-1 gap-1 text-sm">Search people<input v-model="search" maxlength="100" placeholder="First or last name" class="rounded-md border bg-background p-2" /></label><button type="submit" class="rounded-md bg-primary px-4 py-2 text-primary-foreground">Search</button><button type="button" class="rounded-md border px-4 py-2" @click="search = ''; router.get(base)">Clear</button></form>
            <WayAlongRecordTable :rows="rows()" :columns="columns" storage-key="organization-people" :client-search="false" :can-add="true" add-label="Add person" @add="router.get(`${base}/create`)">
                <template #actions="{ row }"><select aria-label="Person actions" class="rounded-md border bg-background px-3 py-2 text-sm" value="" @change="action($event, row)"><option value="">Actions ▾</option><option value="view">View</option><option value="edit">Edit</option><option value="delete">Delete</option></select></template>
            </WayAlongRecordTable>
            <nav v-if="people.links.length > 3" class="flex flex-wrap gap-2" aria-label="Person pages"><template v-for="(link, i) in people.links" :key="i"><a v-if="link.url" :href="link.url" class="rounded-md border px-3 py-1 text-sm" :class="link.active ? 'bg-primary text-primary-foreground' : ''" v-html="link.label" /><span v-else class="rounded-md border px-3 py-1 text-sm opacity-50" v-html="link.label" /></template></nav>
        </section>
        <WayAlongConfirmDialog :open="!!selected" @update:open="(v: boolean) => { if (!v && !deleting) selected = null; }" title="Delete this person record?" :description="`Delete ${selected?.first_name ?? 'this'} ${selected?.last_name ?? 'person'}? Linked login accounts cannot be deleted here.`" confirm-label="Delete person" destructive @confirm="remove" />
    </main>
</template>
