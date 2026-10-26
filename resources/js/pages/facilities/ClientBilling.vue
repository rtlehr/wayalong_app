<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';

const props = defineProps<{
    enrollment: { id: number };
    client: { id: number; first_name: string; last_name: string };
    facility: { id: number; name: string };
    charges: { id: number; service_on: string; description: string; reversal_reason: string | null; amount_cents: number; paid_cents: number; adjusted_cents: number; due_cents: number; responsibility: string; facility_id: number; reverses_charge_id: number | null; is_reversed: boolean; created_at: string }[];
    payments: { id: number; charge_id: number; received_on: string; payer_type: string; method: string; amount_cents: number; reference: string | null; reversal_reason: string | null; reverses_payment_id: number | null; is_reversed: boolean }[];
    adjustments: { id: number; claim_id: number; charge_id: number; adjusted_on: string; kind: string; amount_cents: number; reason: string; reverses_adjustment_id: number | null; is_reversed: boolean }[];
    insurancePolicies: { id: number; name: string; order: string; expires_on: string | null; effective_on: string | null; verification_status: string }[];
    claims: { id: number; charge_id: number; policy_id: number; claim_number: string | null; status: string; submitted_on: string | null; adjudicated_on: string | null; allowed_amount_cents: number | null; insurer_paid_cents: number | null; patient_responsibility_cents: number | null; reconciliation: { status: string; issues: string[]; recorded_insurer_paid_cents: number; recorded_client_paid_cents: number; recorded_adjustments_cents: number; expected_adjustments_cents: number | null; patient_balance_cents: number | null }; events: { id: number; status: string; notes: string | null; created_at: string }[] }[];
    summary: { posted_cents: number; reversed_cents: number; net_charges_cents: number; net_payments_cents: number; net_adjustments_cents: number; outstanding_cents: number };
}>();
const base = `/facility/clients/${props.enrollment.id}`;
type BillingSection = 'overview' | 'charges' | 'payments' | 'claims';
const activeSection = ref<BillingSection>('overview');
const billingSections: { id: BillingSection; title: string; description: string }[] = [
    { id: 'overview', title: 'Overview', description: 'Account totals and balances' },
    { id: 'charges', title: 'Charges', description: 'Post and reverse charges' },
    { id: 'payments', title: 'Payment Ledger', description: 'Payments and reversals' },
    { id: 'claims', title: 'Insurance Claims', description: 'Claims and adjustments' },
];
type OverviewAlert = { key: string; level: 'critical' | 'warning' | 'info'; title: string; details: string; section: BillingSection; action: string };
const attentionItems = computed<OverviewAlert[]>(() => {
    const alerts: OverviewAlert[] = [];
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const dayMs = 86_400_000;
    for (const policy of props.insurancePolicies) {
        if (policy.expires_on) {
            const expiry = new Date(`${policy.expires_on}T00:00:00`);
            const days = Math.round((expiry.getTime() - today.getTime()) / dayMs);
            if (days < 0) alerts.push({ key: `policy-expired-${policy.id}`, level: 'critical', title: 'Insurance coverage expired', details: `${policy.name} (${policy.order}) expired on ${policy.expires_on}. Verify current coverage before submitting new claims.`, section: 'claims', action: 'Review claims' });
            else if (days <= 30) alerts.push({ key: `policy-expiring-${policy.id}`, level: 'warning', title: 'Coverage expires soon', details: `${policy.name} (${policy.order}) expires on ${policy.expires_on} (${days} ${days === 1 ? 'day' : 'days'} remaining). Review renewal details.`, section: 'claims', action: 'Review claims' });
        }
        if (policy.verification_status === 'rejected' || policy.verification_status === 'failed') {
            alerts.push({ key: `policy-verification-${policy.id}`, level: 'warning', title: 'Insurance verification needs attention', details: `${policy.name} (${policy.order}) has verification status: ${policy.verification_status}. Review the policy on the client Insurance page.`, section: 'claims', action: 'Review claims' });
        }
    }
    for (const claim of props.claims) {
        const claimName = claim.claim_number ? `Claim ${claim.claim_number}` : `Claim #${claim.id}`;
        if (claim.reconciliation.status === 'review_required') {
            alerts.push({ key: `claim-reconciliation-${claim.id}`, level: 'critical', title: `${claimName}: reconciliation needs review`, details: claim.reconciliation.issues.join(' ') || 'Payment or adjustment records require review.', section: 'claims', action: 'View claim details' });
        } else if (claim.reconciliation.status === 'not_ready' && ['approved', 'denied', 'closed'].includes(claim.status)) {
            alerts.push({ key: `claim-adjudication-${claim.id}`, level: 'warning', title: `${claimName}: adjudication incomplete`, details: 'This claim has a final status but is missing figures needed to reconcile it. Record the insurer decision details.', section: 'claims', action: 'Record adjudication' });
        }
        if (claim.status === 'denied') {
            alerts.push({ key: `claim-denied-${claim.id}`, level: 'warning', title: `${claimName}: denied`, details: 'This claim was denied. Review the status history, reason, and any next action before closing.', section: 'claims', action: 'Review denial' });
        } else if (claim.status === 'pending' || claim.status === 'submitted') {
            alerts.push({ key: `claim-pending-${claim.id}`, level: 'info', title: `${claimName}: ${claim.status}`, details: claim.submitted_on ? `Submitted on ${claim.submitted_on}; follow up on processing status as appropriate.` : 'Claim is awaiting a decision. Verify submission details and follow up as needed.', section: 'claims', action: 'View claim' });
        }
    }
    if (props.summary.outstanding_cents > 0) {
        alerts.push({ key: 'outstanding', level: 'info', title: 'Outstanding account balance', details: `${money(props.summary.outstanding_cents)} remains after recorded payments and adjustments. Review individual charges for payment allocation. This is not an overdue determination.`, section: 'charges', action: 'Review charges' });
    }
    const priority = { critical: 0, warning: 1, info: 2 };
    return alerts.sort((a, b) => priority[a.level] - priority[b.level]);
});
const attentionCounts = computed(() => ({
    critical: attentionItems.value.filter(item => item.level === 'critical').length,
    warning: attentionItems.value.filter(item => item.level === 'warning').length,
    info: attentionItems.value.filter(item => item.level === 'info').length,
}));
function openAttentionItem(item: OverviewAlert): void {
    activeSection.value = item.section;
    // Show the relevant work area without guessing a route or changing ledger data.
}
const showForm = ref(false);
const payingCharge = ref<number | null>(null);
const reverseDialogOpen = ref(false);
const reverseTarget = ref<{ type: 'charge' | 'payment'; id: number } | null>(null);
const money = (cents: number) => new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(cents / 100);
const charge = useForm({ service_on: new Date().toISOString().slice(0, 10), description: '', amount: '', responsibility: 'insurance', notes: '' });
const payment = useForm({ received_on: new Date().toISOString().slice(0, 10), amount: '', payer_type: 'client', method: 'check', reference: '', notes: '' });
const reversal = useForm({ reason: '' });
const adjustmentForm = useForm({ adjusted_on: new Date().toISOString().slice(0, 10), kind: 'contractual', amount: '', reason: '' });
const adjustingClaim = ref<number | null>(null);
const adjustmentReversal = useForm({ reason: '' });
const reversingAdjustment = ref<number | null>(null);
function recordAdjustment(id: number): void {
    adjustmentForm.post(`${base}/billing/claims/${id}/adjustments`, {
        preserveScroll: true,
        onSuccess: () => { adjustingClaim.value = null; adjustmentForm.reset('amount', 'reason'); },
    });
}
function reverseAdjustment(id: number): void {
    adjustmentReversal.post(`${base}/billing/adjustments/${id}/reverse`, {
        preserveScroll: true,
        onSuccess: () => { reversingAdjustment.value = null; adjustmentReversal.reset('reason'); },
    });
}
const adjudicationForm = useForm({ adjudicated_on: new Date().toISOString().slice(0, 10), allowed_amount: '', insurer_paid: '', patient_responsibility: '', notes: '' });
const adjudicatingClaim = ref<number | null>(null);
function editAdjudication(item: (typeof props.claims)[number]): void {
    adjudicatingClaim.value = item.id;
    adjudicationForm.adjudicated_on = item.adjudicated_on || new Date().toISOString().slice(0, 10);
    adjudicationForm.allowed_amount = item.allowed_amount_cents === null ? '' : (item.allowed_amount_cents / 100).toFixed(2);
    adjudicationForm.insurer_paid = item.insurer_paid_cents === null ? '' : (item.insurer_paid_cents / 100).toFixed(2);
    adjudicationForm.patient_responsibility = item.patient_responsibility_cents === null ? '' : (item.patient_responsibility_cents / 100).toFixed(2);
    adjudicationForm.notes = '';
    adjudicationForm.clearErrors();
}
function saveAdjudication(id: number): void {
    adjudicationForm.post(`${base}/billing/claims/${id}/adjudication`, {
        preserveScroll: true,
        onSuccess: () => { adjudicatingClaim.value = null; adjudicationForm.reset('notes'); },
    });
}
const claimForm = useForm({ charge_id: '', client_insurance_policy_id: '', claim_number: '', notes: '' });
const statusForms = ref<Record<number, { status: string; notes: string; submitted_on: string }>>({});
const claimStatuses: Record<string, string[]> = {
    draft: ['submitted'], submitted: ['pending', 'approved', 'denied'],
    pending: ['approved', 'denied'], approved: ['closed'], denied: ['closed'], closed: [],
};
function createClaim(): void {
    claimForm.post(`${base}/billing/claims`, {
        preserveScroll: true,
        onSuccess: () => claimForm.reset(),
    });
}
function changeClaimStatus(id: number): void {
    const input = statusForms.value[id];
    if (!input?.status || !input.notes.trim()) return;
    const data = { ...input, notes: input.notes.trim() };
    useForm(data).post(`${base}/billing/claims/${id}/status`, {
        preserveScroll: true,
        onSuccess: () => { delete statusForms.value[id]; },
    });
}

const reverseDialogTitle = computed(() => reverseTarget.value?.type === 'payment' ? 'Reverse payment' : 'Reverse charge');
const reverseDialogDescription = computed(() => reverseTarget.value?.type === 'payment'
    ? 'Enter a reason for reversing this payment. The original payment will be preserved in the ledger and a separate reversal entry will be created.'
    : 'Enter a reason for reversing this charge. The original charge will be preserved in the ledger and a separate reversal entry will be created.');
const reverseConfirmLabel = computed(() => reverseTarget.value?.type === 'payment' ? 'Reverse payment' : 'Reverse charge');

function addCharge(): void {
    charge.post(`${base}/billing/charges`, {
        onSuccess: () => { charge.reset('description', 'amount', 'notes'); showForm.value = false; },
    });
}

function recordPayment(id: number): void {
    payment.post(`${base}/billing/charges/${id}/payments`, {
        preserveScroll: true,
        onSuccess: () => { payment.reset('amount', 'reference', 'notes'); payingCharge.value = null; },
    });
}

function openReverseDialog(type: 'charge' | 'payment', id: number): void {
    reverseTarget.value = { type, id };
    reversal.reset();
    reversal.clearErrors();
    reverseDialogOpen.value = true;
}

function closeReverseDialog(): void {
    reverseDialogOpen.value = false;
    reverseTarget.value = null;
    reversal.reset();
    reversal.clearErrors();
}

function submitReverse(): void {
    if (!reverseTarget.value) return;

    const path = reverseTarget.value.type === 'payment'
        ? `${base}/billing/payments/${reverseTarget.value.id}/reverse`
        : `${base}/billing/charges/${reverseTarget.value.id}/reverse`;

    reversal.post(path, {
        preserveScroll: true,
        onSuccess: () => closeReverseDialog(),
    });
}
</script>

<template>
    <Head :title="`Billing — ${client.first_name} ${client.last_name}`" />
    <div class="mx-auto max-w-7xl space-y-6 p-4 sm:p-6">
        <div class="space-y-2">
            <Link href="/organization/admin/billing" class="text-sm underline">← Client Billing & Accounts</Link>
            <h1 class="text-2xl font-semibold">Client Charges & Billing</h1>
            <p class="text-sm text-muted-foreground">{{ client.first_name }} {{ client.last_name }} · {{ facility.name }}</p>
            <p class="text-sm text-muted-foreground">Charges, allocated payments, claim adjustments, and claims are tracked here. Electronic submission and unapplied credits are not available yet.</p>
        </div>
        <div class="grid items-start gap-5 lg:grid-cols-[220px_minmax(0,1fr)]">
            <nav aria-label="Billing sections" class="rounded-xl border p-2 lg:sticky lg:top-4">
                <p class="px-3 pb-2 pt-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Billing sections</p>
                <div class="space-y-1">
                    <button v-for="section in billingSections" :key="section.id" type="button"
                        :aria-current="activeSection === section.id ? 'page' : undefined"
                        :class="activeSection === section.id ? 'bg-foreground text-background' : 'text-foreground hover:bg-muted'"
                        class="w-full rounded-lg px-3 py-3 text-left transition-colors"
                        @click="activeSection = section.id">
                        <span class="block text-sm font-semibold">{{ section.title }}</span>
                        <span :class="activeSection === section.id ? 'text-background/75' : 'text-muted-foreground'" class="mt-0.5 block text-xs">{{ section.description }}</span>
                    </button>
                </div>
            </nav>

            <main class="min-w-0 space-y-5">
                <section v-show="activeSection === 'overview'" class="space-y-4 rounded-lg border p-4">
                    <div>
                        <h2 class="text-lg font-semibold">Overview</h2>
                        <p class="text-sm text-muted-foreground">Charges, payments, adjustments, and remaining balances for this client.</p>
                    </div>
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <div class="rounded-lg border p-4"><div class="text-sm text-muted-foreground">Posted charges</div><div class="text-xl font-semibold">{{ money(summary.posted_cents) }}</div></div>
            <div class="rounded-lg border p-4"><div class="text-sm text-muted-foreground">Reversals</div><div class="text-xl font-semibold">{{ money(summary.reversed_cents) }}</div></div>
            <div class="rounded-lg border p-4"><div class="text-sm text-muted-foreground">Net charges</div><div class="text-xl font-semibold">{{ money(summary.net_charges_cents) }}</div></div>
            <div class="rounded-lg border p-4"><div class="text-sm text-muted-foreground">Payments received (net)</div><div class="text-xl font-semibold">{{ money(summary.net_payments_cents) }}</div></div>
            <div class="rounded-lg border p-4"><div class="text-sm text-muted-foreground">Claim adjustments (net)</div><div class="text-xl font-semibold">{{ money(summary.net_adjustments_cents) }}</div></div>
            <div class="rounded-lg border p-4"><div class="text-sm text-muted-foreground">Outstanding charges</div><div class="text-xl font-semibold">{{ money(summary.outstanding_cents) }}</div></div>
        </div>
                    <div class="space-y-3 border-t pt-4" aria-live="polite">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <h3 class="text-base font-semibold">Attention Needed</h3>
                                <p class="text-sm text-muted-foreground">Current insurance, claim, and account items that may need action.</p>
                            </div>
                            <span class="rounded-full border px-3 py-1 text-xs font-medium">{{ attentionItems.length }} {{ attentionItems.length === 1 ? 'item' : 'items' }}</span>
                        </div>
                        <div v-if="!attentionItems.length" class="rounded-lg border p-4 text-sm text-muted-foreground">No current insurance or billing issues detected by these checks. Continue routine account review.</div>
                        <div v-else class="space-y-3">
                            <div class="flex flex-wrap gap-2 text-xs">
                                <span v-if="attentionCounts.critical" class="rounded-full border border-red-300 px-2 py-1 text-red-700 dark:text-red-300">{{ attentionCounts.critical }} high priority</span>
                                <span v-if="attentionCounts.warning" class="rounded-full border border-amber-300 px-2 py-1 text-amber-700 dark:text-amber-300">{{ attentionCounts.warning }} warning</span>
                                <span v-if="attentionCounts.info" class="rounded-full border px-2 py-1 text-muted-foreground">{{ attentionCounts.info }} follow-up</span>
                            </div>
                            <div v-for="item in attentionItems" :key="item.key" class="rounded-lg border p-4" :class="item.level === 'critical' ? 'border-red-300 dark:border-red-800' : item.level === 'warning' ? 'border-amber-300 dark:border-amber-800' : ''">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div class="min-w-0 flex-1 space-y-2">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="rounded border px-2 py-0.5 text-xs font-semibold" :class="item.level === 'critical' ? 'border-red-300 text-red-700 dark:text-red-300' : item.level === 'warning' ? 'border-amber-300 text-amber-700 dark:text-amber-300' : 'text-muted-foreground'">{{ item.level === 'critical' ? 'High priority' : item.level === 'warning' ? 'Warning' : 'Follow-up' }}</span>
                                            <h4 class="text-sm font-semibold">{{ item.title }}</h4>
                                        </div>
                                        <p class="text-sm text-muted-foreground">{{ item.details }}</p>
                                    </div>
                                    <Button variant="outline" size="sm" type="button" @click="openAttentionItem(item)">{{ item.action }}</Button>
                                </div>
                            </div>
                        </div>
                        <p class="text-xs text-muted-foreground">Alerts are generated from recorded data. Coverage verification and reconciliation still require staff review. Policy details are managed from the client Insurance section.</p>
                    </div>
                </section>
        <section v-show="activeSection === 'charges'" class="space-y-4 rounded-lg border p-4">
            <div class="flex items-center justify-between gap-3"><h2 class="text-lg font-semibold">Charges</h2><Button variant="outline" type="button" @click="showForm = !showForm; charge.clearErrors()">{{ showForm ? 'Cancel' : 'Add Charge' }}</Button></div>
            <form v-if="showForm" class="grid gap-3 sm:grid-cols-2" @submit.prevent="addCharge">
                <label class="space-y-1 text-sm">Service date<input v-model="charge.service_on" type="date" required class="w-full rounded-md border bg-background p-2" /><span v-if="charge.errors.service_on" class="text-destructive">{{ charge.errors.service_on }}</span></label>
                <label class="space-y-1 text-sm">Amount (USD)<input v-model="charge.amount" type="text" placeholder="125.00" required class="w-full rounded-md border bg-background p-2" /><span v-if="charge.errors.amount" class="text-destructive">{{ charge.errors.amount }}</span></label>
                <label class="space-y-1 text-sm sm:col-span-2">Service description<input v-model="charge.description" maxlength="240" required class="w-full rounded-md border bg-background p-2" /><span v-if="charge.errors.description" class="text-destructive">{{ charge.errors.description }}</span></label>
                <label class="space-y-1 text-sm">Planned responsibility<select v-model="charge.responsibility" class="w-full rounded-md border bg-background p-2"><option value="insurance">Insurance</option><option value="client">Client / family</option></select></label>
                <label class="space-y-1 text-sm sm:col-span-2">Internal notes (optional)<textarea v-model="charge.notes" class="w-full rounded-md border bg-background p-2" rows="2" /></label>
                <div class="sm:col-span-2"><Button type="submit" :disabled="charge.processing">Post charge</Button></div>
            </form>
            <div v-if="!charges.length" class="text-sm text-muted-foreground">No charges recorded for this client.</div>
            <div v-for="item in charges" :key="item.id" class="flex flex-wrap items-start justify-between gap-3 rounded-md border p-3">
                <div class="space-y-1 text-sm"><div class="font-medium">{{ item.description }}</div><div class="text-muted-foreground">{{ item.service_on }} · {{ item.responsibility }} · {{ item.reverses_charge_id ? `Reversal of #${item.reverses_charge_id}` : `Charge #${item.id}` }}</div><div v-if="item.is_reversed" class="font-medium">Reversed</div><div v-if="item.reverses_charge_id && item.reversal_reason" class="text-muted-foreground"><span class="font-medium text-foreground">Reversal reason:</span> {{ item.reversal_reason }}</div><div v-if="!item.reverses_charge_id" class="text-muted-foreground">Paid: {{ money(item.paid_cents) }} · Adjusted: {{ money(item.adjusted_cents) }} · Remaining: {{ money(item.due_cents) }}</div></div>
                <div class="flex items-center gap-3"><strong>{{ money(item.amount_cents) }}</strong><Button v-if="!item.reverses_charge_id && !item.is_reversed && item.due_cents > 0" variant="outline" type="button" @click="payingCharge = payingCharge === item.id ? null : item.id; payment.clearErrors()">{{ payingCharge === item.id ? 'Cancel Payment' : 'Record Payment' }}</Button><Button v-if="!item.reverses_charge_id && !item.is_reversed" variant="outline" type="button" @click="openReverseDialog('charge', item.id)">Reverse</Button></div>
                <form v-if="payingCharge === item.id" class="grid w-full gap-3 rounded-md border p-3 sm:grid-cols-2" @submit.prevent="recordPayment(item.id)">
                    <label class="text-sm">Received date<input v-model="payment.received_on" type="date" required class="mt-1 w-full rounded-md border bg-background p-2" /></label>
                    <label class="text-sm">Amount (USD)<input v-model="payment.amount" type="text" required placeholder="25.00" class="mt-1 w-full rounded-md border bg-background p-2" /><span v-if="payment.errors.amount" class="text-destructive">{{ payment.errors.amount }}</span></label>
                    <label class="text-sm">Paid by<select v-model="payment.payer_type" class="mt-1 w-full rounded-md border bg-background p-2"><option value="client">Client / family</option><option value="insurance">Insurance</option></select></label>
                    <label class="text-sm">Payment method<select v-model="payment.method" class="mt-1 w-full rounded-md border bg-background p-2"><option value="check">Check</option><option value="cash">Cash</option><option value="card">Card (manual record)</option><option value="bank_transfer">Bank transfer</option><option value="insurer_remittance">Insurer remittance</option><option value="other">Other</option></select></label>
                    <label class="text-sm">Reference (optional)<input v-model="payment.reference" maxlength="500" class="mt-1 w-full rounded-md border bg-background p-2" /></label>
                    <label class="text-sm">Internal notes (optional)<input v-model="payment.notes" maxlength="3000" class="mt-1 w-full rounded-md border bg-background p-2" /></label>
                    <div class="sm:col-span-2"><Button type="submit" :disabled="payment.processing">Save payment</Button></div>
                </form>
            </div>
        </section>
        <section v-show="activeSection === 'payments'" class="space-y-3 rounded-lg border p-4">
            <h2 class="text-lg font-semibold">Payment ledger</h2>
            <p class="text-sm text-muted-foreground">Payments are allocated to one charge. Corrections are recorded as separate reversals.</p>
            <p v-if="!payments.length" class="text-sm text-muted-foreground">No payments recorded.</p>
            <div v-for="item in payments" :key="item.id" class="flex flex-wrap items-center justify-between gap-3 rounded-md border p-3 text-sm">
                <div><div class="font-medium">{{ item.reverses_payment_id ? `Payment reversal #${item.reverses_payment_id}` : `Payment #${item.id}` }} · Charge #{{ item.charge_id }}</div><div class="text-muted-foreground">{{ item.received_on }} · {{ item.payer_type }} · {{ item.method }}<span v-if="item.reference"> · Ref: {{ item.reference }}</span></div><div v-if="item.is_reversed">Reversed</div><div v-if="item.reverses_payment_id && item.reversal_reason" class="mt-1 text-muted-foreground"><span class="font-medium text-foreground">Reversal reason:</span> {{ item.reversal_reason }}</div></div>
                <div class="flex items-center gap-3"><strong>{{ money(item.amount_cents) }}</strong><Button v-if="!item.reverses_payment_id && !item.is_reversed" variant="outline" type="button" @click="openReverseDialog('payment', item.id)">Reverse payment</Button></div>
            </div>
        </section>


                <div v-show="activeSection === 'claims'" class="space-y-5">
        <section class="space-y-4 rounded-lg border p-4">
            <h2 class="text-lg font-semibold">Insurance claims</h2>
            <p class="text-sm text-muted-foreground">Track manually submitted claims and their status. After a claim decision, record any payer adjustment separately from money received.</p>
            <form v-if="insurancePolicies.length && charges.some(item => !item.reverses_charge_id && !item.is_reversed)" class="grid gap-3 rounded-md border p-3 sm:grid-cols-2" @submit.prevent="createClaim">
                <label class="text-sm">Charge
                    <select v-model="claimForm.charge_id" required class="mt-1 w-full rounded-md border bg-background p-2">
                        <option value="">Choose a charge</option>
                        <option v-for="item in charges.filter(item => !item.reverses_charge_id && !item.is_reversed)" :key="item.id" :value="item.id">#{{ item.id }} · {{ item.description }} · {{ money(item.amount_cents) }}</option>
                    </select>
                    <InputError :message="claimForm.errors.charge_id" />
                </label>
                <label class="text-sm">Insurance plan
                    <select v-model="claimForm.client_insurance_policy_id" required class="mt-1 w-full rounded-md border bg-background p-2">
                        <option value="">Choose an insurance plan</option>
                        <option v-for="policy in insurancePolicies" :key="policy.id" :value="policy.id">{{ policy.order }} · {{ policy.name }}</option>
                    </select>
                    <InputError :message="claimForm.errors.client_insurance_policy_id" />
                </label>
                <label class="text-sm">Claim / tracking number (optional)<input v-model="claimForm.claim_number" maxlength="100" class="mt-1 w-full rounded-md border bg-background p-2" /></label>
                <label class="text-sm">Internal notes (optional)<input v-model="claimForm.notes" maxlength="3000" class="mt-1 w-full rounded-md border bg-background p-2" /></label>
                <div class="sm:col-span-2"><Button type="submit" :disabled="claimForm.processing">Create draft claim</Button></div>
            </form>
            <p v-else-if="!insurancePolicies.length" class="text-sm text-muted-foreground">Add an insurance policy to this client before creating claims.</p>
            <p v-if="!claims.length" class="text-sm text-muted-foreground">No insurance claims recorded.</p>
            <div v-for="claimItem in claims" :key="claimItem.id" class="space-y-3 rounded-md border p-3">
                <div class="flex flex-wrap justify-between gap-2 text-sm">
                    <strong>Claim #{{ claimItem.id }} · Charge #{{ claimItem.charge_id }}</strong>
                    <span class="font-medium capitalize">{{ claimItem.status }}</span>
                </div>
                <p class="text-sm text-muted-foreground">Tracking: {{ claimItem.claim_number || 'Not assigned' }} · Submitted: {{ claimItem.submitted_on || 'Not submitted' }}</p>
                <div class="space-y-2 rounded-md border bg-muted/20 p-3 text-sm" aria-label="Claim reconciliation">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <strong>Reconciliation review</strong>
                        <span class="rounded-md border px-2 py-1 text-xs font-semibold"
                            :class="claimItem.reconciliation.status === 'reconciled' ? 'text-green-700 dark:text-green-400' : claimItem.reconciliation.status === 'review_required' ? 'text-amber-700 dark:text-amber-400' : 'text-muted-foreground'">
                            {{ claimItem.reconciliation.status === 'reconciled' ? 'Matches ledger' : claimItem.reconciliation.status === 'review_required' ? 'Needs review' : 'Awaiting adjudication' }}
                        </span>
                    </div>
                    <p class="text-xs text-muted-foreground">Read-only comparison. Does not post payments, change responsibility, or submit a claim. Payment amounts are charge-level records.</p>
                    <div v-if="claimItem.reconciliation.expected_adjustments_cents !== null" class="grid gap-1 sm:grid-cols-2">
                        <div>Reported insurer paid: {{ money(claimItem.insurer_paid_cents ?? 0) }}</div>
                        <div>Insurer paid in ledger: {{ money(claimItem.reconciliation.recorded_insurer_paid_cents) }}</div>
                        <div>Charge-to-allowed difference: {{ money(claimItem.reconciliation.expected_adjustments_cents) }}</div>
                        <div>Claim adjustments in ledger: {{ money(claimItem.reconciliation.recorded_adjustments_cents) }}</div>
                        <div>Client payments recorded: {{ money(claimItem.reconciliation.recorded_client_paid_cents) }}</div>
                        <div>Patient amount remaining: {{ money(claimItem.reconciliation.patient_balance_cents ?? 0) }}</div>
                    </div>
                    <ul v-if="claimItem.reconciliation.issues.length" class="list-inside list-disc space-y-1 text-sm">
                        <li v-for="issue in claimItem.reconciliation.issues" :key="issue">{{ issue }}</li>
                    </ul>
                </div>
                <div v-if="['approved', 'denied', 'closed'].includes(claimItem.status)" class="space-y-3 rounded-md border p-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <p class="text-sm font-semibold">Payer adjudication details</p>
                            <p class="text-xs text-muted-foreground">Informational figures only. Record actual payments and adjustments in their separate ledgers.</p>
                        </div>
                        <Button v-if="adjudicatingClaim !== claimItem.id" type="button" variant="outline" @click="editAdjudication(claimItem)">{{ claimItem.adjudicated_on ? 'Update adjudication' : 'Record adjudication' }}</Button>
                    </div>
                    <div v-if="claimItem.adjudicated_on" class="grid gap-2 text-sm sm:grid-cols-2">
                        <div>Decision date: <strong>{{ claimItem.adjudicated_on }}</strong></div>
                        <div>Allowed: <strong>{{ money(claimItem.allowed_amount_cents ?? 0) }}</strong></div>
                        <div>Insurer reported paid: <strong>{{ money(claimItem.insurer_paid_cents ?? 0) }}</strong></div>
                        <div>Patient responsibility: <strong>{{ money(claimItem.patient_responsibility_cents ?? 0) }}</strong></div>
                    </div>
                    <form v-if="adjudicatingClaim === claimItem.id" class="grid gap-3 sm:grid-cols-2" @submit.prevent="saveAdjudication(claimItem.id)">
                        <label class="text-sm">Decision date<input v-model="adjudicationForm.adjudicated_on" type="date" required class="mt-1 w-full rounded-md border bg-background p-2" /></label>
                        <label class="text-sm">Allowed amount (USD)<input v-model="adjudicationForm.allowed_amount" required placeholder="100.00" class="mt-1 w-full rounded-md border bg-background p-2" /></label>
                        <label class="text-sm">Insurer paid (reported, USD)<input v-model="adjudicationForm.insurer_paid" required placeholder="75.00" class="mt-1 w-full rounded-md border bg-background p-2" /></label>
                        <label class="text-sm">Patient responsibility (USD)<input v-model="adjudicationForm.patient_responsibility" required placeholder="25.00" class="mt-1 w-full rounded-md border bg-background p-2" /></label>
                        <label class="text-sm sm:col-span-2">Reason / explanation<input v-model="adjudicationForm.notes" required maxlength="3000" class="mt-1 w-full rounded-md border bg-background p-2" /></label>
                        <div class="space-y-1 sm:col-span-2"><InputError v-for="(error, key) in adjudicationForm.errors" :key="key" :message="error" /></div>
                        <div class="flex flex-wrap gap-2 sm:col-span-2"><Button type="submit" :disabled="adjudicationForm.processing">Save adjudication</Button><Button type="button" variant="outline" @click="adjudicatingClaim = null; adjudicationForm.clearErrors()">Cancel</Button></div>
                    </form>
                </div>
                <div class="space-y-1 text-sm">
                    <div class="font-medium">Status history</div>
                    <div v-for="event in claimItem.events" :key="event.id" class="text-muted-foreground">{{ event.created_at }} · {{ event.status }} · {{ event.notes }}</div>
                </div>
                <div v-if="['approved', 'denied', 'closed'].includes(claimItem.status)" class="space-y-2 rounded-md bg-muted/40 p-3">
                    <Button v-if="adjustingClaim !== claimItem.id" type="button" variant="outline" @click="adjustingClaim = claimItem.id; adjustmentForm.clearErrors()">Record payer adjustment</Button>
                    <form v-else class="grid gap-3 sm:grid-cols-2" @submit.prevent="recordAdjustment(claimItem.id)">
                        <label class="text-sm">Adjustment date<input v-model="adjustmentForm.adjusted_on" required type="date" class="mt-1 w-full rounded-md border bg-background p-2" /></label>
                        <label class="text-sm">Type<select v-model="adjustmentForm.kind" class="mt-1 w-full rounded-md border bg-background p-2"><option value="contractual">Contractual</option><option value="payer_writeoff">Payer write-off</option><option value="other">Other adjustment</option></select></label>
                        <label class="text-sm">Amount (USD)<input v-model="adjustmentForm.amount" required placeholder="50.00" class="mt-1 w-full rounded-md border bg-background p-2" /><InputError :message="adjustmentForm.errors.amount" /></label>
                        <label class="text-sm">Reason (required)<input v-model="adjustmentForm.reason" required maxlength="1000" class="mt-1 w-full rounded-md border bg-background p-2" /><InputError :message="adjustmentForm.errors.reason" /></label>
                        <InputError :message="adjustmentForm.errors.claim" />
                        <div class="flex gap-2 sm:col-span-2"><Button type="submit" :disabled="adjustmentForm.processing">Save adjustment</Button><Button type="button" variant="outline" @click="adjustingClaim = null; adjustmentForm.reset('amount', 'reason'); adjustmentForm.clearErrors()">Cancel</Button></div>
                    </form>
                </div>
                <div v-if="claimStatuses[claimItem.status]?.length" class="space-y-2 rounded-md bg-muted/40 p-3">
                    <Button v-if="!statusForms[claimItem.id]" type="button" variant="outline" @click="statusForms[claimItem.id] = { status: '', notes: '', submitted_on: '' }">Update claim status</Button>
                    <form v-else class="grid gap-2 sm:grid-cols-2" @submit.prevent="changeClaimStatus(claimItem.id)">
                        <label class="text-sm">New status
                            <select v-model="statusForms[claimItem.id].status" required class="mt-1 w-full rounded-md border bg-background p-2">
                                <option value="">Choose status</option>
                                <option v-for="status in claimStatuses[claimItem.status]" :key="status" :value="status">{{ status }}</option>
                            </select>
                        </label>
                        <label class="text-sm">Reason / status notes (required)<input v-model="statusForms[claimItem.id].notes" required maxlength="3000" class="mt-1 w-full rounded-md border bg-background p-2" /></label>
                        <div class="flex gap-2 sm:col-span-2">
                            <Button type="submit" :disabled="!statusForms[claimItem.id].status || !statusForms[claimItem.id].notes.trim()">Save status</Button>
                            <Button type="button" variant="outline" @click="delete statusForms[claimItem.id]">Cancel</Button>
                        </div>
                    </form>
                </div>
            </div>
        </section>

        <section class="space-y-3 rounded-lg border p-4">
            <h2 class="text-lg font-semibold">Claim adjustment ledger</h2>
            <p class="text-sm text-muted-foreground">Adjustments reduce the amount due but are not payments. Reversals preserve the full history.</p>
            <p v-if="!adjustments.length" class="text-sm text-muted-foreground">No claim adjustments recorded.</p>
            <div v-for="item in adjustments" :key="item.id" class="space-y-2 rounded-md border p-3 text-sm">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div><strong>{{ item.reverses_adjustment_id ? `Adjustment reversal #${item.reverses_adjustment_id}` : `Adjustment #${item.id}` }}</strong><span v-if="item.is_reversed"> · Reversed</span>
                        <div class="text-muted-foreground">{{ item.adjusted_on }} · Claim #{{ item.claim_id }} · Charge #{{ item.charge_id }} · {{ item.kind.replace('_', ' ') }}</div>
                        <div class="mt-1">Reason: {{ item.reason }}</div>
                    </div>
                    <div class="flex items-center gap-2"><strong>{{ money(item.amount_cents) }}</strong><Button v-if="!item.reverses_adjustment_id && !item.is_reversed" type="button" variant="outline" @click="reversingAdjustment = item.id; adjustmentReversal.clearErrors()">Reverse</Button></div>
                </div>
                <form v-if="reversingAdjustment === item.id" class="flex flex-wrap items-end gap-2" @submit.prevent="reverseAdjustment(item.id)">
                    <label class="min-w-48 flex-1">Reversal reason<input v-model="adjustmentReversal.reason" required maxlength="1000" class="mt-1 w-full rounded-md border bg-background p-2" /><InputError :message="adjustmentReversal.errors.reason" /></label>
                    <Button type="submit" variant="destructive" :disabled="adjustmentReversal.processing || !adjustmentReversal.reason.trim()">Confirm reversal</Button>
                    <Button type="button" variant="outline" @click="reversingAdjustment = null; adjustmentReversal.reset('reason')">Cancel</Button>
                </form>
            </div>
        </section>

                </div>
            </main>
        </div>

        <Dialog v-model:open="reverseDialogOpen">
            <DialogContent>
                <DialogHeader class="space-y-3">
                    <DialogTitle>{{ reverseDialogTitle }}</DialogTitle>
                    <DialogDescription>
                        {{ reverseDialogDescription }}
                    </DialogDescription>
                </DialogHeader>

                <form class="space-y-4" @submit.prevent="submitReverse">
                    <div class="grid gap-2">
                        <label for="reverse-reason" class="text-sm font-medium">Reason</label>
                        <textarea
                            id="reverse-reason"
                            v-model="reversal.reason"
                            rows="3"
                            maxlength="3000"
                            class="w-full rounded-md border bg-background p-2 text-sm"
                            placeholder="Enter the reason for this reversal"
                        />
                        <InputError :message="reversal.errors.reason" />
                    </div>

                    <DialogFooter class="gap-2">
                        <Button type="button" variant="outline" @click="closeReverseDialog">Cancel</Button>
                        <Button type="submit" variant="destructive" :disabled="reversal.processing || !reversal.reason.trim()">
                            {{ reverseConfirmLabel }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
