<script setup lang="ts">
import { Head, useForm, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import WayAlongFormSections from '@/components/WayAlongFormSections.vue';

type User = { id: number; name: string; email: string; phone?: string | null; mobile_phone?: string | null; address_line_1?: string | null; address_line_2?: string | null; city?: string | null; state?: string | null; postal_code?: string | null; is_active?: boolean; display_name?: string };
type Facility = { id: number; name: string; code: string };
type Contact = { kind: 'email' | 'phone' | 'address'; label: string; is_primary: boolean; value: string; line_1: string; line_2: string; city: string; state: string; postal_code: string; country: string };
const props = defineProps<{ mode: 'create' | 'view' | 'edit'; organization: { id: number; name: string }; user?: User; availableFacilities: Facility[]; assignedFacilities: number[]; contactMethods?: Contact[]; membershipStatus?: string }>();
const resetConfirm = ref(false);
const activeConfirm = ref(false);
const addContact = (kind: Contact['kind']) => { if (readonly.value) return; form.contacts.push({ kind, label: kind === 'phone' ? 'Mobile' : kind === 'email' ? 'Personal' : 'Home', is_primary: !form.contacts.some(c => c.kind === kind && c.is_primary), value: '', line_1: '', line_2: '', city: '', state: '', postal_code: '', country: 'US' }); };
const removeContact = (index: number) => { if (!readonly.value) form.contacts.splice(index, 1); };
const markPrimary = (index: number) => { const kind = form.contacts[index].kind; form.contacts.forEach((c, i) => { if (c.kind === kind) c.is_primary = i === index; }); };
const sendReset = () => { resetConfirm.value = false; if (props.user) router.post(`${base}/${props.user.id}/password-reset`); };
const toggleActive = () => { activeConfirm.value = false; if (props.user) router.patch(`${base}/${props.user.id}/active`, { is_active: props.membershipStatus !== 'active' }); };
const base = '/organization/admin/users';
const active = ref('personal');
const readonly = computed(() => props.mode === 'view');
const form = useForm({
    name: props.user?.name ?? '', email: props.user?.email ?? '',
    display_name: props.user?.display_name ?? props.user?.name ?? '',
    membership_status: props.membershipStatus ?? 'active',
    phone: props.user?.phone ?? '', mobile_phone: props.user?.mobile_phone ?? '',
    address_line_1: props.user?.address_line_1 ?? '', address_line_2: props.user?.address_line_2 ?? '',
    city: props.user?.city ?? '', state: props.user?.state ?? '', postal_code: props.user?.postal_code ?? '',
    facility_ids: [...props.assignedFacilities],
    contacts: (props.contactMethods ?? []).map(c => ({ kind: c.kind, label: c.label, is_primary: !!c.is_primary, value: c.value ?? '', line_1: c.line_1 ?? '', line_2: c.line_2 ?? '', city: c.city ?? '', state: c.state ?? '', postal_code: c.postal_code ?? '', country: c.country ?? 'US' })),
});
const sections = computed(() => [
    { id: 'personal', title: 'Personal information', description: 'Central identity and organization display name', invalid: !!(form.errors.name || form.errors.email) },
    { id: 'contact', title: 'Contact information', description: 'Phone numbers and address', invalid: Object.keys(form.errors).some(key => key.startsWith('contacts') || ['phone', 'mobile_phone', 'address_line_1', 'address_line_2', 'city', 'state', 'postal_code'].includes(key)) },
    { id: 'security', title: 'Account & security', description: 'Membership status and central password reset', invalid: false },
    { id: 'facilities', title: 'Facilities & locations', description: 'Current organization assignments', invalid: !!form.errors.facility_ids },
]);
const title = computed(() => props.mode === 'create' ? 'Add user' : props.mode === 'edit' ? 'Edit user' : 'View user');
const save = () => { if (readonly.value) return; if (props.mode === 'create') form.post(base); else form.patch(`${base}/${props.user?.id}`); };
const toggleFacility = (id: number, checked: boolean) => { if (checked) { if (!form.facility_ids.includes(id)) form.facility_ids.push(id); } else form.facility_ids = form.facility_ids.filter(v => v !== id); };
</script>
<template>
    <Head :title="title" />
    <main class="mx-auto w-full max-w-7xl space-y-6 p-4 md:p-8">
        <header class="space-y-2"><a :href="base" class="text-sm underline">← User Management</a><h1 class="text-2xl font-semibold">{{ title }}</h1><p class="text-sm text-muted-foreground">{{ organization.name }} · The central login is shared; contact details, membership status, and facilities belong to this organization.</p></header>
        <WayAlongFormSections v-model:active="active" :sections="sections"><template #default>
            <form class="space-y-5" @submit.prevent="save">
                <div><h2 class="text-lg font-semibold">{{ sections.find(s => s.id === active)?.title }}</h2><p class="text-sm text-muted-foreground">Fields marked * are required.</p></div>
                <template v-if="active === 'personal'"><label v-for="field in ([{ key: 'name', label: 'Full name' }, { key: 'email', label: 'Email address' }] as const)" :key="field.key" class="grid gap-1 text-sm">{{ field.label }} <span class="text-destructive">*</span><input v-model="form[field.key]" :type="field.key === 'email' ? 'email' : 'text'" :readonly="readonly || (mode !== 'create')" required maxlength="255" class="rounded-md border bg-background px-3 py-2 read-only:bg-muted/30" /><span v-if="form.errors[field.key]" role="alert" class="text-destructive">{{ form.errors[field.key] }}</span></label><label class="grid gap-1 text-sm">Display name in this organization<input v-model="form.display_name" :readonly="readonly" maxlength="255" class="rounded-md border bg-background px-3 py-2" /></label><p v-if="mode !== 'create'" class="text-xs text-muted-foreground">Central name and login email cannot be modified by organization administrators.</p><p v-if="mode === 'create'" class="text-xs text-muted-foreground">New users receive an account setup email. To link an existing WayAlong user without duplicating their account, use the invitation workflow from Add Staff.</p></template>
                <template v-else-if="active === 'contact'">
                    <p class="text-sm text-muted-foreground">Add multiple contact records. Only the primary login email under Personal Information is used for authentication.</p>
                    <div v-for="kind in (['email', 'phone', 'address'] as const)" :key="kind" class="space-y-3 rounded-lg border p-4">
                        <div class="flex items-center justify-between"><h3 class="font-medium capitalize">{{ kind === 'address' ? 'Addresses' : kind === 'email' ? 'Email addresses' : 'Phone numbers' }}</h3><button v-if="!readonly" type="button" class="rounded-md border px-3 py-1 text-sm" @click="addContact(kind)">+ Add {{ kind }}</button></div>
                        <p v-if="!form.contacts.some(c => c.kind === kind)" class="text-sm text-muted-foreground">No {{ kind }} records yet.</p>
                        <div v-for="(contact, index) in form.contacts" v-show="contact.kind === kind" :key="index" class="space-y-3 rounded-md bg-muted/30 p-3">
                            <div class="flex flex-wrap items-center gap-3"><label class="grid flex-1 gap-1 text-sm">Type / label<input v-model="contact.label" :readonly="readonly" maxlength="50" required class="rounded-md border bg-background px-3 py-2" /></label><label class="flex items-center gap-2 text-sm"><input type="checkbox" :checked="contact.is_primary" :disabled="readonly" @change="markPrimary(index)" /> Primary</label><button v-if="!readonly" type="button" class="rounded-md border px-3 py-2 text-sm text-destructive" @click="removeContact(index)">Remove</button></div>
                            <label v-if="kind !== 'address'" class="grid gap-1 text-sm">{{ kind === 'email' ? 'Email address' : 'Phone number' }}<input v-model="contact.value" :type="kind === 'email' ? 'email' : 'tel'" :readonly="readonly" required maxlength="255" class="rounded-md border bg-background px-3 py-2" /></label>
                            <div v-else class="grid gap-3 md:grid-cols-2"><label class="grid gap-1 text-sm md:col-span-2">Address line 1<input v-model="contact.line_1" :readonly="readonly" required maxlength="255" class="rounded-md border bg-background px-3 py-2" /></label><label class="grid gap-1 text-sm md:col-span-2">Address line 2<input v-model="contact.line_2" :readonly="readonly" maxlength="255" class="rounded-md border bg-background px-3 py-2" /></label><label class="grid gap-1 text-sm">City<input v-model="contact.city" :readonly="readonly" class="rounded-md border bg-background px-3 py-2" /></label><label class="grid gap-1 text-sm">State<input v-model="contact.state" :readonly="readonly" class="rounded-md border bg-background px-3 py-2" /></label><label class="grid gap-1 text-sm">Postal code<input v-model="contact.postal_code" :readonly="readonly" class="rounded-md border bg-background px-3 py-2" /></label><label class="grid gap-1 text-sm">Country code<input v-model="contact.country" :readonly="readonly" maxlength="2" class="rounded-md border bg-background px-3 py-2" /></label></div>
                        </div>
                    </div>
                    <p v-for="(message, field) in form.errors" v-show="String(field).startsWith('contacts')" :key="field" role="alert" class="text-sm text-destructive">{{ message }}</p>
                </template>
                <template v-else-if="active === 'security'">
                    <p v-if="mode === 'create'" class="text-sm text-muted-foreground">Creating this account sends a secure, expiring password setup link to the primary login email.</p>
                    <template v-else><div class="rounded-md border p-4"><p class="font-medium">Membership in {{ organization.name }}: {{ membershipStatus === 'active' ? 'Active' : 'Inactive' }}</p><p class="mt-1 text-sm text-muted-foreground">Membership deactivation blocks access to this organization only. The shared login and all other organizations remain unchanged.</p></div>
                    <div class="flex flex-wrap gap-3"><button type="button" class="rounded-md border px-4 py-2 text-sm" :disabled="!user?.is_active" @click="resetConfirm = true">Send secure password reset link</button><button type="button" class="rounded-md border px-4 py-2 text-sm" :class="membershipStatus === 'active' ? 'text-destructive' : ''" @click="activeConfirm = true">{{ membershipStatus === 'active' ? 'Deactivate membership' : 'Reactivate membership' }}</button></div></template>
                </template>
                <template v-else-if="active === 'facilities'"><p class="text-sm text-muted-foreground">Select the facilities this person is associated with in {{ organization.name }}. This selection does not change access in any other organization.</p><label v-for="facility in availableFacilities" :key="facility.id" class="flex items-center gap-3 rounded-md border p-3 text-sm"><input type="checkbox" :checked="form.facility_ids.includes(facility.id)" :disabled="readonly" @change="toggleFacility(facility.id, ($event.target as HTMLInputElement).checked)" />{{ facility.name }} <span class="text-muted-foreground">({{ facility.code }})</span></label><p v-if="!availableFacilities.length" class="text-sm text-muted-foreground">No facilities have been configured yet.</p><p v-if="form.errors.facility_ids" role="alert" class="text-destructive">{{ form.errors.facility_ids }}</p></template>
                <div class="flex items-center justify-between border-t pt-4"><a :href="base" class="rounded-md border px-4 py-2 text-sm">Back to users</a><div class="flex gap-2"><a v-if="readonly" :href="`${base}/${user?.id}/edit`" class="rounded-md bg-primary px-4 py-2 text-sm text-primary-foreground">Edit</a><button v-else type="submit" :disabled="form.processing" class="rounded-md bg-primary px-4 py-2 text-sm text-primary-foreground disabled:opacity-50">{{ mode === 'create' ? 'Create user' : 'Save user' }}</button></div></div>
                <p v-if="Object.keys(form.errors).length" role="alert" class="text-sm text-destructive">Please correct the highlighted sections.</p>
            </form>
        </template></WayAlongFormSections>
        <div v-if="resetConfirm || activeConfirm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" role="dialog" aria-modal="true"><div class="w-full max-w-md space-y-4 rounded-xl bg-background p-6 shadow-xl"><h2 class="text-lg font-semibold">{{ resetConfirm ? 'Send password reset email?' : membershipStatus === 'active' ? 'Deactivate this membership?' : 'Reactivate this membership?' }}</h2><p class="text-sm text-muted-foreground">{{ resetConfirm ? `A secure reset link will be emailed to ${user?.email}. No password will be shown to administrators.` : 'This affects only this organization and its facilities; the central login and other organizations are unchanged.' }}</p><div class="flex justify-end gap-2"><button type="button" class="rounded-md border px-4 py-2" @click="resetConfirm = false; activeConfirm = false">Cancel</button><button type="button" class="rounded-md bg-primary px-4 py-2 text-primary-foreground" @click="resetConfirm ? sendReset() : toggleActive()">Confirm</button></div></div></div>
    </main>
</template>
