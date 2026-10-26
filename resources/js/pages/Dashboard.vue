<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Building2, Mail, ArrowRight, MapPin, CheckCircle2, Users, Contact, ClipboardList, ShieldAlert, CalendarDays, Clock3, Eye } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '@/components/ui/sheet';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';

type Facility = { id: number; name: string; role: string | null };
type Membership = { id: number; name: string; role: string; facilities: Facility[] };
type ClientAlert = { id: number; category: string; priority: 'high' | 'warning' | 'follow_up'; title: string; details: string | null; created_at: string | null; created_by_name: string; original: { title: string; details: string | null }; latest_update: { reason: string; actor_name: string; created_at: string | null } | null };
type ShiftPreview = { id: number; shift_date: string; shift: string; summary: string; handoff: string | null; author_id: number; author_name: string; created_at: string };
type AssignedClient = { latestShiftNote: ShiftPreview | null; activeAlertCount: number; alerts: ClientAlert[]; id: number; name: string; facility_id: number; facility_name: string; status: string; enrolled_on: string | null; phone: string | null; email: string | null };
const props = defineProps<{ memberships: Membership[]; myClients: AssignedClient[]; canViewClinicalClients: boolean; activeOrganizationId: number | null; activeFacilityId: number | null; activeFacilityPermissions: string[] }>();
const switching = ref(false);
const quickViewOpen = ref(false);
const alertFormOpen = ref(false);
const alertForm = useForm({ category: 'general', priority: 'warning', title: '', details: '' });
const resolvingAlertId = ref<number | null>(null);
const resolveDialogOpen = ref(false);
const resolveForm = useForm({ reason: '' });
const updateDialogOpen = ref(false);
const updatingAlertId = ref<number | null>(null);
const updateForm = useForm({ reason: '', category: 'general', priority: 'warning', title: '', details: '' });
function requestUpdate(alert: ClientAlert): void {
    updatingAlertId.value = alert.id;
    updateForm.category = alert.category;
    updateForm.priority = alert.priority;
    updateForm.title = alert.title;
    updateForm.details = alert.details ?? '';
    updateForm.reason = '';
    updateForm.clearErrors();
    updateDialogOpen.value = true;
}
function cancelUpdate(): void {
    if (updateForm.processing) return;
    updateDialogOpen.value = false;
    updatingAlertId.value = null;
    updateForm.reset();
    updateForm.clearErrors();
}
function saveUpdate(): void {
    if (!selectedClient.value || updatingAlertId.value === null || updateForm.reason.trim().length < 3) return;
    updateForm.patch(`/facility/clients/${selectedClient.value.id}/alerts/${updatingAlertId.value}`, {
        preserveScroll: true,
        onSuccess: () => cancelUpdate(),
    });
}

function requestResolution(id: number): void {
    resolvingAlertId.value = id;
    resolveForm.reset();
    resolveForm.clearErrors();
    resolveDialogOpen.value = true;
}
function cancelResolution(): void {
    if (resolveForm.processing) return;
    resolveDialogOpen.value = false;
    resolvingAlertId.value = null;
    resolveForm.reset();
    resolveForm.clearErrors();
}
const priorityClass = (priority: string) => priority === 'high' ? 'text-red-600 dark:text-red-400' : priority === 'warning' ? 'text-amber-600 dark:text-amber-400' : 'text-slate-500';
const priorityLabel = (priority: string) => priority === 'high' ? 'High priority' : priority === 'warning' ? 'Warning' : 'Follow-up';
function createAlert(): void {
    if (!selectedClient.value) return;
    alertForm.post(`/facility/clients/${selectedClient.value.id}/alerts`, {
        preserveScroll: true,
        onSuccess: () => { alertForm.reset(); alertFormOpen.value = false; },
    });
}
function resolveAlert(): void {
    if (!selectedClient.value || resolvingAlertId.value === null || !resolveForm.reason.trim()) return;
    resolveForm.patch(`/facility/clients/${selectedClient.value.id}/alerts/${resolvingAlertId.value}/resolve`, {
        preserveScroll: true,
        onSuccess: () => { resolveDialogOpen.value = false; resolvingAlertId.value = null; resolveForm.reset(); resolveForm.clearErrors(); },
    });
}

const selectedClient = ref<AssignedClient | null>(null);
const currentClient = computed(() => props.myClients.find(client => client.id === selectedClient.value?.id) ?? selectedClient.value);
function showQuickView(client: AssignedClient): void {
    selectedClient.value = client;
    alertFormOpen.value = false;
    alertForm.clearErrors();
    cancelResolution();
    cancelUpdate();
    quickViewOpen.value = true;
}
function openSelectedClient(section: 'personal' | 'contact' = 'personal'): void {
    if (!selectedClient.value) return;
    quickViewOpen.value = false;
    openClient(selectedClient.value, section);
}

const clientSearch = ref('');
const facilityFilter = ref('all');
const clientFacilities = computed(() => [...new Map(props.myClients.map(client => [client.facility_id, { id: client.facility_id, name: client.facility_name }])).values()]);
const filteredClients = computed(() => props.myClients.filter(client => {
    const matchesFacility = facilityFilter.value === 'all' || String(client.facility_id) === facilityFilter.value;
    const query = clientSearch.value.trim().toLocaleLowerCase();
    return matchesFacility && (!query || `${client.name} ${client.facility_name}`.toLocaleLowerCase().includes(query));
}));
function enterOrganization(id: number): void {
    if (switching.value) return;
    switching.value = true;
    router.post('/organizations/switch', { organization_id: id }, { onFinish: () => { switching.value = false; } });
}
function enterFacility(organizationId: number, facilityId: number, currentOrganizationId: number | null): void {
    if (switching.value || currentOrganizationId !== organizationId) return;
    switching.value = true;
    router.post('/facilities/switch', { facility_id: facilityId }, { onFinish: () => { switching.value = false; } });
}
function openTreatment(client: AssignedClient): void {
    if (switching.value) return;
    const url = `/facility/clients/${client.id}/treatment`;
    if (props.activeFacilityId === client.facility_id) {
        router.visit(url);
        return;
    }
    switching.value = true;
    router.post('/facilities/switch', { facility_id: client.facility_id }, {
        onSuccess: () => router.visit(url),
        onError: () => { switching.value = false; },
        onFinish: () => { switching.value = false; },
    });
}
function openSelectedTreatment(): void {
    if (!selectedClient.value) return;
    quickViewOpen.value = false;
    openTreatment(selectedClient.value);
}
function openClient(client: AssignedClient, section: 'personal' | 'contact' = 'personal'): void {
    if (switching.value) return;
    const url = `/facility/clients/${client.id}?section=${section}`;
    if (props.activeFacilityId === client.facility_id) {
        router.visit(url);
        return;
    }
    switching.value = true;
    router.post('/facilities/switch', { facility_id: client.facility_id }, {
        onSuccess: () => router.visit(url),
        onError: () => { switching.value = false; },
        onFinish: () => { switching.value = false; },
    });
}
</script>
<template>
    <Head title="My WayAlong" />
    <main class="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-8">
        <header class="space-y-1"><h1 class="text-2xl font-semibold">My WayAlong</h1><p class="text-sm text-muted-foreground">Your personal dashboard and authorized organization and facility relationships.</p></header>
        <section v-if="canViewClinicalClients" class="space-y-3" aria-labelledby="my-clients-heading">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div><h2 id="my-clients-heading" class="text-xl font-semibold">My Clients</h2><p class="text-sm text-muted-foreground">Clients assigned to you in your authorized facilities.</p></div>
                <span class="rounded-md border px-3 py-1 text-sm">{{ myClients.length }} shown</span>
            </div>
            <div v-if="myClients.length" class="flex flex-wrap gap-3" role="search" aria-label="Filter my clients">
                <label class="flex min-w-48 flex-1 flex-col gap-1 text-sm"><span>Find a client</span><input v-model="clientSearch" type="search" placeholder="Search by name or facility" class="rounded-md border bg-background px-3 py-2" /></label>
                <label v-if="clientFacilities.length > 1" class="flex flex-col gap-1 text-sm"><span>Facility</span><select v-model="facilityFilter" class="rounded-md border bg-background px-3 py-2"><option value="all">All my facilities</option><option v-for="facility in clientFacilities" :key="facility.id" :value="String(facility.id)">{{ facility.name }}</option></select></label>
            </div>
            <p v-if="!myClients.length" class="rounded-xl border p-5 text-sm text-muted-foreground">No active clients are currently assigned to you in this organization.</p>
            <p v-else-if="!filteredClients.length" class="rounded-xl border p-5 text-sm text-muted-foreground">No assigned clients match your filters. Try another name or facility.</p>
            <div v-if="filteredClients.length" class="grid gap-3 lg:grid-cols-2">
                <article v-for="client in filteredClients" :key="client.id" class="rounded-xl border bg-card p-4 space-y-3">
                    <div class="flex items-start gap-3"><Users class="mt-1 size-5 shrink-0"/><div class="min-w-0"><h3 class="flex items-center gap-2 font-semibold">{{ client.name }}<ShieldAlert v-if="client.alerts?.length" :class="priorityClass(client.alerts[0].priority)" class="size-4 shrink-0" :aria-label="priorityLabel(client.alerts[0].priority)" :title="`${priorityLabel(client.alerts[0].priority)}: ${client.alerts[0].title}`" /></h3><p class="text-sm text-muted-foreground">{{ client.facility_name }} · {{ client.status }}</p></div></div>
                    <div class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-muted-foreground">
                        <a v-if="client.phone" :href="`tel:${client.phone}`" class="break-all underline underline-offset-2" :aria-label="`Call ${client.name} at ${client.phone}`">Phone: {{ client.phone }}</a>
                        <a v-if="client.email" :href="`mailto:${client.email}`" class="break-all underline underline-offset-2" :aria-label="`Email ${client.name}`">Email: {{ client.email }}</a>
                        <span v-if="!client.phone && !client.email">No direct contact details on file</span>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" class="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-2 text-sm text-primary-foreground hover:opacity-90" @click="showQuickView(client)"><Eye class="size-4"/> Quick view</button>
                        <button type="button" :disabled="switching" class="inline-flex items-center gap-2 rounded-md border px-3 py-2 text-sm hover:bg-accent disabled:opacity-50" @click="openClient(client, 'contact')"><Contact class="size-4"/> Contact information</button>
                        <button type="button" :disabled="switching" class="inline-flex items-center gap-2 rounded-md border px-3 py-2 text-sm hover:bg-accent disabled:opacity-50" @click="openClient(client)">Client administration <ArrowRight class="size-4"/></button>
                        <button type="button" :disabled="switching" class="inline-flex items-center gap-2 rounded-md border px-3 py-2 text-sm hover:bg-accent disabled:opacity-50" @click="openTreatment(client)"><ClipboardList class="size-4"/> Client treatment <ArrowRight class="size-4"/></button>
                    </div>
                </article>
            </div>
            <p v-if="myClients.length === 100" class="text-xs text-muted-foreground">Showing up to 100 assigned clients. Search and facility filters apply to these loaded records.</p>
        </section>
        <Sheet v-model:open="quickViewOpen">
            <SheetContent side="left" class="w-full overflow-y-auto p-0 sm:max-w-2xl" aria-describedby="client-quick-view-description">
                <template v-if="selectedClient">
                    <SheetHeader class="border-b px-6 py-5 pr-12">
                        <SheetTitle class="text-xl">{{ selectedClient.name }}</SheetTitle>
                        <SheetDescription id="client-quick-view-description">Client quick view · {{ selectedClient.facility_name }}</SheetDescription>
                        <div class="flex flex-wrap gap-2 pt-2">
                            <button type="button" :disabled="switching" class="rounded-md bg-primary px-3 py-2 text-sm text-primary-foreground disabled:opacity-50" @click="openSelectedClient()">Client administration ↗</button>
                            <button type="button" :disabled="switching" class="rounded-md border px-3 py-2 text-sm disabled:opacity-50" @click="openSelectedTreatment()">Client treatment ↗</button>
                            <button type="button" :disabled="switching" class="rounded-md border px-3 py-2 text-sm disabled:opacity-50" @click="openSelectedClient('contact')">Contact information</button>
                        </div>
                    </SheetHeader>
                    <div class="space-y-5 px-6 py-5">
                        <section class="space-y-2" aria-labelledby="quick-overview-heading">
                            <h3 id="quick-overview-heading" class="flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-muted-foreground"><Users class="size-4"/> Client overview</h3>
                            <div class="grid grid-cols-2 gap-3 rounded-lg border p-4 text-sm">
                                <div><div class="text-muted-foreground">Enrollment status</div><div class="font-medium capitalize">{{ selectedClient.status }}</div></div>
                                <div><div class="text-muted-foreground">Enrolled on</div><div class="font-medium">{{ selectedClient.enrolled_on || 'Not recorded' }}</div></div>
                                <div class="col-span-2"><div class="text-muted-foreground">Facility</div><div class="font-medium">{{ selectedClient.facility_name }}</div></div>
                            </div>
                        </section>
                        <section class="space-y-2" aria-labelledby="quick-contact-heading">
                            <h3 id="quick-contact-heading" class="flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-muted-foreground"><Contact class="size-4"/> Quick contact</h3>
                            <div class="space-y-2 rounded-lg border p-4 text-sm">
                                <p v-if="!selectedClient.phone && !selectedClient.email" class="text-muted-foreground">No direct contact details on file. Open the contact section for additional authorized contacts.</p>
                                <p v-if="selectedClient.phone">Phone: <a class="underline" :href="`tel:${selectedClient.phone}`">{{ selectedClient.phone }}</a></p>
                                <p v-if="selectedClient.email">Email: <a class="break-all underline" :href="`mailto:${selectedClient.email}`">{{ selectedClient.email }}</a></p>
                                <button type="button" class="text-sm font-medium underline" @click="openSelectedClient('contact')">Open full contact information →</button>
                            </div>
                        </section>
                        <section class="space-y-2" aria-labelledby="quick-alerts-heading">
                            <h3 id="quick-alerts-heading" class="flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-muted-foreground"><ShieldAlert class="size-4"/> Important alerts</h3>
                            <div v-if="!currentClient?.alerts?.length" class="rounded-lg border p-4 text-sm text-muted-foreground">No active alerts recorded for this client.</div>
                            <div v-for="alert in currentClient?.alerts ?? []" :key="alert.id" class="space-y-2 rounded-lg border p-4 text-sm">
                                <div class="flex items-center gap-2"><ShieldAlert class="size-4" :class="priorityClass(alert.priority)"/><strong>{{ alert.title }}</strong><span class="text-xs text-muted-foreground">{{ priorityLabel(alert.priority) }}</span></div>
                                <p class="capitalize text-muted-foreground">{{ alert.category }} · {{ alert.created_at || 'Date unavailable' }}</p>
                                <div class="space-y-1 rounded-md bg-muted/40 p-3">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Original alert</p>
                                    <p class="font-medium">{{ alert.original.title }}</p>
                                    <p class="text-xs text-muted-foreground">Recorded by {{ alert.created_by_name }}</p>
                                    <p v-if="alert.original.details" class="whitespace-pre-wrap">{{ alert.original.details }}</p>
                                    <p v-else class="text-xs text-muted-foreground">No original details provided.</p>
                                </div>
                                <div v-if="alert.latest_update" class="space-y-1 rounded-md border-l-2 border-primary bg-muted/20 p-3">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Latest update · {{ alert.latest_update.created_at || 'Date unavailable' }} · {{ alert.latest_update.actor_name }}</p>
                                    <p class="whitespace-pre-wrap">{{ alert.latest_update.reason }}</p>
                                </div>
                                <p v-else class="text-xs text-muted-foreground">No progress updates yet.</p>
                                <div class="flex flex-wrap gap-2">
                                    <button type="button" class="rounded-md border px-3 py-1.5 text-xs hover:bg-accent disabled:opacity-50" :disabled="updateForm.processing || resolveForm.processing" @click="requestUpdate(alert)">Update alert</button>
                                    <button type="button" class="rounded-md border px-3 py-1.5 text-xs hover:bg-accent disabled:opacity-50" :disabled="updateForm.processing || resolveForm.processing" @click="requestResolution(alert.id)">Resolve alert</button>
                                    <Link :href="`/facility/clients/${selectedClient.id}/treatment/alerts?status=all#alert-${alert.id}`" class="rounded-md border px-3 py-1.5 text-xs hover:bg-accent" @click="quickViewOpen = false">View history</Link>
                                </div>
                            </div>
                            <button type="button" class="rounded-md border px-3 py-2 text-sm" @click="alertFormOpen = !alertFormOpen; alertForm.clearErrors()">{{ alertFormOpen ? 'Cancel new alert' : 'Add alert' }}</button>
                            <form v-if="alertFormOpen" class="space-y-3 rounded-lg border p-4" @submit.prevent="createAlert">
                                <label class="block text-sm">Category<select v-model="alertForm.category" class="mt-1 w-full rounded-md border bg-background p-2"><option value="medical">Medical</option><option value="behavioral">Behavioral</option><option value="general">General</option></select></label>
                                <label class="block text-sm">Priority<select v-model="alertForm.priority" class="mt-1 w-full rounded-md border bg-background p-2"><option value="high">High priority</option><option value="warning">Warning</option><option value="follow_up">Follow-up</option></select></label>
                                <label class="block text-sm">Title<input v-model="alertForm.title" required maxlength="160" class="mt-1 w-full rounded-md border bg-background p-2"/><span v-if="alertForm.errors.title" class="block text-destructive">{{ alertForm.errors.title }}</span></label>
                                <label class="block text-sm">Details (optional)<textarea v-model="alertForm.details" maxlength="3000" rows="3" class="mt-1 w-full rounded-md border bg-background p-2"/><span v-if="alertForm.errors.details" class="block text-destructive">{{ alertForm.errors.details }}</span></label>
                                <button type="submit" :disabled="alertForm.processing" class="rounded-md bg-primary px-3 py-2 text-sm text-primary-foreground disabled:opacity-50">Save alert</button>
                            </form>
                            <p v-if="(currentClient?.activeAlertCount ?? 0) > (currentClient?.alerts?.length ?? 0)" class="text-sm text-muted-foreground">Showing the five highest-priority active alerts. {{ (currentClient?.activeAlertCount ?? 0) - (currentClient?.alerts?.length ?? 0) }} more active alerts are in the full record.</p>
                            <Link :href="`/facility/clients/${selectedClient.id}/treatment/alerts?status=all`" class="inline-flex text-sm font-medium underline" @click="quickViewOpen = false">View all alerts &amp; history →</Link>
                        </section>
                        <section class="space-y-2" aria-labelledby="quick-shift-heading">
                            <h3 id="quick-shift-heading" class="flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-muted-foreground"><ClipboardList class="size-4"/> End of Shift Notes</h3>
                            <div v-if="currentClient?.latestShiftNote" class="space-y-2 rounded-lg border p-4 text-sm">
                                <p class="text-xs text-muted-foreground capitalize">{{ currentClient.latestShiftNote.shift }} shift · {{ currentClient.latestShiftNote.shift_date }} · Recorded by {{ currentClient.latestShiftNote.author_name }}</p>
                                <p class="whitespace-pre-wrap"><strong>Summary:</strong> {{ currentClient.latestShiftNote.summary }}</p>
                                <p v-if="currentClient.latestShiftNote.handoff" class="whitespace-pre-wrap"><strong>Next shift handoff:</strong> {{ currentClient.latestShiftNote.handoff }}</p>
                            </div>
                            <p v-else class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">No End of Shift notes recorded yet.</p>
                            <Link :href="`/facility/clients/${selectedClient.id}/treatment/shift-notes`" class="inline-flex text-sm font-medium underline" @click="quickViewOpen = false">View shift notes &amp; handoff →</Link>
                        </section>
                        <section class="space-y-2" aria-labelledby="quick-care-heading">
                            <h3 id="quick-care-heading" class="flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-muted-foreground"><CalendarDays class="size-4"/> Care & upcoming work</h3>
                            <div class="rounded-lg border border-dashed p-4 text-sm"><strong>Coming soon: care plan and scheduled activities</strong><p class="mt-1 text-muted-foreground">Future authorized care-plan milestones, appointments, and assigned tasks will appear here.</p></div>
                        </section>
                        <p class="flex items-center gap-2 text-xs text-muted-foreground"><Clock3 class="size-4"/> This preview shows currently available client details. Additional clinical modules will populate the placeholders later.</p>
                    </div>
                </template>
            </SheetContent>
        </Sheet>
        <Dialog v-model:open="updateDialogOpen">
            <DialogContent class="max-h-[90vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>Update client alert</DialogTitle>
                    <DialogDescription>Add a progress note or revise the current alert. Every saved update is retained in the alert history.</DialogDescription>
                </DialogHeader>
                <form class="space-y-3" @submit.prevent="saveUpdate">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block text-sm">Category<select v-model="updateForm.category" class="mt-1 w-full rounded-md border bg-background p-2"><option value="medical">Medical</option><option value="behavioral">Behavioral</option><option value="general">General</option></select><span v-if="updateForm.errors.category" class="text-destructive">{{ updateForm.errors.category }}</span></label>
                        <label class="block text-sm">Priority<select v-model="updateForm.priority" class="mt-1 w-full rounded-md border bg-background p-2"><option value="high">High</option><option value="warning">Warning</option><option value="follow_up">Follow-up</option></select><span v-if="updateForm.errors.priority" class="text-destructive">{{ updateForm.errors.priority }}</span></label>
                    </div>
                    <label class="block text-sm">Title<input v-model="updateForm.title" required maxlength="160" class="mt-1 w-full rounded-md border bg-background p-2"/><span v-if="updateForm.errors.title" class="text-destructive">{{ updateForm.errors.title }}</span></label>
                    <label class="block text-sm">Current details<textarea v-model="updateForm.details" rows="3" maxlength="3000" class="mt-1 w-full rounded-md border bg-background p-2"/><span v-if="updateForm.errors.details" class="text-destructive">{{ updateForm.errors.details }}</span></label>
                    <label class="block text-sm">Progress update / reason (required)<textarea v-model="updateForm.reason" required minlength="3" rows="3" maxlength="3000" class="mt-1 w-full rounded-md border bg-background p-2" placeholder="What changed or what action was taken?"/><span v-if="updateForm.errors.reason" class="text-destructive">{{ updateForm.errors.reason }}</span></label>
                    <DialogFooter>
                        <Button type="button" variant="outline" :disabled="updateForm.processing" @click="cancelUpdate">Cancel</Button>
                        <Button type="submit" :disabled="updateForm.processing || updateForm.reason.trim().length < 3 || !updateForm.title.trim()">Save update to history</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
        <Dialog v-model:open="resolveDialogOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Resolve client alert</DialogTitle>
                    <DialogDescription>Explain why this alert no longer requires active attention. The explanation will be kept in the alert history.</DialogDescription>
                </DialogHeader>
                <form class="space-y-4" @submit.prevent="resolveAlert">
                    <label class="block text-sm font-medium" for="alert-resolution-reason">Resolution explanation (required)</label>
                    <textarea id="alert-resolution-reason" v-model="resolveForm.reason" class="w-full rounded-md border bg-background p-2 text-sm" rows="4" maxlength="3000" required minlength="3" />
                    <p v-if="resolveForm.errors.reason" class="text-sm text-destructive">{{ resolveForm.errors.reason }}</p>
                    <DialogFooter>
                        <Button type="button" variant="outline" :disabled="resolveForm.processing" @click="cancelResolution">Cancel</Button>
                        <Button type="submit" :disabled="resolveForm.processing || resolveForm.reason.trim().length < 3">Resolve alert</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
        <Link href="/messages" class="flex items-center justify-between rounded-xl border p-4 hover:bg-accent"><span class="flex items-center gap-3"><Mail class="size-5"/><span><strong class="block">Message Center</strong><small class="text-muted-foreground">Read notifications and invitations</small></span></span><ArrowRight class="size-4" /></Link>
        <section class="space-y-3"><h2 class="text-lg font-semibold">My organizations & facilities</h2>
            <p v-if="!memberships.length" class="rounded-xl border p-6 text-sm text-muted-foreground">You do not have any active organization memberships yet. Invitations will appear in your Message Center when available.</p>
            <div v-else class="grid gap-4 md:grid-cols-2">
                <article v-for="organization in memberships" :key="organization.id" class="space-y-4 rounded-xl border p-5">
                    <div class="flex items-start gap-3"><Building2 class="mt-1 size-5"/><div class="flex-1"><h3 class="font-semibold">{{ organization.name }}</h3><p class="text-sm capitalize text-muted-foreground">{{ organization.role }}</p></div><span v-if="activeOrganizationId === organization.id" class="text-xs font-medium text-green-700 dark:text-green-400">Current</span></div>
                    <button type="button" class="rounded-md bg-primary px-4 py-2 text-sm text-primary-foreground disabled:opacity-50" :disabled="switching || activeOrganizationId === organization.id" @click="enterOrganization(organization.id)">{{ activeOrganizationId === organization.id ? 'Organization selected' : 'Enter organization' }}</button>
                    <div class="space-y-2 border-t pt-3">
                        <h4 class="text-sm font-semibold">My facility assignments</h4>
                        <p v-if="!organization.facilities.length" class="text-sm text-muted-foreground">No active facility assignments. Organization access may still be available based on your role.</p>
                        <div v-for="facility in organization.facilities" :key="facility.id" class="flex flex-wrap items-center justify-between gap-2 rounded-md border p-3">
                            <div class="flex items-start gap-2"><MapPin class="mt-0.5 size-4"/><div><p class="text-sm font-medium">{{ facility.name }}</p><p class="text-xs capitalize text-muted-foreground">{{ facility.role || 'Assigned staff' }}</p></div></div>
                            <span v-if="activeOrganizationId === organization.id && activeFacilityId === facility.id" class="inline-flex items-center gap-1 text-xs text-green-700 dark:text-green-400"><CheckCircle2 class="size-4"/> Selected</span>
                            <button v-else type="button" class="rounded-md border px-3 py-1.5 text-xs hover:bg-accent disabled:opacity-50" :disabled="switching || activeOrganizationId !== organization.id" @click="enterFacility(organization.id, facility.id, activeOrganizationId)">{{ activeOrganizationId === organization.id ? 'Select facility' : 'Enter organization first' }}</button>
                        </div>
                    </div>
                </article>
            </div>
        </section>
        <Link v-if="activeFacilityId" href="/facility/staff" class="inline-flex items-center gap-2 rounded-md border px-4 py-2 text-sm hover:bg-accent">View selected facility staff roster <ArrowRight class="size-4" /></Link>
        <Link v-if="activeFacilityId && activeFacilityPermissions.includes('facility.clients.view')" href="/facility/clients" class="inline-flex items-center gap-2 rounded-md border px-4 py-2 text-sm hover:bg-accent">View selected facility clients <ArrowRight class="size-4" /></Link>
        <Link v-if="activeFacilityId && activeFacilityPermissions.includes('facility.clients.manage')" href="/facility/documents/audit" class="inline-flex items-center gap-2 rounded-md border px-4 py-2 text-sm hover:bg-accent">Document Administration &amp; Audit <ArrowRight class="size-4" /></Link>
        <p class="text-xs text-muted-foreground">Facility selection establishes your active workspace. Facility-specific permission enforcement throughout all clinical modules will be introduced separately; organization permissions still apply.</p>
    </main>
</template>
