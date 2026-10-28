<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
const props = defineProps<{
    facility: { id: number; name: string };
    member: { id: number; name: string };
    primaryRole: string;
    additionalRoles: string[];
    canAssignOwner: boolean;
}>();
const form = useForm({ facility_id: props.facility.id, primary_role: props.primaryRole, additional_roles: [...props.additionalRoles] });
const extra = [
    { value: 'clinician', label: 'Clinician' }, { value: 'floor_staff', label: 'Floor Staff' },
    { value: 'client_admin', label: 'Client Administrator' }, { value: 'staff', label: 'Staff' },
];
const toggle = (role: string, checked: boolean) => {
    form.additional_roles = checked ? [...form.additional_roles, role] : form.additional_roles.filter(r => r !== role);
};
const save = () => form.patch(`/facility/staff/${props.facility.id}/members/${props.member.id}/roles`, { preserveScroll: true });
</script>
<template>
    <Head :title="`Facility roles — ${member.name}`" />
    <main class="mx-auto w-full max-w-4xl space-y-5 p-4 md:p-8">
        <Link href="/facility/staff" class="text-sm underline">← Staff roster</Link>
        <header class="rounded-xl border bg-card p-5">
            <h1 class="text-2xl font-semibold">Facility roles</h1>
            <p class="text-sm text-muted-foreground">{{ member.name }} · {{ facility.name }}</p>
            <p class="mt-2 text-sm text-muted-foreground">Primary Owner/Admin roles control leadership. Additional roles grant ordinary access only in this facility.</p>
        </header>
        <form class="space-y-5 rounded-xl border bg-card p-5" @submit.prevent="save">
            <label class="grid gap-1 text-sm">Primary role
                <select v-model="form.primary_role" class="rounded-md border bg-background p-2">
                    <option value="staff">Staff</option><option value="clinician">Clinician</option>
                    <option value="floor_staff">Floor Staff</option><option value="client_admin">Client Administrator</option>
                    <option value="facility_manager">Facility Manager</option><option value="facility_admin">Facility Admin</option>
                    <option v-if="canAssignOwner || form.primary_role === 'facility_owner'" value="facility_owner">Facility Owner</option>
                </select>
            </label>
            <fieldset class="space-y-2"><legend class="font-medium">Additional roles</legend>
                <label v-for="option in extra" :key="option.value" class="flex items-center gap-2 text-sm">
                    <input type="checkbox" :checked="form.additional_roles.includes(option.value)" @change="toggle(option.value, ($event.target as HTMLInputElement).checked)" />{{ option.label }}
                </label>
            </fieldset>
            <p v-if="Object.keys(form.errors).length" role="alert" class="text-sm text-destructive">{{ Object.values(form.errors).join(' ') }}</p>
            <button type="submit" :disabled="form.processing" class="rounded-md bg-primary px-4 py-2 text-primary-foreground disabled:opacity-50">Save roles</button>
        </form>
    </main>
</template>
