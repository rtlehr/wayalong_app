<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ClipboardList, ShieldAlert, HeartPulse, NotebookPen, LockKeyhole, LayoutDashboard, ChevronRight } from '@lucide/vue';

type AlertSummary = { id: number; title: string; category: string; priority: string; details: string | null; created_at: string | null };
type ShiftPreview = { id: number; shift_date: string | null; shift: string; summary: string; handoff: string | null; author_name: string; created_at: string | null };
defineProps<{
    client: { id: number; name: string; facility: string };
    canViewAlerts: boolean;
    canViewShiftNotes: boolean;
    activeAlertCount: number | null;
    alerts: AlertSummary[];
    latestShiftNote: ShiftPreview | null;
}>();
const priorityClass = (priority: string) => priority === 'high' ? 'text-red-600 dark:text-red-400' : priority === 'warning' ? 'text-amber-600 dark:text-amber-400' : 'text-slate-500';
</script>

<template>
    <Head :title="`Treatment Overview — ${client.name}`" />
    <main class="mx-auto max-w-6xl space-y-5 p-6">
        <Link href="/dashboard" class="text-sm underline">← My WayAlong</Link>
        <div><h1 class="text-2xl font-semibold">Client Treatment</h1><p class="text-sm text-muted-foreground">{{ client.name }} · {{ client.facility }}</p></div>
        <div class="grid gap-6 md:grid-cols-[240px_minmax(0,1fr)]">
            <nav aria-label="Client treatment sections" class="self-start space-y-1 rounded-lg border p-2">
                <div class="flex gap-2 rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground"><LayoutDashboard class="size-4" />Overview</div>
                <Link v-if="canViewAlerts" :href="`/facility/clients/${client.id}/treatment/alerts`" class="flex gap-2 rounded-md px-3 py-2 text-sm hover:bg-muted"><ShieldAlert class="size-4" />Alerts &amp; Safety</Link>
                <Link v-if="canViewShiftNotes" :href="`/facility/clients/${client.id}/treatment/shift-notes`" class="flex gap-2 rounded-md px-3 py-2 text-sm hover:bg-muted"><ClipboardList class="size-4" />End of Shift</Link>
                <div class="flex gap-2 rounded-md px-3 py-2 text-sm text-muted-foreground"><HeartPulse class="size-4" />Medical <span class="ml-auto text-xs">Soon</span></div>
                <div class="flex gap-2 rounded-md px-3 py-2 text-sm text-muted-foreground"><NotebookPen class="size-4" />Treatment Plans <span class="ml-auto text-xs">Soon</span></div>
                <div class="flex gap-2 rounded-md px-3 py-2 text-sm text-muted-foreground"><LockKeyhole class="size-4" />Therapy Notes <span class="ml-auto text-xs">Restricted</span></div>
            </nav>
            <section class="min-w-0 space-y-5" aria-labelledby="overview-heading">
                <div><h2 id="overview-heading" class="text-xl font-semibold">Treatment Overview</h2><p class="text-sm text-muted-foreground">Current concerns and recent authorized treatment information for this client.</p></div>
                <section v-if="canViewAlerts" class="space-y-3 rounded-lg border p-4" aria-labelledby="overview-alerts">
                    <div class="flex flex-wrap items-center justify-between gap-2"><h3 id="overview-alerts" class="flex items-center gap-2 font-semibold"><ShieldAlert class="size-4" /> Active Alerts <span class="text-xs font-normal text-muted-foreground">({{ activeAlertCount }})</span></h3><Link :href="`/facility/clients/${client.id}/treatment/alerts`" class="inline-flex items-center text-sm underline">View alerts <ChevronRight class="size-4" /></Link></div>
                    <p v-if="!alerts.length" class="text-sm text-muted-foreground">No active alerts.</p>
                    <div v-for="alert in alerts" :key="alert.id" class="rounded-md border p-3">
                        <Link :href="`/facility/clients/${client.id}/treatment/alerts?status=active#alert-${alert.id}`" class="flex items-center gap-2 font-medium hover:underline"><ShieldAlert class="size-4 shrink-0" :class="priorityClass(alert.priority)" />{{ alert.title }} <ChevronRight class="ml-auto size-4" /></Link>
                        <p class="mt-1 text-xs capitalize text-muted-foreground">{{ alert.category }} · {{ alert.priority.replace('_', ' ') }} · {{ alert.created_at }}</p>
                        <p v-if="alert.details" class="mt-2 whitespace-pre-wrap text-sm">{{ alert.details }}</p>
                    </div>
                    <p v-if="activeAlertCount !== null && activeAlertCount > alerts.length" class="text-xs text-muted-foreground">{{ activeAlertCount - alerts.length }} additional active alert(s) on the Alerts &amp; Safety page.</p>
                </section>
                <section v-if="canViewShiftNotes" class="space-y-3 rounded-lg border p-4" aria-labelledby="overview-shift">
                    <div class="flex flex-wrap items-center justify-between gap-2"><h3 id="overview-shift" class="flex items-center gap-2 font-semibold"><ClipboardList class="size-4" /> Latest End of Shift Note</h3><Link :href="`/facility/clients/${client.id}/treatment/shift-notes`" class="inline-flex items-center text-sm underline">View shift notes <ChevronRight class="size-4" /></Link></div>
                    <div v-if="latestShiftNote" class="space-y-2">
                        <p class="text-xs capitalize text-muted-foreground">{{ latestShiftNote.shift }} · {{ latestShiftNote.shift_date }} · Recorded by {{ latestShiftNote.author_name }}</p>
                        <p class="whitespace-pre-wrap text-sm">{{ latestShiftNote.summary }}</p>
                        <div v-if="latestShiftNote.handoff" class="rounded-md bg-muted/40 p-3"><h4 class="text-xs font-semibold uppercase text-muted-foreground">Next shift handoff</h4><p class="mt-1 whitespace-pre-wrap text-sm">{{ latestShiftNote.handoff }}</p></div>
                        <Link :href="`/facility/clients/${client.id}/treatment/shift-notes#note-${latestShiftNote.id}`" class="inline-flex items-center text-sm underline">Open full note <ChevronRight class="size-4" /></Link>
                    </div>
                    <p v-else class="text-sm text-muted-foreground">No End of Shift Notes have been recorded.</p>
                </section>
                <section class="space-y-2 rounded-lg border p-4" aria-labelledby="overview-medical"><h3 id="overview-medical" class="flex items-center gap-2 font-semibold"><HeartPulse class="size-4" /> Medical Information</h3><p class="text-sm text-muted-foreground">Medical treatment records are not yet available in WayAlong. This overview will show authorized clinical summaries once the Medical module and its permissions are implemented.</p></section>
                <section class="space-y-2 rounded-lg border p-4" aria-labelledby="overview-notes"><h3 id="overview-notes" class="flex items-center gap-2 font-semibold"><LockKeyhole class="size-4" /> Clinical &amp; Therapy Notes</h3><p class="text-sm text-muted-foreground">Restricted clinical and therapy notes are not yet implemented. They will be shown only to staff with appropriate record-specific access, not automatically to everyone who can view this overview.</p></section>
            </section>
        </div>
    </main>
</template>
