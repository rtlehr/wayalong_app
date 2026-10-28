<script setup lang="ts">
import { computed, ref, watch } from 'vue';
type Row = Record<string, any>;
type Column = { key: string; label: string };
const props = withDefaults(defineProps<{ rows: Row[]; columns: Column[]; storageKey: string; clientSearch?: boolean; canAdd?: boolean; addLabel?: string; beforeExport?: (rows: Row[]) => Promise<boolean> }>(), { clientSearch: true, canAdd: false, addLabel: 'Add' });
const emit = defineEmits<{ add: [] }>();
const search = ref('');
const sortKey = ref('');
const descending = ref(false);
const settingsOpen = ref(false);
const visible = ref(props.columns.map(c => c.key));
const order = ref(props.columns.map(c => c.key));
const storageKey = `wayalong.table.${props.storageKey}`;
if (typeof window !== 'undefined') {
    try {
        const saved = JSON.parse(window.localStorage.getItem(storageKey) || '{}');
        if (Array.isArray(saved.visible)) visible.value = saved.visible.filter((v: unknown): v is string => typeof v === 'string');
        if (Array.isArray(saved.order)) order.value = [...saved.order.filter((v: unknown): v is string => typeof v === 'string'), ...props.columns.map(c => c.key).filter(k => !saved.order.includes(k))];
    } catch { /* use defaults */ }
}
watch([visible, order], () => { try { window.localStorage.setItem(storageKey, JSON.stringify({ visible: visible.value, order: order.value })); } catch { /* storage optional */ } }, { deep: true });
const shown = computed(() => order.value.map(key => props.columns.find(c => c.key === key)).filter((c): c is Column => !!c && visible.value.includes(c.key)));
const displayed = computed(() => props.rows.filter(row => !props.clientSearch || Object.values(row).some(v => String(v ?? '').toLowerCase().includes(search.value.trim().toLowerCase()))).sort((a,b) => { if (!sortKey.value) return 0; const v = String(a[sortKey.value] ?? '').localeCompare(String(b[sortKey.value] ?? ''), undefined, { numeric: true }); return descending.value ? -v : v; }));
const sort = (key: string) => { descending.value = sortKey.value === key && !descending.value; sortKey.value = key; };
const move = (key: string, step: number) => { const i = order.value.indexOf(key); const j = i + step; if (j < 0 || j >= order.value.length) return; const next = [...order.value]; [next[i], next[j]] = [next[j], next[i]]; order.value = next; };
const exporting = ref(false);
const exportCsv = async () => {
    if (exporting.value) return;
    exporting.value = true;
    try {
        if (props.beforeExport && !(await props.beforeExport(displayed.value))) return;
        const escape = (value: unknown) => `"${String(value ?? '').replace(/"/g, '""')}"`;
        const csv = [shown.value.map(c => escape(c.label)).join(','), ...displayed.value.map(row => shown.value.map(c => escape(row[c.key])).join(','))].join('\r\n');
        const url = URL.createObjectURL(new Blob(['\ufeff', csv], { type: 'text/csv;charset=utf-8' }));
        const link = document.createElement('a'); link.href = url; link.download = `${props.storageKey}.csv`; link.click(); window.setTimeout(() => URL.revokeObjectURL(url), 0);
    } finally { exporting.value = false; }
};
</script>
<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-end gap-2">
            <input v-if="clientSearch" v-model="search" type="search" aria-label="Search records" placeholder="Search records…" class="min-w-48 flex-1 rounded-md border bg-background px-3 py-2 text-sm" />
            <button type="button" class="rounded-md border px-3 py-2 text-sm hover:bg-accent" :aria-expanded="settingsOpen" @click="settingsOpen = !settingsOpen">Column Settings</button>
            <button type="button" class="rounded-md border px-3 py-2 text-sm hover:bg-accent" :disabled="exporting" @click="exportCsv">{{ exporting ? 'Checking access…' : 'Export CSV' }}</button>
            <button v-if="canAdd" type="button" class="rounded-md bg-primary px-3 py-2 text-sm text-primary-foreground" @click="emit('add')">+ {{ addLabel }}</button>
        </div>
        <div v-if="settingsOpen" class="rounded-xl border bg-muted/30 p-4">
            <h3 class="mb-2 text-sm font-semibold">Visible columns and order</h3>
            <div v-for="column in order.map(k => columns.find(c => c.key === k)).filter((c): c is Column => !!c)" :key="column.key" class="flex items-center gap-3 border-b py-2 text-sm last:border-0">
                <label class="flex flex-1 items-center gap-2"><input v-model="visible" type="checkbox" :value="column.key" />{{ column.label }}</label>
                <button type="button" class="rounded border px-2 py-1" :aria-label="`Move ${column.label} up`" @click="move(column.key, -1)">↑</button>
                <button type="button" class="rounded border px-2 py-1" :aria-label="`Move ${column.label} down`" @click="move(column.key, 1)">↓</button>
            </div>
        </div>
        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-left text-sm">
                <thead class="bg-muted/60"><tr><th v-for="column in shown" :key="column.key" scope="col" class="whitespace-nowrap px-4 py-3 font-semibold"><button type="button" class="hover:underline" @click="sort(column.key)">{{ column.label }} <span aria-hidden="true">{{ sortKey === column.key ? (descending ? '↓' : '↑') : '↕' }}</span></button></th><th scope="col" class="px-4 py-3 text-right font-semibold">Actions</th></tr></thead>
                <tbody><tr v-for="(row, i) in displayed" :key="row.id ?? i" class="border-t hover:bg-muted/30"><td v-for="column in shown" :key="column.key" class="px-4 py-3"><slot :name="`cell-${column.key}`" :row="row">{{ row[column.key] ?? '—' }}</slot></td><td class="px-4 py-3 text-right"><slot name="actions" :row="row" /></td></tr><tr v-if="!displayed.length"><td :colspan="shown.length + 1" class="px-4 py-8 text-center text-muted-foreground">No matching records.</td></tr></tbody>
            </table>
        </div>
        <p class="text-xs text-muted-foreground">Showing {{ displayed.length }} of {{ rows.length }} records{{ clientSearch ? '' : ' on this page' }}</p>
    </div>
</template>
