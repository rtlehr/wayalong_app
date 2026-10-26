<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import WayAlongFormSections from '@/components/WayAlongFormSections.vue';
import WayAlongDocuments from '@/components/WayAlongDocuments.vue';
import ClientInsurancePolicies from '@/components/ClientInsurancePolicies.vue';

type Contact = { kind: 'email' | 'phone' | 'address'; label: string; is_primary: boolean; value: string; line_1: string; line_2: string; city: string; state: string; postal_code: string; country: string };
type PersonOption = { id: number; first_name: string; last_name: string };
type Guardian = PersonOption & { relationship_label: string; is_emergency_contact: boolean; notes: string | null; contacts: Contact[] };
type GuardianDetail = { relationship_label: string; is_emergency_contact: boolean; notes: string };
type AuthorizationRecord = { id: number; guardian_person_id: number; record_type: string; subject: string; decision: string; effective_on: string; expires_on: string | null; evidence_reference: string | null; evidence_document_id: number | null; notes: string | null; recorded_by: string; created_at: string };
type StaffOption = { id: number; name: string };
const props = defineProps<{
    mode: 'view' | 'edit'; canManage: boolean;
    facility: { id: number; name: string };
    enrollment: { id: number; status: string; enrolled_at: string; discharged_at: string | null };
    person: { id: number; first_name: string; last_name: string; date_of_birth: string | null };
    canAccessBilling: boolean;
    insuranceProviders: { id: number; name: string; payer_code: string | null; phone: string | null }[];
    insurancePolicies: { id: number; insurance_provider_id: number | null; verifications: { id: number; status: string; method: string; checked_on: string; reference_number: string | null; notes: string | null }[]; payer_name: string; coverage_order: string; member_id: string | null; group_number: string | null; plan_name: string | null; subscriber_name: string | null; subscriber_relationship: string | null; effective_on: string | null; expires_on: string | null; verification_status: string; verified_on: string | null; notes: string | null }[];
    contacts: Contact[];
    authorizationRecords: AuthorizationRecord[];
    documents: { current_version?: number; versions?: { id: number; number: number; filename: string; notes?: string | null; created_at?: string | null }[]; review_status?: string; review_notes?: string | null; reviewed_at?: string | null; review_events?: { status: string; notes: string | null; created_at: string }[]; id: number; title: string; category: string; original_filename: string; file_size: number; expires_on: string | null; created_at: string | null }[];
    guardians: Guardian[]; guardianOptions: PersonOption[];
    assignedStaff: StaffOption[]; staffOptions: StaffOption[];
}>();
const initialSection = new URLSearchParams(usePage().url.split('?')[1] ?? '').get('section');
const active = ref(initialSection === 'contact' ? 'contact' : 'personal');
const guardianSearch = ref('');
const showNewGuardian = ref(false);
const authorization = useForm({guardian_person_id: '', record_type: 'authority', subject: 'legal_authority', decision: 'pending', effective_on: new Date().toISOString().slice(0,10), expires_on: '', evidence_reference: '', evidence_document_id: '', notes: ''});
function recordAuthorization(): void {
    authorization.post(`${base}/guardian-authorizations`, {onSuccess: () => { active.value = 'authorization'; authorization.reset('evidence_reference', 'notes'); }});
}
function authorizationTypeChanged(): void {
    if (authorization.record_type === 'authority') { authorization.subject = 'legal_authority'; authorization.decision = 'pending'; }
    else { authorization.subject = 'treatment'; authorization.decision = 'granted'; }
}

const filteredGuardianOptions = computed(() => props.guardianOptions.filter(person => `${person.first_name} ${person.last_name}`.toLowerCase().includes(guardianSearch.value.toLowerCase())));
const newGuardian = useForm({ first_name: '', last_name: '', relationship_label: 'Parent', is_emergency_contact: false, notes: '', phone: '', email: '', address: '', city: '', state: '', postal_code: '' });
function createGuardian(): void {
    newGuardian.post(`${base}/guardians`, { onSuccess: () => {
        showNewGuardian.value = false;
        active.value = 'guardians';
        // The response includes the newly linked person. Keep the edit form's
        // relationship state current so a later Save won't remove that link.
        form.guardian_ids = props.guardians.map(guardian => guardian.id);
        form.guardian_details = Object.fromEntries(props.guardians.map(guardian => [guardian.id, {
            relationship_label: guardian.relationship_label || 'Guardian',
            is_emergency_contact: guardian.is_emergency_contact,
            notes: guardian.notes || '',
        }]));
        newGuardian.reset();
    } });
}

const readonly = computed(() => props.mode === 'view');
const phoneContacts = computed(() => props.contacts.filter(contact => contact.kind === 'phone'));
const emailContacts = computed(() => props.contacts.filter(contact => contact.kind === 'email'));
const addressContacts = computed(() => props.contacts.filter(contact => contact.kind === 'address'));
const base = `/facility/clients/${props.enrollment.id}`;
const form = useForm({
    first_name: props.person.first_name,
    last_name: props.person.last_name,
    date_of_birth: props.person.date_of_birth?.slice(0, 10) ?? '',
    contacts: props.contacts.map(contact => ({ ...contact })),
    guardian_ids: props.guardians.map(guardian => guardian.id),
    guardian_details: Object.fromEntries(props.guardians.map(guardian => [guardian.id, {
        relationship_label: guardian.relationship_label || 'Guardian',
        is_emergency_contact: guardian.is_emergency_contact,
        notes: guardian.notes || '',
    }])) as Record<number, GuardianDetail>,
    staff_ids: props.assignedStaff.map(staff => staff.id),
});
const sections = computed(() => [
    { id: 'personal', title: 'Personal Information', description: 'Client name and birth date', invalid: !!(form.errors.first_name || form.errors.last_name || form.errors.date_of_birth) },
    { id: 'contact', title: 'Contact Information', description: 'Optional emails, phones and addresses', invalid: Object.keys(form.errors).some(key => key.startsWith('contacts')) },
    { id: 'guardians', title: 'Parents & Guardians', description: 'Associated person records', invalid: Object.keys(form.errors).some(key => key.startsWith('guardian_ids')) },
    { id: 'authorization', title: 'Authorization & Consent', description: 'Recorded decisions and supporting references' },
    { id: 'insurance', title: 'Insurance', description: 'Organization-level coverage and verification' },
    { id: 'documents', title: 'Client Documents', description: 'Secure uploads and supporting records' },
    { id: 'enrollment', title: 'Facility & Enrollment', description: 'Status and enrollment dates' },
    { id: 'staff', title: 'Assigned Staff', description: 'Facility treatment team', invalid: Object.keys(form.errors).some(key => key.startsWith('staff_ids')) },
]);
function addContact(kind: Contact['kind']): void {
    form.contacts.push({ kind, label: kind === 'phone' ? 'Mobile' : kind === 'address' ? 'Home' : 'Personal', is_primary: !form.contacts.some(contact => contact.kind === kind && contact.is_primary), value: '', line_1: '', line_2: '', city: '', state: '', postal_code: '', country: 'US' });
}
function setPrimary(index: number): void {
    const selected = form.contacts[index];
    if (selected?.is_primary) form.contacts.forEach((contact, j) => { if (j !== index && contact.kind === selected.kind) contact.is_primary = false; });
}
function ensureGuardianDetail(id: number): GuardianDetail {
    if (!form.guardian_details[id]) {
        form.guardian_details[id] = { relationship_label: 'Guardian', is_emergency_contact: false, notes: '' };
    }
    return form.guardian_details[id];
}
function syncGuardianDetail(id: number): void {
    if (form.guardian_ids.includes(id)) ensureGuardianDetail(id);
    else delete form.guardian_details[id];
}
function save(): void {
    if (!readonly.value) form.patch(`${base}/profile`);
}
</script>

<template>
    <Head :title="`${mode === 'edit' ? 'Edit' : 'View'} client — ${person.first_name} ${person.last_name}`" />
    <main class="mx-auto w-full max-w-7xl space-y-6 p-4 md:p-8">
        <header class="space-y-2">
            <Link href="/facility/clients" class="text-sm underline underline-offset-4">← Client Directory</Link>
            <h1 class="text-2xl font-semibold">{{ mode === 'edit' ? 'Edit' : 'View' }} Client — {{ person.first_name }} {{ person.last_name }}</h1>
            <p class="text-sm text-muted-foreground">{{ facility.name }} · {{ enrollment.status }} · Client accounts do not require email or login credentials.</p>
        </header>
        <WayAlongFormSections v-model:active="active" :sections="sections">
            <template #default>
                <form class="space-y-5" @submit.prevent="save">
                    <div>
                        <h2 class="text-lg font-semibold">{{ sections.find(s => s.id === active)?.title }}</h2>
                        <p class="text-sm text-muted-foreground">{{ sections.find(s => s.id === active)?.description }}</p>
                    </div>
                    <template v-if="active === 'personal'">
                        <label class="grid gap-1 text-sm">First name *<input v-model="form.first_name" :readonly="readonly" required maxlength="255" class="rounded-md border bg-background px-3 py-2" /><span v-if="form.errors.first_name" class="text-destructive">{{ form.errors.first_name }}</span></label>
                        <label class="grid gap-1 text-sm">Last name *<input v-model="form.last_name" :readonly="readonly" required maxlength="255" class="rounded-md border bg-background px-3 py-2" /><span v-if="form.errors.last_name" class="text-destructive">{{ form.errors.last_name }}</span></label>
                        <label class="grid gap-1 text-sm">Date of birth<input v-model="form.date_of_birth" type="date" :disabled="readonly" class="rounded-md border bg-background px-3 py-2" /><span v-if="form.errors.date_of_birth" class="text-destructive">{{ form.errors.date_of_birth }}</span></label>
                    </template>
                    <template v-else-if="active === 'contact'">
                        <p class="text-sm text-muted-foreground">Contact details are optional, including for youth clients.</p>
                        <template v-if="readonly">
                            <section class="space-y-2" aria-labelledby="client-phones-heading">
                                <h3 id="client-phones-heading" class="font-semibold">Phone Numbers</h3>
                                <div v-if="phoneContacts.length" class="overflow-x-auto rounded-md border">
                                    <table class="w-full text-left text-sm">
                                        <thead class="border-b bg-muted/40"><tr><th scope="col" class="px-3 py-2 font-medium">Label</th><th scope="col" class="px-3 py-2 font-medium">Number</th><th scope="col" class="px-3 py-2 text-center font-medium">Primary</th></tr></thead>
                                        <tbody><tr v-for="(contact, index) in phoneContacts" :key="index" class="border-b last:border-b-0"><td class="px-3 py-2">{{ contact.label || '—' }}</td><td class="px-3 py-2">{{ contact.value || '—' }}</td><td class="px-3 py-2 text-center"><span v-if="contact.is_primary" aria-label="Primary phone number" title="Primary phone number">X</span><span v-else>—</span></td></tr></tbody>
                                    </table>
                                </div>
                                <p v-else class="text-sm text-muted-foreground">No phone numbers on file.</p>
                            </section>
                            <section class="space-y-2" aria-labelledby="client-emails-heading">
                                <h3 id="client-emails-heading" class="font-semibold">Email Addresses</h3>
                                <div v-if="emailContacts.length" class="overflow-x-auto rounded-md border">
                                    <table class="w-full text-left text-sm">
                                        <thead class="border-b bg-muted/40"><tr><th scope="col" class="px-3 py-2 font-medium">Label</th><th scope="col" class="px-3 py-2 font-medium">Email</th><th scope="col" class="px-3 py-2 text-center font-medium">Primary</th></tr></thead>
                                        <tbody><tr v-for="(contact, index) in emailContacts" :key="index" class="border-b last:border-b-0"><td class="px-3 py-2">{{ contact.label || '—' }}</td><td class="break-all px-3 py-2">{{ contact.value || '—' }}</td><td class="px-3 py-2 text-center"><span v-if="contact.is_primary" aria-label="Primary email address" title="Primary email address">X</span><span v-else>—</span></td></tr></tbody>
                                    </table>
                                </div>
                                <p v-else class="text-sm text-muted-foreground">No email addresses on file.</p>
                            </section>
                            <section class="space-y-2" aria-labelledby="client-addresses-heading">
                                <h3 id="client-addresses-heading" class="font-semibold">Addresses</h3>
                                <div v-if="addressContacts.length" class="grid gap-3 sm:grid-cols-2">
                                    <div v-for="(contact, index) in addressContacts" :key="index" class="rounded-md border p-3 text-sm">
                                        <div class="mb-2 flex flex-wrap items-center gap-2"><strong>{{ contact.label || 'Address' }}</strong><span v-if="contact.is_primary" class="rounded bg-muted px-2 py-0.5 text-xs">Primary</span></div>
                                        <address class="not-italic">
                                            <div v-if="contact.line_1">{{ contact.line_1 }}</div>
                                            <div v-if="contact.line_2">{{ contact.line_2 }}</div>
                                            <div v-if="contact.city || contact.state || contact.postal_code">{{ [contact.city, contact.state].filter(Boolean).join(', ') }}{{ contact.postal_code ? ` ${contact.postal_code}` : '' }}</div>
                                            <div v-if="contact.country && contact.country !== 'US'">{{ contact.country }}</div>
                                        </address>
                                    </div>
                                </div>
                                <p v-else class="text-sm text-muted-foreground">No addresses on file.</p>
                            </section>
                        </template>
                        <template v-else>
                            <div v-for="(contact, index) in form.contacts" :key="index" class="space-y-3 rounded-lg border p-4">
                                <div class="flex justify-between"><strong class="capitalize">{{ contact.kind }}</strong><button type="button" class="text-sm text-destructive underline" @click="form.contacts.splice(index, 1)">Remove</button></div>
                                <label class="grid gap-1 text-sm">Label<input v-model="contact.label" maxlength="50" class="rounded-md border bg-background p-2" /></label>
                                <template v-if="contact.kind === 'address'">
                                    <label v-for="field in (['line_1', 'line_2', 'city', 'state', 'postal_code'] as const)" :key="field" class="grid gap-1 text-sm">{{ field.replace('_', ' ') }}<input v-model="contact[field]" class="rounded-md border bg-background p-2" /></label>
                                </template>
                                <label v-else class="grid gap-1 text-sm">{{ contact.kind === 'email' ? 'Email address' : 'Phone number' }}<input v-model="contact.value" :type="contact.kind === 'email' ? 'email' : 'tel'" class="rounded-md border bg-background p-2" /></label>
                                <label class="flex items-center gap-2 text-sm"><input v-model="contact.is_primary" type="checkbox" @change="setPrimary(index)" /> Primary {{ contact.kind }}</label>
                            </div>
                            <div class="flex flex-wrap gap-2"><button v-for="kind in (['email', 'phone', 'address'] as const)" :key="kind" type="button" class="rounded-md border px-3 py-2 text-sm" @click="addContact(kind)">+ Add {{ kind }}</button></div>
                        </template>
                        <p v-if="form.errors.contacts" role="alert" class="text-sm text-destructive">{{ form.errors.contacts }}</p>
                    </template>
                    <template v-else-if="active === 'guardians'">
                        <p class="text-sm text-muted-foreground">Parent and guardian relationships are recorded separately from legal authorization and portal access.</p>
                        <div v-if="readonly" class="space-y-2">
                            <p v-if="!guardians.length" class="text-sm text-muted-foreground">No parents or guardians linked.</p>
                            <div v-for="guardian in guardians" :key="guardian.id" class="space-y-1 rounded-md border p-3 text-sm">
                                <Link :href="`${base}/guardians/${guardian.id}`" class="font-medium underline underline-offset-4">{{ guardian.first_name }} {{ guardian.last_name }} → Full profile</Link>
                                <p>Relationship: {{ guardian.relationship_label }}</p>
                                <p v-if="guardian.is_emergency_contact">Emergency contact: Yes</p>
                                <p v-if="guardian.notes" class="whitespace-pre-wrap">Notes: {{ guardian.notes }}</p>
                                <div v-if="guardian.contacts?.length" class="space-y-2 border-t pt-2">
                                    <div v-for="(contact, i) in guardian.contacts" :key="i" class="text-sm">
                                        <span class="font-medium">{{ contact.label || contact.kind }}{{ contact.is_primary ? ' (Primary)' : '' }}:</span>
                                        <span v-if="contact.kind !== 'address'"> {{ contact.value }}</span>
                                        <address v-else class="not-italic"><div>{{ contact.line_1 }}</div><div v-if="contact.line_2">{{ contact.line_2 }}</div><div>{{ [contact.city, contact.state].filter(Boolean).join(', ') }} {{ contact.postal_code }}</div></address>
                                    </div>
                                </div>
                                <p v-else class="text-muted-foreground">No contact details on file.</p>
                            </div>
                        </div>
                        <div v-else class="space-y-3">
                            <div class="flex flex-wrap gap-2"><button type="button" class="rounded-md bg-primary px-3 py-2 text-sm text-primary-foreground" @click="showNewGuardian = !showNewGuardian">{{ showNewGuardian ? 'Cancel New Person' : '+ Add New Person' }}</button><button type="button" class="rounded-md border px-3 py-2 text-sm" @click="showNewGuardian = false; guardianSearch = '';">Link Existing Person ↓</button></div>
                            <div v-if="showNewGuardian" class="space-y-3 rounded-lg border p-4" aria-label="Add new parent or guardian">
                                <h3 class="font-semibold">New Parent / Guardian</h3>
                                <p class="text-xs text-muted-foreground">Creating a parent saves and links the new person immediately. Save any other pending client edits first.</p>
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <label class="grid gap-1 text-sm">First name *<input v-model="newGuardian.first_name" required maxlength="255" class="rounded-md border p-2" /><span v-if="newGuardian.errors.first_name" class="text-destructive">{{ newGuardian.errors.first_name }}</span></label>
                                    <label class="grid gap-1 text-sm">Last name *<input v-model="newGuardian.last_name" required maxlength="255" class="rounded-md border p-2" /><span v-if="newGuardian.errors.last_name" class="text-destructive">{{ newGuardian.errors.last_name }}</span></label>
                                    <label class="grid gap-1 text-sm">Relationship<select v-model="newGuardian.relationship_label" class="rounded-md border p-2"><option v-for="relationship in ['Parent', 'Legal guardian', 'Foster parent', 'Relative', 'Other', 'Guardian']" :key="relationship">{{ relationship }}</option></select></label>
                                    <label class="flex items-center gap-2 text-sm"><input v-model="newGuardian.is_emergency_contact" type="checkbox" /> Emergency contact</label>
                                    <label class="grid gap-1 text-sm">Phone<input v-model="newGuardian.phone" type="tel" class="rounded-md border p-2" /></label>
                                    <label class="grid gap-1 text-sm">Email (optional)<input v-model="newGuardian.email" type="email" class="rounded-md border p-2" /><span v-if="newGuardian.errors.email" class="text-destructive">{{ newGuardian.errors.email }}</span></label>
                                    <label class="grid gap-1 text-sm sm:col-span-2">Street address<input v-model="newGuardian.address" class="rounded-md border p-2" /></label>
                                    <label class="grid gap-1 text-sm">City<input v-model="newGuardian.city" class="rounded-md border p-2" /></label>
                                    <label class="grid gap-1 text-sm">State<input v-model="newGuardian.state" class="rounded-md border p-2" /></label>
                                    <label class="grid gap-1 text-sm">ZIP<input v-model="newGuardian.postal_code" class="rounded-md border p-2" /></label>
                                    <label class="grid gap-1 text-sm sm:col-span-2">Relationship notes<textarea v-model="newGuardian.notes" maxlength="1000" class="rounded-md border p-2" /></label>
                                </div>
                                <button type="button" class="rounded-md bg-primary px-3 py-2 text-sm text-primary-foreground disabled:opacity-50" :disabled="newGuardian.processing || !newGuardian.first_name || !newGuardian.last_name" @click="createGuardian">{{ newGuardian.processing ? 'Creating…' : 'Create & Link Person' }}</button>
                            </div>
                            <label class="grid gap-1 text-sm">Link Existing Person — Search<input v-model="guardianSearch" placeholder="Search parents and guardians" class="rounded-md border bg-background p-2" /></label>
                            <div v-for="guardian in filteredGuardianOptions" :key="guardian.id" class="space-y-3 rounded-md border p-3 text-sm">
                                <label class="flex items-center gap-2 font-medium"><input v-model="form.guardian_ids" type="checkbox" :value="guardian.id" @change="syncGuardianDetail(guardian.id)" />{{ guardian.first_name }} {{ guardian.last_name }}</label>
                                <div v-if="form.guardian_ids.includes(guardian.id)" class="grid gap-3 border-t pt-3 sm:grid-cols-2">
                                    <label class="grid gap-1">Relationship
                                        <select v-model="ensureGuardianDetail(guardian.id).relationship_label" class="rounded-md border bg-background p-2">
                                            <option v-for="relationship in ['Parent', 'Legal guardian', 'Foster parent', 'Relative', 'Other', 'Guardian']" :key="relationship" :value="relationship">{{ relationship }}</option>
                                        </select>
                                    </label>
                                    <label class="flex items-center gap-2"><input v-model="ensureGuardianDetail(guardian.id).is_emergency_contact" type="checkbox" /> Emergency contact</label>
                                    <label class="grid gap-1 sm:col-span-2">Relationship notes
                                        <textarea v-model="ensureGuardianDetail(guardian.id).notes" maxlength="1000" rows="2" class="rounded-md border bg-background p-2" />
                                    </label>
                                </div>
                            </div>
                            <p v-if="!guardianOptions.length" class="text-sm text-muted-foreground">No existing parent or guardian Person records available. Use Add New Person above.</p>
                            <p v-if="form.errors.guardian_ids || form.errors.guardian_details" class="text-sm text-destructive">{{ form.errors.guardian_ids || form.errors.guardian_details }}</p>
                        </div>
                    </template>
                    <template v-else-if="active === 'authorization'">
                        <p class="text-sm text-muted-foreground">These are staff-recorded authorization and consent decisions, not legal determinations. Recording a decision does not grant account or clinical-record access. Verify supporting documentation according to facility policy.</p>
                        <div v-if="!authorizationRecords.length" class="rounded-md border p-3 text-sm text-muted-foreground">No authorization or consent decisions recorded.</div>
                        <div v-for="record in authorizationRecords" :key="record.id" class="space-y-1 rounded-md border p-3 text-sm">
                            <p class="font-semibold">{{ guardians.find(g => g.id === record.guardian_person_id)?.first_name }} {{ guardians.find(g => g.id === record.guardian_person_id)?.last_name }} — {{ record.record_type === 'authority' ? 'Authority' : 'Consent' }}: {{ record.decision }}</p>
                            <p>Subject: {{ record.subject.replaceAll('_', ' ') }} · Effective: {{ record.effective_on?.slice(0,10) }} <span v-if="record.expires_on">· Expires: {{ record.expires_on?.slice(0,10) }}</span></p>
                            <p v-if="record.evidence_reference">Supporting reference: {{ record.evidence_reference }}</p><p v-if="record.evidence_document_id">Supporting document: <a :href="`${base}/documents/${record.evidence_document_id}`" class="underline">Download attached document</a></p>
                            <p v-if="record.notes" class="whitespace-pre-wrap">Notes: {{ record.notes }}</p>
                            <p class="text-xs text-muted-foreground">Recorded by {{ record.recorded_by }} on {{ record.created_at?.slice(0,10) }}</p>
                        </div>
                        <div v-if="!readonly && guardians.length" class="space-y-3 rounded-md border p-4">
                            <h3 class="font-semibold">Record a new decision</h3>
                            <p class="text-xs text-muted-foreground">This adds an entry to the history. Previous entries are retained rather than overwritten.</p>
                            <label class="grid gap-1 text-sm">Linked parent or guardian
                                <select v-model="authorization.guardian_person_id" class="rounded-md border bg-background p-2" required><option value="">Select a person</option><option v-for="guardian in guardians" :key="guardian.id" :value="String(guardian.id)">{{ guardian.first_name }} {{ guardian.last_name }}</option></select>
                            </label>
                            <label class="grid gap-1 text-sm">Record type<select v-model="authorization.record_type" class="rounded-md border bg-background p-2" @change="authorizationTypeChanged"><option value="authority">Legal authority review</option><option value="consent">Consent decision</option></select></label>
                            <label v-if="authorization.record_type === 'consent'" class="grid gap-1 text-sm">Consent subject<select v-model="authorization.subject" class="rounded-md border bg-background p-2"><option value="treatment">Treatment</option><option value="information_sharing">Information sharing</option><option value="emergency_care">Emergency care</option><option value="communication">Communication</option><option value="other">Other</option></select></label>
                            <label class="grid gap-1 text-sm">Decision<select v-model="authorization.decision" class="rounded-md border bg-background p-2"><template v-if="authorization.record_type === 'authority'"><option value="pending">Pending review</option><option value="verified">Verified (documentation reviewed)</option><option value="restricted">Restricted</option><option value="revoked">Revoked</option></template><template v-else><option value="granted">Granted</option><option value="declined">Declined</option><option value="withdrawn">Withdrawn</option></template></select></label>
                            <div class="grid gap-3 sm:grid-cols-2"><label class="grid gap-1 text-sm">Effective date<input v-model="authorization.effective_on" type="date" required class="rounded-md border bg-background p-2" /></label><label class="grid gap-1 text-sm">Expiration (optional)<input v-model="authorization.expires_on" type="date" class="rounded-md border bg-background p-2" /></label></div>
                            <label class="grid gap-1 text-sm">Supporting document / evidence reference {{ ['verified', 'granted'].includes(authorization.decision) ? '*' : '(optional)' }}<input v-model="authorization.evidence_reference" maxlength="255" :required="['verified', 'granted'].includes(authorization.decision) && !authorization.evidence_document_id" placeholder="External reference (optional if document selected)" class="rounded-md border bg-background p-2" /></label>
                            <label class="grid gap-1 text-sm">Supporting uploaded document (optional)<select v-model="authorization.evidence_document_id" class="rounded-md border bg-background p-2"><option value="">None selected</option><option v-for="document in documents" :key="document.id" :value="String(document.id)">{{ document.title }} ({{ document.review_status || 'pending' }})</option></select></label>
                            <label class="grid gap-1 text-sm">Notes<textarea v-model="authorization.notes" maxlength="2000" class="rounded-md border bg-background p-2" /></label>
                            <p v-if="Object.keys(authorization.errors).length" role="alert" class="text-sm text-destructive">{{ Object.values(authorization.errors).join(' ') }}</p>
                            <button type="button" :disabled="authorization.processing || !authorization.guardian_person_id" class="rounded-md bg-primary px-4 py-2 text-sm text-primary-foreground disabled:opacity-50" @click="recordAuthorization">{{ authorization.processing ? 'Saving…' : 'Record decision' }}</button>
                        </div>
                        <p v-else-if="!readonly && !guardians.length" class="text-sm text-muted-foreground">Link a parent or guardian and save the client profile before recording authorization.</p>
                    </template>
                    <template v-else-if="active === 'insurance'">
                        <Link v-if="canAccessBilling" :href="`${base}/billing`" class="mb-4 inline-block rounded-md border px-3 py-2 text-sm font-medium">Open Client Billing & Charges →</Link>
                        <ClientInsurancePolicies :enrollment-id="enrollment.id" :providers="insuranceProviders" :policies="insurancePolicies" :can-manage="canManage && !readonly" />
                    </template>
                    <template v-else-if="active === 'documents'">
                        <WayAlongDocuments :documents="documents" :upload-url="`${base}/documents`" :download-base="`${base}/documents`" :can-upload="!readonly && canManage" />
                    </template>
                    <template v-else-if="active === 'enrollment'">
                        <dl class="grid gap-3 text-sm sm:grid-cols-2"><div><dt class="text-muted-foreground">Facility</dt><dd>{{ facility.name }}</dd></div><div><dt class="text-muted-foreground">Status</dt><dd class="capitalize">{{ enrollment.status }}</dd></div><div><dt class="text-muted-foreground">Enrolled on</dt><dd>{{ enrollment.enrolled_at?.slice(0,10) }}</dd></div><div><dt class="text-muted-foreground">Discharged on</dt><dd>{{ enrollment.discharged_at?.slice(0,10) ?? '—' }}</dd></div></dl>
                        <p class="text-sm text-muted-foreground">Enrollment status is managed from the Client Directory. Historical enrollment dates are preserved.</p>
                    </template>
                    <template v-else-if="active === 'staff'">
                        <p class="text-sm text-muted-foreground">Only staff actively assigned to this facility may be added to the client's team. Team assignment alone does not override facility permissions.</p>
                        <div v-if="readonly" class="space-y-2"><p v-if="!assignedStaff.length" class="text-sm text-muted-foreground">No staff assigned.</p><p v-for="staff in assignedStaff" :key="staff.id" class="rounded-md border p-3">{{ staff.name }}</p></div>
                        <div v-else class="space-y-2"><label v-for="staff in staffOptions" :key="staff.id" class="flex items-center gap-2 rounded-md border p-3 text-sm"><input v-model="form.staff_ids" type="checkbox" :value="staff.id" />{{ staff.name }}</label><p v-if="!staffOptions.length" class="text-sm text-muted-foreground">No active staff assigned to this facility.</p><p v-if="form.errors.staff_ids" class="text-sm text-destructive">{{ form.errors.staff_ids }}</p></div>
                    </template>
                    <div class="flex items-center justify-between gap-3 border-t pt-4"><Link href="/facility/clients" class="rounded-md border px-4 py-2 text-sm">Back to directory</Link><Link v-if="readonly && canManage" :href="`${base}/edit`" class="rounded-md bg-primary px-4 py-2 text-sm text-primary-foreground">Edit client</Link><button v-else-if="!readonly" type="submit" :disabled="form.processing" class="rounded-md bg-primary px-4 py-2 text-sm text-primary-foreground disabled:opacity-50">{{ form.processing ? 'Saving…' : 'Save client profile' }}</button></div>
                    <p v-if="Object.keys(form.errors).length" role="alert" class="text-sm text-destructive">Please correct the highlighted sections before saving.</p>
                </form>
            </template>
        </WayAlongFormSections>
    </main>
</template>
