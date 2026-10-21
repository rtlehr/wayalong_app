<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import WayAlongFormSections from '@/components/WayAlongFormSections.vue';

type Contact = { kind: 'email' | 'phone' | 'address'; label: string; is_primary: boolean; value: string; line_1: string; line_2: string; city: string; state: string; postal_code: string; country: string };
type Person = { id: number; first_name: string; last_name: string; date_of_birth: string | null; person_type: string; facility_id: number | null; notes: string | null; user_id: number | null };
const props = defineProps<{ mode: 'create' | 'view' | 'edit'; organization: { id: number; name: string }; facilities: { id: number; name: string }[]; person?: Person; contacts: Contact[] }>();
const base = '/organization/admin/people';
const active = ref('personal');
const readonly = computed(() => props.mode === 'view');
const form = useForm({
    first_name: props.person?.first_name ?? '', last_name: props.person?.last_name ?? '',
    date_of_birth: props.person?.date_of_birth?.slice(0, 10) ?? '', person_type: props.person?.person_type ?? 'client',
    facility_id: props.person?.facility_id ?? null as number | null,
    notes: props.person?.notes ?? '',
    contacts: (props.contacts ?? []).map(c => ({ ...c })),
});
const sections = computed(() => [
    { id: 'personal', title: 'Personal information', description: 'Name, date of birth and relationship', invalid: !!(form.errors.first_name || form.errors.last_name || form.errors.date_of_birth || form.errors.person_type) },
    { id: 'contact', title: 'Contact information', description: 'Optional emails, phone numbers and addresses', invalid: Object.keys(form.errors).some(k => k.startsWith('contacts')) },
    { id: 'facility', title: 'Facility association', description: 'Organization-specific record', invalid: !!form.errors.facility_id },
    { id: 'access', title: 'Login & access', description: 'Optional future portal access', invalid: false },
]);
const title = computed(() => `${props.mode === 'create' ? 'Add' : props.mode === 'edit' ? 'Edit' : 'View'} person`);
function addContact(kind: Contact['kind']) {
    form.contacts.push({ kind, label: kind === 'phone' ? 'Mobile' : kind === 'address' ? 'Home' : 'Personal', is_primary: !form.contacts.some(c => c.kind === kind && c.is_primary), value: '', line_1: '', line_2: '', city: '', state: '', postal_code: '', country: 'US' });
}
function save() {
    if (readonly.value) return;
    if (props.mode === 'create') form.post(base);
    else form.patch(`${base}/${props.person?.id}`);
}
</script>
<template>
    <Head :title="title" />
    <main class="mx-auto w-full max-w-7xl space-y-6 p-4 md:p-8">
        <header class="space-y-2"><a :href="base" class="text-sm underline">← Person Directory</a><h1 class="text-2xl font-semibold">{{ title }}</h1><p class="text-sm text-muted-foreground">{{ organization.name }} · Email and login credentials are not required for a person record.</p></header>
        <WayAlongFormSections v-model:active="active" :sections="sections"><template #default>
            <form class="space-y-5" @submit.prevent="save">
                <div><h2 class="text-lg font-semibold">{{ sections.find(s => s.id === active)?.title }}</h2><p class="text-sm text-muted-foreground">Fields marked * are required.</p></div>
                <template v-if="active === 'personal'">
                    <label class="grid gap-1 text-sm">First name *<input v-model="form.first_name" :readonly="readonly" required maxlength="255" class="rounded-md border bg-background px-3 py-2" /><span v-if="form.errors.first_name" class="text-destructive">{{ form.errors.first_name }}</span></label>
                    <label class="grid gap-1 text-sm">Last name *<input v-model="form.last_name" :readonly="readonly" required maxlength="255" class="rounded-md border bg-background px-3 py-2" /><span v-if="form.errors.last_name" class="text-destructive">{{ form.errors.last_name }}</span></label>
                    <label class="grid gap-1 text-sm">Date of birth (optional)<input v-model="form.date_of_birth" type="date" :disabled="readonly" class="rounded-md border bg-background px-3 py-2" /></label>
                    <label class="grid gap-1 text-sm">Relationship *<select v-model="form.person_type" :disabled="readonly" class="rounded-md border bg-background px-3 py-2"><option value="client">Client</option><option value="guardian">Parent / Guardian</option><option value="staff">Staff</option><option value="other">Other</option></select></label>
                </template>
                <template v-else-if="active === 'contact'">
                    <p class="text-sm text-muted-foreground">Leave this section empty for a youth client without an email address. Placeholder email addresses are never necessary.</p>
                    <div v-for="(c, index) in form.contacts" :key="index" class="space-y-3 rounded-lg border p-4">
                        <div class="flex justify-between"><strong class="capitalize">{{ c.kind }} {{ index + 1 }}</strong><button v-if="!readonly" type="button" class="text-sm text-destructive underline" @click="form.contacts.splice(index, 1)">Remove</button></div>
                        <label class="grid gap-1 text-sm">Label<input v-model="c.label" :readonly="readonly" maxlength="80" class="rounded-md border bg-background p-2" /></label>
                        <template v-if="c.kind === 'address'"><label class="grid gap-1 text-sm">Street address<input v-model="c.line_1" :readonly="readonly" class="rounded-md border bg-background p-2" /></label><label class="grid gap-1 text-sm">Address line 2<input v-model="c.line_2" :readonly="readonly" class="rounded-md border bg-background p-2" /></label><label class="grid gap-1 text-sm">City<input v-model="c.city" :readonly="readonly" class="rounded-md border bg-background p-2" /></label><label class="grid gap-1 text-sm">State<input v-model="c.state" :readonly="readonly" class="rounded-md border bg-background p-2" /></label><label class="grid gap-1 text-sm">Postal code<input v-model="c.postal_code" :readonly="readonly" class="rounded-md border bg-background p-2" /></label></template>
                        <label v-else class="grid gap-1 text-sm">{{ c.kind === 'email' ? 'Email address' : 'Phone number' }}<input v-model="c.value" :type="c.kind === 'email' ? 'email' : 'tel'" :readonly="readonly" class="rounded-md border bg-background p-2" /></label>
                        <label class="flex gap-2 text-sm"><input v-model="c.is_primary" type="checkbox" :disabled="readonly" @change="() => { if (c.is_primary) form.contacts.forEach((other, j) => { if (j !== index && other.kind === c.kind) other.is_primary = false; }); }" /> Primary {{ c.kind }}</label>
                    </div>
                    <div v-if="!readonly" class="flex flex-wrap gap-2"><button v-for="kind in (['email','phone','address'] as const)" :key="kind" type="button" class="rounded-md border px-3 py-2 text-sm" @click="addContact(kind)">+ Add {{ kind }}</button></div>
                    <p v-if="form.errors.contacts" role="alert" class="text-sm text-destructive">{{ form.errors.contacts }}</p>
                </template>
                <template v-else-if="active === 'facility'">
                    <label class="grid gap-1 text-sm">Facility (optional)<select v-model="form.facility_id" :disabled="readonly" class="rounded-md border bg-background px-3 py-2"><option :value="null">Not assigned</option><option v-for="f in facilities" :key="f.id" :value="f.id">{{ f.name }}</option></select></label>
                    <label class="grid gap-1 text-sm">Notes<textarea v-model="form.notes" :readonly="readonly" rows="4" maxlength="4000" class="rounded-md border bg-background p-2" /></label>
                    <p v-if="form.errors.facility_id" class="text-sm text-destructive">{{ form.errors.facility_id }}</p>
                </template>
                <template v-else><div class="rounded-lg border p-4"><strong>{{ person?.user_id ? 'Login account linked' : 'No login account' }}</strong><p class="mt-2 text-sm text-muted-foreground">Adding this record does not create a Laravel login, send an invitation or grant access to treatment information. Account linking and consent-based portal access will be added in a separate, authorized workflow.</p></div></template>
                <div class="flex items-center justify-between border-t pt-4"><a :href="base" class="rounded-md border px-4 py-2 text-sm">Back to directory</a><div><a v-if="readonly" :href="`${base}/${person?.id}/edit`" class="rounded-md bg-primary px-4 py-2 text-sm text-primary-foreground">Edit</a><button v-else type="submit" :disabled="form.processing" class="rounded-md bg-primary px-4 py-2 text-sm text-primary-foreground">{{ mode === 'create' ? 'Add person' : 'Save changes' }}</button></div></div>
                <p v-if="Object.keys(form.errors).length" role="alert" class="text-sm text-destructive">Please correct the highlighted sections.</p>
            </form>
        </template></WayAlongFormSections>
    </main>
</template>
