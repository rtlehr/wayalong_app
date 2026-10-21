<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Building2, Mail, ArrowRight, MapPin, CheckCircle2 } from '@lucide/vue';
import { ref } from 'vue';

type Facility = { id: number; name: string; role: string | null };
type Membership = { id: number; name: string; role: string; facilities: Facility[] };
defineProps<{ memberships: Membership[]; activeOrganizationId: number | null; activeFacilityId: number | null; activeFacilityPermissions: string[] }>();
const switching = ref(false);
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
</script>
<template>
    <Head title="My WayAlong" />
    <main class="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-8">
        <header class="space-y-1"><h1 class="text-2xl font-semibold">My WayAlong</h1><p class="text-sm text-muted-foreground">Your personal dashboard and authorized organization and facility relationships.</p></header>
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
