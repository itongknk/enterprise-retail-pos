import { ApiRequestOptions, apiRequest } from './client';

export type UserStatus = 'ACTIVE' | 'INACTIVE' | 'LOCKED';

export interface ManagedUser {
  id: string;
  organization_id: string;
  employee_code: string;
  email: string | null;
  full_name: string;
  is_org_admin: boolean;
  status: UserStatus;
  failed_login_attempts?: number;
  locked_until?: string | null;
  created_at?: string;
  updated_at?: string;
}

export interface UsersListResponse {
  data?: ManagedUser[];
  items?: ManagedUser[];
}

export interface UserMutationResponse {
  message?: string;
  data?: ManagedUser;
}

export interface CreateUserPayload {
  employee_code: string;
  email: string;
  full_name: string;
  password: string;
  pin?: string | null;
  status?: UserStatus;
  is_org_admin?: boolean;
  branch_id?: string | null;
  role_id?: string | null;
}

export interface UpdateUserPayload {
  employee_code?: string;
  email?: string;
  full_name?: string;
  password?: string | null;
  pin?: string | null;
  status?: UserStatus;
  is_org_admin?: boolean;
}

export function listUsers<T = UsersListResponse | ManagedUser[]>(
  auth: ApiRequestOptions
): Promise<T> {
  return apiRequest<T>('/users', {
    ...auth,
    method: 'GET',
  });
}

export function getUserById<T = UserMutationResponse | ManagedUser>(
  userId: string,
  auth: ApiRequestOptions
): Promise<T> {
  return apiRequest<T>(`/users/${encodeURIComponent(userId)}`, {
    ...auth,
    method: 'GET',
  });
}

export function createUser<T = UserMutationResponse>(
  payload: CreateUserPayload,
  auth: ApiRequestOptions
): Promise<T> {
  return apiRequest<T>('/users', {
    ...auth,
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function updateUser<T = UserMutationResponse>(
  userId: string,
  payload: UpdateUserPayload,
  auth: ApiRequestOptions
): Promise<T> {
  return apiRequest<T>(`/users/${encodeURIComponent(userId)}`, {
    ...auth,
    method: 'PATCH',
    body: JSON.stringify(payload),
  });
}