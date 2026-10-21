<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import WayAlongRecordTable from '@/components/WayAlongRecordTable.vue';
import WayAlongConfirmDialog from '@/components/WayAlongConfirmDialog.vue';

type Person = { id: number; name: string; email: string; phone: string | null; role: string; status: string };
type Link = { url: string | null; label: string; active: boolean };
const props = defineProps<{ organization: { id: number; name: string }; users: { data: Person[]; total: number; links: Link[] }; search: string }>();
const search = ref(props.search);
const deleteTarget = ref<Person | null>(null);
const removing = ref(false);
const columns = [{ key: 'name', label: 'Name' }, { key: 'email', label: 'Email' }, { key: 'phone', label: 'Phone' }, { key: 'status', label: 'Status' }];
const base = '/organization/admin/users';
const remove = () => { if (!deleteTarget.value) return; removing.value = true; router.delete(`${base}/${deleteTarget.value.id}`, { onFinish: () => { removing.value = false; deleteTarget.value = null; } }); };
</script>
<template>
    <Head title="User Management" />
    <main class="mx-auto w-full max-w-7xl space-y-6 p-4 md:p-8">
        <header class="space-y-2"><a href="/admin" class="text-sm underline">← Admin Command Center</a><div class="flex flex-wrap items-center justify-between gap-3"><h1 class="text-2xl font-semibold">User Management</h1><a href="/organization/admin/people" class="rounded-md border px-4 py-2 text-sm">Person Directory</a></div><p class="text-sm text-muted-foreground">{{ organization.name }} · Central identities with organization-specific contact information, membership status, and facility access.</p></header>
        <section class="space-y-4 rounded-xl border p-5">
            <form class="flex items-end gap-3" @submit.prevent="router.get(base, { search }, { preserveState: true })"><label class="grid flex-1 gap-1 text-sm">Search users<input v-model="search" maxlength="100" placeholder="Name or email" class="rounded-md border bg-background p-2" /></label><button type="submit" class="rounded-md bg-primary px-4 py-2 text-primary-foreground">Search</button><button type="button" class="rounded-md border px-4 py-2" @click="search = ''; router.get(base)">Clear</button></form>
            <WayAlongRecordTable :rows="users.data" :columns="columns" storage-key="organization-users" :client-search="false" :can-add="true" add-label="Add user" @add="router.get(`${base}/create`)">
                <template #actions="{ row }"><div class="relative inline-block"><select aria-label="User actions" class="rounded-md border bg-background px-3 py-2 text-sm" value="" @change="(event: Event) => { const action = (event.target as HTMLSelectElement).value; (event.target as HTMLSelectElement).value = ''; if (action === 'delete') deleteTarget = row; else if (action) router.get(`${base}/${row.id}${action === 'edit' ? '/edit' : ''}`); }"><option value="">Actions ▾</option><option value="view">View</option><option value="edit">Edit</option><option value="delete">Delete</option></select></div></template>
            </WayAlongRecordTable>
            <p class="text-xs text-muted-foreground">Deleting here removes organization membership, not the shared user account.</p>
            <nav v-if="users.links.length > 3" class="flex flex-wrap gap-2" aria-label="User pages"><template v-for="(link, index) in users.links" :key="index"><a v-if="link.url" :href="link.url" class="rounded-md border px-3 py-1 text-sm" :class="link.active ? 'bg-primary text-primary-foreground' : ''" v-html="link.label" /><span v-else class="rounded-md border px-3 py-1 text-sm opacity-50" v-html="link.label" /></template></nav>
        </section>
        <WayAlongConfirmDialog :open="!!deleteTarget" @update:open="(open: boolean) => { if (!open && !removing) deleteTarget = null; }" title="Remove user from organization?" :description="`Remove ${deleteTarget?.name ?? 'this user'} from ${organization.name}? Their shared account and other organization memberships will remain intact.`" confirm-label="Remove membership" destructive @confirm="remove" />
    </main>
</template>
