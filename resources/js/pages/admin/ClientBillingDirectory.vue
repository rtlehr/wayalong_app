<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { AlertCircle, AlertTriangle, Info } from '@lucide/vue';

const props = defineProps<{
    clients: { enrollment_id: number; client_name: string; facility_name: string; status: string; notice: { level: 'critical' | 'warning' | 'info'; label: string } | null }[];
    hasFacilityAssignments: boolean;
}>();
const search = ref('');
const filtered = computed(() => props.clients.filter(client =>
    `${client.client_name} ${client.facility_name}`.toLowerCase().includes(search.value.toLowerCase().trim()),
));
</script>

<template>
    <Head title="Client Billing & Accounts" />
    <main class="mx-auto max-w-5xl space-y-6 p-6">
        <Link href="/admin" class="text-sm underline">← Admin Command Center</Link>
        <div class="space-y-2">
            <h1 class="text-2xl font-semibold">Client Billing & Accounts</h1>
            <p class="text-sm text-muted-foreground">Financial access is limited to clients in your assigned facilities. Clinical records remain separate.</p>
        </div>
        <div v-if="!hasFacilityAssignments" class="rounded-lg border p-4 text-sm">You have no active facility assignments. Ask an organization administrator to assign you to the appropriate facility before accessing billing records.</div>
        <template v-else>
            <label class="block space-y-2 text-sm">Search clients or facilities
                <input v-model="search" type="search" placeholder="Search billing accounts…" class="w-full rounded-md border bg-background p-3" />
            </label>
            <div v-if="!filtered.length" class="rounded-lg border p-5 text-sm text-muted-foreground">No matching client billing accounts in your assigned facilities.</div>
            <div v-for="client in filtered" :key="client.enrollment_id" class="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-4">
                <div>
                    <div class="flex items-center gap-2">
                        <p class="font-medium">{{ client.client_name }}</p>
                        <span v-if="client.notice" role="img" :aria-label="client.notice.label" :title="client.notice.label" class="inline-flex shrink-0 items-center">
                            <AlertCircle v-if="client.notice.level === 'critical'" class="size-4 text-red-600 dark:text-red-400" aria-hidden="true" />
                            <AlertTriangle v-else-if="client.notice.level === 'warning'" class="size-4 text-amber-600 dark:text-amber-400" aria-hidden="true" />
                            <Info v-else class="size-4 text-muted-foreground" aria-hidden="true" />
                        </span>
                    </div>
                    <p class="text-sm text-muted-foreground">{{ client.facility_name }} · {{ client.status }}</p>
                </div>
                <Link :href="`/facility/clients/${client.enrollment_id}/billing`" class="rounded-md border px-3 py-2 text-sm font-medium hover:bg-accent">Open billing →</Link>
            </div>
        </template>
    </main>
</template>
