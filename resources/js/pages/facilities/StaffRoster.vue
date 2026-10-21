<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    facility: { id: number; name: string };
    staff: { id: number; name: string; role: string | null }[];
}>();
</script>

<template>
    <Head :title="`${facility.name} — Staff Roster`" />
    <main class="mx-auto w-full max-w-5xl space-y-5 p-4 md:p-8">
        <Link href="/dashboard" class="text-sm underline underline-offset-4">← My WayAlong</Link>
        <header>
            <h1 class="text-2xl font-semibold">{{ facility.name }} — Staff Roster</h1>
            <p class="text-sm text-muted-foreground">Active staff assigned to your selected facility. This view is read-only.</p>
        </header>
        <div class="overflow-hidden rounded-xl border">
            <table class="w-full text-left text-sm">
                <thead class="bg-muted/40"><tr><th scope="col" class="p-3 font-semibold">Staff member</th><th scope="col" class="p-3 font-semibold">Facility role</th></tr></thead>
                <tbody>
                    <tr v-for="person in staff" :key="person.id" class="border-t"><td class="p-3">{{ person.name }}</td><td class="p-3 capitalize">{{ person.role || 'Assigned staff' }}</td></tr>
                    <tr v-if="!staff.length"><td colspan="2" class="p-4 text-muted-foreground">No active staff assignments found.</td></tr>
                </tbody>
            </table>
        </div>
    </main>
</template>
