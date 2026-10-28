<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps<{
    facility: { id: number; name: string };
    organization: { id: number; name: string };
    member: { id: number; name: string };
    permissions: { key: string; label: string; description: string }[];
    overrides: Record<string, string>;
    effectivePermissions: Record<string, boolean>;
}>();
const busy = ref(false);
const error = ref('');
const change = (permission: string, decision: string) => {
    busy.value = true;
    error.value = '';
    router.patch(`/facility/staff/${props.facility.id}/members/${props.member.id}/permissions`, { permission, decision }, {
        preserveScroll: true,
        onError: errors => { error.value = Object.values(errors).join(' '); },
        onFinish: () => { busy.value = false; },
    });
};
</script>

<template>
    <Head :title="`Facility permissions — ${member.name}`" />
    <main class="mx-auto w-full max-w-4xl space-y-6 p-4 md:p-8">
        <Link href="/facility/staff" class="text-sm underline">← Staff roster</Link>
        <header class="rounded-xl border bg-card p-5">
            <h1 class="text-2xl font-semibold">Facility staff permissions</h1>
            <p class="text-sm text-muted-foreground">{{ member.name }} · {{ facility.name }} · {{ organization.name }}</p>
            <p class="mt-2 text-sm text-muted-foreground">Only permissions you can delegate at this facility appear below. A change never grants access outside the staff member's assignments.</p>
        </header>
        <section class="rounded-xl border bg-card p-5">
            <p class="mb-3 text-sm text-muted-foreground">The status shows the staff member's current effective permission. Inherit follows their assigned role(s); Allow and Deny are individual overrides.</p>
            <p v-if="error" role="alert" class="mb-3 text-sm text-destructive">{{ error }}</p>
            <div v-for="permission in permissions" :key="permission.key" class="flex flex-wrap items-center justify-between gap-4 border-b py-4 last:border-0">
                <div class="min-w-0">
                    <h2 class="font-medium">{{ permission.label }}</h2>
                    <p class="text-xs text-muted-foreground">{{ permission.description }}</p>
                </div>
                <div class="flex shrink-0 flex-wrap items-center justify-end gap-3">
                    <span
                        :aria-label="`${permission.label}: effectively ${effectivePermissions[permission.key] ? 'allowed' : 'denied'}`"
                        class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-semibold"
                        :class="effectivePermissions[permission.key]
                            ? 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-300'
                            : 'border-slate-200 bg-slate-100 text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300'"
                    >
                        <span aria-hidden="true">{{ effectivePermissions[permission.key] ? '✓' : '–' }}</span>
                        {{ effectivePermissions[permission.key] ? 'Allowed' : 'Denied' }}
                    </span>
                    <select :value="overrides[permission.key] ?? 'inherit'" :disabled="busy" :aria-label="`${permission.label} override`" class="rounded-md border bg-background px-3 py-2" @change="change(permission.key, ($event.target as HTMLSelectElement).value)">
                        <option value="inherit">Inherit</option><option value="allow">Allow</option><option value="deny">Deny</option>
                    </select>
                </div>
            </div>
            <p v-if="!permissions.length" class="text-sm text-muted-foreground">No permissions are delegated to your facility management role.</p>
        </section>
    </main>
</template>
