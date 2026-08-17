import { api } from '@/shared/api/client';

/**
 * Roles the business defines for itself.
 *
 * Super admin only. The server asks that directly rather than through an
 * ability, so there is nothing here to hide behind `auth.can()` - a non-admin
 * simply never reaches the screen.
 */

export interface AbilityOption { key: string; label: string }
export interface AbilityModule { module: string; abilities: AbilityOption[] }

export interface BaseRole {
    value: string;
    label: string;
    sees_all_sectors: boolean;
    /** What this role grants before anybody customises it. */
    abilities: string[];
}

export interface CustomRole {
    id: string;
    name: string;
    description: string | null;
    base_role: string;
    base_role_label: string;
    abilities: string[];
    by_module: Record<string, string[]>;
    status: boolean;
    users_count: number;
}

export async function fetchCatalogue(): Promise<{
    data: AbilityModule[];
    base_roles: BaseRole[];
    note: string;
}> {
    const { data } = await api.get('/roles/catalogue');
    return data;
}

export async function fetchRoles(): Promise<CustomRole[]> {
    const { data } = await api.get('/roles');
    return data.data;
}

export interface RoleInput {
    name: string;
    description: string | null;
    base_role: string;
    abilities: string[];
    status: boolean;
}

export async function saveRole(input: RoleInput, id?: string | null): Promise<CustomRole> {
    const { data } = id
        ? await api.patch(`/roles/${id}`, input)
        : await api.post('/roles', input);

    return data.data;
}

export async function deleteRole(id: string): Promise<string> {
    const { data } = await api.delete(`/roles/${id}`);
    return data.message;
}

/** Give somebody a role, or take it away by passing null. */
export async function assignRole(userId: string, roleId: string | null): Promise<string> {
    const { data } = await api.post(`/users/${userId}/role`, { custom_role_id: roleId });
    return data.message;
}
