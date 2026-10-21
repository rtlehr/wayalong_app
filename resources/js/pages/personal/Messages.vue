<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
type Message = { id: string; title: string; message: string; url: string | null; read: boolean; createdAt: string | null; invitationToken?: string | null };
defineProps<{ messages: Message[] }>();
const declining = ref<string | null>(null);
const reason = ref('');
function acceptInvitation(token: string): void { router.post(`/invitations/${token}/accept`); }
function declineInvitation(token: string): void { router.post(`/invitations/${token}/decline`, { reason: reason.value }, { onSuccess: () => { declining.value = null; reason.value = ''; } }); }
function markRead(id: string): void { router.patch(`/messages/${id}/read`, {}, { preserveScroll: true }); }
</script>
<template>
    <Head title="Message Center" />
    <main class="mx-auto w-full max-w-4xl space-y-5 p-4 md:p-8">
        <Link href="/dashboard" class="text-sm underline">← My WayAlong</Link><h1 class="text-2xl font-semibold">Message Center</h1>
        <p class="text-sm text-muted-foreground">Your personal messages stay with your WayAlong account, independent of your selected organization.</p>
        <p v-if="!messages.length" class="rounded-xl border p-6 text-sm text-muted-foreground">Your inbox is empty.</p>
        <article v-for="message in messages" :key="message.id" class="space-y-2 rounded-xl border p-5" :class="!message.read ? 'border-primary/50' : ''">
            <div class="flex flex-wrap items-center justify-between gap-2"><h2 class="font-semibold">{{ message.title }} <span v-if="!message.read" class="text-xs text-primary">· Unread</span></h2><time class="text-xs text-muted-foreground">{{ message.createdAt ? new Date(message.createdAt).toLocaleString() : '' }}</time></div>
            <p class="whitespace-pre-line text-sm">{{ message.message }}</p>
            <div v-if="message.invitationToken" class="flex flex-wrap items-center gap-3 text-sm">
                <button type="button" class="rounded-md bg-primary px-3 py-2 text-primary-foreground" @click="acceptInvitation(message.invitationToken!)">Accept invitation</button>
                <button type="button" class="rounded-md border px-3 py-2" @click="declining = message.id">Decline invitation</button>
            </div>
            <form v-if="declining === message.id && message.invitationToken" class="space-y-2" @submit.prevent="declineInvitation(message.invitationToken!)">
                <label class="block text-sm">Reason for declining (optional)<textarea v-model="reason" maxlength="1000" rows="2" class="mt-1 block w-full rounded-md border bg-background p-2" /></label>
                <button type="submit" class="rounded-md bg-primary px-3 py-2 text-sm text-primary-foreground">Confirm decline</button>
                <button type="button" class="ml-3 text-sm underline" @click="declining = null">Cancel</button>
            </form>
            <div class="flex gap-4 text-sm"><Link v-if="message.url?.startsWith('/') && !message.url.startsWith('//')" :href="message.url" class="underline">Open message</Link><button v-if="!message.read" type="button" class="underline" @click="markRead(message.id)">Mark as read</button></div>
        </article>
    </main>
</template>
