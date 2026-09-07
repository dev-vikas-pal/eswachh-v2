import { api, describeError } from '@/shared/api/client';

/**
 * Downloading the message wording as files MSG91 will import.
 *
 * MSG91's Templates screen has an Import button that takes one JSON file at a
 * time and accepts nothing but a WhatsApp exported template, so the server
 * produces exactly that. This half is only the plumbing: ask for the file, hand
 * it to the browser, and pass on the warning the server attaches when the files
 * would be wrong to upload.
 *
 * Here rather than in the view because there are decisions in it - which name
 * to save under, what to do with a failure that arrives as a blob rather than
 * as JSON - and a component should bind and render, not decide.
 */

export interface ExportResult {
    ok: boolean;
    /** Shown as a caution when the files are downloadable but not uploadable. */
    warning?: string;
    message?: string;
}

/**
 * The filename the server asked for, if it named one.
 *
 * The fallback matters: without it a failed parse saves the file as the last
 * path segment, so a zip of every template would land as `export`.
 */
function filenameFrom(disposition: string | undefined, fallback: string): string {
    const match = /filename="?([^";]+)"?/i.exec(disposition ?? '');

    return match?.[1] ?? fallback;
}

/**
 * Hand a downloaded blob to the browser.
 *
 * The object URL is revoked afterwards; without that the whole file stays in
 * memory for the life of the tab, which on a page somebody exports from
 * repeatedly adds up.
 */
function save(blob: Blob, filename: string): void {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');

    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();

    URL.revokeObjectURL(url);
}

/**
 * A failure that arrived as a blob.
 *
 * The request asks for a file, so axios hands back a Blob whatever the status -
 * including the 422 that says there is nothing to export. Read as text and
 * parsed, or the customer-facing message would be "[object Blob]".
 */
async function messageFromBlob(error: unknown): Promise<string> {
    const response = (error as { response?: { data?: unknown } })?.response;
    const data = response?.data;

    if (data instanceof Blob) {
        try {
            const parsed = JSON.parse(await data.text());

            if (typeof parsed?.message === 'string') return parsed.message;
        } catch {
            // Not JSON. Fall through to the generic description below.
        }
    }

    return describeError(error).message;
}

async function download(url: string, fallbackName: string): Promise<ExportResult> {
    try {
        const response = await api.get(url, { responseType: 'blob' });

        save(
            response.data as Blob,
            filenameFrom(response.headers['content-disposition'] as string | undefined, fallbackName),
        );

        return {
            ok: true,
            // Set by the server when the button in these files points at a
            // machine no customer's phone can reach - see the controller.
            warning: (response.headers['x-export-warning'] as string | undefined) || undefined,
        };
    } catch (error) {
        return { ok: false, message: await messageFromBlob(error) };
    }
}

/** Every template, zipped. The first upload, when there are seventeen of them. */
export function exportAllTemplates(): Promise<ExportResult> {
    return download('/message-templates/export', 'eswachh-templates.zip');
}

/**
 * One template, as the file to upload for it.
 *
 * The one used day to day: templates are approved one at a time, and after the
 * first upload only the one whose wording changed needs submitting again.
 */
export function exportOneTemplate(id: string, providerName: string | null): Promise<ExportResult> {
    return download(`/message-templates/${id}/export`, `${providerName ?? 'template'}.json`);
}
