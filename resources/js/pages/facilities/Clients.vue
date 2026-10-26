<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import WayAlongConfirmDialog from '@/components/WayAlongConfirmDialog.vue';
import WayAlongRecordTable from '@/components/WayAlongRecordTable.vue';

type Enrollment = {
    id: number;
    status: string;
    enrolled_at: string;
    discharged_at: string | null;
    person: { id: number; first_name: string; last_name: string; date_of_birth: string | null };
};
type PageLink = { url: string | null; label: string; active: boolean };
type ClientRow = Enrollment & { name: string; date_of_birth: string; enrollment_date: string; discharge_date: string };
const props = defineProps<{
    facility: { id: number; name: string };
    clients: { data: Enrollment[]; total: number; from: number | null; to: number | null; links: PageLink[] };
    search: string;
    canManage: boolean;
}>();

const term = ref(props.search);
const busy = ref(false);
const selectedClient = ref<ClientRow | null>(null);
const confirmationOpen = ref(false);
const columns = [
    { key: 'name', label: 'Client' },
    { key: 'date_of_birth', label: 'Date of Birth' },
    { key: 'enrollment_date', label: 'Enrolled' },
    { key: 'status', label: 'Status' },
    { key: 'discharge_date', label: 'Discharged' },
];
const rows = computed<ClientRow[]>(() => props.clients.data.map(client => ({
    ...client,
    name: `${client.person.first_name} ${client.person.last_name}`,
    date_of_birth: client.person.date_of_birth?.slice(0, 10) ?? '—',
    enrollment_date: client.enrolled_at.slice(0, 10),
    discharge_date: client.discharged_at?.slice(0, 10) ?? '—',
})));

function filter(): void {
    router.get('/facility/clients', { search: term.value }, { preserveState: true, replace: true });
}
function clearFilter(): void {
    term.value = '';
    filter();
}
function handleAction(event: Event, client: ClientRow): void {
    const select = event.target as HTMLSelectElement;
    const action = select.value;
    select.value = '';
    if (action === 'view') {
        router.get(`/facility/clients/${client.id}`);
    } else if (action === 'treatment') {
        router.get(`/facility/clients/${client.id}/treatment`);
    } else if (action === 'edit' && props.canManage) {
        router.get(`/facility/clients/${client.id}/edit`);
    } else if (action === 'status' && props.canManage && !busy.value) {
        selectedClient.value = client;
        confirmationOpen.value = true;
    }
}
function changeStatus(): void {
    const client = selectedClient.value;
    if (!client || !props.canManage || busy.value) return;
    const discharge = client.status !== 'discharged';
    busy.value = true;
    router.patch(`/facility/clients/${client.id}`, {
        status: discharge ? 'discharged' : 'active',
        discharged_at: discharge ? new Date().toISOString().slice(0, 10) : null,
    }, {
        preserveScroll: true,
        onFinish: () => { busy.value = false; selectedClient.value = null; confirmationOpen.value = false; },
    });
}
</script>

<template>
    <Head :title="`${facility.name} — Clients`" />
    <main class="mx-auto w-full max-w-7xl space-y-6 p-4 md:p-8">
        <header class="space-y-2">
            <Link href="/dashboard" class="text-sm underline underline-offset-4">← My WayAlong</Link>
            <h1 class="text-2xl font-semibold">{{ facility.name }} — Client Directory</h1>
            <p class="text-sm text-muted-foreground">Only clients you are authorized to access at the selected facility are shown.</p>
        </header>
        <section class="space-y-4 rounded-xl border p-5">
            <form class="flex flex-wrap items-end gap-3" @submit.prevent="filter">
                <label class="grid min-w-48 flex-1 gap-1 text-sm">Search clients
                    <input v-model="term" type="search" maxlength="100" placeholder="First or last name" class="rounded-md border bg-background p-2" />
                </label>
                <button type="submit" class="rounded-md bg-primary px-4 py-2 text-sm text-primary-foreground">Search</button>
                <button type="button" class="rounded-md border px-4 py-2 text-sm" @click="clearFilter">Clear</button>
            </form>
            <WayAlongRecordTable
                :rows="rows"
                :columns="columns"
                :client-search="false"
                :can-add="canManage"
                storage-key="facility-client-directory"
                add-label="Enroll Client"
                @add="router.get('/facility/clients/create')"
            >
                <template #cell-status="{ row }"><span class="rounded-full bg-muted px-2 py-1 text-xs capitalize">{{ row.status }}</span></template>
                <template #actions="{ row }">
                    <select aria-label="Client actions" class="rounded-md border bg-background px-3 py-2 text-sm" value="" :disabled="busy" @change="handleAction($event, row)">
                        <option value="">Actions ▾</option>
                        <option value="view">Client Administration</option>
                        <option value="treatment">Client Treatment</option>
                        <option v-if="canManage" value="edit">Edit</option>
                        <option v-if="canManage" value="status">{{ row.status === 'discharged' ? 'Reactivate' : 'Discharge' }}</option>
                    </select>
                </template>
            </WayAlongRecordTable>
            <p class="text-xs text-muted-foreground">Search and pagination operate across the facility directory. Column sorting and CSV export use the records on the current page and only the columns you choose to display.</p>
            <p v-if="clients.total" class="text-sm text-muted-foreground">Showing {{ clients.from }}–{{ clients.to }} of {{ clients.total }} clients</p>
            <nav v-if="clients.links.length > 3" aria-label="Client directory pages" class="flex flex-wrap gap-2 border-t pt-4">
                <template v-for="(link, index) in clients.links" :key="index">
                    <Link v-if="link.url" :href="link.url" class="rounded-md border px-3 py-1 text-sm" :class="link.active ? 'bg-primary text-primary-foreground' : ''" :aria-current="link.active ? 'page' : undefined" v-html="link.label" />
                    <span v-else class="rounded-md border px-3 py-1 text-sm opacity-50" v-html="link.label" />
                </template>
            </nav>
        </section>
        <WayAlongConfirmDialog
            v-model:open="confirmationOpen"
            :title="selectedClient?.status === 'discharged' ? 'Reactivate client enrollment?' : 'Discharge client from facility?'"
            :description="selectedClient?.status === 'discharged'
                ? `Reactivate ${selectedClient?.name ?? 'this client'} at ${facility.name}?`
                : `Discharge ${selectedClient?.name ?? 'this client'} from ${facility.name}? The enrollment history will be retained.`"
            :confirm-label="selectedClient?.status === 'discharged' ? 'Reactivate client' : 'Discharge client'"
            :destructive="selectedClient?.status !== 'discharged'"
            @confirm="changeStatus"
        />
    </main>
</template>
