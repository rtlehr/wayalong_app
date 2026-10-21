<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import WayAlongFormSections from '@/components/WayAlongFormSections.vue';

type Organization = { id: number; name: string; slug: string; status: string; timezone: string };
const props = defineProps<{ mode: 'create' | 'view' | 'edit'; organization?: Organization }>();
const readonly = computed(() => props.mode === 'view');
const active = ref('details');
const form = useForm({
    name: props.organization?.name ?? '',
    slug: props.organization?.slug ?? '',
    status: props.organization?.status ?? 'active',
    timezone: props.organization?.timezone ?? 'America/New_York',
});
const sections = computed(() => [
    { id: 'details', title: 'Organization Details', description: 'Name and login identifier', invalid: !!form.errors.name || !!form.errors.slug },
    { id: 'settings', title: 'Settings & Status', description: 'Timezone and availability', invalid: !!form.errors.timezone || !!form.errors.status },
]);
const save = () => { if (readonly.value) return; if (props.mode === 'create') form.post('/platform/organizations'); else form.patch(`/platform/organizations/${props.organization?.id}`); };
</script>
<template>
    <Head :title="mode === 'create' ? 'Add Organization' : mode === 'view' ? 'View Organization' : 'Edit Organization'" />
    <main class="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-8">
        <header class="space-y-2"><a href="/platform/organizations" class="text-sm underline">← Organization Management</a><h1 class="text-2xl font-semibold">{{ mode === 'create' ? 'Add Organization' : mode === 'view' ? 'View Organization' : 'Edit Organization' }}</h1><p class="text-sm text-muted-foreground">Changes to the login identifier should be coordinated with users who have bookmarked their sign-in page.</p></header>
        <WayAlongFormSections v-model:active="active" :sections="sections">
            <template #default="{ section }">
                <form class="space-y-5" @submit.prevent="save">
                    <template v-if="section === 'details'">
                        <label class="grid gap-1 text-sm">Organization name *<input v-model="form.name" :readonly="readonly" required maxlength="255" class="rounded-md border bg-background px-3 py-2" /><span v-if="form.errors.name" class="text-destructive">{{ form.errors.name }}</span></label>
                        <label class="grid gap-1 text-sm">Login identifier (slug) *<input v-model="form.slug" :readonly="readonly" required maxlength="255" pattern="[a-zA-Z0-9_-]+" class="rounded-md border bg-background px-3 py-2" /><span v-if="form.errors.slug" class="text-destructive">{{ form.errors.slug }}</span></label>
                        <p class="text-xs text-muted-foreground">Reserved for organization-specific authentication URLs in the upcoming login migration.</p>
                    </template>
                    <template v-else-if="section === 'settings'">
                        <label class="grid gap-1 text-sm">Status *<select v-model="form.status" :disabled="readonly" class="rounded-md border bg-background px-3 py-2"><option value="active">Active</option><option value="inactive">Inactive</option></select><span v-if="form.errors.status" class="text-destructive">{{ form.errors.status }}</span></label>
                        <label class="grid gap-1 text-sm">Timezone *<input v-model="form.timezone" :readonly="readonly" required maxlength="100" placeholder="America/New_York" class="rounded-md border bg-background px-3 py-2" /><span v-if="form.errors.timezone" class="text-destructive">{{ form.errors.timezone }}</span></label>
                        <p class="text-sm text-muted-foreground">Deactivation preserves records while preventing selection of the organization through the existing organization context.</p>
                    </template>
                    <div class="flex items-center justify-between border-t pt-4"><a href="/platform/organizations" class="rounded-md border px-4 py-2 text-sm">Back to organizations</a><a v-if="readonly" :href="`/platform/organizations/${organization?.id}/edit`" class="rounded-md bg-primary px-4 py-2 text-sm text-primary-foreground">Edit</a><button v-else type="submit" :disabled="form.processing" class="rounded-md bg-primary px-4 py-2 text-sm text-primary-foreground disabled:opacity-50">{{ mode === 'create' ? 'Create organization' : 'Save organization' }}</button></div>
                </form>
            </template>
        </WayAlongFormSections>
    </main>
</template>
