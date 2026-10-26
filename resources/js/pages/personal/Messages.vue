<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Inbox, MailOpen, ArrowLeft, CheckCheck, ArrowUpRight } from '@lucide/vue';
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
    <main class="mx-auto w-full max-w-5xl space-y-6 p-4 md:p-8">
        <header class="wayalong-page-intro space-y-3">
            <Link href="/dashboard" class="inline-flex items-center gap-2 text-sm font-medium text-muted-foreground hover:text-foreground"><ArrowLeft class="size-4" />My WayAlong</Link>
            <div class="flex items-center gap-3"><div class="flex size-11 items-center justify-center rounded-xl bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-200"><Inbox class="size-6" /></div><div><h1 class="text-2xl font-bold tracking-tight">Message Center</h1><p class="mt-1 text-sm text-muted-foreground">Your personal inbox and organization invitations</p></div></div>
            <p class="text-sm text-muted-foreground">Your messages stay with your WayAlong account, independent of the organization you've selected.</p>
        </header>
        <div class="flex items-center justify-between gap-3"><h2 class="text-sm font-semibold uppercase tracking-wide text-muted-foreground">Inbox</h2><span class="rounded-full border bg-card px-3 py-1 text-xs font-medium text-muted-foreground">{{ messages.length }} {{ messages.length === 1 ? 'message' : 'messages' }}</span></div>
        <section v-if="!messages.length" class="wayalong-empty-state flex flex-col items-center justify-center gap-3 rounded-2xl border p-10 text-center sm:p-14"><div class="flex size-14 items-center justify-center rounded-2xl bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-200"><MailOpen class="size-7" /></div><h3 class="text-base font-semibold">You're all caught up</h3><p class="max-w-sm text-sm text-muted-foreground">Your inbox is empty. Invitations and account messages will appear here when they arrive.</p></section>
        <article v-for="message in messages" :key="message.id" class="wayalong-message-card space-y-3 rounded-2xl border bg-card p-5 sm:p-6" :class="!message.read ? 'border-primary/50' : ''">
            <div class="flex flex-wrap items-center justify-between gap-2"><h2 class="font-semibold">{{ message.title }} <span v-if="!message.read" class="rounded-full bg-sky-100 px-2 py-0.5 text-xs font-semibold text-sky-800 dark:bg-sky-900 dark:text-sky-100">Unread</span></h2><time class="text-xs text-muted-foreground">{{ message.createdAt ? new Date(message.createdAt).toLocaleString() : '' }}</time></div>
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
            <div class="flex flex-wrap items-center gap-4 border-t pt-3 text-sm"><Link v-if="message.url?.startsWith('/') && !message.url.startsWith('//')" :href="message.url" class="underline">Open message</Link><button v-if="!message.read" type="button" class="underline" @click="markRead(message.id)">Mark as read</button></div>
        </article>
    </main>
</template>
