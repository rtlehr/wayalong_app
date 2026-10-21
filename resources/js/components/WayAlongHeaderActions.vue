<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { Bell, CircleHelp, Plus, X } from '@lucide/vue';
const page = usePage();
const helpOpen = ref(false);
const alertsOpen = ref(false);
const ticketOpen = ref(false);
const pageName = computed(() => String((page.props as { helpTitle?: string }).helpTitle || page.url.split('?')[0].split('/').filter(Boolean).join(' / ') || 'Dashboard'));
const helpText = computed(() => (page.props as { pageHelp?: string }).pageHelp || 'Page-specific guidance will appear here as this module is developed.');
const alerts = computed(() => ((page.props as { alerts?: { id: string; title: string; message: string }[] }).alerts || []));
</script>
<template>
  <div class="ml-auto flex items-center gap-1">
    <button type="button" class="rounded-md p-2 hover:bg-accent focus-visible:outline-2 focus-visible:outline-ring" aria-label="Create support ticket" title="Support ticket" @click="ticketOpen = true"><Plus class="size-5" /></button>
    <div class="relative">
      <button type="button" class="relative rounded-md p-2 hover:bg-accent focus-visible:outline-2 focus-visible:outline-ring" aria-label="Notifications" :aria-expanded="alertsOpen" @click="alertsOpen = !alertsOpen"><Bell class="size-5" /><span v-if="alerts.length" class="absolute -right-1 -top-1 rounded-full bg-destructive px-1 text-[10px] text-destructive-foreground">{{ alerts.length }}</span></button>
      <div v-if="alertsOpen" class="absolute right-0 top-11 z-40 w-80 rounded-lg border bg-popover p-4 shadow-lg"><div class="mb-3 flex justify-between"><strong>Notifications</strong><button aria-label="Close notifications" @click="alertsOpen = false"><X class="size-4" /></button></div><p v-if="!alerts.length" class="text-sm text-muted-foreground">You have no new notifications.</p><div v-for="alert in alerts" :key="alert.id" class="border-t py-2 text-sm"><strong>{{ alert.title }}</strong><p>{{ alert.message }}</p></div><Link href="/messages" class="mt-2 block border-t pt-2 text-sm underline" @click="alertsOpen = false">Open Message Center</Link></div>
    </div>
    <button type="button" class="rounded-md p-2 hover:bg-accent focus-visible:outline-2 focus-visible:outline-ring" aria-label="Help for this page" :aria-expanded="helpOpen" @click="helpOpen = !helpOpen"><CircleHelp class="size-5" /></button>
  </div>
  <aside v-if="helpOpen" aria-label="Page help" class="fixed inset-y-0 right-0 z-40 w-full max-w-sm overflow-y-auto border-l bg-background p-6 shadow-xl sm:top-16"><div class="mb-6 flex items-start justify-between gap-4"><div><h2 class="text-lg font-semibold">{{ pageName }} help</h2><p class="text-xs text-muted-foreground">Keep this panel open while you work.</p></div><button aria-label="Close help" @click="helpOpen = false"><X class="size-5" /></button></div><p class="whitespace-pre-line text-sm leading-6">{{ helpText }}</p></aside>
  <div v-if="ticketOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="ticketOpen = false"><section role="dialog" aria-modal="true" aria-labelledby="ticket-title" class="w-full max-w-md rounded-xl bg-background p-6 shadow-xl"><div class="flex justify-between"><h2 id="ticket-title" class="text-lg font-semibold">Support ticket</h2><button aria-label="Close support ticket" @click="ticketOpen = false"><X class="size-5" /></button></div><p class="mt-3 text-sm text-muted-foreground">Support ticket submission will be enabled when the support module is available. No ticket has been submitted.</p><button class="mt-5 rounded-md border px-4 py-2 text-sm" @click="ticketOpen = false">Close</button></section></div>
</template>
