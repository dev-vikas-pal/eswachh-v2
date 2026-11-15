<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useQuery, useQueryClient } from '@tanstack/vue-query';
import { api } from '@/shared/api/client';
import { refreshAfter } from '@/shared/api/refresh';
import { describeError } from '@/shared/api/client';
import { useAuthStore } from '@/shared/stores/auth';
import RichTextEditor from '@/admin/components/RichTextEditor.vue';
import ImageField from '@/admin/components/ImageField.vue';
import { exportAllTemplates, exportOneTemplate } from '@/admin/shared/templateExport';

const auth = useAuthStore();

interface MasterMeta {
    key: string;
    label: string;
    singular: string;
    group: string;
    parent: { key: string; master: string; label: string } | null;
    /** Labels for the levels shown above the parent - Area, City for a society. */
    lineage: string[];
    money: string[];
    columns: string[];
    rich: string[];
    long: string[];
    dates: string[];
    images: string[];
    title_field: string;
    fields: string[];
    /** Whether this master is assigned to people, so the form shows the picker. */
    staff: boolean;
}

interface MasterRow {
    id: string;
    name: string;
    status: boolean;
    withdrawn: boolean;
    parent_id?: string | null;
    /** The sector a society is in, the state a city is in - whatever the parent is called. */
    parent_name?: string | null;
    /** The levels above the parent, matching MasterMeta.lineage. */
    lineage_names?: (string | null)[];
    [key: string]: unknown;
}

const queryClient = useQueryClient();

const selected = ref<string>('states');
const parentId = ref<string>('');
const search = ref('');
const includeWithdrawn = ref(false);
const editing = ref<MasterRow | null>(null);
// eslint-disable-next-line @typescript-eslint/no-explicit-any
const form = ref<Record<string, any>>({});
const formError = ref<string | null>(null);
const saving = ref(false);

/** Which lists exist. Comes from the server so the menu cannot drift. */
const { data: catalogue } = useQuery({
    queryKey: ['masters'],
    queryFn: async (): Promise<{ data: MasterMeta[] }> => (await api.get('/masters')).data,
    staleTime: Infinity,
});

const masters = computed(() => catalogue.value?.data ?? []);
const current = computed(() => masters.value.find((m) => m.key === selected.value) ?? null);

const groups = computed(() => {
    const out: Record<string, MasterMeta[]> = {};
    for (const m of masters.value) (out[m.group] ??= []).push(m);
    return out;
});

const isSectors = computed(() => selected.value === 'sectors');

/**
 * The message wording is the one master that also lives somewhere else.
 *
 * Every other list here is the whole of itself. These are edited here and then
 * have to be approved by MSG91 before a word of the change reaches anybody, so
 * this screen is also where the files for that are downloaded.
 */
const isMessageTemplates = computed(() => selected.value === 'message-templates');

const exporting = ref<string | null>(null);
const exportProblem = ref<string | null>(null);
const exportWarning = ref<string | null>(null);
const smsFor = ref<string | null>(null);
const smsProblem = ref<string | null>(null);
const smsNotice = ref<string | null>(null);

async function runExport(what: string, download: () => Promise<{ ok: boolean; warning?: string; message?: string }>) {
    exporting.value = what;
    exportProblem.value = null;
    exportWarning.value = null;

    const result = await download();
    exporting.value = null;

    if (result.ok) {
        // Set when the button in those files points at a machine no customer's
        // phone can reach. The download still happened; uploading it is the
        // mistake, so this is a caution rather than an error.
        exportWarning.value = result.warning ?? null;
        return;
    }

    exportProblem.value = result.message ?? 'The export could not be downloaded.';
}

const exportAll = () => runExport('all', exportAllTemplates);

const exportRow = (row: MasterRow) =>
    runExport(row.id, () => exportOneTemplate(row.id, (row.provider_template as string) ?? null));

/**
 * Send this wording to Twilio as an SMS template.
 *
 * Does not touch the WhatsApp name or the MSG91 file. A second click replaces
 * the stored id with a new template, because Twilio will not edit one.
 */
async function createSmsTemplate(row: MasterRow) {
    const replacing = Boolean(row.sms_content_sid);
    const question = replacing
        ? `Replace the SMS template for “${row.name}”? The words on this screen stay as they are.`
        : `Create an SMS template on Twilio for “${row.name}”? WhatsApp and the MSG91 export are not changed.`;

    if (!window.confirm(question)) return;

    smsFor.value = row.id;
    smsProblem.value = null;
    smsNotice.value = null;

    try {
        const response = await api.post(`/message-templates/${row.id}/sms`);
        smsNotice.value = `SMS template ${response.data.data.sms_content_sid} is saved for ${row.name}.`;
        await refreshAfter(queryClient, 'masters');
    } catch (error) {
        smsProblem.value = describeError(error).message;
    } finally {
        smsFor.value = null;
    }
}

/**
 * "On sale" is right for a package and wrong for a sector, a state or a blog
 * tag - none of which are sold.
 */
const onLabel = computed(() => (current.value?.group === 'Price list' ? 'On sale' : 'Active'));

/**
 * The line under the table.
 *
 * It used to say "prices here feed every quote" on all twenty lists, including
 * the ones with no price on them at all - which read as a warning about
 * something the screen was not doing.
 */
const footnote = computed(() => {
    if (isSectors.value) {
        return 'A sector is the territory. Whoever is ticked here sees the customers in it — that is '
            + 'the whole rule, and changing it takes effect immediately without moving any customer.';
    }

    if (current.value?.group === 'Price list') {
        return 'Prices here feed every quote. Changing one changes what new plans and renewals cost, '
            + 'for every branch — running plans keep the price they were sold at.';
    }

    if (isMessageTemplates.value) {
        return 'Editing the words here changes what this system records and shows, but not what WhatsApp '
            + 'delivers: that wording is approved by MSG91 first. Export the template, upload the file on '
            + 'MSG91’s Templates screen under Import, and the change goes live once it is approved. '
            + 'Create SMS template sends the same words to Twilio as a text template. It does not register '
            + 'them on India’s DLT portal, which is still required before an Indian number can receive the SMS.';
    }

    return null;
});

/** The parent list, when this master hangs off another one. */
const { data: parentRows } = useQuery({
    queryKey: computed(() => ['masters', current.value?.parent?.master, 'options']),
    enabled: computed(() => !!current.value?.parent),
    queryFn: async (): Promise<{ data: MasterRow[] }> =>
        (await api.get(`/masters/${current.value!.parent!.master}`)).data,
});

const { data, isPending, isError, error, isFetching } = useQuery({
    queryKey: computed(() => ['masters', selected.value, parentId.value, search.value, includeWithdrawn.value]),
    queryFn: async (): Promise<{ data: MasterRow[]; meta: MasterMeta }> =>
        (
            await api.get(`/masters/${selected.value}`, {
                params: {
                    parent_id: parentId.value || undefined,
                    search: search.value || undefined,
                    include_withdrawn: includeWithdrawn.value ? 1 : undefined,
                },
            })
        ).data,
});

const rows = computed(() => data.value?.data ?? []);

/** The label field this master uses: name, headline or question. */
const titleField = computed(() => current.value?.title_field ?? 'name');

/**
 * Fields the form asks for: everything except the title, which has its own
 * control, and the parent key. Who covers a sector is not a field at all - it
 * is rows in a pivot - so it gets a picker of its own below.
 */
const formFields = computed(() =>
    (current.value?.fields ?? []).filter((f) => f !== titleField.value),
);

/**
 * Columns the table shows. A master that names its own gets those; otherwise
 * every field, which is right for the short ones and wrong for a banner.
 */
const tableColumns = computed(() => {
    const named = current.value?.columns ?? [];
    const fields = named.length ? named : (current.value?.fields ?? []);
    const columns = fields.filter((f) => f !== titleField.value);

    /*
     * Who covers a sector belongs in the table, not only in the form.
     *
     * "Which sectors has nobody got" is the question this screen exists to
     * answer, and it should be readable without opening every row.
     */
    return current.value?.staff ? [...columns, 'staff_names'] : columns;
});

// A different list means the old parent filter is meaningless.
watch(selected, () => {
    parentId.value = '';
    search.value = '';
    editing.value = null;
});

/** Money is edited in rupees and sent in paise, so nobody types a paise figure. */
function toPaise(rupees: unknown): number {
    return Math.round(Number(rupees ?? 0) * 100);
}

function rupeeField(field: string): string {
    return field.replace('_paise', '');
}

function startNew() {
    formError.value = null;
    editing.value = { id: '', name: '', status: true, withdrawn: false } as MasterRow;
    form.value = { [titleField.value]: '', status: true };
    for (const f of formFields.value) {
        form.value[f] = current.value?.money.includes(f) ? 0 : '';
    }
    if (current.value?.parent) form.value[current.value.parent.key] = parentId.value || '';
}

function startEdit(row: MasterRow) {
    formError.value = null;
    editing.value = row;
    form.value = { [titleField.value]: row.name, status: row.status };
    for (const f of formFields.value) {
        form.value[f] = current.value?.money.includes(f)
            ? Number(row[rupeeField(f)] ?? 0)
            : (row[f] ?? '');
    }
    if (current.value?.parent) form.value[current.value.parent.key] = row.parent_id ?? '';
}

async function save() {
    if (!current.value || !editing.value) return;

    saving.value = true;
    formError.value = null;

    const payload: Record<string, unknown> = {
        [titleField.value]: form.value[titleField.value],
        status: form.value.status,
    };

    for (const f of formFields.value) {
        payload[f] = current.value.money.includes(f) ? toPaise(form.value[f]) : form.value[f];
    }
    if (current.value.parent) payload[current.value.parent.key] = form.value[current.value.parent.key];

    try {
        let notice: string | null = null;

        if (editing.value.id) {
            const { data } = await api.patch(`/masters/${selected.value}/${editing.value.id}`, payload);
            notice = data.notice ?? null;
        } else {
            await api.post(`/masters/${selected.value}`, payload);
        }
        editing.value = null;

        if (notice) alert(notice);
        await refreshAfter(queryClient, 'masters');
        await refreshOwnSectors();
    } catch (e) {
        formError.value = describeError(e).message;
    } finally {
        saving.value = false;
    }
}

/**
 * An administrator can assign a sector to themselves, and the session they are
 * reading it through was fetched at sign-in.
 *
 * Without this, changing your own assignment leaves every other screen showing
 * the old territory until a reload nobody would think to do.
 */
async function refreshOwnSectors(): Promise<void> {
    if (isSectors.value) await auth.loadSession();
}

async function withdraw(row: MasterRow) {
    const question = isSectors.value
        // Withdrawing a sector is not a withdrawal from sale, and the question
        // should not imply that plans carry on as though nothing happened.
        ? `Withdraw the sector "${row.name}"? This is refused while customers still live in it.`
        : `Withdraw "${row.name}" from sale? Plans already using it are unaffected.`;

    if (!confirm(question)) return;

    try {
        const { data } = await api.delete(`/masters/${selected.value}/${row.id}`);
        if (data.in_use > 0) alert(data.message);
        await refreshAfter(queryClient, 'masters');
        await refreshOwnSectors();
    } catch (e) {
        // The server refuses some of these with a reason worth reading - a
        // sector that still has customers in it. Swallowing it left the row
        // sitting there with no explanation.
        alert(describeError(e).message);
    }
}

async function restore(row: MasterRow) {
    await api.post(`/masters/${selected.value}/${row.id}/restore`);
    await refreshAfter(queryClient, 'masters');
}
</script>

<template>
    <div class="flex flex-col gap-4 lg:flex-row">
        <!-- Which list. Grouped, because geography and prices are different jobs. -->
        <nav class="shrink-0 lg:w-56">
            <div v-for="(items, group) in groups" :key="group" class="mb-4">
                <h2 class="mb-1.5 px-1 text-xs font-medium uppercase tracking-wide text-muted">{{ group }}</h2>
                <div class="flex flex-wrap gap-1 lg:flex-col">
                    <button
                        v-for="item in items"
                        :key="item.key"
                        type="button"
                        class="rounded px-3 py-1.5 text-left text-sm font-medium transition"
                        :class="selected === item.key
                            ? 'bg-accent-soft text-accent-ink'
                            : 'text-body hover:bg-sunk hover:text-ink'"
                        @click="selected = item.key"
                    >
                        {{ item.label }}
                    </button>
                </div>
            </div>
        </nav>

        <div class="min-w-0 flex-1">
            <div class="mb-4 flex flex-wrap items-center gap-3">
                <h1 class="text-xl font-semibold tracking-tight text-ink">{{ current?.label ?? 'Masters' }}</h1>
                <span v-if="isFetching" class="text-xs text-faint">updating…</span>

                <!--
                    Downloading the wording as files MSG91 will import.

                    Beside the Add button rather than tucked into a menu: after
                    editing a message this is the step that makes the change
                    real, and one that is easy to forget is one that leaves the
                    customer reading last month's wording.
                -->
                <button
                    v-if="isMessageTemplates"
                    type="button"
                    class="ms-auto rounded border border-line px-3 py-1.5 text-sm font-medium text-body transition hover:bg-sunk focus:outline-none focus:ring-2 focus:ring-accent disabled:opacity-60"
                    :disabled="exporting !== null"
                    @click="exportAll"
                >
                    {{ exporting === 'all' ? 'Preparing…' : 'Export all for MSG91' }}
                </button>

                <button
                    type="button"
                    class="rounded bg-accent px-3 py-1.5 text-sm font-medium text-on-accent transition hover:brightness-110 focus:outline-none focus:ring-2 focus:ring-accent"
                    :class="isMessageTemplates ? '' : 'ms-auto'"
                    @click="startNew"
                >
                    Add {{ current?.singular?.toLowerCase() }}
                </button>
            </div>

            <p v-if="exportProblem" class="mb-4 rounded border border-crit bg-crit-soft px-3 py-2 text-sm text-crit">
                {{ exportProblem }}
            </p>

            <p v-if="exportWarning" class="mb-4 rounded border border-warn bg-warn-soft px-3 py-2 text-sm text-warn">
                {{ exportWarning }}
            </p>

            <p v-if="smsProblem" class="mb-4 rounded border border-crit bg-crit-soft px-3 py-2 text-sm text-crit">
                {{ smsProblem }}
            </p>

            <p v-if="smsNotice" class="mb-4 rounded border border-ok bg-ok-soft px-3 py-2 text-sm text-ok">
                {{ smsNotice }}
            </p>

            <div class="mb-4 flex flex-wrap items-end gap-3 rounded-lg border border-line bg-surface p-3">
                <label v-if="current?.parent">
                    <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">
                        {{ current.parent.label }}
                    </span>
                    <select
                        v-model="parentId"
                        class="rounded border border-line-strong px-2 py-1.5 text-sm focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                    >
                        <option value="">All</option>
                        <option v-for="p in parentRows?.data ?? []" :key="p.id" :value="p.id">{{ p.name }}</option>
                    </select>
                </label>

                <label>
                    <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">Search</span>
                    <input
                        v-model.trim="search"
                        type="search"
                        class="rounded border border-line-strong px-3 py-1.5 text-sm focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                    />
                </label>

                <label class="flex items-center gap-2 pb-1.5 text-sm text-body">
                    <input v-model="includeWithdrawn" type="checkbox" class="rounded border-line-strong" />
                    Show withdrawn
                </label>
            </div>

            <p v-if="isError" class="rounded border border-crit bg-crit-soft px-3 py-2 text-sm text-crit">
                {{ describeError(error).message }}
            </p>

            <div v-else class="overflow-x-auto rounded-lg border border-line bg-surface">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-line text-left text-xs uppercase tracking-wide text-muted">
                            <th class="px-3 py-2 font-medium">Name</th>
                            <th v-if="current?.parent" class="px-3 py-2 font-medium">{{ current.parent.label }}</th>
                            <th v-for="label in current?.lineage ?? []" :key="label" class="px-3 py-2 font-medium">{{ label }}</th>
                            <th v-for="f in tableColumns"
                                :key="f" class="px-3 py-2 font-medium">
                                <template v-if="f === 'staff_names'">Covered by</template>
                                <template v-else>
                                    {{ current?.money.includes(f) ? rupeeField(f).replace('_', ' ') + ' (₹)' : f.replace('_', ' ') }}
                                </template>
                            </th>
                            <th class="px-3 py-2 font-medium">Status</th>
                            <th class="px-3 py-2 text-right font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="isPending">
                            <td colspan="9" class="px-3 py-6 text-center text-muted">Loading…</td>
                        </tr>
                        <tr v-else-if="!rows.length">
                            <td colspan="9" class="px-3 py-6 text-center text-muted">Nothing here yet.</td>
                        </tr>
                        <tr
                            v-for="row in rows"
                            :key="row.id"
                            class="border-b border-line last:border-0 hover:bg-sunk"
                            :class="row.withdrawn ? 'opacity-60' : ''"
                        >
                            <td class="px-3 py-2 font-medium text-ink">{{ row.name }}</td>
                            <td v-if="current?.parent" class="px-3 py-2 text-body">{{ row.parent_name ?? '—' }}</td>
                            <td v-for="(label, i) in current?.lineage ?? []" :key="label" class="px-3 py-2 text-body">
                                {{ row.lineage_names?.[i] ?? '—' }}
                            </td>
                            <td v-for="f in tableColumns"
                                :key="f" class="px-3 py-2 tabular-nums text-body">
                                <span v-if="current?.rich.includes(f)" class="line-clamp-2 block max-w-md">
                                    {{ row[f + '_text'] || '—' }}
                                </span>
                                <template v-else-if="current?.money.includes(f)">{{ row[rupeeField(f)] }}</template>

                                <!-- Nobody assigned is worth noticing, not a dash. -->
                                <span
                                    v-else-if="f === 'staff_names'"
                                    :class="row.staff_names ? 'text-body' : 'rounded bg-warn-soft px-2 py-0.5 text-xs text-warn'"
                                >
                                    {{ row.staff_names || 'Nobody' }}
                                </span>

                                <template v-else>{{ row[f] ?? '—' }}</template>
                            </td>
                            <td class="px-3 py-2">
                                <span
                                    class="rounded px-2 py-0.5 text-xs font-medium"
                                    :class="row.withdrawn
                                        ? 'bg-crit-soft text-crit'
                                        : row.status ? 'bg-ok-soft text-ok' : 'bg-warn-soft text-warn'"
                                >
                                    {{ row.withdrawn ? 'Removed' : row.status ? onLabel : 'Off' }}
                                </span>
                            </td>
                            <td class="px-3 py-2 text-right whitespace-nowrap">
                                <!--
                                    One template's file. What gets used after
                                    the first upload: templates are approved one
                                    at a time, so only the one whose wording
                                    changed needs submitting again.
                                -->
                                <button
                                    v-if="isMessageTemplates && !row.withdrawn"
                                    type="button"
                                    class="rounded px-2 py-1 text-xs font-medium text-body hover:bg-sunk disabled:opacity-60"
                                    :disabled="exporting !== null"
                                    @click="exportRow(row)"
                                >
                                    {{ exporting === row.id ? 'Preparing…' : 'Export' }}
                                </button>
                                <button
                                    v-if="isMessageTemplates && !row.withdrawn"
                                    type="button"
                                    class="rounded px-2 py-1 text-xs font-medium text-body hover:bg-sunk disabled:opacity-60"
                                    :title="row.sms_content_sid ? String(row.sms_content_sid) : 'Create this wording as a Twilio SMS template'"
                                    :disabled="smsFor !== null"
                                    @click="createSmsTemplate(row)"
                                >
                                    {{ smsFor === row.id ? 'Creating…' : row.sms_content_sid ? 'Update SMS' : 'Create SMS' }}
                                </button>
                                <button
                                    v-if="!row.withdrawn"
                                    type="button"
                                    class="rounded px-2 py-1 text-xs font-medium text-accent-ink hover:bg-accent-soft"
                                    @click="startEdit(row)"
                                >
                                    Edit
                                </button>
                                <button
                                    v-if="!row.withdrawn"
                                    type="button"
                                    class="rounded px-2 py-1 text-xs font-medium text-crit hover:bg-crit-soft"
                                    @click="withdraw(row)"
                                >
                                    Withdraw
                                </button>
                                <button
                                    v-else
                                    type="button"
                                    class="rounded px-2 py-1 text-xs font-medium text-ok hover:bg-ok-soft"
                                    @click="restore(row)"
                                >
                                    {{ isSectors ? 'Put back' : 'Put back on sale' }}
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p v-if="footnote" class="mt-3 text-xs text-muted">{{ footnote }}</p>
        </div>

        <!-- Edit panel -->
        <div v-if="editing" class="fixed inset-0 z-40 overflow-y-auto bg-black/30 p-4 pt-16">
            <div class="mx-auto flex max-h-[calc(100dvh-5rem)] w-full max-w-md flex-col overflow-hidden rounded-lg border border-line-strong bg-surface shadow-xl">
                <h2 class="shrink-0 px-4 pt-4 text-lg font-semibold text-ink">
                    {{ editing.id ? 'Edit' : 'Add' }} {{ current?.singular?.toLowerCase() }}
                </h2>

                <form class="flex min-h-0 flex-1 flex-col" @submit.prevent="save">
                    <div class="flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto px-4 py-3">
                    <label v-if="current?.parent">
                        <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">
                            {{ current.parent.label }}
                        </span>
                        <select
                            v-model="form[current.parent.key]"
                            required
                            class="w-full rounded border border-line-strong px-3 py-2 text-sm focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                        >
                            <option value="">Choose…</option>
                            <option v-for="p in parentRows?.data ?? []" :key="p.id" :value="p.id">{{ p.name }}</option>
                        </select>
                    </label>

                    <label>
                        <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">
                            {{ titleField.replace('_', ' ') }}
                        </span>
                        <input
                            v-model.trim="form[titleField]"
                            type="text"
                            required
                            class="w-full rounded border border-line-strong px-3 py-2 text-sm focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                        />
                    </label>

                    <!--
                        Who covers this sector is shown, not edited.

                        Territory is assigned on the person, under People,
                        because that is the moment it matters: an account
                        created without one signs in to empty screens. Here it
                        is just the answer to "has anybody got this sector".
                    -->
                    <p v-if="current?.staff" class="rounded border border-line bg-sunk px-3 py-2 text-xs text-muted">
                        <template v-if="editing?.staff_names">
                            Covered by <span class="text-ink">{{ editing.staff_names }}</span>.
                        </template>
                        <template v-else>
                            Nobody covers this sector yet, so its customers are invisible to every
                            franchise user and cleaner.
                        </template>
                        Assign it on the <span class="text-ink">People</span> screen.
                    </p>

                    <label v-for="f in formFields" :key="f">
                        <span class="mb-1 block text-xs font-medium uppercase tracking-wide text-muted">
                            {{ f === 'sms_content_sid'
                                ? 'Twilio SMS SID'
                                : current?.money.includes(f) ? rupeeField(f).replace('_', ' ') + ' (₹)' : f.replace('_', ' ') }}
                        </span>
                        <ImageField v-if="current?.images?.includes(f)" v-model="form[f]" :folder="selected" />
                        <RichTextEditor v-else-if="current?.rich.includes(f)" v-model="form[f]" />
                        <textarea
                            v-else-if="current?.long.includes(f)"
                            v-model="form[f]"
                            rows="4"
                            class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                        />
                        <input
                            v-else-if="current?.dates.includes(f)"
                            v-model="form[f]"
                            type="datetime-local"
                            class="w-full rounded border border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                        />
                        <input
                            v-else
                            v-model="form[f]"
                            :type="current?.money.includes(f) || f === 'months' || f === 'cloth_count' ? 'number' : 'text'"
                            :step="current?.money.includes(f) ? '0.01' : '1'"
                            min="0"
                            class="w-full rounded border border-line-strong px-3 py-2 text-sm tabular-nums focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"
                        />
                        <span v-if="f === 'sms_content_sid'" class="mt-1 block text-xs text-muted">
                            Paste an existing HX id. An SMS is sent with this SID, not with the provider template name.
                            Leave it empty to send the words as plain text. WhatsApp still uses the name above.
                        </span>
                    </label>

                    <label class="flex items-center gap-2 text-sm text-body">
                        <input v-model="form.status" type="checkbox" class="rounded border-line-strong" />
                        On sale
                    </label>

                    <p v-if="formError" class="rounded bg-crit-soft px-3 py-2 text-sm text-crit">{{ formError }}</p>
                    </div>

                    <div class="flex shrink-0 gap-2 border-t border-line px-4 py-3">
                        <button
                            type="submit"
                            :disabled="saving"
                            class="rounded bg-accent px-4 py-2 text-sm font-medium text-on-accent transition hover:brightness-110 disabled:opacity-60 focus:outline-none focus:ring-2 focus:ring-accent"
                        >
                            {{ saving ? 'Saving…' : 'Save' }}
                        </button>
                        <button
                            type="button"
                            class="rounded border border-line-strong px-4 py-2 text-sm text-body transition hover:bg-sunk"
                            @click="editing = null"
                        >
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
