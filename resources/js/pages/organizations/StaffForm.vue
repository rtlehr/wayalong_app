<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import WayAlongFormSections from '@/components/WayAlongFormSections.vue';

type Member = { id: number; name: string; email: string; role: string; status: string };
type EligibleUser = { id: number; name: string; email: string };
type Facility = { id: number; name: string; code: string };
const props = defineProps<{
    organization: { id: number; name: string };
    mode: 'create' | 'view' | 'edit';
    member?: Member;
    customRoles: { value: string; name: string }[];
    isOwner: boolean;
    eligibleUsers?: EligibleUser[];
    availableFacilities?: Facility[];
    staffDetails?: { job_title?: string | null; staff_type?: string | null; credentials?: string | null; notes?: string | null } | null;
    assignedFacilities?: number[];
    selectedFacilityId?: number;
    permissionGroups?: { name: string; permissions: { key: string; label: string; description: string }[] }[];
    organizationOverrides?: Record<string, string>;
    facilityOverrides?: Record<string, string>;
    effectivePermissions?: Record<string, boolean>;
    facilityRoleAssignment?: { id: number; role: string | null } | null;
    facilityAdditionalRoles?: string[];
    facilityStaffDetails?: { job_title?: string | null; staff_type?: string | null; credentials?: string | null; notes?: string | null } | null;
}>();
const base = '/organization/admin/staff';
const activeSection = ref('user');
const userSearch = ref('');
const selectedUserId = ref<number | null>(null);
const inviteEmail = ref('');
const invitationMode = ref(false);
const matches = computed(() => (props.eligibleUsers ?? []).filter(u => `${u.name} ${u.email}`.toLowerCase().includes(userSearch.value.toLowerCase())));
const chosenUser = computed(() => (props.eligibleUsers ?? []).find(u => u.id === selectedUserId.value));
const roles = computed(() => [
    { value: 'staff', name: 'Staff' }, { value: 'facility_manager', name: 'Facility Manager' }, { value: 'clinician', name: 'Clinician' }, { value: 'floor_staff', name: 'Floor Staff' }, { value: 'client_admin', name: 'Client Administrator' },
    { value: 'admin', name: 'Admin' },
    ...(props.isOwner ? [{ value: 'owner', name: 'Owner' }] : []), ...props.customRoles,
]);
const membership = useForm({ role: props.member?.role ?? 'staff', status: props.member?.status ?? 'active' });
const facilityRoleForm = useForm({
    facility_id: props.selectedFacilityId ?? 0,
    primary_role: props.facilityRoleAssignment?.role ?? 'staff',
    additional_roles: [...(props.facilityAdditionalRoles ?? [])],
});
const facilityAdditionalOptions = [
    { value: 'clinician', label: 'Clinician' }, { value: 'floor_staff', label: 'Floor Staff' },
    { value: 'client_admin', label: 'Client Administrator' }, { value: 'staff', label: 'Staff' },
];
const toggleFacilityAdditional = (role: string, enabled: boolean) => {
    facilityRoleForm.additional_roles = enabled
        ? [...facilityRoleForm.additional_roles, role] : facilityRoleForm.additional_roles.filter(r => r !== role);
};
const saveFacilityRoles = () => {
    if (!props.member || readOnly.value || !props.selectedFacilityId) return;
    facilityRoleForm.patch(`${base}/${props.member.id}/facility-roles`, { preserveScroll: true });
};
const pendingPermission = ref('');
const permissionError = ref('');
const selectedScope = ref<'organization' | 'facility'>('organization');
const permissionChoices = computed(() => (props.permissionGroups ?? []).flatMap(g => g.permissions).filter(p => selectedScope.value === 'facility' ? p.key.startsWith('facility.') : !p.key.startsWith('facility.')));
const permissionDecision = (key: string) => selectedScope.value === 'facility' ? props.facilityOverrides?.[key] ?? 'inherit' : props.organizationOverrides?.[key] ?? 'inherit';
const updatePermission = (key: string, decision: string) => {
    if (!props.member || readOnly.value || pendingPermission.value) return;
    pendingPermission.value = key;
    permissionError.value = '';
    router.patch(`/organization/admin/members/${props.member.id}/permissions`, {
        permission: key, decision, facility_id: selectedScope.value === 'facility' ? props.selectedFacilityId : null,
    }, { preserveScroll: true, onFinish: () => { pendingPermission.value = ''; }, onError: (errors) => { permissionError.value = Object.values(errors).join(' '); } });
};
const invitation = useForm({ email: '', role: 'staff', facility_id: null as number | null });
const details = useForm({
    job_title: props.facilityStaffDetails?.job_title ?? props.staffDetails?.job_title ?? '', staff_type: props.facilityStaffDetails?.staff_type ?? props.staffDetails?.staff_type ?? '',
    credentials: props.facilityStaffDetails?.credentials ?? props.staffDetails?.credentials ?? '', notes: props.facilityStaffDetails?.notes ?? props.staffDetails?.notes ?? '',
    facility_id: props.selectedFacilityId ?? 0,
    facility_ids: [...(props.assignedFacilities ?? [])],
});
const sectionLabels = [
    { id: 'user', title: 'User selection', description: 'Shared user identity' },
    { id: 'details', title: 'Staff details', description: 'Selected facility profile' },
    { id: 'roles', title: 'Roles & permissions', description: 'Organization access' },
    { id: 'facilities', title: 'Facilities & locations', description: 'Work locations' },
    { id: 'clients', title: 'Client assignments', description: 'Future client caseload' },
];
const sections = computed(() => sectionLabels.map(section => ({ ...section, invalid: section.id === 'roles' ? !!(membership.errors.role || membership.errors.status) : section.id === 'details' ? !!(details.errors.job_title || details.errors.staff_type || details.errors.credentials || details.errors.notes) : section.id === 'facilities' ? !!details.errors.facility_ids : section.id === 'user' ? !!invitation.errors.email : false })));
const title = computed(() => props.mode === 'create' ? 'Add staff' : props.mode === 'edit' ? 'Edit staff' : 'View staff');
const readOnly = computed(() => props.mode === 'view');
const saveMembership = () => {
    if (!props.member || readOnly.value) return;
    membership.patch(`/organization/admin/members/${props.member.id}`, { preserveScroll: true });
};
const saveDetails = () => {
    if (!props.member || readOnly.value) return;
    details.patch(`${base}/${props.member.id}/details`, { preserveScroll: true });
};
const sendInvitation = () => {
    invitation.email = inviteEmail.value;
    invitation.post('/organization/admin/invitations', { onSuccess: () => router.visit(base) });
};
const selectExisting = () => {
    if (selectedUserId.value) router.visit(`${base}/${selectedUserId.value}/edit`);
};

</script>

<template>
    <Head :title="title" />
    <main class="mx-auto w-full max-w-7xl space-y-6 p-4 md:p-8">
        <header class="space-y-2">
            <a :href="base" class="text-sm underline">← Staff Directory</a>
            <h1 class="text-2xl font-semibold">{{ title }}</h1>
            <p class="text-sm text-muted-foreground">{{ organization.name }} · Personal identity is shared; staff details and access belong to this organization.</p>
        </header>
        <WayAlongFormSections v-model:active="activeSection" :sections="sections">
            <template #default>
                <div class="space-y-5">
                    <div>
                        <h2 class="text-lg font-semibold">{{ sections.find(s => s.id === activeSection)?.title }}</h2>
                        <p class="text-sm text-muted-foreground">Required fields are marked with an asterisk.</p>
                    </div>
                    <template v-if="activeSection === 'user'">
                        <template v-if="mode === 'create'">
                            <div class="flex flex-wrap gap-4 text-sm">
                                <label class="inline-flex items-center gap-2"><input v-model="invitationMode" type="radio" :value="false" /> Select organization user</label>
                                <label class="inline-flex items-center gap-2"><input v-model="invitationMode" type="radio" :value="true" /> Invite a user</label>
                            </div>
                            <template v-if="!invitationMode">
                                <label class="grid gap-1 text-sm">Search current organization users
                                    <input v-model="userSearch" class="rounded-md border bg-background px-3 py-2" placeholder="Search name or email" maxlength="100" />
                                </label>
                                <label class="grid gap-1 text-sm">Select user <span class="text-destructive">*</span>
                                    <select v-model="selectedUserId" class="rounded-md border bg-background px-3 py-2">
                                        <option :value="null">Select an existing member</option>
                                        <option v-for="user in matches" :key="user.id" :value="user.id">{{ user.name }} — {{ user.email }}</option>
                                    </select>
                                </label>
                                <p v-if="chosenUser" class="text-sm text-muted-foreground">{{ chosenUser.name }} already belongs to this organization. Continue to their staff profile to configure details and assignments.</p>
                                <button type="button" :disabled="!selectedUserId" class="rounded-md bg-primary px-4 py-2 text-sm text-primary-foreground disabled:opacity-50" @click="selectExisting">Open staff profile</button>
                            </template>
                            <form v-else class="space-y-3" @submit.prevent="sendInvitation">
                                <p class="text-sm text-muted-foreground">Invite someone not yet linked to this organization. The invitation can connect an existing WayAlong account without duplicating personal information. Other organizations' members are not searchable here.</p>
                                <label class="grid gap-1 text-sm">Email <span class="text-destructive">*</span>
                                    <input v-model="inviteEmail" type="email" required maxlength="255" class="rounded-md border bg-background px-3 py-2" />
                                    <span v-if="invitation.errors.email" class="text-destructive" role="alert">{{ invitation.errors.email }}</span>
                                </label>
                                <label class="grid gap-1 text-sm">Initial role <span class="text-destructive">*</span>
                                    <select v-model="invitation.role" required class="rounded-md border bg-background px-3 py-2">
                                        <option value="staff">Staff</option><option value="clinician">Clinician</option><option value="admin">Admin</option>
                                    </select>
                                </label>
                                <label class="grid gap-1 text-sm">Facility (optional)
                                    <select v-model="invitation.facility_id" class="rounded-md border bg-background px-3 py-2">
                                        <option :value="null">Organization only</option>
                                        <option v-for="facility in availableFacilities ?? []" :key="facility.id" :value="facility.id">{{ facility.name }}</option>
                                    </select><span v-if="invitation.errors.facility_id" class="text-destructive">{{ invitation.errors.facility_id }}</span>
                                </label>
                                <button class="rounded-md bg-primary px-4 py-2 text-sm text-primary-foreground" :disabled="invitation.processing">Send invitation</button>
                            </form>
                        </template>
                        <div v-else class="grid gap-3 text-sm">
                            <label class="grid gap-1">Name<input :value="member?.name" readonly class="rounded-md border bg-muted/30 px-3 py-2" /></label>
                            <label class="grid gap-1">Email<input :value="member?.email" readonly class="rounded-md border bg-muted/30 px-3 py-2" /></label>
                            <p class="text-xs text-muted-foreground">Shared personal and contact information is managed on the user's account, not duplicated here.</p>
                        </div>
                    </template>
                    <template v-else-if="activeSection === 'details'">
                        <p v-if="mode === 'create'" class="text-sm text-muted-foreground">Select or invite a user first, then add their staff details on the Edit Staff page.</p>
                        <form v-else class="space-y-4" @submit.prevent="saveDetails">
                            <p class="text-sm text-muted-foreground">Changes here apply only to the selected facility.</p>
                            <label class="grid gap-1 text-sm">Job title<input v-model="details.job_title" :readonly="readOnly" maxlength="255" class="rounded-md border bg-background px-3 py-2 read-only:bg-muted/30" /></label>
                            <label class="grid gap-1 text-sm">Staff type<input v-model="details.staff_type" :readonly="readOnly" maxlength="255" class="rounded-md border bg-background px-3 py-2 read-only:bg-muted/30" /></label>
                            <label class="grid gap-1 text-sm">Credentials<input v-model="details.credentials" :readonly="readOnly" maxlength="255" class="rounded-md border bg-background px-3 py-2 read-only:bg-muted/30" /></label>
                            <label class="grid gap-1 text-sm">Staff notes<textarea v-model="details.notes" :readonly="readOnly" maxlength="2000" rows="3" class="rounded-md border bg-background px-3 py-2 read-only:bg-muted/30" /></label>
                            <p v-if="Object.keys(details.errors).length" class="text-sm text-destructive" role="alert">{{ Object.values(details.errors).join(' ') }}</p>
                            <button v-if="!readOnly" type="submit" :disabled="details.processing" class="rounded-md bg-primary px-4 py-2 text-sm text-primary-foreground">Save staff details</button>
                        </form>
                    </template>
                    <template v-else-if="activeSection === 'roles'">
                        <p v-if="mode === 'create'" class="text-sm text-muted-foreground">The invitation sets the initial role. Edit the member after association to configure role and status.</p>
                        <form v-else class="space-y-4" @submit.prevent="saveMembership">
                            <label class="grid gap-1 text-sm">Organization role <span class="text-destructive">*</span>
                                <select v-model="membership.role" required :disabled="readOnly" class="rounded-md border bg-background px-3 py-2 disabled:bg-muted/30">
                                    <option v-for="role in roles" :key="role.value" :value="role.value">{{ role.name }}</option>
                                    <option v-if="!roles.some(role => role.value === membership.role)" :value="membership.role">{{ membership.role }}</option>
                                </select><span v-if="membership.errors.role" class="text-destructive">{{ membership.errors.role }}</span>
                            </label>
                            <label class="grid gap-1 text-sm">Status <span class="text-destructive">*</span>
                                <select v-model="membership.status" required :disabled="readOnly" class="rounded-md border bg-background px-3 py-2 disabled:bg-muted/30"><option value="active">Active</option><option value="inactive">Inactive</option></select>
                                <span v-if="membership.errors.status" class="text-destructive">{{ membership.errors.status }}</span>
                            </label>
                            <div class="space-y-4 rounded-xl border bg-muted/10 p-4">
                                <h3 class="font-semibold">Individual permission overrides</h3>
                                <p class="text-sm text-muted-foreground">Inherit uses the selected role. Allow or Deny takes effect immediately within the chosen scope. Organization and client-access boundaries remain enforced.</p>
                                <label class="grid gap-1 text-sm">Permission scope
                                    <select v-model="selectedScope" class="rounded-md border bg-background px-3 py-2">
                                        <option value="organization">Organization-wide permissions</option>
                                        <option v-if="selectedFacilityId" value="facility">Selected facility permissions</option>
                                    </select>
                                </label>
                                <div v-if="selectedScope === 'facility' && (assignedFacilities ?? []).length > 1" class="space-y-1">
                                    <label for="override-facility" class="text-sm font-medium">Facility</label>
                                    <select id="override-facility" :value="selectedFacilityId ?? 0" class="w-full rounded-md border bg-background px-3 py-2 text-sm" @change="router.visit(`${base}/${member?.id}/edit?facility_id=${($event.target as HTMLSelectElement).value}`)">
                                        <option v-for="facility in (availableFacilities ?? []).filter(f => (assignedFacilities ?? []).includes(f.id))" :key="facility.id" :value="facility.id">{{ facility.name }}</option>
                                    </select>
                                </div>
                                <p v-if="selectedScope === 'facility'" class="text-xs text-muted-foreground">Applies only to the selected facility. Client assignments and other mandatory access limits remain in force.</p>
                                <p v-if="permissionError" class="text-sm text-destructive" role="alert">{{ permissionError }}</p>
                                <div v-for="item in permissionChoices" :key="item.key" class="flex flex-wrap items-center justify-between gap-3 border-t py-3">
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-medium">{{ item.label }}</p>
                                        <p class="text-xs text-muted-foreground">{{ item.description }}</p>
                                        <p class="text-xs text-muted-foreground">{{ item.key }} · Currently {{ effectivePermissions?.[item.key] ? 'allowed' : 'denied' }}</p>
                                    </div>
                                    <select :value="permissionDecision(item.key)" :disabled="readOnly || !!pendingPermission" class="rounded-md border bg-background px-2 py-2 text-sm" :aria-label="`Override ${item.label}`" @change="updatePermission(item.key, ($event.target as HTMLSelectElement).value)">
                                        <option value="inherit">Inherit</option><option value="allow">Allow</option><option value="deny">Deny</option>
                                    </select>
                                </div>
                            </div>
                            <button v-if="!readOnly" type="submit" :disabled="membership.processing" class="rounded-md bg-primary px-4 py-2 text-sm text-primary-foreground">Save role and status</button>
                        </form>
                    </template>
                    <template v-else-if="activeSection === 'facilities'">
                        <form v-if="mode === 'edit' && selectedFacilityId && facilityRoleAssignment" class="mb-5 space-y-3 rounded-xl border bg-card p-4" @submit.prevent="saveFacilityRoles">
                            <h3 class="font-semibold">Facility roles · {{ availableFacilities?.find(f => f.id === selectedFacilityId)?.name }}</h3>
                            <p class="text-xs text-muted-foreground">The primary role defines leadership authority. Additional roles grant ordinary capabilities only at this facility.</p>
                            <label class="grid gap-1 text-sm">Primary facility role
                                <select v-model="facilityRoleForm.primary_role" class="rounded-md border bg-background px-3 py-2">
                                    <option value="staff">Staff</option><option value="clinician">Clinician</option>
                                    <option value="floor_staff">Floor Staff</option><option value="client_admin">Client Administrator</option>
                                    <option value="facility_manager">Facility Manager</option><option value="facility_admin">Facility Admin</option>
                                    <option v-if="isOwner || facilityRoleForm.primary_role === 'facility_owner'" value="facility_owner">Facility Owner (Owner assignment required)</option>
                                </select>
                            </label>
                            <fieldset class="space-y-2"><legend class="text-sm font-medium">Additional facility roles</legend>
                                <label v-for="option in facilityAdditionalOptions" :key="option.value" class="flex items-center gap-2 text-sm">
                                    <input type="checkbox" :checked="facilityRoleForm.additional_roles.includes(option.value)" @change="toggleFacilityAdditional(option.value, ($event.target as HTMLInputElement).checked)" />{{ option.label }}
                                </label>
                            </fieldset>
                            <p v-if="Object.keys(facilityRoleForm.errors).length" class="text-sm text-destructive" role="alert">{{ Object.values(facilityRoleForm.errors).join(' ') }}</p>
                            <button class="rounded-md bg-primary px-4 py-2 text-sm text-primary-foreground disabled:opacity-50" :disabled="facilityRoleForm.processing">Save facility roles</button>
                        </form>
                        <p class="text-sm text-muted-foreground">Facility assignments belong to the user's organization membership. Maintain them in User Management, not within an individual staff profile.</p>
                        <ul class="space-y-2"><li v-for="facility in (availableFacilities ?? []).filter(f => (assignedFacilities ?? []).includes(f.id))" :key="facility.id" class="rounded-md border p-3 text-sm">{{ facility.name }}</li></ul>
                        <a v-if="member" :href="`/organization/admin/users/${member.id}/edit`" class="inline-block rounded-md border px-4 py-2 text-sm">Edit facility assignments</a>
                    </template>
                    <div v-else class="space-y-2 text-sm">
                        <p>Client assignment management will be connected when the Client Management module is available.</p>
                        <p class="text-muted-foreground">Client lists and permissions will always be scoped to this organization. No client access is granted by this placeholder.</p>
                    </div>
                    <div class="flex gap-3 border-t pt-4">
                        <a v-if="mode === 'view' && member" :href="`${base}/${member.id}/edit?facility_id=${selectedFacilityId ?? 0}`" class="rounded-md bg-primary px-4 py-2 text-sm text-primary-foreground">Edit staff</a>
                        <a :href="base" class="rounded-md border px-4 py-2 text-sm">{{ mode === 'view' ? 'Back to directory' : 'Close' }}</a>
                    </div>
                </div>
            </template>
        </WayAlongFormSections>
    </main>
</template>
