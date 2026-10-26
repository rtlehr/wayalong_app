<script setup lang="ts">
import { useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';

type Verification = { id: number; status: string; method: string; checked_on: string; reference_number: string | null; notes: string | null };
type Provider = { id: number; name: string; payer_code: string | null; phone: string | null };
type Policy = { id: number; insurance_provider_id: number | null; verifications: Verification[]; payer_name: string; coverage_order: string; member_id: string | null; group_number: string | null; plan_name: string | null; subscriber_name: string | null; subscriber_relationship: string | null; effective_on: string | null; expires_on: string | null; verification_status: string; verified_on: string | null; notes: string | null };
const props = defineProps<{ enrollmentId: number; policies: Policy[]; providers: Provider[]; canManage: boolean }>();
const selected = ref<number | null>(null);
const showPolicyForm = ref(false);
const base = `/facility/clients/${props.enrollmentId}/insurance`;
const empty = () => ({ insurance_provider_id: null as number | null, payer_name: '', coverage_order: 'primary', member_id: '', group_number: '', plan_name: '', subscriber_name: '', subscriber_relationship: 'self', effective_on: '', expires_on: '', verification_status: 'unverified', verified_on: '', notes: '' });
const form = useForm(empty());
const verificationForm = useForm({ status: 'pending', method: 'phone', checked_on: new Date().toISOString().slice(0, 10), reference_number: '', notes: '' });
const verifying = ref<number | null>(null);
function saveVerification(): void {
    if (props.canManage && verifying.value) verificationForm.post(`${base}/${verifying.value}/verifications`, { preserveScroll: true, onSuccess: () => { verifying.value = null; verificationForm.reset(); } });
}
function coverageLabel(policy: Policy): string {
    if (!policy.expires_on) return 'No expiration recorded';
    const days = Math.ceil((new Date(`${policy.expires_on}T00:00:00`).getTime() - new Date(new Date().toDateString()).getTime()) / 86400000);
    if (days < 0) return 'Expired';
    if (days <= 30) return `Expires in ${days} days`;
    return 'Coverage dates current';
}
function edit(policy: Policy): void {
    showPolicyForm.value = true;
    selected.value = policy.id;
    for (const field of Object.keys(empty()) as (keyof ReturnType<typeof empty>)[]) (form as any)[field] = (policy as any)[field] ?? (field === 'insurance_provider_id' ? null : '');
    form.clearErrors();
}
function reset(): void { selected.value = null; showPolicyForm.value = false; form.defaults(empty()); form.reset(); form.clearErrors(); }
function addPolicy(): void { reset(); showPolicyForm.value = true; }
function save(): void {
    if (!props.canManage) return;
    const options = { preserveScroll: true, onSuccess: () => reset() };
    if (selected.value) form.patch(`${base}/${selected.value}`, options);
    else form.post(base, options);
}
function remove(policy: Policy): void {
    if (props.canManage && !policy.verifications?.length && window.confirm(`Remove ${policy.payer_name} coverage?`)) router.delete(`${base}/${policy.id}`, { preserveScroll: true, onSuccess: reset });
}
</script>

<template>
    <section class="space-y-4" aria-label="Client insurance policies">
        <p class="text-sm text-muted-foreground">Coverage belongs to the client across this organization. Only authorized facility staff can view or change it.</p>
        <div v-if="!policies.length" class="rounded-md border p-4 text-sm">No insurance policies recorded.</div>
        <div v-for="policy in policies" :key="policy.id" class="flex flex-wrap items-center justify-between gap-3 rounded-md border p-4 text-sm">
            <div><p class="font-semibold">{{ policy.coverage_order.toUpperCase() }} · {{ policy.payer_name }}</p><p>{{ policy.plan_name || 'No plan name' }} · {{ policy.verification_status }}</p><p v-if="policy.effective_on || policy.expires_on">{{ policy.effective_on || 'Unknown start' }} — {{ policy.expires_on || 'No expiration' }}</p><p class="font-medium">{{ coverageLabel(policy) }}</p>
            <div v-if="policy.verifications?.length" class="mt-2 space-y-1"><p class="font-medium">Verification history</p><p v-for="v in policy.verifications" :key="v.id">{{ v.checked_on }} · {{ v.status }} · {{ v.method }}<span v-if="v.reference_number"> · Ref: {{ v.reference_number }}</span><span v-if="v.notes"> · {{ v.notes }}</span></p></div></div>
            <div v-if="canManage" class="flex gap-2"><button type="button" class="rounded-md border px-3 py-2" @click="edit(policy)">Edit policy</button><button type="button" class="rounded-md border px-3 py-2" @click="verifying = policy.id">Record verification</button></div>
        </div>
        <form v-if="canManage && verifying" class="space-y-3 rounded-md border p-4" @submit.prevent="saveVerification">
            <h3 class="font-semibold">Record eligibility verification</h3>
            <div class="grid gap-3 md:grid-cols-2">
                <label class="grid gap-1 text-sm">Status<select v-model="verificationForm.status" class="rounded-md border bg-background p-2"><option v-for="s in ['pending','verified','rejected','unverified']" :key="s" :value="s">{{ s }}</option></select></label>
                <label class="grid gap-1 text-sm">Method<select v-model="verificationForm.method" class="rounded-md border bg-background p-2"><option v-for="m in ['phone','portal','fax','email','other']" :key="m" :value="m">{{ m }}</option></select></label>
                <label class="grid gap-1 text-sm">Checked on<input v-model="verificationForm.checked_on" type="date" required class="rounded-md border bg-background p-2" /></label>
                <label class="grid gap-1 text-sm">Reference number<input v-model="verificationForm.reference_number" maxlength="200" class="rounded-md border bg-background p-2" /></label>
                <label class="grid gap-1 text-sm md:col-span-2">Notes<textarea v-model="verificationForm.notes" maxlength="3000" class="rounded-md border bg-background p-2" /></label>
            </div>
            <p v-for="(error, field) in verificationForm.errors" :key="field" class="text-sm text-destructive">{{ field }}: {{ error }}</p>
            <button class="rounded-md bg-primary px-4 py-2 text-sm text-primary-foreground" type="submit" :disabled="verificationForm.processing">Save verification</button>
            <button type="button" class="ml-2 rounded-md border px-4 py-2 text-sm" @click="verifying = null">Cancel</button>
        </form>
        <button v-if="canManage && !showPolicyForm" type="button" class="rounded-md bg-primary px-4 py-2 text-sm text-primary-foreground" @click="addPolicy">Add Insurance Plan</button>
        <div v-if="canManage && showPolicyForm" class="space-y-3 rounded-md border p-4">
            <h3 class="font-semibold">{{ selected ? 'Edit insurance policy' : 'Add insurance policy' }}</h3>
            <div class="grid gap-3 md:grid-cols-2">
                <label class="grid gap-1 text-sm">Provider directory<select v-model="form.insurance_provider_id" class="rounded-md border bg-background p-2"><option :value="null">Manual payer name</option><option v-for="p in providers" :key="p.id" :value="p.id">{{ p.name }}</option></select></label>
                <label class="grid gap-1 text-sm">Payer name *<input v-model="form.payer_name" required maxlength="200" class="rounded-md border bg-background p-2" /></label>
                <label class="grid gap-1 text-sm">Coverage priority *<select v-model="form.coverage_order" class="rounded-md border bg-background p-2"><option value="primary">Primary</option><option value="secondary">Secondary</option><option value="tertiary">Tertiary</option></select></label>
                <label class="grid gap-1 text-sm">Member ID<input v-model="form.member_id" maxlength="200" autocomplete="off" class="rounded-md border bg-background p-2" /></label>
                <label class="grid gap-1 text-sm">Group number<input v-model="form.group_number" maxlength="200" autocomplete="off" class="rounded-md border bg-background p-2" /></label>
                <label class="grid gap-1 text-sm">Plan name<input v-model="form.plan_name" maxlength="200" class="rounded-md border bg-background p-2" /></label>
                <label class="grid gap-1 text-sm">Subscriber name<input v-model="form.subscriber_name" maxlength="200" class="rounded-md border bg-background p-2" /></label>
                <label class="grid gap-1 text-sm">Subscriber relationship<select v-model="form.subscriber_relationship" class="rounded-md border bg-background p-2"><option v-for="relation in ['self','parent','guardian','spouse','other']" :key="relation" :value="relation">{{ relation }}</option></select></label>
                <label class="grid gap-1 text-sm">Effective date<input v-model="form.effective_on" type="date" class="rounded-md border bg-background p-2" /></label>
                <label class="grid gap-1 text-sm">Expiration date<input v-model="form.expires_on" type="date" class="rounded-md border bg-background p-2" /></label>
                <label class="grid gap-1 text-sm">Verification status<select v-model="form.verification_status" class="rounded-md border bg-background p-2"><option v-for="status in ['unverified','pending','verified','rejected']" :key="status" :value="status">{{ status }}</option></select></label>
                <label class="grid gap-1 text-sm">Verified on<input v-model="form.verified_on" type="date" class="rounded-md border bg-background p-2" /></label>
                <label class="grid gap-1 text-sm md:col-span-2">Notes<textarea v-model="form.notes" maxlength="3000" rows="2" class="rounded-md border bg-background p-2" /></label>
            </div>
            <div v-if="Object.keys(form.errors).length" role="alert" class="space-y-1 text-sm text-destructive"><p v-for="(error, field) in form.errors" :key="field">{{ field }}: {{ error }}</p></div>
            <div class="flex flex-wrap gap-2"><button type="button" :disabled="form.processing" class="rounded-md bg-primary px-4 py-2 text-sm text-primary-foreground" @click="save">{{ form.processing ? 'Saving…' : 'Save insurance policy' }}</button><button type="button" :disabled="form.processing" class="rounded-md border px-4 py-2 text-sm" @click="reset">{{ selected ? 'Cancel editing' : 'Remove Insurance' }}</button><button v-if="selected && !policies.find(p => p.id === selected)?.verifications?.length" type="button" :disabled="form.processing" class="rounded-md border px-4 py-2 text-sm text-destructive" @click="remove(policies.find(p => p.id === selected)!)">Delete saved policy</button></div>
        </div>
    </section>
</template>
