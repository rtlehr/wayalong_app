<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { ShieldAlert, ClipboardList, HeartPulse, NotebookPen, LockKeyhole, LayoutDashboard, ChevronDown } from '@lucide/vue';

type Event = { id: number; action: string; reason: string | null; changes: Record<string, { from: string | null; to: string | null }> | null; actor_id: number | null; actor_name: string; created_at: string | null };
type Alert = { id: number; title: string; category: string; priority: 'high' | 'warning' | 'follow_up'; details: string | null; created_at: string | null; resolved_at: string | null; events: Event[] };
const props = defineProps<{ client: { id: number; name: string; facility: string }; alerts: Alert[] }>();
const initial = typeof window !== 'undefined' ? new URLSearchParams(window.location.search).get('status') : null;
const status = ref(initial === 'resolved' || initial === 'all' ? initial : 'active');
const search = ref('');
const category = ref('all');
const priorityRank = (priority: string) => priority === 'high' ? 0 : priority === 'warning' ? 1 : 2;
const visible = computed(() => props.alerts.filter(alert =>
    (status.value === 'all' || (status.value === 'active' ? !alert.resolved_at : !!alert.resolved_at)) &&
    (category.value === 'all' || alert.category === category.value) &&
    (alert.title.toLowerCase().includes(search.value.toLowerCase()) || (alert.details ?? '').toLowerCase().includes(search.value.toLowerCase()))
).sort((a,b) => priorityRank(a.priority)-priorityRank(b.priority) || b.id-a.id));
// Detail cards are collapsed by default. Preserve deep links from Quick View.
const expandedAlerts = ref<number[]>([]);
const deepLinkedAlert = typeof window !== 'undefined' ? /^#alert-(\d+)$/.exec(window.location.hash) : null;
if (deepLinkedAlert) expandedAlerts.value = [Number(deepLinkedAlert[1])];
function isExpanded(id: number): boolean { return expandedAlerts.value.includes(id); }
function toggleAlert(id: number): void {
    expandedAlerts.value = isExpanded(id) ? expandedAlerts.value.filter(value => value !== id) : [...expandedAlerts.value, id];
}
const editingAlert = ref<number | null>(null);
const updateForm = useForm({ reason: '', category: 'general', priority: 'follow_up', title: '', details: '' });
function startUpdate(alert: Alert): void {
    expandedAlerts.value = [...new Set([...expandedAlerts.value, alert.id])];
    editingAlert.value = alert.id;
    updateForm.category = alert.category;
    updateForm.priority = alert.priority;
    updateForm.title = alert.title;
    updateForm.details = alert.details ?? '';
    updateForm.reason = '';
    updateForm.clearErrors();
}
function cancelUpdate(): void {
    editingAlert.value = null;
    updateForm.reset();
    updateForm.clearErrors();
}
function saveUpdate(alert: Alert): void {
    updateForm.patch(`/facility/clients/${props.client.id}/alerts/${alert.id}`, {
        preserveScroll: true,
        onSuccess: () => cancelUpdate(),
    });
}
const severityClass = (priority: string) => priority === 'high' ? 'text-red-600 dark:text-red-400' : priority === 'warning' ? 'text-amber-600 dark:text-amber-400' : 'text-slate-500';
</script>

<template>
    <Head :title="`Alerts & Safety — ${client.name}`" />
    <main class="mx-auto max-w-6xl space-y-5 p-6">
        <Link href="/dashboard" class="text-sm underline">← My WayAlong</Link>
        <div><h1 class="text-2xl font-semibold">Client Treatment</h1><p class="text-sm text-muted-foreground">{{ client.name }} · {{ client.facility }}</p></div>
        <div class="grid gap-6 md:grid-cols-[240px_minmax(0,1fr)]">
            <nav aria-label="Client treatment sections" class="self-start rounded-lg border p-2 space-y-1">
                <Link :href="`/facility/clients/${client.id}/treatment`" class="flex gap-2 rounded-md px-3 py-2 text-sm hover:bg-muted"><LayoutDashboard class="size-4" />Overview</Link>
                <div class="flex gap-2 rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground"><ShieldAlert class="size-4" />Alerts &amp; Safety</div>
                <Link :href="`/facility/clients/${client.id}/treatment/shift-notes`" class="flex gap-2 rounded-md px-3 py-2 text-sm hover:bg-muted"><ClipboardList class="size-4" />End of Shift</Link>
                <div class="flex gap-2 rounded-md px-3 py-2 text-sm text-muted-foreground"><HeartPulse class="size-4" />Medical <span class="ml-auto text-xs">Soon</span></div>
                <div class="flex gap-2 rounded-md px-3 py-2 text-sm text-muted-foreground"><NotebookPen class="size-4" />Treatment Plans <span class="ml-auto text-xs">Soon</span></div>
                <div class="flex gap-2 rounded-md px-3 py-2 text-sm text-muted-foreground"><LockKeyhole class="size-4" />Therapy Notes <span class="ml-auto text-xs">Restricted</span></div>
            </nav>
            <section class="min-w-0 space-y-4" aria-labelledby="alerts-heading">
                <div><h2 id="alerts-heading" class="text-xl font-semibold">Alerts &amp; Safety</h2><p class="text-sm text-muted-foreground">Active concerns and complete historical records, including resolution explanations.</p></div>
                <div class="flex flex-wrap gap-2">
                    <button v-for="option in [{value:'active',label:'Active'},{value:'resolved',label:'Resolved'},{value:'all',label:'All history'}]" :key="option.value" type="button" :aria-pressed="status === option.value" :class="status === option.value ? 'bg-primary text-primary-foreground' : 'border'" class="rounded-md px-3 py-2 text-sm" @click="status=option.value">{{option.label}}</button>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="text-sm">Search alerts<input v-model="search" type="search" placeholder="Search titles or details" class="mt-1 w-full rounded-md border bg-background p-2"/></label>
                    <label class="text-sm">Category<select v-model="category" class="mt-1 w-full rounded-md border bg-background p-2"><option value="all">All categories</option><option value="medical">Medical</option><option value="behavioral">Behavioral</option><option value="general">General</option></select></label>
                </div>
                <p v-if="!visible.length" class="rounded-lg border p-4 text-sm text-muted-foreground">No alerts match these filters.</p>
                <article v-for="alert in visible" :id="`alert-${alert.id}`" :key="alert.id" class="scroll-mt-6 space-y-3 rounded-lg border p-4">
                    <button type="button" class="flex w-full items-start gap-3 rounded-md text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring" :aria-expanded="isExpanded(alert.id)" :aria-controls="`alert-details-${alert.id}`" :aria-label="`${isExpanded(alert.id) ? 'Collapse' : 'Expand'} ${alert.title} details`" @click="toggleAlert(alert.id)">
                        <ShieldAlert class="mt-0.5 size-5 shrink-0" :class="severityClass(alert.priority)" />
                        <span class="min-w-0 flex-1 space-y-1">
                            <span class="flex flex-wrap items-center gap-2"><span class="font-semibold">{{ alert.title }}</span><span class="ml-auto text-xs text-muted-foreground">{{ alert.resolved_at ? 'Resolved' : 'Active' }}</span></span>
                            <span class="block text-xs capitalize text-muted-foreground">{{ alert.category }} · {{ alert.priority.replace('_',' ') }} · {{ alert.created_at }}</span>
                            <span v-if="alert.details" class="block whitespace-pre-wrap text-sm">{{ alert.details }}</span>
                        </span>
                        <ChevronDown class="mt-0.5 size-4 shrink-0 text-muted-foreground transition-transform" :class="isExpanded(alert.id) ? 'rotate-180' : ''" aria-hidden="true" />
                    </button>
                    <div v-show="isExpanded(alert.id)" :id="`alert-details-${alert.id}`" class="space-y-3 border-t pt-3">
                    <div v-if="!alert.resolved_at" class="space-y-3">
                        <button v-if="editingAlert !== alert.id" type="button" class="rounded-md border px-3 py-2 text-sm" @click="startUpdate(alert)">Add update / Edit alert</button>
                        <form v-else class="space-y-3 rounded-md border bg-muted/20 p-3" @submit.prevent="saveUpdate(alert)">
                            <h4 class="font-semibold text-sm">Record alert progress</h4>
                            <p class="text-xs text-muted-foreground">Every submission creates a permanent history entry. A progress explanation is required, even if no other field changes.</p>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <label class="text-sm">Category<select v-model="updateForm.category" class="mt-1 w-full rounded-md border bg-background p-2"><option value="medical">Medical</option><option value="behavioral">Behavioral</option><option value="general">General</option></select></label>
                                <label class="text-sm">Priority<select v-model="updateForm.priority" class="mt-1 w-full rounded-md border bg-background p-2"><option value="high">High</option><option value="warning">Warning</option><option value="follow_up">Follow-up</option></select></label>
                                <label class="text-sm sm:col-span-2">Title<input v-model="updateForm.title" maxlength="160" required class="mt-1 w-full rounded-md border bg-background p-2" /></label>
                                <label class="text-sm sm:col-span-2">Current details<textarea v-model="updateForm.details" rows="3" maxlength="3000" class="mt-1 w-full rounded-md border bg-background p-2" /></label>
                                <label class="text-sm sm:col-span-2">Progress update / reason (required)<textarea v-model="updateForm.reason" rows="3" maxlength="3000" required class="mt-1 w-full rounded-md border bg-background p-2" placeholder="What changed or what action was taken?" /><span v-if="updateForm.errors.reason" class="text-destructive">{{ updateForm.errors.reason }}</span></label>
                            </div>
                            <div class="flex gap-2"><button type="button" class="rounded-md border px-3 py-2 text-sm" @click="cancelUpdate">Cancel</button><button type="submit" :disabled="updateForm.processing || updateForm.reason.trim().length < 3" class="rounded-md bg-primary px-3 py-2 text-sm text-primary-foreground disabled:opacity-50">Save update to history</button></div>
                        </form>
                    </div>
                    <div class="space-y-2 border-t pt-3"><h4 class="text-sm font-semibold">History</h4>
                        <div v-for="event in alert.events" :key="event.id" class="rounded-md bg-muted/40 p-3 text-sm">
                            <p class="font-medium capitalize">{{event.action}} · {{event.created_at}}</p>
                            <p class="text-xs text-muted-foreground">Recorded by {{ event.actor_name }}</p>
                            <p v-if="event.reason" class="mt-1 whitespace-pre-wrap"><strong>Explanation:</strong> {{event.reason}}</p>
                            <div v-if="event.changes && Object.keys(event.changes).length" class="mt-2 space-y-1 border-t pt-2">
                                <p v-for="(change, field) in event.changes" :key="field" class="whitespace-pre-wrap text-xs"><strong class="capitalize">{{ String(field).replace('_', ' ') }}:</strong> {{change.from ?? '(empty)'}} → {{change.to ?? '(empty)'}}</p>
                            </div>
                        </div>
                        <p v-if="!alert.events.length" class="text-xs text-muted-foreground">No event history available for this older alert.</p>
                    </div>
                    </div>
                </article>
            </section>
        </div>
    </main>
</template>
