<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

type Facility = { id: number; name: string; code: string; status: string; address_line_1: string | null; address_line_2: string | null; city: string | null; state: string | null; postal_code: string | null; phone: string | null; email: string | null; timezone: string | null };
type Member = { id: number; name: string; email: string; role: string; status: string };
const props = defineProps<{ organization: { id: number; name: string; slug: string; status: string; timezone: string }; facilities: Facility[]; members: Member[] }>();
const base = '/organization/admin';
const settings = useForm({ name: props.organization.name, timezone: props.organization.timezone });
const blank = () => ({ name: '', code: '', status: 'active', address_line_1: '', address_line_2: '', city: '', state: '', postal_code: '', phone: '', email: '', timezone: '' });
const facility = useForm(blank());
const editing = ref<number | null>(null);
const edit = (item: Facility) => {
    editing.value = item.id;
    facility.clearErrors();
    facility.defaults({ ...blank(), ...Object.fromEntries(Object.entries(item).map(([k, v]) => [k, v ?? ''])) });
    facility.reset();
};
const clear = () => { editing.value = null; facility.defaults(blank()); facility.reset(); facility.clearErrors(); };
const saveFacility = () => {
    if (editing.value !== null) facility.patch(`${base}/facilities/${editing.value}`, { onSuccess: clear, preserveScroll: true });
    else facility.post(`${base}/facilities`, { onSuccess: clear, preserveScroll: true });
};
const remove = (item: Facility) => {
    if (window.confirm(`Delete ${item.name}?`)) useForm({}).delete(`${base}/facilities/${item.id}`, { preserveScroll: true });
};
</script>

<template>
    <Head title="Organization administration" />
    <div class="mx-auto w-full max-w-5xl space-y-8 p-4 md:p-8">
        <header>
            <h1 class="text-2xl font-semibold">Organization administration</h1>
            <p class="text-sm text-muted-foreground">Manage {{ organization.name }} and its facilities. Staff memberships are read-only in this increment.</p>
        </header>

        <section class="space-y-4 rounded-xl border p-5">
            <h2 class="text-lg font-semibold">Organization settings</h2>
            <form class="grid gap-4 md:grid-cols-2" @submit.prevent="settings.patch(`${base}/settings`, { preserveScroll: true })">
                <label class="grid gap-1 text-sm">Name<input v-model="settings.name" required maxlength="255" class="rounded-md border bg-background p-2" /><span class="text-red-600">{{ settings.errors.name }}</span></label>
                <label class="grid gap-1 text-sm">Timezone<input v-model="settings.timezone" required placeholder="America/New_York" class="rounded-md border bg-background p-2" /><span class="text-red-600">{{ settings.errors.timezone }}</span></label>
                <div><button class="rounded-md bg-primary px-4 py-2 text-primary-foreground" :disabled="settings.processing">Save settings</button></div>
            </form>
        </section>

        <section class="space-y-4 rounded-xl border p-5">
            <h2 class="text-lg font-semibold">Facilities ({{ facilities.length }})</h2>
            <div v-if="facilities.length === 0" class="text-sm text-muted-foreground">No facilities yet.</div>
            <div v-for="item in facilities" :key="item.id" class="flex flex-wrap items-center justify-between gap-3 border-b py-3">
                <div><strong>{{ item.name }}</strong><div class="text-sm text-muted-foreground">{{ item.code }} · {{ item.status }} · {{ [item.city, item.state].filter(Boolean).join(', ') }}</div></div>
                <div class="flex gap-2"><button class="rounded-md border px-3 py-1" @click="edit(item)">Edit</button><button class="rounded-md border px-3 py-1 text-red-600" @click="remove(item)">Delete</button></div>
            </div>
            <h3 class="font-medium">{{ editing === null ? 'Add facility' : 'Edit facility' }}</h3>
            <form class="grid gap-3 md:grid-cols-2" @submit.prevent="saveFacility">
                <label v-for="field in (['name','code','address_line_1','address_line_2','city','state','postal_code','phone','email','timezone'] as const)" :key="field" class="grid gap-1 text-sm">
                    {{ field.replaceAll('_',' ') }}
                    <input v-model="facility[field]" :required="field === 'name' || field === 'code'" :maxlength="field === 'state' ? 2 : field === 'code' ? 50 : 255" class="rounded-md border bg-background p-2" />
                    <span class="text-red-600">{{ facility.errors[field] }}</span>
                </label>
                <label class="grid gap-1 text-sm">Status<select v-model="facility.status" class="rounded-md border bg-background p-2"><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
                <div class="flex items-end gap-2"><button class="rounded-md bg-primary px-4 py-2 text-primary-foreground" :disabled="facility.processing">{{ editing === null ? 'Add facility' : 'Save changes' }}</button><button v-if="editing !== null" type="button" class="rounded-md border px-4 py-2" @click="clear">Cancel</button></div>
            </form>
        </section>

        <section class="space-y-3 rounded-xl border p-5">
            <h2 class="text-lg font-semibold">Staff memberships ({{ members.length }})</h2>
            <div v-for="member in members" :key="member.id" class="flex justify-between gap-4 border-b py-2 text-sm"><span>{{ member.name }} <span class="text-muted-foreground">{{ member.email }}</span></span><span>{{ member.role }} · {{ member.status }}</span></div>
        </section>
    </div>
</template>
