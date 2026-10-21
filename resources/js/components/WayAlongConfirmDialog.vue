<script setup lang="ts">
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
defineProps<{ title: string; description: string; confirmLabel?: string; destructive?: boolean }>();
const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ confirm: [] }>();
const confirm = () => { emit('confirm'); open.value = false; };
</script><template><Dialog v-model:open="open"><DialogContent><DialogHeader><DialogTitle>{{ title }}</DialogTitle><DialogDescription>{{ description }}</DialogDescription></DialogHeader><DialogFooter><button class="rounded-md border px-4 py-2 text-sm" @click="open = false">Cancel</button><button class="rounded-md px-4 py-2 text-sm text-white" :class="destructive ? 'bg-destructive' : 'bg-primary'" @click="confirm">{{ confirmLabel || 'Confirm' }}</button></DialogFooter></DialogContent></Dialog></template>
