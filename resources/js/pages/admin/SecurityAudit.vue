<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';

type Event = { id: number; user_id: number; staff_name: string | null; client_enrollment_id: number; facility_id: number | null; section: string; outcome: string; created_at: string };
type Filters = { user_id?: number; client_enrollment_id?: number; facility_id?: number; section?: string; outcome?: string; from?: string; to?: string };
const props = defineProps<{ organization: { id: number; name: string }; events: { data: Event[]; prev_page_url: string | null; next_page_url: string | null; total: number }; filters: Filters }>();
const form = reactive({ user_id: String(props.filters.user_id ?? ''), client_enrollment_id: String(props.filters.client_enrollment_id ?? ''), facility_id: String(props.filters.facility_id ?? ''), section: props.filters.section ?? '', outcome: props.filters.outcome ?? '', from: props.filters.from ?? '', to: props.filters.to ?? '' });
const apply = () => router.get('/admin/security/audit', Object.fromEntries(Object.entries(form).filter(([, value]) => value !== '')), { preserveState: true, replace: true });
const reset = () => { Object.keys(form).forEach(key => { (form as Record<string, string>)[key] = ''; }); apply(); };
const formatDate = (value: string) => new Date(value).toLocaleString();
</script>

<template>
  <Head title="Security Audit Viewer" />
  <main class="mx-auto w-full max-w-7xl space-y-6 p-4 md:p-8">
    <header class="space-y-2">
      <Link href="/admin" class="text-sm underline">← Admin Command Center</Link>
      <h1 class="text-3xl font-semibold">Security Audit Viewer</h1>
      <p class="text-sm text-muted-foreground">{{ organization.name }} · Read-only clinical access metadata. Patient notes and alert descriptions are never shown.</p>
    </header>
    <form class="grid gap-3 rounded-xl border bg-card p-4 sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="apply">
      <label class="grid gap-1 text-sm">Staff user ID<input v-model="form.user_id" type="number" min="1" class="rounded-md border bg-background p-2" /></label>
      <label class="grid gap-1 text-sm">Client enrollment ID<input v-model="form.client_enrollment_id" type="number" min="1" class="rounded-md border bg-background p-2" /></label>
      <label class="grid gap-1 text-sm">Facility ID<input v-model="form.facility_id" type="number" min="1" class="rounded-md border bg-background p-2" /></label>
      <label class="grid gap-1 text-sm">Section<select v-model="form.section" class="rounded-md border bg-background p-2"><option value="">All sections</option><option value="overview">Overview</option><option value="alerts">Alerts &amp; Safety</option><option value="shift_notes">End of Shift</option></select></label>
      <label class="grid gap-1 text-sm">Outcome<select v-model="form.outcome" class="rounded-md border bg-background p-2"><option value="">All outcomes</option><option value="viewed">Viewed</option><option value="denied">Denied</option></select></label>
      <label class="grid gap-1 text-sm">From<input v-model="form.from" type="date" class="rounded-md border bg-background p-2" /></label>
      <label class="grid gap-1 text-sm">Through<input v-model="form.to" type="date" class="rounded-md border bg-background p-2" /></label>
      <div class="flex items-end gap-2"><button type="submit" class="rounded-md bg-primary px-4 py-2 text-sm text-primary-foreground">Apply filters</button><button type="button" class="rounded-md border px-3 py-2 text-sm" @click="reset">Clear</button></div>
    </form>
    <section class="overflow-x-auto rounded-xl border bg-card" aria-label="Clinical access events">
      <table class="w-full text-left text-sm"><thead class="border-b bg-muted/40"><tr><th class="p-3">Time</th><th class="p-3">Staff</th><th class="p-3">Client enrollment</th><th class="p-3">Facility</th><th class="p-3">Section</th><th class="p-3">Outcome</th></tr></thead>
      <tbody><tr v-for="event in events.data" :key="event.id" class="border-b last:border-0"><td class="whitespace-nowrap p-3">{{ formatDate(event.created_at) }}</td><td class="p-3">{{ event.staff_name || 'Former staff member' }} <span class="text-xs text-muted-foreground">#{{ event.user_id }}</span></td><td class="p-3">#{{ event.client_enrollment_id }}</td><td class="p-3">{{ event.facility_id ? `#${event.facility_id}` : 'Unavailable' }}</td><td class="p-3">{{ event.section.replaceAll('_', ' ') }}</td><td class="p-3"><span :class="event.outcome === 'denied' ? 'text-destructive font-semibold' : 'text-muted-foreground'">{{ event.outcome }}</span></td></tr>
      <tr v-if="!events.data.length"><td colspan="6" class="p-8 text-center text-muted-foreground">No audit events match the selected filters.</td></tr></tbody></table>
    </section>
    <nav class="flex items-center justify-between text-sm" aria-label="Audit pagination"><span>{{ events.total }} matching events</span><div class="flex gap-2"><Link v-if="events.prev_page_url" :href="events.prev_page_url" class="rounded-md border px-3 py-2">Previous</Link><Link v-if="events.next_page_url" :href="events.next_page_url" class="rounded-md border px-3 py-2">Next</Link></div></nav>
  </main>
</template>
