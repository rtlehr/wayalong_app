<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ClipboardList, ShieldAlert, HeartPulse, NotebookPen, LockKeyhole, LayoutDashboard, ChevronDown } from '@lucide/vue';
import { ref } from 'vue';

type ShiftNote = {
    id: number; shift_date: string; shift: string; summary: string; observations: string | null;
    significant_events: string | null; interventions: string | null; handoff: string | null;
    author_id: number; author_name: string; created_at: string; acknowledged_by_me: boolean; acknowledgment_count: number;
    acknowledgments: { user_name: string; acknowledged_at: string | null }[];
};
const props = defineProps<{
    client: { id: number; name: string; facility: string };
    notes: { data: ShiftNote[]; links: { url: string | null; label: string; active: boolean }[] };
    canWrite: boolean; canAcknowledge: boolean; currentUserId: number;
}>();
const today = new Date().toLocaleDateString('sv-SE');
// Notes stay compact until a reader requests the full handoff record.
const expandedNotes = ref<number[]>([]);
const deepLinkedNote = typeof window !== 'undefined' ? /^#note-(\d+)$/.exec(window.location.hash) : null;
if (deepLinkedNote) expandedNotes.value = [Number(deepLinkedNote[1])];
function isExpanded(id: number): boolean { return expandedNotes.value.includes(id); }
function toggleNote(id: number): void {
    expandedNotes.value = isExpanded(id) ? expandedNotes.value.filter(value => value !== id) : [...expandedNotes.value, id];
}
const showForm = ref(false);
const noteFields = [
    { key: 'summary', label: 'Shift summary (required)' },
    { key: 'observations', label: 'Observations' },
    { key: 'significant_events', label: 'Significant events' },
    { key: 'interventions', label: 'Interventions / staff response' },
    { key: 'handoff', label: 'Next shift handoff / follow-up' },
] as const;
const form = useForm({ shift_date: today, shift: 'day', summary: '', observations: '', significant_events: '', interventions: '', handoff: '' });
function submit(): void {
    form.post(`/facility/clients/${props.client.id}/treatment/shift-notes`, {
        preserveScroll: true,
        onSuccess: () => { showForm.value = false; form.reset(); form.shift_date = today; },
    });
}
const acknowledging = ref<number | null>(null);
function acknowledge(note: ShiftNote): void {
    if (acknowledging.value !== null) return;
    acknowledging.value = note.id;
    router.post(`/facility/clients/${props.client.id}/treatment/shift-notes/${note.id}/acknowledge`, {}, {
            preserveScroll: true, onFinish: () => { acknowledging.value = null; },
        });
}
</script>

<template>
    <Head :title="`End of Shift — ${client.name}`" />
    <main class="mx-auto max-w-6xl space-y-5 p-6">
        <Link href="/dashboard" class="text-sm underline">← My WayAlong</Link>
        <div><h1 class="text-2xl font-semibold">Client Treatment</h1><p class="text-sm text-muted-foreground">{{ client.name }} · {{ client.facility }}</p></div>
        <div class="grid gap-6 md:grid-cols-[240px_minmax(0,1fr)]">
            <nav aria-label="Client treatment sections" class="self-start rounded-lg border p-2 space-y-1">
                <Link :href="`/facility/clients/${client.id}/treatment`" class="flex gap-2 rounded-md px-3 py-2 text-sm hover:bg-muted"><LayoutDashboard class="size-4" />Overview</Link>
                <Link :href="`/facility/clients/${client.id}/treatment/alerts`" class="flex gap-2 rounded-md px-3 py-2 text-sm hover:bg-muted"><ShieldAlert class="size-4" />Alerts &amp; Safety</Link>
                <div class="flex gap-2 rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground"><ClipboardList class="size-4" />End of Shift</div>
                <div class="flex gap-2 rounded-md px-3 py-2 text-sm text-muted-foreground"><HeartPulse class="size-4" />Medical <span class="ml-auto text-xs">Soon</span></div>
                <div class="flex gap-2 rounded-md px-3 py-2 text-sm text-muted-foreground"><NotebookPen class="size-4" />Treatment Plans <span class="ml-auto text-xs">Soon</span></div>
                <div class="flex gap-2 rounded-md px-3 py-2 text-sm text-muted-foreground"><LockKeyhole class="size-4" />Therapy Notes <span class="ml-auto text-xs">Restricted</span></div>
            </nav>
            <section class="min-w-0 space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div><h2 class="text-xl font-semibold">End of Shift Notes</h2><p class="text-sm text-muted-foreground">Shift observations, significant events, interventions and handoff instructions. Submitted notes cannot be edited or deleted.</p></div>
                    <button v-if="canWrite" type="button" class="rounded-md bg-primary px-3 py-2 text-sm text-primary-foreground" @click="showForm = !showForm">{{ showForm ? 'Cancel' : 'Add shift note' }}</button>
                </div>
                <form v-if="showForm && canWrite" class="space-y-3 rounded-lg border p-4" @submit.prevent="submit">
                    <h3 class="font-semibold">Record an End of Shift Note</h3>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="text-sm">Shift date <input v-model="form.shift_date" type="date" :max="today" required class="mt-1 w-full rounded-md border bg-background p-2" /></label>
                        <label class="text-sm">Shift <select v-model="form.shift" required class="mt-1 w-full rounded-md border bg-background p-2"><option value="day">Day</option><option value="evening">Evening</option><option value="overnight">Overnight</option></select></label>
                    </div>
                    <label v-for="field in noteFields" :key="field.key" class="block text-sm">
                        {{ field.label }}
                        <textarea v-model="form[field.key]" rows="3" maxlength="5000" :required="field.key === 'summary'" class="mt-1 w-full rounded-md border bg-background p-2" />
                        <span v-if="form.errors[field.key]" class="text-sm text-destructive">{{ form.errors[field.key] }}</span>
                    </label>
                    <p class="text-xs text-muted-foreground">The note is permanently attributed to your account and timestamped when saved.</p>
                    <button type="submit" :disabled="form.processing || form.summary.trim().length < 3" class="rounded-md bg-primary px-3 py-2 text-sm text-primary-foreground disabled:opacity-50">Save shift note</button>
                </form>
                <p v-if="!notes.data.length" class="rounded-lg border p-4 text-sm text-muted-foreground">No End of Shift notes have been recorded for this client.</p>
                <article v-for="note in notes.data" :key="note.id" :id="`note-${note.id}`" class="space-y-3 rounded-lg border p-4">
                    <button type="button" class="flex w-full items-start justify-between gap-3 rounded-md text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring" :aria-expanded="isExpanded(note.id)" :aria-controls="`note-details-${note.id}`" :aria-label="`${isExpanded(note.id) ? 'Collapse' : 'Expand'} ${note.shift} shift on ${note.shift_date}`" @click="toggleNote(note.id)">
                        <span class="min-w-0 flex-1 space-y-1">
                            <span class="block font-semibold capitalize">{{ note.shift }} shift · {{ note.shift_date }}</span>
                            <span class="block text-xs text-muted-foreground">Recorded by {{ note.author_name }} · {{ note.created_at }}</span>
                            <span class="block whitespace-pre-wrap text-sm">{{ note.summary }}</span>
                            <span class="block text-xs text-muted-foreground">{{ note.acknowledgment_count }} staff acknowledgment(s)<span v-if="note.acknowledged_by_me"> · Acknowledged by you</span></span>
                        </span>
                        <ChevronDown class="mt-1 size-4 shrink-0 text-muted-foreground transition-transform" :class="isExpanded(note.id) ? 'rotate-180' : ''" aria-hidden="true" />
                    </button>
                    <div v-show="isExpanded(note.id)" :id="`note-details-${note.id}`" class="space-y-3 border-t pt-3">
                    <div v-for="section in [{label:'Observations',value:note.observations},{label:'Significant events',value:note.significant_events},{label:'Interventions',value:note.interventions},{label:'Next shift handoff',value:note.handoff}]" :key="section.label" v-show="section.value" class="space-y-1"><h4 class="text-xs font-semibold uppercase text-muted-foreground">{{ section.label }}</h4><p class="whitespace-pre-wrap text-sm">{{ section.value }}</p></div>
                    <div class="flex flex-wrap items-center gap-3 border-t pt-3 text-xs text-muted-foreground">
                        <span>{{ note.acknowledgment_count }} staff acknowledgment(s)</span>
                        <div v-if="note.acknowledgments.length" class="w-full space-y-1">
                            <p v-for="(ack, i) in note.acknowledgments" :key="i">Reviewed by {{ ack.user_name }} · {{ ack.acknowledged_at }}</p>
                        </div>
                        <span v-if="note.acknowledged_by_me">✓ You acknowledged this handoff</span>
                        <button v-else-if="canAcknowledge && note.author_id !== currentUserId" type="button" :disabled="acknowledging !== null" class="rounded-md border px-3 py-2 text-sm text-foreground disabled:opacity-50" @click="acknowledge(note)">Acknowledge handoff</button>
                    </div>
                    </div>
                </article>
                <nav v-if="notes.links.length > 3" aria-label="Shift notes pages" class="flex flex-wrap gap-2">
                    <template v-for="link in notes.links" :key="link.label"><Link v-if="link.url" :href="link.url" class="rounded-md border px-3 py-2 text-sm" :class="link.active ? 'bg-primary text-primary-foreground' : ''" v-html="link.label"/><span v-else class="rounded-md border px-3 py-2 text-sm opacity-50" v-html="link.label"/></template>
                </nav>
            </section>
        </div>
    </main>
</template>
