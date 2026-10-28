<script setup lang="ts">
import { computed, ref } from 'vue';
import { Mail, Phone, ShieldCheck, UserRoundCog, Trash2 } from '@lucide/vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import WayAlongConfirmDialog from '@/components/WayAlongConfirmDialog.vue';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';

const props = defineProps<{
    facility: { id: number; name: string };
    canManageStaffPermissions?: boolean;
    currentUserId?: number;
    actorOrganizationRole?: string | null;
    actorFacilityRole?: string | null;
    canAssignRoles?: boolean;
    canAssignOwner?: boolean;
    canCreateOrganizationStaff?: boolean;
    eligibleMembers?: { id: number; name: string; email: string }[];
    staff: { id: number; name: string; phone: string | null; email: string | null; role: string | null; organizationRole: string | null; canRemove: boolean; isLastFacilityOwner: boolean; canEditPermissions: boolean; canEditRoles: boolean; managementStatus: string | null }[];
}>();
type SortColumn = 'name' | 'role' | 'phone' | 'email';
const search = ref('');
const sortColumn = ref<SortColumn>('name');
const sortDirection = ref<'asc' | 'desc'>('asc');
const searchText = computed(() => search.value.trim().toLocaleLowerCase());
const filteredStaff = computed(() => props.staff.filter((person) => {
    if (!searchText.value) return true;
    return [person.name, roleLabel(person.role), person.organizationRole, person.phone, person.email]
        .some((value) => (value || '').toLocaleLowerCase().includes(searchText.value));
}).sort((a, b) => {
    const sortValue = (person: typeof props.staff[number]) => sortColumn.value === 'role'
        ? `${roleLabel(person.role)} ${person.organizationRole || ''}`
        : person[sortColumn.value] || '';
    const comparison = sortValue(a).localeCompare(sortValue(b), undefined, { sensitivity: 'base', numeric: true });
    return sortDirection.value === 'asc' ? comparison : -comparison;
}));
function changeSort(column: SortColumn) {
    if (sortColumn.value === column) sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc';
    else { sortColumn.value = column; sortDirection.value = 'asc'; }
}
const sortIndicator = (column: SortColumn) => sortColumn.value !== column ? '↕' : sortDirection.value === 'asc' ? '↑' : '↓';
const canManage = computed(() => Boolean(props.canManageStaffPermissions && props.canAssignRoles));
const showAdd = ref(false);
const form = useForm({ user_id: '', role: 'staff' });
const removing = ref<number | null>(null);
const removalTarget = ref<{ id: number; name: string } | null>(null);
const showLastOwnerWarning = ref(false);
const roleLabel = (role: string | null) => (role || 'staff').replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
function addStaff() {
    form.post('/facility/staff/assignments', { preserveScroll: true, onSuccess: () => { form.reset(); showAdd.value = false; } });
}
function requestRemoval(person: { id: number; name: string; isLastFacilityOwner: boolean }) {
    if (person.isLastFacilityOwner) {
        showLastOwnerWarning.value = true;
        return;
    }
    removalTarget.value = { id: person.id, name: person.name };
}
function confirmRemoval() {
    if (!removalTarget.value || removing.value !== null) return;
    removing.value = removalTarget.value.id;
    router.delete(`/facility/staff/assignments/${removalTarget.value.id}`, {
        preserveScroll: true,
        onFinish: () => { removing.value = null; removalTarget.value = null; },
    });
}
</script>

<template>
    <Head :title="`${facility.name} — Staff Roster`" />
    <main class="mx-auto w-full max-w-7xl space-y-5 p-4 md:p-8">
        <Link href="/dashboard" class="text-sm underline underline-offset-4">← My WayAlong</Link>
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold">{{ facility.name }} — Staff Roster</h1>
                <p class="mt-1 text-sm text-muted-foreground">Manage people assigned to this facility. Removing an assignment does not delete their central account.</p>
            </div>
            <button v-if="canManage" type="button" class="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground" @click="showAdd = !showAdd">{{ showAdd ? 'Close' : '+ Add Staff' }}</button>
        </header>
        <p class="text-sm text-muted-foreground">
            Signed-in access: Organization {{ roleLabel(actorOrganizationRole ?? 'staff') }}
            · Facility {{ roleLabel(actorFacilityRole ?? 'unassigned') }}.
            <span v-if="canAssignOwner" class="font-medium">Owner assignments permitted.</span>
            <span v-else>Only Owners may appoint a Facility Owner.</span>
        </p>
        <section v-if="canManage && showAdd" class="space-y-4 rounded-xl border bg-card p-5">
            <h2 class="text-lg font-semibold">Add staff to {{ facility.name }}</h2>
            <p class="text-sm text-muted-foreground">Choose an active organization member who is not already assigned to this facility. New identities are created through organization staff management.</p>
            <form class="grid gap-4 md:grid-cols-2" @submit.prevent="addStaff">
                <label class="space-y-2 text-sm font-medium">Organization member
                    <select v-model="form.user_id" required class="block w-full rounded-lg border bg-background p-2.5">
                        <option value="" disabled>Select a staff member</option>
                        <option v-for="member in eligibleMembers || []" :key="member.id" :value="String(member.id)">{{ member.name }} ({{ member.email }})</option>
                    </select>
                    <span v-if="form.errors.user_id" class="block text-sm text-destructive">{{ form.errors.user_id }}</span>
                </label>
                <label class="space-y-2 text-sm font-medium">Initial facility role
                    <select v-model="form.role" class="block w-full rounded-lg border bg-background p-2.5">
                        <option value="staff">Staff</option><option value="clinician">Clinician</option><option value="floor_staff">Floor Staff</option>
                        <option value="client_admin">Client Administrator</option><option value="facility_manager">Facility Manager</option>
                        <option value="facility_admin">Facility Admin</option>
                        <option v-if="canAssignOwner" value="facility_owner">Facility Owner (Owners only)</option>
                    </select>
                    <span v-if="form.errors.role" class="block text-sm text-destructive">{{ form.errors.role }}</span>
                </label>
                <div class="md:col-span-2"><button type="submit" :disabled="form.processing || !form.user_id" class="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground disabled:opacity-50">Add to facility</button></div>
            </form>
            <Link v-if="canCreateOrganizationStaff" href="/organization/admin/staff/create" class="text-sm underline underline-offset-4">Need to create a new organization staff account?</Link>
        </section>
        <div class="space-y-3 rounded-xl border bg-card p-4">
            <div class="flex flex-wrap items-end gap-3">
                <label class="min-w-56 flex-1 space-y-1 text-sm font-medium">
                    <span>Search staff contacts</span>
                    <input v-model="search" type="search" class="block w-full rounded-lg border bg-background px-3 py-2 font-normal"
                        placeholder="Search name, role, phone, or email…" aria-label="Search staff contacts" />
                </label>
                <button type="button" class="rounded-lg border px-3 py-2 text-sm" @click="search = ''; sortColumn = 'name'; sortDirection = 'asc'">Clear</button>
                <span class="text-xs text-muted-foreground" aria-live="polite">{{ filteredStaff.length }} of {{ staff.length }} staff</span>
            </div>
            <div class="overflow-x-auto">
            <table class="w-full min-w-[760px] text-left text-sm">
                <thead class="bg-muted/40"><tr>
                    <th v-for="column in ([['name', 'Staff member'], ['role', 'Facility role'], ['phone', 'Primary phone'], ['email', 'Primary email']] as const)" :key="column[0]" scope="col" class="px-3 py-3 font-semibold">
                        <button type="button" class="inline-flex items-center gap-2 hover:underline" :aria-label="`Sort by ${column[1]}`" @click="changeSort(column[0])">{{ column[1] }} <span aria-hidden="true" class="text-muted-foreground">{{ sortIndicator(column[0]) }}</span></button>
                    </th>
                    <th v-if="canManageStaffPermissions || canAssignRoles" scope="col" class="px-3 py-3 font-semibold">Manage</th>
                </tr></thead>
                <tbody>
                    <tr v-for="person in filteredStaff" :key="person.id" class="border-t">
                        <td class="px-3 py-3 font-medium">{{ person.name }}</td>
                        <td class="px-3 py-3"><div>{{ roleLabel(person.role) }}</div><div v-if="person.organizationRole === 'owner' || person.organizationRole === 'admin'" class="text-xs font-medium text-muted-foreground">Organization {{ roleLabel(person.organizationRole) }}</div></td>
                        <td class="px-3 py-3 text-center">
                            <a v-if="person.phone" :href="`tel:${person.phone}`" :title="`Call ${person.name}: ${person.phone}`" :aria-label="`Call ${person.name} at ${person.phone}`" class="inline-flex h-9 w-9 items-center justify-center rounded-md text-primary hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring">
                                <Phone :size="18" aria-hidden="true" />
                            </a>
                            <span v-else class="text-muted-foreground" title="No primary phone available">—</span>
                        </td>
                        <td class="px-3 py-3 text-center">
                            <a v-if="person.email" :href="`mailto:${person.email}`" :title="`Email ${person.name}: ${person.email}`" :aria-label="`Email ${person.name} at ${person.email}`" class="inline-flex h-9 w-9 items-center justify-center rounded-md text-primary hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring">
                                <Mail :size="18" aria-hidden="true" />
                            </a>
                            <span v-else class="text-muted-foreground" title="No primary email available">—</span>
                        </td>
                        <td v-if="canManageStaffPermissions || canAssignRoles" class="px-3 py-3">
                            <div v-if="person.id !== currentUserId && (person.canEditPermissions || person.canEditRoles || person.canRemove)" class="flex flex-wrap items-center gap-1.5">
                                <Link v-if="person.canEditPermissions" :href="`/facility/staff/${facility.id}/members/${person.id}/permissions`" class="inline-flex h-9 w-9 items-center justify-center rounded-md text-primary hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring" title="Edit permissions" aria-label="Edit permissions"><ShieldCheck :size="18" aria-hidden="true" /></Link>
                                <Link v-if="person.canEditRoles" :href="`/facility/staff/${facility.id}/members/${person.id}/roles`" class="inline-flex h-9 w-9 items-center justify-center rounded-md text-primary hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring" title="Edit roles" aria-label="Edit roles"><UserRoundCog :size="18" aria-hidden="true" /></Link>
                                <button v-if="person.canRemove" type="button" :disabled="removing === person.id" class="inline-flex h-9 w-9 items-center justify-center rounded-md text-destructive hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:opacity-50" title="Remove from facility" :aria-label="`Remove ${person.name} from facility`" @click="requestRemoval(person)"><Trash2 :size="18" aria-hidden="true" /></button>
                            </div>
                            <span v-else class="inline-flex items-center gap-1.5 text-xs text-muted-foreground" :title="person.managementStatus || 'Management unavailable'">
                                <span aria-hidden="true">🔒</span>{{ person.managementStatus || 'Management unavailable' }}
                            </span>
                        </td>
                    </tr>
                    <tr v-if="!filteredStaff.length"><td :colspan="canManageStaffPermissions || canAssignRoles ? 5 : 4" class="p-4 text-muted-foreground">{{ staff.length ? 'No staff match your search.' : 'No active staff assignments found.' }}</td></tr>
                </tbody>
            </table>
            </div>
        </div>
        <WayAlongConfirmDialog
            :open="removalTarget !== null"
            @update:open="(open: boolean) => { if (!open && removing === null) removalTarget = null; }"
            title="Remove staff from facility?"
            :description="`Remove ${removalTarget?.name ?? 'this person'} from ${facility.name}? Their WayAlong account and other facility assignments will remain unchanged.`"
            confirm-label="Remove from facility"
            destructive
            @confirm="confirmRemoval"
        />
        <Dialog v-model:open="showLastOwnerWarning">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Cannot remove the last Facility Owner</DialogTitle>
                    <DialogDescription>
                        You can't delete the last Owner. Assign another active Facility Owner to {{ facility.name }} before removing this person.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <button type="button" class="rounded-md bg-primary px-4 py-2 text-sm text-primary-foreground" @click="showLastOwnerWarning = false">OK</button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </main>
</template>
