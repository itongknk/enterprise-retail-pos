export interface ApiRequestOptions extends RequestInit {
  accessToken?: string | null;
  branchId?: string | null;
}

const rawBaseUrl =
  (import.meta as unknown as { env?: Record<string, string | undefined> }).env
    ?.VITE_API_BASE_URL ?? '';

export const API_BASE_URL = (rawBaseUrl || '/api/v1').replace(/\/+$/, '');

function extractErrorMessage(payload: unknown, status: number): string {
  if (payload && typeof payload === 'object') {
    const record = payload as {
      message?: unknown;
      error?: { message?: unknown };
      errors?: Record<string, unknown>;
    };

    const validationMessages: string[] = [];
    if (record.errors && typeof record.errors === 'object') {
      for (const val of Object.values(record.errors)) {
        if (Array.isArray(val)) {
          for (const item of val) {
            if (typeof item === 'string' && item.trim()) {
              validationMessages.push(item.trim());
            }
          }
        } else if (typeof val === 'string' && val.trim()) {
          validationMessages.push(val.trim());
        }
      }
    }

    const baseMessage =
      (typeof record.error?.message === 'string' && record.error.message.trim()) ||
      (typeof record.message === 'string' && record.message.trim()) ||
      `HTTP Error ${status}`;

    if (validationMessages.length > 0) {
      return `${baseMessage}: ${validationMessages.join(' | ')}`;
    }
    return baseMessage;
  }

  return `HTTP Error ${status}`;
}

export async function apiRequest<T>(
  path: string,
  options: ApiRequestOptions = {}
): Promise<T> {
  const { accessToken, branchId, headers, ...rest } = options;

  const mergedHeaders: Record<string, string> = {
    'Content-Type': 'application/json',
    Accept: 'application/json',
    ...((headers as Record<string, string> | undefined) ?? {}),
  };

  if (accessToken) {
    mergedHeaders.Authorization = `Bearer ${accessToken}`;
  }

  if (branchId) {
    mergedHeaders['X-Branch-Id'] = branchId;
  }

  const normalizedPath = path.startsWith('/') ? path : `/${path}`;
  const response = await fetch(`${API_BASE_URL}${normalizedPath}`, {
    ...rest,
    headers: mergedHeaders,
  });

  const payload = await response.json().catch(() => ({}));
  if (!response.ok) {
    throw new Error(extractErrorMessage(payload, response.status));
  }

  return payload as T;
}