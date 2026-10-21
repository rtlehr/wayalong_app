<script setup lang="ts">
import AdministrationForm from './Admin.vue';
const props = defineProps<{ organization: { id: number; name: string; slug: string; status: string; timezone: string }; facilities: any[]; members: any[]; customRoles: any[] }>();
</script>
<template>
  <AdministrationForm v-bind="props" page-mode="facilities" />
</template>
