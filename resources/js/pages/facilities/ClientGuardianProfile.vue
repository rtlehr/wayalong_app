<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
type Contact = { kind: string; label: string | null; is_primary: boolean; value: string | null; line_1: string | null; line_2: string | null; city: string | null; state: string | null; postal_code: string | null; country: string | null };
const props = defineProps<{ enrollmentId: number; guardian: { id: number; first_name: string; last_name: string; date_of_birth: string | null; notes: string | null }; contacts: Contact[] }>();
const addressText = (contact: Contact) => [contact.line_1, contact.line_2, [contact.city, contact.state].filter(Boolean).join(', ') + (contact.postal_code ? ' ' + contact.postal_code : '')].filter(Boolean);
</script>
<template>
    <Head :title="`Person — ${guardian.first_name} ${guardian.last_name}`" />
    <main class="mx-auto w-full max-w-5xl space-y-6 p-4 md:p-8">
        <Link :href="`/facility/clients/${enrollmentId}`" class="text-sm underline underline-offset-4">← Back to client profile</Link>
        <h1 class="text-2xl font-semibold">{{ guardian.first_name }} {{ guardian.last_name }}</h1>
        <p class="text-sm text-muted-foreground">Linked parent / guardian person profile · Read-only</p>
        <section class="space-y-3 rounded-lg border p-5"><h2 class="text-lg font-semibold">Personal Information</h2><p>Date of birth: {{ guardian.date_of_birth?.slice(0, 10) || '—' }}</p><p v-if="guardian.notes" class="whitespace-pre-wrap">{{ guardian.notes }}</p></section>
        <section class="space-y-4 rounded-lg border p-5">
            <h2 class="text-lg font-semibold">Contact Information</h2>
            <div v-for="kind in ['phone', 'email']" :key="kind" class="space-y-2">
                <h3 class="font-medium">{{ kind === 'phone' ? 'Phone Numbers' : 'Email Addresses' }}</h3>
                <table v-if="contacts.some(c => c.kind === kind)" class="w-full text-left text-sm"><thead class="border-b"><tr><th class="py-2">Label</th><th>{{ kind === 'phone' ? 'Number' : 'Email' }}</th><th>Primary</th></tr></thead><tbody><tr v-for="(contact, i) in contacts.filter(c => c.kind === kind)" :key="i" class="border-b"><td class="py-2">{{ contact.label || '—' }}</td><td>{{ contact.value || '—' }}</td><td>{{ contact.is_primary ? 'X' : '—' }}</td></tr></tbody></table>
                <p v-else class="text-sm text-muted-foreground">None on file.</p>
            </div>
            <h3 class="font-medium">Addresses</h3>
            <div v-for="(contact, i) in contacts.filter(c => c.kind === 'address')" :key="i" class="rounded-md border p-3 text-sm"><strong>{{ contact.label || 'Address' }}{{ contact.is_primary ? ' · Primary' : '' }}</strong><address class="mt-2 not-italic"><div v-for="(line, j) in addressText(contact)" :key="j">{{ line }}</div></address></div>
            <p v-if="!contacts.some(c => c.kind === 'address')" class="text-sm text-muted-foreground">No addresses on file.</p>
        </section>
    </main>
</template>
