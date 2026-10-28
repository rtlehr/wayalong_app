<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import WayAlongRecordTable from '@/components/WayAlongRecordTable.vue';
import WayAlongConfirmDialog from '@/components/WayAlongConfirmDialog.vue';
import WayAlongFormSections from '@/components/WayAlongFormSections.vue';

type FacilityContact = { kind: 'phone' | 'email'; label: string; value: string; is_primary: boolean };
type Facility = { contact_methods?: FacilityContact[]; id: number; name: string; code: string; status: string; address_line_1: string | null; address_line_2: string | null; city: string | null; state: string | null; postal_code: string | null; phone: string | null; email: string | null; timezone: string | null };
type CustomRole = { id: number; name: string; value: string; permissions: string[] };
type PermissionGroup = { name: string; permissions: { key: string; label: string; description: string }[] };
type RoleTemplate = { key: string; label: string; permissions: string[] };
type Member = { id: number; name: string; email: string; role: string; status: string };
const props = defineProps<{ pageMode?: 'settings' | 'facilities' | 'roles' | 'members'; organization: { id: number; name: string; slug: string; status: string; timezone: string }; facilities: Facility[]; members: Member[]; customRoles: CustomRole[]; permissionGroups?: PermissionGroup[]; roleTemplates?: RoleTemplate[] }>();
const base = '/organization/admin';
const newRole = useForm({ name: '', permissions: [] as string[] });
const selectedTemplate = ref('blank');
const applyTemplate = () => { newRole.permissions = [...(props.roleTemplates?.find(template => template.key === selectedTemplate.value)?.permissions ?? [])]; };
const selectedPermissionCount = (permissions: string[]) => permissions.length;
const roleEdits = ref<Record<number, { name: string; permissions: string[] }>>(Object.fromEntries(props.customRoles.map(r => [r.id, { name: r.name, permissions: [...r.permissions] }])));
const createRole = () => newRole.post(`${base}/roles`, { preserveScroll: true, onSuccess: () => newRole.reset() });
const updateRole = (id: number) => useForm(roleEdits.value[id]).patch(`${base}/roles/${id}`, { preserveScroll: true });
const roleToDelete = ref<number | null>(null);
const deleteRole = (id: number) => { roleToDelete.value = id; };
const confirmDeleteRole = () => { if (roleToDelete.value !== null) useForm({}).delete(`${base}/roles/${roleToDelete.value}`, { preserveScroll: true }); roleToDelete.value = null; }; 
const settings = useForm({ name: props.organization.name, timezone: props.organization.timezone });
const blank = () => ({ name: '', code: '', status: 'active', address_line_1: '', address_line_2: '', city: '', state: '', postal_code: '', phone: '', email: '', timezone: '', contacts: [] as FacilityContact[] });
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
    const contacts = (item.contact_methods ?? []).filter(c => c.kind === 'phone' || c.kind === 'email').map(c => ({ kind: c.kind, label: c.label, value: c.value, is_primary: !!c.is_primary }));
    if (!contacts.some(c => c.kind === 'phone') && item.phone) contacts.push({ kind: 'phone', label: 'Main', value: item.phone, is_primary: true });
    if (!contacts.some(c => c.kind === 'email') && item.email) contacts.push({ kind: 'email', label: 'Main', value: item.email, is_primary: true });
    facility.defaults({ ...blank(), ...Object.fromEntries(Object.entries(item).filter(([k]) => k !== 'contact_methods').map(([k, v]) => [k, v ?? ''])), contacts });
    facility.reset();
};
const addFacilityContact = (kind: 'phone' | 'email') => {
    facility.contacts.push({ kind, label: 'Work', value: '', is_primary: !facility.contacts.some(c => c.kind === kind) });
};
const removeFacilityContact = (index: number) => {
    const removed = facility.contacts[index];
    facility.contacts.splice(index, 1);
    if (removed.is_primary && !facility.contacts.some(c => c.kind === removed.kind && c.is_primary)) {
        const replacement = facility.contacts.find(c => c.kind === removed.kind);
        if (replacement) replacement.is_primary = true;
    }
};
const setPrimaryFacilityContact = (index: number) => {
    const kind = facility.contacts[index].kind;
    facility.contacts.forEach((c, i) => { if (c.kind === kind) c.is_primary = i === index; });
};
const clear = () => { editing.value = null; facility.defaults(blank()); facility.reset(); facility.clearErrors(); };
const saveFacility = () => {
    if (editing.value !== null) facility.patch(`${base}/facilities/${editing.value}`, { onSuccess: clear, preserveScroll: true, onError: (errors) => { facilitySection.value = errors.name || errors.code || errors.status ? 'details' : 'contact'; } });
    else facility.post(`${base}/facilities`, { onSuccess: clear, preserveScroll: true, onError: (errors) => { facilitySection.value = errors.name || errors.code || errors.status ? 'details' : 'contact'; } });
};
const facilityToDelete = ref<Facility | null>(null);
const remove = (item: Facility) => { facilityToDelete.value = item; };
const confirmRemove = () => { if (facilityToDelete.value) useForm({}).delete(`${base}/facilities/${facilityToDelete.value.id}`, { preserveScroll: true }); facilityToDelete.value = null; };
const pageMode = computed(() => props.pageMode ?? 'settings');
const facilitySection = ref('details');
const facilityFormSections = computed(() => [{ id: 'details', title: 'Facility Details', description: 'Name, code and status', invalid: !!(facility.errors.name || facility.errors.code || facility.errors.status) }, { id: 'contact', title: 'Location & Contact', description: 'Address and contact information', invalid: !!(facility.errors.address_line_1 || facility.errors.address_line_2 || facility.errors.city || facility.errors.state || facility.errors.postal_code || facility.errors.phone || facility.errors.email || facility.errors.timezone || facility.errors.contacts) }]);
const facilityDeleteOpen = computed({ get: () => facilityToDelete.value !== null, set: (value: boolean) => { if (!value) facilityToDelete.value = null; } });
const roleDeleteOpen = computed({ get: () => roleToDelete.value !== null, set: (value: boolean) => { if (!value) roleToDelete.value = null; } });
const facilityColumns = [{ key: 'name', label: 'Facility' }, { key: 'code', label: 'Code' }, { key: 'status', label: 'Status' }, { key: 'city', label: 'City' }, { key: 'state', label: 'State' }];
const openFacility = (item?: Facility) => { if (item) edit(item); else clear(); facilitySection.value = 'details'; document.getElementById('facility-editor')?.scrollIntoView({ behavior: 'smooth', block: 'start' }); };
</script>

<template>
    <Head :title="pageMode === 'settings' ? 'Organization Settings' : pageMode === 'facilities' ? 'Facilities & Locations' : pageMode === 'roles' ? 'Roles & Permissions' : 'Staff Management'" />
    <main class="mx-auto w-full max-w-7xl space-y-6 p-4 md:p-8">
      <header class="space-y-2">
        <a href="/admin" class="text-sm text-muted-foreground underline">← Admin Command Center</a>
        <h1 class="text-2xl font-semibold">{{ pageMode === 'settings' ? 'Organization Settings' : pageMode === 'facilities' ? 'Facilities & Locations' : pageMode === 'roles' ? 'Roles & Permissions' : 'Staff Management' }}</h1>
        <p class="text-sm text-muted-foreground">{{ organization.name }}</p>
      </header>
        <section v-if="pageMode === 'settings'" id="organization-settings" class="space-y-4 rounded-xl border bg-card p-5 shadow-sm">
            <h2 class="text-lg font-semibold">Organization settings</h2>
            <a href="/organization/admin/insurance-providers" class="inline-flex rounded-md border px-4 py-2 text-sm hover:bg-accent">Manage Insurance Providers →</a>
            <form class="grid gap-4 md:grid-cols-2" @submit.prevent="settings.patch(`${base}/settings`, { preserveScroll: true })">
                <label class="grid gap-1 text-sm">Name<input v-model="settings.name" required maxlength="255" class="rounded-md border bg-background p-2" /><span class="text-red-600">{{ settings.errors.name }}</span></label>
                <label class="grid gap-1 text-sm">Timezone<input v-model="settings.timezone" required placeholder="America/New_York" class="rounded-md border bg-background p-2" /><span class="text-red-600">{{ settings.errors.timezone }}</span></label>
                <div><button class="rounded-md bg-primary px-4 py-2 text-primary-foreground" :disabled="settings.processing">Save settings</button></div>
            </form>
        </section>

        <section v-if="pageMode === 'facilities'" id="facilities" class="space-y-5 rounded-xl border bg-card p-5 shadow-sm">
            <h2 class="text-lg font-semibold">Facilities ({{ facilities.length }})</h2>
            <p class="text-sm text-muted-foreground">Find and manage the facilities associated with this organization.</p>
            <WayAlongRecordTable :rows="facilities" :columns="facilityColumns" storage-key="organization-facilities" :can-add="true" add-label="Add facility" @add="openFacility()">
                <template #cell-status="{ row }"><span class="inline-flex rounded-full bg-muted px-2 py-1 text-xs font-medium capitalize">{{ row.status }}</span></template>
                <template #actions="{ row }"><div class="flex justify-end gap-2"><button type="button" class="rounded-md border px-3 py-1.5 text-sm hover:bg-accent" @click="openFacility(row as Facility)">Edit</button><button type="button" class="rounded-md border px-3 py-1.5 text-sm text-destructive hover:bg-accent" @click="remove(row as Facility)">Delete</button></div></template>
            </WayAlongRecordTable>
            <div id="facility-editor" class="scroll-mt-6 border-t pt-5">
            <h3 class="font-medium">{{ editing === null ? 'Add facility' : 'Edit facility' }}</h3>
            <form class="mt-4 space-y-4" @submit.prevent="saveFacility">
                <WayAlongFormSections v-model:active="facilitySection" :sections="facilityFormSections">
                    <template #default="{ section }">
                        <div v-if="section === 'details'" class="grid gap-4 md:grid-cols-2">
                            <label v-for="field in (['name', 'code'] as const)" :key="field" class="grid gap-1 text-sm"><span class="font-medium capitalize">{{ field }} <span class="text-destructive">*</span></span><input v-model="facility[field]" required :maxlength="field === 'code' ? 50 : 255" class="rounded-md border bg-background p-2" /><span class="text-destructive">{{ facility.errors[field] }}</span></label>
                            <label class="grid gap-1 text-sm"><span class="font-medium">Status</span><select v-model="facility.status" class="rounded-md border bg-background p-2"><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
                        </div>
                        <div v-else class="grid gap-4 md:grid-cols-2">
                            <label v-for="field in (['address_line_1','address_line_2','city','state','postal_code','timezone'] as const)" :key="field" class="grid gap-1 text-sm"><span class="font-medium capitalize">{{ field.replaceAll('_',' ') }}</span><input v-model="facility[field]" :maxlength="field === 'state' ? 2 : 255" class="rounded-md border bg-background p-2" /><span class="text-destructive">{{ facility.errors[field] }}</span></label>
                            <div v-for="kind in (['phone', 'email'] as const)" :key="kind" class="space-y-3 rounded-lg border p-3 md:col-span-2">
                                <div class="flex items-center justify-between gap-2"><h4 class="font-medium">{{ kind === 'phone' ? 'Phone numbers' : 'Email addresses' }}</h4><button type="button" class="rounded-md border px-3 py-1.5 text-sm hover:bg-accent" @click="addFacilityContact(kind)">+ Add {{ kind === 'phone' ? 'phone' : 'email' }}</button></div>
                                <div v-for="(contact, index) in facility.contacts" :key="index">
                                    <div v-if="contact.kind === kind" class="grid items-end gap-2 sm:grid-cols-[minmax(90px,1fr)_minmax(130px,2fr)_auto_auto]">
                                        <label class="grid gap-1 text-sm">Label<input v-model="contact.label" maxlength="50" placeholder="Main, Office, Fax" class="min-w-0 rounded-md border bg-background p-2" required /></label>
                                        <label class="grid gap-1 text-sm">{{ kind === 'phone' ? 'Phone number' : 'Email address' }}<input v-model="contact.value" :type="kind === 'email' ? 'email' : 'tel'" maxlength="255" required class="min-w-0 rounded-md border bg-background p-2" /></label>
                                        <label class="flex items-center gap-1.5 pb-2 text-sm"><input type="radio" :name="`primary-facility-${kind}`" :checked="contact.is_primary" @change="setPrimaryFacilityContact(index)" /> Primary</label>
                                        <button type="button" class="rounded-md border px-3 py-2 text-sm text-destructive" :aria-label="`Remove ${kind}`" @click="removeFacilityContact(index)">Remove</button>
                                    </div>
                                </div>
                                <p v-if="!facility.contacts.some(c => c.kind === kind)" class="text-sm text-muted-foreground">No {{ kind === 'phone' ? 'phone numbers' : 'email addresses' }} added.</p>
                            </div>
                            <p v-if="facility.errors.contacts" class="text-sm text-destructive md:col-span-2">{{ facility.errors.contacts }}</p>
                        </div>
                    </template>
                </WayAlongFormSections>
                <p v-if="Object.keys(facility.errors).length" role="alert" class="text-sm text-destructive">Please review the sections marked with validation errors.</p>
                <div class="flex justify-end gap-2"><button v-if="editing !== null" type="button" class="rounded-md border px-4 py-2" @click="clear">Cancel</button><button class="rounded-md bg-primary px-4 py-2 text-primary-foreground" :disabled="facility.processing">{{ editing === null ? 'Add facility' : 'Save changes' }}</button></div>
            </form>
            </div>
        </section>

        <section v-if="pageMode === 'roles'" id="custom-roles" class="space-y-6 rounded-xl border bg-card p-5 shadow-sm">
            <div class="space-y-1">
                <h2 class="text-lg font-semibold">Roles &amp; Permissions</h2>
                <p class="text-sm text-muted-foreground">Start from a standard role template, then grant only the individual permissions required. Templates populate the form once; later changes to a template do not silently change an assigned role.</p>
                <p class="text-xs text-muted-foreground">Permissions are also restricted by active organization membership, facility assignment, and assigned-client rules. Owner and system administration permissions cannot be granted here.</p>
            </div>
            <form class="space-y-5 rounded-xl border bg-muted/20 p-4" @submit.prevent="createRole">
                <div class="grid gap-4 md:grid-cols-2">
                    <label class="grid gap-1 text-sm"><span class="font-medium">Role name</span><input v-model="newRole.name" required maxlength="100" class="rounded-md border bg-background p-2" /><span class="text-destructive">{{ newRole.errors.name }}</span></label>
                    <label class="grid gap-1 text-sm"><span class="font-medium">Start with default permissions</span><select v-model="selectedTemplate" class="rounded-md border bg-background p-2" @change="applyTemplate"><option v-for="template in roleTemplates ?? []" :key="template.key" :value="template.key">{{ template.label }}</option></select><span class="text-xs text-muted-foreground">You can add or remove individual permissions below.</span></label>
                </div>
                <div v-for="group in permissionGroups ?? []" :key="group.name" class="space-y-2">
                    <div class="flex items-center justify-between gap-2"><h3 class="font-semibold">{{ group.name }}</h3><span class="text-xs text-muted-foreground">{{ group.permissions.filter(item => newRole.permissions.includes(item.key)).length }} / {{ group.permissions.length }}</span></div>
                    <div class="grid gap-2 md:grid-cols-2">
                        <label v-for="permission in group.permissions" :key="permission.key" class="flex cursor-pointer items-start gap-3 rounded-lg border bg-background p-3 hover:bg-accent/40"><input v-model="newRole.permissions" type="checkbox" :value="permission.key" class="mt-1" /><span class="grid gap-1"><span class="text-sm font-medium">{{ permission.label }}</span><span class="text-xs text-muted-foreground">{{ permission.description }}</span><code class="text-[11px] text-muted-foreground">{{ permission.key }}</code></span></label>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-3"><button class="rounded-md bg-primary px-4 py-2 text-primary-foreground" :disabled="newRole.processing">Create role ({{ selectedPermissionCount(newRole.permissions) }} permissions)</button><span class="text-sm text-destructive">{{ newRole.errors.permissions }}</span></div>
            </form>
            <div class="space-y-4"><h3 class="font-semibold">Existing custom roles</h3>
                <div v-for="role in customRoles" :key="role.id" class="space-y-3 rounded-xl border p-4">
                    <template v-if="roleEdits[role.id]">
                        <div class="flex flex-wrap items-center justify-between gap-3"><label class="grid gap-1 text-sm"><span class="font-medium">Role name</span><input v-model="roleEdits[role.id].name" :aria-label="`Name for ${role.name}`" class="rounded-md border bg-background p-2" /></label><span class="text-xs text-muted-foreground">{{ selectedPermissionCount(roleEdits[role.id].permissions) }} permissions granted</span></div>
                        <details v-for="group in permissionGroups ?? []" :key="group.name" class="rounded-lg border p-3"><summary class="cursor-pointer font-medium">{{ group.name }} ({{ group.permissions.filter(item => roleEdits[role.id].permissions.includes(item.key)).length }} selected)</summary><div class="mt-3 grid gap-2 md:grid-cols-2"><label v-for="permission in group.permissions" :key="permission.key" class="flex items-start gap-2 rounded-md p-2 text-sm hover:bg-muted"><input v-model="roleEdits[role.id].permissions" type="checkbox" :value="permission.key" class="mt-1" /><span><span class="block font-medium">{{ permission.label }}</span><span class="block text-xs text-muted-foreground">{{ permission.description }}</span></span></label></div></details>
                        <div class="flex gap-2"><button class="rounded-md bg-primary px-4 py-2 text-sm text-primary-foreground" @click="updateRole(role.id)">Save role</button><button class="rounded-md border px-4 py-2 text-sm text-destructive" @click="deleteRole(role.id)">Delete</button></div>
                    </template>
                </div>
            </div>
        </section>

        <section v-if="pageMode === 'members'" class="space-y-4 rounded-xl border bg-card p-5 shadow-sm">
            <h2 class="text-lg font-semibold">Staff memberships ({{ members.length }})</h2>
            <a href="/organization/admin/staff" class="inline-flex rounded-md border px-3 py-2 text-sm hover:bg-muted">Open staff directory (search and filters) →</a>
            <form class="flex flex-wrap items-end gap-3 rounded-md border p-3" @submit.prevent="sendInvitation">
                <label class="grid gap-1 text-sm">Invite staff by email
                    <input v-model="invitation.email" type="email" required class="rounded-md border bg-background p-2" />
                    <span class="text-red-600">{{ invitation.errors.email }}</span>
                </label>
                <label class="grid gap-1 text-sm">Invitation role
                    <select v-model="invitation.role" class="rounded-md border bg-background p-2">
                        <option v-for="role in ['staff', 'floor_staff', 'clinician', 'admin', 'owner']" :key="role" :value="role">{{ role }}</option>

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
                        <option v-for="role in ['staff', 'floor_staff', 'clinician', 'admin', 'owner']" :key="role" :value="role">{{ role }}</option>
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
                        <option v-for="role in ['staff', 'floor_staff', 'clinician', 'admin', 'owner']" :key="role" :value="role">{{ role }}</option>
                        <option v-for="role in customRoles" :key="role.id" :value="role.value">{{ role.name }}</option>
                    </select>
                    <select v-model="memberEdits[member.id].status" :aria-label="`Membership status for ${member.name}`" class="rounded-md border bg-background p-2">
                        <option value="active">Active</option><option value="inactive">Inactive</option>
                    </select>
                    <button class="rounded-md border px-3 py-2" :disabled="savingMember === member.id" @click="saveMember(member.id)">Save</button>
                </div>
            </div>
        </section>
        <WayAlongConfirmDialog v-model:open="facilityDeleteOpen" title="Delete facility?" :description="`Delete ${facilityToDelete?.name ?? 'this facility'}? This action cannot be undone.`" confirm-label="Delete" destructive @confirm="confirmRemove" />
        <WayAlongConfirmDialog v-model:open="roleDeleteOpen" title="Delete custom role?" description="This custom role will be permanently deleted." confirm-label="Delete" destructive @confirm="confirmDeleteRole" />
    </main>
</template>
