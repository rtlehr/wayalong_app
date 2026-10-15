<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { reactive, ref, watch } from 'vue';

type Member = { id: number; name: string; email: string; role: string; status: string };
type PageLink = { url: string | null; label: string; active: boolean };
type MemberPage = { data: Member[]; total: number; from: number | null; to: number | null; links: PageLink[] };
type CustomRole = { value: string; name: string };
const props = defineProps<{
    organization: { id: number; name: string };
    members: MemberPage;
    filters: { search: string; role: string; status: string };
    customRoles: CustomRole[];
    isOwner: boolean;
}>();
const base = '/organization/admin';
const filters = reactive({ ...props.filters });
const applyFilters = () => router.get(`${base}/staff`, { ...filters }, { preserveState: true, replace: true });
const clearFilters = () => { filters.search = ''; filters.role = ''; filters.status = ''; applyFilters(); };
const create = useForm({ email: '', role: 'staff' });
const invite = useForm({ email: '', role: 'staff' });
const memberEdits = ref<Record<number, { role: string; status: string }>>(
    Object.fromEntries(props.members.data.map(member => [member.id, { role: member.role, status: member.status }]))
);
watch(() => props.members.data, (members) => {
    memberEdits.value = Object.fromEntries(members.map(member => [member.id, { role: member.role, status: member.status }]));
});
const saving = ref<number | null>(null);
const errors = ref<Record<number, string>>({});
const save = (id: number) => {
    saving.value = id;
    errors.value[id] = '';
    useForm(memberEdits.value[id]).patch(`${base}/members/${id}`, {
        preserveScroll: true,
        onError: (validation) => { errors.value[id] = Object.values(validation).join(' '); },
        onFinish: () => { saving.value = null; },
    });
};
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
    <main class="mx-auto w-full max-w-5xl space-y-6 p-4 md:p-8">
        <header class="space-y-2">
            <a href="/organization/admin" class="text-sm underline">← Organization administration</a>
            <h1 class="text-2xl font-semibold">Staff directory</h1>
            <p class="text-sm text-muted-foreground">{{ organization.name }} · Manage memberships within this organization.</p>
        </header>

        <section class="space-y-3 rounded-xl border p-5" aria-label="Search staff">
            <form class="flex flex-wrap items-end gap-3" @submit.prevent="applyFilters">
                <label class="grid min-w-48 flex-1 gap-1 text-sm">Name or email
                    <input v-model="filters.search" maxlength="100" class="rounded-md border bg-background p-2" placeholder="Search staff" />
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

        <section class="grid gap-4 md:grid-cols-2">
            <form class="space-y-3 rounded-xl border p-5" @submit.prevent="create.post(`${base}/members`, { preserveScroll: true, onSuccess: () => create.reset() })">
                <h2 class="font-semibold">Add existing user</h2>
                <label class="grid gap-1 text-sm">Email<input v-model="create.email" type="email" required class="rounded-md border bg-background p-2" /></label>
                <label class="grid gap-1 text-sm">Role<select v-model="create.role" class="rounded-md border bg-background p-2"><option v-for="role in roleOptions" :key="role.value" :value="role.value">{{ role.name }}</option></select></label>
                <p v-if="create.errors.email || create.errors.role" class="text-sm text-red-600">{{ create.errors.email || create.errors.role }}</p>
                <button class="rounded-md bg-primary px-4 py-2 text-primary-foreground" :disabled="create.processing">Add member</button>
            </form>
            <form class="space-y-3 rounded-xl border p-5" @submit.prevent="invite.post(`${base}/invitations`, { preserveScroll: true, onSuccess: () => invite.reset() })">
                <h2 class="font-semibold">Invite new staff</h2>
                <label class="grid gap-1 text-sm">Email<input v-model="invite.email" type="email" required class="rounded-md border bg-background p-2" /></label>
                <label class="grid gap-1 text-sm">Role<select v-model="invite.role" class="rounded-md border bg-background p-2"><option v-for="role in roleOptions.filter(r => !r.value.startsWith('custom:'))" :key="role.value" :value="role.value">{{ role.name }}</option></select></label>
                <p v-if="invite.errors.email || invite.errors.role" class="text-sm text-red-600">{{ invite.errors.email || invite.errors.role }}</p>
                <button class="rounded-md bg-primary px-4 py-2 text-primary-foreground" :disabled="invite.processing">Send invitation</button>
            </form>
        </section>

        <section class="space-y-3 rounded-xl border p-5">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-lg font-semibold">Members ({{ members.total }})</h2>
                <span v-if="members.from !== null" class="text-sm text-muted-foreground">Showing {{ members.from }}–{{ members.to }}</span>
            </div>
            <p v-if="members.data.length === 0" class="text-sm text-muted-foreground">No staff match these filters.</p>
            <div v-for="member in members.data" :key="member.id" class="flex flex-wrap items-center justify-between gap-3 border-t py-3">
                <div><strong>{{ member.name }}</strong><div class="text-sm text-muted-foreground">{{ member.email }}</div></div>
                <div v-if="memberEdits[member.id]" class="flex flex-wrap items-center gap-2">
                    <select v-model="memberEdits[member.id].role" :aria-label="`Role for ${member.name}`" class="rounded-md border bg-background p-2 text-sm">
                        <option v-for="role in roleOptions" :key="role.value" :value="role.value">{{ role.name }}</option>
                        <option v-if="!roleOptions.some(role => role.value === memberEdits[member.id].role)" :value="memberEdits[member.id].role">{{ memberEdits[member.id].role }}</option>
                    </select>
                    <select v-model="memberEdits[member.id].status" :aria-label="`Status for ${member.name}`" class="rounded-md border bg-background p-2 text-sm">
                        <option value="active">Active</option><option value="inactive">Inactive</option>
                    </select>
                    <button class="rounded-md border px-3 py-2 text-sm" :disabled="saving === member.id" @click="save(member.id)">Save</button>
                    <p v-if="errors[member.id]" role="alert" class="text-sm text-red-600">{{ errors[member.id] }}</p>
                </div>
            </div>
            <nav v-if="members.links.length > 3" aria-label="Staff directory pages" class="flex flex-wrap gap-2 border-t pt-4">
                <template v-for="(link, index) in members.links" :key="index">
                    <a v-if="link.url" :href="link.url" :aria-current="link.active ? 'page' : undefined" class="rounded-md border px-3 py-1 text-sm" :class="{ 'bg-primary text-primary-foreground': link.active }" v-html="link.label" />
                    <span v-else class="rounded-md border px-3 py-1 text-sm opacity-50" v-html="link.label" />
                </template>
            </nav>
        </section>
    </main>
</template>
