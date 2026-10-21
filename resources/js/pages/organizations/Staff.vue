<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import WayAlongRecordTable from '@/components/WayAlongRecordTable.vue';
import WayAlongConfirmDialog from '@/components/WayAlongConfirmDialog.vue';

type Member = { id: number; name: string; email: string; role: string; status: string };
type PageLink = { url: string | null; label: string; active: boolean };
type MemberPage = { data: Member[]; total: number; from: number | null; to: number | null; links: PageLink[] };
type CustomRole = { value: string; name: string };
const props = defineProps<{
    organization: { id: number; name: string };
    members: MemberPage;
    filters: { search: string; role: string; status: string; facility_id: number };
    customRoles: CustomRole[];
    isOwner: boolean;
    availableFacilities: { id: number; name: string; code: string }[];
    availableFacilities: { id: number; name: string; code: string }[];
}>();
const base = '/organization/admin';
const filters = reactive({ ...props.filters });
const applyFilters = () => router.get(`${base}/staff`, { ...filters, facility_id: filters.facility_id || undefined }, { preserveState: true, replace: true });
const clearFilters = () => { filters.search = ''; filters.role = ''; filters.status = ''; applyFilters(); };
const deleteTarget = ref<Member | null>(null);
const removing = ref(false);
const deleteOpen = computed({ get: () => deleteTarget.value !== null, set: (open: boolean) => { if (!open && !removing.value) deleteTarget.value = null; } });
const deleteMember = () => {
    if (!deleteTarget.value) return;
    removing.value = true;
    router.delete(`${base}/staff/${deleteTarget.value.id}?facility_id=${filters.facility_id}`, {
        preserveScroll: true,
        onFinish: () => { removing.value = false; deleteTarget.value = null; },
    });
};
const staffColumns = [{ key: 'name', label: 'Name' }, { key: 'email', label: 'Email' }, { key: 'role', label: 'Role' }, { key: 'status', label: 'Status' }];

const roleOptions = [
    { value: 'staff', name: 'Staff' },
    { value: 'clinician', name: 'Clinician' },
    { value: 'admin', name: 'Admin' },
    ...(props.isOwner ? [{ value: 'owner', name: 'Owner' }] : []),
    ...props.customRoles,
];
</script>

<template>
    <Head title="Staff directory" />
    <main class="mx-auto w-full max-w-7xl space-y-6 p-4 md:p-8">
        <header class="space-y-2">
            <a href="/admin" class="text-sm underline">← Admin Command Center</a>
            <h1 class="text-2xl font-semibold">Staff directory</h1>
            <p class="text-sm text-muted-foreground">{{ organization.name }} · Staff assignments for the selected facility. Manage personal details and facility access in User Management.</p>
        </header>

        <section class="space-y-3 rounded-xl border p-5" aria-label="Search staff">
            <form class="flex flex-wrap items-end gap-3" @submit.prevent="applyFilters">
                <label class="grid min-w-48 flex-1 gap-1 text-sm">Name or email
                    <input v-model="filters.search" maxlength="100" class="rounded-md border bg-background p-2" placeholder="Search staff" />
                </label>
                <label class="grid gap-1 text-sm">Facility
                    <select v-model.number="filters.facility_id" class="rounded-md border bg-background p-2" @change="applyFilters">
                        <option v-for="facility in availableFacilities" :key="facility.id" :value="facility.id">{{ facility.name }}</option>
                    </select>
                </label>
                <label class="grid gap-1 text-sm">Facility
                    <select v-model.number="filters.facility_id" class="rounded-md border bg-background p-2" @change="applyFilters">
                        <option v-for="facility in availableFacilities" :key="facility.id" :value="facility.id">{{ facility.name }}</option>
                    </select>
                </label>
                <label class="grid gap-1 text-sm">Role
                    <select v-model="filters.role" class="rounded-md border bg-background p-2">
                        <option value="">All roles</option>
                        <option v-for="role in roleOptions" :key="role.value" :value="role.value">{{ role.name }}</option>
                    </select>
                </label>
                <label class="grid gap-1 text-sm">Status
                    <select v-model="filters.status" class="rounded-md border bg-background p-2">
                        <option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option>
                    </select>
                </label>
                <button class="rounded-md bg-primary px-4 py-2 text-primary-foreground" type="submit">Search</button>
                <button class="rounded-md border px-4 py-2" type="button" @click="clearFilters">Clear</button>
            </form>
        </section>

        <section class="space-y-3 rounded-xl border p-5">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-lg font-semibold">Facility staff ({{ members.total }})</h2>
                <span v-if="members.from !== null" class="text-sm text-muted-foreground">Showing {{ members.from }}–{{ members.to }}</span>
            </div>
            <WayAlongRecordTable :rows="members.data" :columns="staffColumns" storage-key="staff-directory" :client-search="false" :can-add="true" add-label="Add staff" @add="router.get('/organization/admin/staff/create')">
                <template #cell-status="{ row }"><span class="rounded-full bg-muted px-2 py-1 text-xs capitalize">{{ row.status }}</span></template>
                <template #actions="{ row }">
                    <select aria-label="Staff actions" class="rounded-md border bg-background px-3 py-2 text-sm" value="" @change="(event: Event) => { const action = (event.target as HTMLSelectElement).value; (event.target as HTMLSelectElement).value = ''; if (action === 'delete') deleteTarget = row; else if (action) router.get(`${base}/staff/${row.id}${action === 'edit' ? '/edit' : ''}?facility_id=${filters.facility_id}`); }">
                        <option value="">Actions ▾</option><option value="view">View</option><option value="edit">Edit</option><option value="delete">Delete</option>
                    </select>
                </template>
            </WayAlongRecordTable>
            <p class="text-xs text-muted-foreground">Select Add staff to assign an existing organization user or invite someone to join a facility. Search filters and pagination apply to the full directory. CSV export includes the records on this page.</p>
            <nav v-if="members.links.length > 3" aria-label="Staff directory pages" class="flex flex-wrap gap-2 border-t pt-4">
                <template v-for="(link, index) in members.links" :key="index">
                    <a v-if="link.url" :href="link.url" :aria-current="link.active ? 'page' : undefined" class="rounded-md border px-3 py-1 text-sm" :class="{ 'bg-primary text-primary-foreground': link.active }" v-html="link.label" />
                    <span v-else class="rounded-md border px-3 py-1 text-sm opacity-50" v-html="link.label" />
                </template>
            </nav>
        </section>
        <WayAlongConfirmDialog v-model:open="deleteOpen" title="Remove facility staff assignment?" :description="`Remove ${deleteTarget?.name ?? 'this person'} from ${organization.name}? Their user account and other facility/organization assignments will remain intact.`" confirm-label="Remove from facility" destructive @confirm="deleteMember" />
    </main>
</template>
