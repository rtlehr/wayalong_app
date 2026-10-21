<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

type Item = { current_version?: number; versions?: { id: number; number: number; filename: string; notes?: string | null; created_at?: string | null }[];  review_status?: string; review_notes?: string | null; reviewed_at?: string | null; review_events?: { status: string; notes: string | null; created_at: string }[]; id: number; title: string; category: string; original_filename: string; file_size: number; expires_on: string | null; created_at: string | null };
const props = defineProps<{ documents: Item[]; uploadUrl: string; downloadBase: string; canUpload: boolean }>();
const reviewing = ref<number | null>(null);
const replacing = ref<number | null>(null);
const replacement = useForm<{file: File | null; notes: string}>({file: null, notes: ''});
function saveReplacement(id: number): void {
    replacement.post(`${props.downloadBase}/${id}/replace`, {
        forceFormData: true,
        onSuccess: () => { replacing.value = null; replacement.reset(); },
    });
}
const review = useForm({status: 'verified', notes: ''});
function saveReview(id: number): void {
    review.post(`${props.downloadBase}/${id}/review`, {onSuccess: () => { reviewing.value = null; review.reset(); }});
}
const upload = useForm<{ title: string; category: string; expires_on: string; file: File | null }>({ title: '', category: 'other', expires_on: '', file: null });
const fileInput = ref<HTMLInputElement | null>(null);
function send(): void {
    upload.post(props.uploadUrl, { forceFormData: true, onSuccess: () => {
        upload.reset();
        if (fileInput.value) fileInput.value.value = '';
    }});
}
</script>

<template>
    <div class="space-y-5">
        <p class="text-sm text-muted-foreground">Documents are stored privately. Uploading evidence does not verify legal authority or grant portal access.</p>
        <div v-if="!documents.length" class="rounded-md border p-3 text-sm text-muted-foreground">No documents uploaded for this record.</div>
        <div v-else class="overflow-x-auto rounded-md border">
            <table class="w-full text-left text-sm">
                <thead class="border-b bg-muted/40"><tr><th class="px-3 py-2">Document</th><th class="px-3 py-2">Category</th><th class="px-3 py-2">Uploaded</th><th class="px-3 py-2">Expiration</th><th class="px-3 py-2">Review</th><th class="px-3 py-2">Actions</th></tr></thead>
                <tbody><tr v-for="document in documents" :key="document.id" class="border-b last:border-b-0">
                    <td class="px-3 py-2"><span class="font-medium">{{ document.title }}</span><span class="block text-muted-foreground">{{ document.original_filename }} · v{{ document.current_version ?? 1 }}</span>
                        <details v-if="document.versions?.length" class="mt-1 text-xs"><summary class="cursor-pointer underline">Version history</summary>
                            <div v-for="version in document.versions" :key="version.id" class="mt-1">
                                <a :href="`${downloadBase}/${document.id}/versions/${version.id}`" class="underline">v{{ version.number }} — {{ version.filename }}</a>
                                <span v-if="version.notes"> ({{ version.notes }})</span>
                            </div>
                        </details></td>
                    <td class="px-3 py-2 capitalize">{{ document.category }}</td>
                    <td class="px-3 py-2">{{ document.created_at?.slice(0,10) ?? '—' }}</td>
                    <td class="px-3 py-2"><span :class="document.expires_on && document.expires_on < new Date().toISOString().slice(0,10) ? 'text-destructive font-semibold' : ''">{{ document.expires_on || '—' }}</span><span v-if="document.expires_on && document.expires_on < new Date().toISOString().slice(0,10)" class="block text-destructive">Expired</span></td>
                    <td class="px-3 py-2"><span class="capitalize">{{ document.review_status || 'pending' }}</span><span v-if="document.reviewed_at" class="block text-xs text-muted-foreground">{{ document.reviewed_at.slice(0,10) }}</span><p v-if="document.review_notes" class="text-xs">{{ document.review_notes }}</p><details v-if="document.review_events?.length" class="text-xs"><summary>Review history</summary><p v-for="(event, index) in document.review_events" :key="index">{{ event.created_at.slice(0,10) }} — {{ event.status }} <span v-if="event.notes">({{ event.notes }})</span></p></details></td>
                    <td class="px-3 py-2"><a :href="`${downloadBase}/${document.id}`" class="underline underline-offset-4">Download</a><button v-if="canUpload" type="button" class="ml-3 underline" @click="reviewing = reviewing === document.id ? null : document.id">Review</button><button v-if="canUpload" type="button" class="ml-3 underline" @click="replacing = replacing === document.id ? null : document.id; replacement.reset()">Replace</button>
                        <div v-if="replacing === document.id && canUpload" class="mt-2 grid gap-2 min-w-44">
                            <label class="grid gap-1">Replacement file (10 MB max)<input type="file" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" class="rounded border p-1" @change="replacement.file = ($event.target as HTMLInputElement).files?.[0] ?? null" /></label>
                            <label class="grid gap-1">Change notes (optional)<textarea v-model="replacement.notes" maxlength="2000" class="rounded border p-1" /></label>
                            <p v-if="Object.keys(replacement.errors).length" class="text-destructive">{{ Object.values(replacement.errors).join(' ') }}</p>
                            <button type="button" class="rounded bg-primary p-1 text-primary-foreground disabled:opacity-50" :disabled="replacement.processing || !replacement.file" @click="saveReplacement(document.id)">Save replacement</button>
                        </div><div v-if="reviewing === document.id && canUpload" class="mt-2 grid gap-2 min-w-44"><select v-model="review.status" class="border rounded p-1"><option value="verified">Verified</option><option value="rejected">Rejected</option><option value="pending">Pending review</option></select><textarea v-model="review.notes" maxlength="2000" placeholder="Review notes (optional)" class="border rounded p-1" /><p v-if="review.errors.status || review.errors.notes" class="text-destructive">{{ review.errors.status || review.errors.notes }}</p><button type="button" :disabled="review.processing" class="rounded bg-primary text-primary-foreground p-1" @click="saveReview(document.id)">Save review</button></div></td>
                </tr></tbody>
            </table>
        </div>
        <div v-if="canUpload" class="space-y-3 rounded-md border p-4">
            <h3 class="font-semibold">Upload a document</h3>
            <div class="space-y-3">
                <label class="grid gap-1 text-sm">Title *<input v-model="upload.title" required maxlength="255" class="rounded-md border bg-background p-2" /></label>
                <label class="grid gap-1 text-sm">Category *<select v-model="upload.category" required class="rounded-md border bg-background p-2"><option v-for="category in ['custody','consent','treatment','insurance','identification','other']" :key="category" :value="category">{{ category.charAt(0).toUpperCase() + category.slice(1) }}</option></select></label>
                <label class="grid gap-1 text-sm">Expiration date (optional)<input v-model="upload.expires_on" type="date" class="rounded-md border bg-background p-2" /></label>
                <label class="grid gap-1 text-sm">File * (PDF, Word (.doc, .docx), JPG, PNG; maximum 10 MB)<input ref="fileInput" type="file" required accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" class="rounded-md border bg-background p-2" @change="upload.file = ($event.target as HTMLInputElement).files?.[0] ?? null" /></label>
                <div v-if="Object.keys(upload.errors).length" class="text-sm text-destructive" role="alert"><p v-for="(error, key) in upload.errors" :key="key">{{ error }}</p></div>
                <button type="button" @click="send" :disabled="upload.processing || !upload.file || !upload.title" class="rounded-md bg-primary px-4 py-2 text-sm text-primary-foreground disabled:opacity-50">{{ upload.processing ? 'Uploading…' : 'Upload document' }}</button>
            </div>
        </div>
    </div>
</template>
