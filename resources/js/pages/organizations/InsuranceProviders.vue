<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

type Provider = { id: number; name: string; payer_code: string | null; phone: string | null; is_active: boolean };
defineProps<{ organization: { id: number; name: string }; providers: Provider[] }>();
const base = '/organization/admin/insurance-providers';
const empty = () => ({ name: '', payer_code: '', phone: '', is_active: true });
const form = useForm(empty());
const editing = ref<number | null>(null);
const open = (provider?: Provider) => {
    editing.value = provider?.id ?? null;
    form.name = provider?.name ?? '';
    form.payer_code = provider?.payer_code ?? '';
    form.phone = provider?.phone ?? '';
    form.is_active = provider?.is_active ?? true;
    form.clearErrors();
};
const cancel = () => { editing.value = null; form.reset(); form.clearErrors(); };
const save = () => {
    if (editing.value !== null) form.patch(`${base}/${editing.value}`, { preserveScroll: true, onSuccess: cancel });
    else form.post(base, { preserveScroll: true, onSuccess: cancel });
};
</script>

<template>
    <Head title="Insurance Providers" />
    <main class="mx-auto w-full max-w-5xl space-y-6 p-4 md:p-8">
        <a href="/organization/admin" class="text-sm text-muted-foreground underline">← Organization Settings</a>
        <header><h1 class="text-2xl font-semibold">Insurance Provider Directory</h1><p class="text-sm text-muted-foreground">{{ organization.name }} · Providers are shared by authorized facilities in this organization.</p></header>
        <section class="space-y-3 rounded-xl border p-5">
            <h2 class="font-semibold">Organization insurance providers</h2>
            <p v-if="!providers.length" class="text-sm text-muted-foreground">No providers added yet.</p>
            <div v-for="provider in providers" :key="provider.id" class="flex flex-wrap items-center justify-between gap-3 rounded-md border p-3 text-sm">
                <div><p class="font-medium">{{ provider.name }} <span v-if="!provider.is_active" class="text-muted-foreground">(Inactive)</span></p><p class="text-muted-foreground">Payer code: {{ provider.payer_code || 'Not specified' }} · Phone: {{ provider.phone || 'Not specified' }}</p></div>
                <button type="button" class="rounded-md border px-3 py-2" @click="open(provider)">Edit provider</button>
            </div>
        </section>
        <section class="space-y-4 rounded-xl border p-5">
            <h2 class="font-semibold">{{ editing === null ? 'Add insurance provider' : 'Edit insurance provider' }}</h2>
            <form class="space-y-3" @submit.prevent="save">
                <div class="grid gap-3 md:grid-cols-3">
                    <label class="grid gap-1 text-sm">Provider name *<input v-model="form.name" required maxlength="200" class="rounded-md border bg-background p-2" /><span class="text-destructive">{{ form.errors.name }}</span></label>
                    <label class="grid gap-1 text-sm">Payer code<input v-model="form.payer_code" maxlength="80" class="rounded-md border bg-background p-2" /><span class="text-destructive">{{ form.errors.payer_code }}</span></label>
                    <label class="grid gap-1 text-sm">Phone<input v-model="form.phone" maxlength="40" class="rounded-md border bg-background p-2" /><span class="text-destructive">{{ form.errors.phone }}</span></label>
                </div>
                <label v-if="editing !== null" class="flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox" />Active (available for new client insurance policies)</label>
                <div class="flex gap-2"><button :disabled="form.processing" class="rounded-md bg-primary px-4 py-2 text-sm text-primary-foreground">{{ editing === null ? 'Add provider' : 'Save provider' }}</button><button v-if="editing !== null" type="button" class="rounded-md border px-4 py-2 text-sm" @click="cancel">Cancel</button></div>
            </form>
        </section>
    </main>
</template>
