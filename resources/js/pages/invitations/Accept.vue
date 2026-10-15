<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps<{ token: string; email: string; organization: string; existingAccount: boolean; signedIn: boolean }>();
const form = useForm({ name: '', password: '', password_confirmation: '' });
const accept = () => form.post(`/invitations/${props.token}/accept`);
</script>

<template>
    <Head title="Accept staff invitation" />
    <main class="mx-auto max-w-md space-y-5 p-8">
        <h1 class="text-2xl font-semibold">Join {{ organization }}</h1>
        <p>Invitation for <strong>{{ email }}</strong></p>
        <p v-if="existingAccount && !signedIn">You already have a WayAlong account. Sign in using the invited email address, then return to this invitation link.</p>
        <p v-else-if="existingAccount">Confirm that you want to join this organization.</p>
        <p v-else-if="signedIn">Sign out before accepting this invitation to create an account for {{ email }}.</p>
        <form v-if="!existingAccount && !signedIn" class="grid gap-4" @submit.prevent="accept">
            <label class="grid gap-1">Your name<input v-model="form.name" required maxlength="255" class="rounded border p-2" /><span class="text-red-600">{{ form.errors.name }}</span></label>
            <label class="grid gap-1">Password (12+ characters)<input v-model="form.password" type="password" required minlength="12" autocomplete="new-password" class="rounded border p-2" /><span class="text-red-600">{{ form.errors.password }}</span></label>
            <label class="grid gap-1">Confirm password<input v-model="form.password_confirmation" type="password" required autocomplete="new-password" class="rounded border p-2" /></label>
            <button class="rounded bg-primary px-4 py-2 text-primary-foreground" :disabled="form.processing">Create account and accept</button>
        </form>
        <button v-if="existingAccount && signedIn" class="rounded bg-primary px-4 py-2 text-primary-foreground" :disabled="form.processing" @click="accept">Accept invitation</button>
        <a v-if="existingAccount && !signedIn" href="/login" class="underline">Sign in</a>
    </main>
</template>
