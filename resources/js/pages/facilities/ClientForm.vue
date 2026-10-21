<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps<{ facility: { id: number; name: string } }>();
const form = useForm({ first_name: '', last_name: '', date_of_birth: '', enrolled_at: new Date().toISOString().slice(0, 10) });
function submit(): void { form.post('/facility/clients'); }
</script>
<template>
    <Head title="Enroll Client" />
    <main class="mx-auto w-full max-w-3xl space-y-6 p-4 md:p-8">
        <Link href="/facility/clients" class="text-sm underline underline-offset-4">← Client Directory</Link>
        <header><h1 class="text-2xl font-semibold">Enroll Client — {{ facility.name }}</h1><p class="text-sm text-muted-foreground">A client does not need an email or login account. Enrollment belongs to the selected facility.</p></header>
        <form class="space-y-5 rounded-xl border p-5" @submit.prevent="submit">
            <div v-for="field in ([['first_name', 'First name'], ['last_name', 'Last name'], ['date_of_birth', 'Date of birth'], ['enrolled_at', 'Enrollment date']] as const)" :key="field[0]" class="space-y-1.5">
                <label :for="field[0]" class="block text-sm font-medium">{{ field[1] }} <span v-if="field[0] !== 'date_of_birth'" aria-hidden="true">*</span></label>
                <input :id="field[0]" v-model="form[field[0]]" :type="field[0].endsWith('_at') || field[0] === 'date_of_birth' ? 'date' : 'text'" :required="field[0] !== 'date_of_birth'" class="w-full rounded-md border bg-background px-3 py-2" :aria-invalid="!!form.errors[field[0]]" />
                <p v-if="form.errors[field[0]]" class="text-sm text-destructive">{{ form.errors[field[0]] }}</p>
            </div>
            <button type="submit" :disabled="form.processing" class="rounded-md bg-primary px-4 py-2 text-sm text-primary-foreground disabled:opacity-50">{{ form.processing ? 'Saving…' : 'Enroll client' }}</button>
        </form>
    </main>
</template>
