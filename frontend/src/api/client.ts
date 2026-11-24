export interface ApiRequestOptions extends RequestInit {
  accessToken?: string | null;
  branchId?: string | null;
}

export async function apiRequest<T>(
  path: string,
  options: ApiRequestOptions = {}
): Promise<T> {
  const { accessToken, branchId, headers, ...rest } = options;

  const mergedHeaders: Record<string, string> = {
    'Content-Type': 'application/json',
    Accept: 'application/json',
    ...(headers as Record<string, string> | undefined),
  };

  if (accessToken) {
    mergedHeaders.Authorization = `Bearer ${accessToken}`;
  }

  if (branchId) {
    mergedHeaders['X-Branch-Id'] = branchId;
  }

  const response = await fetch(`/api/v1${path}`, {
    ...rest,
    headers: mergedHeaders,
  });

  const payload = await response.json().catch(() => ({}));

  if (!response.ok) {
    const message =
      payload?.error?.message || `HTTP Error ${response.status}`;
    throw new Error(message);
  }

  return payload as T;
}