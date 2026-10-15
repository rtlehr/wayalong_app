<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

type Facility = { id: number; name: string; code: string; status: string; address_line_1: string | null; address_line_2: string | null; city: string | null; state: string | null; postal_code: string | null; phone: string | null; email: string | null; timezone: string | null };
type CustomRole = { id: number; name: string; value: string; permissions: string[] };
type Member = { id: number; name: string; email: string; role: string; status: string };
const props = defineProps<{ organization: { id: number; name: string; slug: string; status: string; timezone: string }; facilities: Facility[]; members: Member[]; customRoles: CustomRole[] }>();
const base = '/organization/admin';
const newRole = useForm({ name: '', permissions: [] as string[] });
const roleEdits = ref<Record<number, { name: string; permissions: string[] }>>(Object.fromEntries(props.customRoles.map(r => [r.id, { name: r.name, permissions: [...r.permissions] }])));
const createRole = () => newRole.post(`${base}/roles`, { preserveScroll: true, onSuccess: () => newRole.reset() });
const updateRole = (id: number) => useForm(roleEdits.value[id]).patch(`${base}/roles/${id}`, { preserveScroll: true });
const deleteRole = (id: number) => { if (window.confirm('Delete this custom role?')) useForm({}).delete(`${base}/roles/${id}`, { preserveScroll: true }); }; 
const settings = useForm({ name: props.organization.name, timezone: props.organization.timezone });
const blank = () => ({ name: '', code: '', status: 'active', address_line_1: '', address_line_2: '', city: '', state: '', postal_code: '', phone: '', email: '', timezone: '' });
const facility = useForm(blank());
const editing = ref<number | null>(null);
const newMember = useForm({ email: '', role: 'staff' });
const invitation = useForm({ email: '', role: 'staff' });
const sendInvitation = () => invitation.post(`${base}/invitations`, { preserveScroll: true, onSuccess: () => invitation.reset() });
const memberEdits = ref<Record<number, { role: string; status: string }>>(
    Object.fromEntries(props.members.map(member => [member.id, { role: member.role, status: member.status }]))
);
const savingMember = ref<number | null>(null);
const saveMember = (id: number) => {
    savingMember.value = id;
    useForm(memberEdits.value[id]).patch(`${base}/members/${id}`, {
        preserveScroll: true,
        onFinish: () => { savingMember.value = null; },
    });
};
const addMember = () => newMember.post(`${base}/members`, {
    preserveScroll: true,
    onSuccess: () => newMember.reset(),
});
const edit = (item: Facility) => {
    editing.value = item.id;
    facility.clearErrors();
    facility.defaults({ ...blank(), ...Object.fromEntries(Object.entries(item).map(([k, v]) => [k, v ?? ''])) });
    facility.reset();
};
const clear = () => { editing.value = null; facility.defaults(blank()); facility.reset(); facility.clearErrors(); };
const saveFacility = () => {
    if (editing.value !== null) facility.patch(`${base}/facilities/${editing.value}`, { onSuccess: clear, preserveScroll: true });
    else facility.post(`${base}/facilities`, { onSuccess: clear, preserveScroll: true });
};
const remove = (item: Facility) => {
    if (window.confirm(`Delete ${item.name}?`)) useForm({}).delete(`${base}/facilities/${item.id}`, { preserveScroll: true });
};
</script>

<template>
    <Head title="Organization administration" />
    <div class="mx-auto w-full max-w-5xl space-y-8 p-4 md:p-8">
        <header>
            <h1 class="text-2xl font-semibold">Organization administration</h1>
            <p class="text-sm text-muted-foreground">Manage {{ organization.name }} and its facilities. Manage staff memberships and facilities for the selected organization.</p>
        </header>

        <section id="organization-settings" class="space-y-4 rounded-xl border p-5">
            <h2 class="text-lg font-semibold">Organization settings</h2>
            <form class="grid gap-4 md:grid-cols-2" @submit.prevent="settings.patch(`${base}/settings`, { preserveScroll: true })">
                <label class="grid gap-1 text-sm">Name<input v-model="settings.name" required maxlength="255" class="rounded-md border bg-background p-2" /><span class="text-red-600">{{ settings.errors.name }}</span></label>
                <label class="grid gap-1 text-sm">Timezone<input v-model="settings.timezone" required placeholder="America/New_York" class="rounded-md border bg-background p-2" /><span class="text-red-600">{{ settings.errors.timezone }}</span></label>
                <div><button class="rounded-md bg-primary px-4 py-2 text-primary-foreground" :disabled="settings.processing">Save settings</button></div>
            </form>
        </section>

        <section id="facilities" class="space-y-4 rounded-xl border p-5">
            <h2 class="text-lg font-semibold">Facilities ({{ facilities.length }})</h2>
            <div v-if="facilities.length === 0" class="text-sm text-muted-foreground">No facilities yet.</div>
            <div v-for="item in facilities" :key="item.id" class="flex flex-wrap items-center justify-between gap-3 border-b py-3">
                <div><strong>{{ item.name }}</strong><div class="text-sm text-muted-foreground">{{ item.code }} · {{ item.status }} · {{ [item.city, item.state].filter(Boolean).join(', ') }}</div></div>
                <div class="flex gap-2"><button class="rounded-md border px-3 py-1" @click="edit(item)">Edit</button><button class="rounded-md border px-3 py-1 text-red-600" @click="remove(item)">Delete</button></div>
            </div>
            <h3 class="font-medium">{{ editing === null ? 'Add facility' : 'Edit facility' }}</h3>
            <form class="grid gap-3 md:grid-cols-2" @submit.prevent="saveFacility">
                <label v-for="field in (['name','code','address_line_1','address_line_2','city','state','postal_code','phone','email','timezone'] as const)" :key="field" class="grid gap-1 text-sm">
                    {{ field.replaceAll('_',' ') }}
                    <input v-model="facility[field]" :required="field === 'name' || field === 'code'" :maxlength="field === 'state' ? 2 : field === 'code' ? 50 : 255" class="rounded-md border bg-background p-2" />
                    <span class="text-red-600">{{ facility.errors[field] }}</span>
                </label>
                <label class="grid gap-1 text-sm">Status<select v-model="facility.status" class="rounded-md border bg-background p-2"><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
                <div class="flex items-end gap-2"><button class="rounded-md bg-primary px-4 py-2 text-primary-foreground" :disabled="facility.processing">{{ editing === null ? 'Add facility' : 'Save changes' }}</button><button v-if="editing !== null" type="button" class="rounded-md border px-4 py-2" @click="clear">Cancel</button></div>
            </form>
        </section>

        <section id="custom-roles" class="space-y-3 rounded-xl border p-5">
            <h2 class="text-lg font-semibold">Custom roles</h2>
            <p class="text-sm text-muted-foreground">Custom roles are limited to facility management and viewing members. System owner/admin privileges cannot be assigned here.</p>
            <form class="flex flex-wrap items-end gap-3" @submit.prevent="createRole">
                <label class="grid gap-1 text-sm">Role name<input v-model="newRole.name" required maxlength="100" class="rounded-md border bg-background p-2" /><span class="text-red-600">{{ newRole.errors.name }}</span></label>
                <label class="flex items-center gap-2 text-sm"><input v-model="newRole.permissions" type="checkbox" value="facilities.manage" />Manage facilities</label>
                <label class="flex items-center gap-2 text-sm"><input v-model="newRole.permissions" type="checkbox" value="members.view" />View members</label>
                <button class="rounded-md bg-primary px-4 py-2 text-primary-foreground" :disabled="newRole.processing">Create role</button>
                <span class="text-red-600">{{ newRole.errors.permissions }}</span>
            </form>
            <div v-for="role in customRoles" :key="role.id" class="flex flex-wrap items-center gap-3 border-t py-3">
                <template v-if="roleEdits[role.id]">
                    <input v-model="roleEdits[role.id].name" :aria-label="`Name for ${role.name}`" class="rounded-md border bg-background p-2" />
                    <label class="flex items-center gap-2 text-sm"><input v-model="roleEdits[role.id].permissions" type="checkbox" value="facilities.manage" />Manage facilities</label>
                    <label class="flex items-center gap-2 text-sm"><input v-model="roleEdits[role.id].permissions" type="checkbox" value="members.view" />View members</label>
                    <button class="rounded-md border px-3 py-2" @click="updateRole(role.id)">Save</button>
                    <button class="rounded-md border px-3 py-2 text-red-600" @click="deleteRole(role.id)">Delete</button>
                </template>
            </div>
        </section>

        <section class="space-y-3 rounded-xl border p-5">
            <h2 class="text-lg font-semibold">Staff memberships ({{ members.length }})</h2>
            <a href="/organization/admin/staff" class="inline-flex rounded-md border px-3 py-2 text-sm hover:bg-muted">Open staff directory (search and filters) →</a>
            <form class="flex flex-wrap items-end gap-3 rounded-md border p-3" @submit.prevent="sendInvitation">
                <label class="grid gap-1 text-sm">Invite staff by email
                    <input v-model="invitation.email" type="email" required class="rounded-md border bg-background p-2" />
                    <span class="text-red-600">{{ invitation.errors.email }}</span>
                </label>
                <label class="grid gap-1 text-sm">Invitation role
                    <select v-model="invitation.role" class="rounded-md border bg-background p-2">
                        <option v-for="role in ['staff', 'clinician', 'admin', 'owner']" :key="role" :value="role">{{ role }}</option>

                    </select>
                    <span class="text-red-600">{{ invitation.errors.role }}</span>
                </label>
                <button class="rounded-md bg-primary px-4 py-2 text-primary-foreground" :disabled="invitation.processing">Send invitation</button>
            </form>
            <form class="flex flex-wrap items-end gap-3" @submit.prevent="addMember">
                <label class="grid gap-1 text-sm">Existing user's email
                    <input v-model="newMember.email" type="email" required class="rounded-md border bg-background p-2" />
                    <span class="text-red-600">{{ newMember.errors.email }}</span>
                </label>
                <label class="grid gap-1 text-sm">Role
                    <select v-model="newMember.role" class="rounded-md border bg-background p-2">
                        <option v-for="role in ['staff', 'clinician', 'admin', 'owner']" :key="role" :value="role">{{ role }}</option>
                        <option v-for="role in customRoles" :key="role.id" :value="role.value">{{ role.name }}</option>
                    </select>
                    <span class="text-red-600">{{ newMember.errors.role }}</span>
                </label>
                <button class="rounded-md bg-primary px-4 py-2 text-primary-foreground" :disabled="newMember.processing">Add staff member</button>
            </form>
            <div v-for="member in members" :key="member.id" class="flex flex-wrap items-center justify-between gap-3 border-b py-3 text-sm">
                <div><strong>{{ member.name }}</strong><div class="text-muted-foreground">{{ member.email }}</div></div>
                <div v-if="memberEdits[member.id]" class="flex flex-wrap items-center gap-2">
                    <select v-model="memberEdits[member.id].role" :aria-label="`Role for ${member.name}`" class="rounded-md border bg-background p-2">
                        <option v-for="role in ['staff', 'clinician', 'admin', 'owner']" :key="role" :value="role">{{ role }}</option>
                        <option v-for="role in customRoles" :key="role.id" :value="role.value">{{ role.name }}</option>
                    </select>
                    <select v-model="memberEdits[member.id].status" :aria-label="`Membership status for ${member.name}`" class="rounded-md border bg-background p-2">
                        <option value="active">Active</option><option value="inactive">Inactive</option>
                    </select>
                    <button class="rounded-md border px-3 py-2" :disabled="savingMember === member.id" @click="saveMember(member.id)">Save</button>
                </div>
            </div>
        </section>
    </div>
</template>
