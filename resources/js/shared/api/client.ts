import axios, { AxiosError } from 'axios';

/**
 * The one place the front end talks to the server.
 *
 * Authentication is the session cookie, so every request carries credentials
 * and the CSRF token. Nothing is kept in localStorage: a token stored there is
 * readable by any script that gets onto the page.
 */
export const api = axios.create({
    baseURL: '/api/v1',
    withCredentials: true,
    // axios reads the XSRF-TOKEN cookie Laravel sets and echoes it back as a
    // header. Required from axios 1.x, where it is no longer implicit.
    withXSRFToken: true,
    headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
});

/**
 * Ask Laravel to set the CSRF cookie.
 *
 * Called once before signing in. Note it is not under /api/v1, so it bypasses
 * the axios baseURL.
 */
export async function primeCsrf(): Promise<void> {
    await axios.get('/sanctum/csrf-cookie', { withCredentials: true });
}

export type ValidationErrors = Record<string, string[]>;

/**
 * Turn any failure into something a component can show a person.
 *
 * Server messages are used when they are meant for humans; anything else gets
 * a plain sentence rather than a stack trace or a status code.
 */
export function describeError(error: unknown): { message: string; errors: ValidationErrors } {
    const fallback = { message: 'Something went wrong. Please try again.', errors: {} };

    if (!(error instanceof AxiosError)) {
        return fallback;
    }

    if (!error.response) {
        return { message: 'Cannot reach the server. Check your connection.', errors: {} };
    }

    const { status, data } = error.response;

    if (status === 422) {
        return {
            message: data?.message ?? 'Please check the highlighted fields.',
            errors: (data?.errors ?? {}) as ValidationErrors,
        };
    }

    if (status === 401) return { message: 'Your session has ended. Please sign in again.', errors: {} };
    if (status === 403) return { message: 'You do not have access to that.', errors: {} };
    if (status === 404) return { message: 'That record could not be found.', errors: {} };
    if (status === 429) return { message: data?.message ?? 'Too many attempts. Please wait a moment.', errors: {} };

    return fallback;
}
