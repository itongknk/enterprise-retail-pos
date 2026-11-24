import { ApiRequestOptions, apiRequest } from './client';

export interface QueuedPosOperation {
  id: string;
  createdAt: string;
  method: 'POST' | 'PUT' | 'PATCH';
  path: string;
  body: unknown;
  branchId?: string | null;
  retryCount: number;
  lastError?: string | null;
}

const DB_NAME = 'enterprise_retail_pos_db';
const DB_VERSION = 1;
const STORE_NAME = 'sync_queue';

function openQueueDb(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, DB_VERSION);

    request.onupgradeneeded = () => {
      const db = request.result;
      if (!db.objectStoreNames.contains(STORE_NAME)) {
        const store = db.createObjectStore(STORE_NAME, { keyPath: 'id' });
        store.createIndex('createdAt', 'createdAt', { unique: false });
      }
    };

    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error ?? new Error('เปิด IndexedDB ไม่สำเร็จ'));
  });
}

function generateOpId(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID();
  }
  return `op-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
}

export async function enqueuePosOperation(
  op: Omit<QueuedPosOperation, 'id' | 'createdAt' | 'retryCount' | 'lastError'>
): Promise<QueuedPosOperation> {
  const db = await openQueueDb();
  const record: QueuedPosOperation = {
    ...op,
    id: generateOpId(),
    createdAt: new Date().toISOString(),
    retryCount: 0,
    lastError: null,
  };

  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE_NAME, 'readwrite');
    const store = tx.objectStore(STORE_NAME);
    const req = store.add(record);

    req.onsuccess = () => resolve(record);
    req.onerror = () => reject(req.error ?? new Error('บันทึกคิวออฟไลน์ไม่สำเร็จ'));
    tx.oncomplete = () => db.close();
  });
}

export async function listQueuedOperations(): Promise<QueuedPosOperation[]> {
  const db = await openQueueDb();

  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE_NAME, 'readonly');
    const store = tx.objectStore(STORE_NAME);
    const req = store.getAll();

    req.onsuccess = () => {
      const rows = (req.result as QueuedPosOperation[]) ?? [];
      rows.sort((a, b) => a.createdAt.localeCompare(b.createdAt));
      resolve(rows);
    };
    req.onerror = () => reject(req.error ?? new Error('อ่านคิวออฟไลน์ไม่สำเร็จ'));
    tx.oncomplete = () => db.close();
  });
}

async function removeQueuedOperation(id: string): Promise<void> {
  const db = await openQueueDb();

  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE_NAME, 'readwrite');
    const store = tx.objectStore(STORE_NAME);
    const req = store.delete(id);

    req.onsuccess = () => resolve();
    req.onerror = () => reject(req.error ?? new Error('ลบรายการคิวไม่สำเร็จ'));
    tx.oncomplete = () => db.close();
  });
}

async function updateQueuedOperationError(item: QueuedPosOperation, errorMessage: string): Promise<void> {
  const db = await openQueueDb();
  const updated: QueuedPosOperation = {
    ...item,
    retryCount: item.retryCount + 1,
    lastError: errorMessage,
  };

  return new Promise((resolve, reject) => {
    const tx = db.transaction(STORE_NAME, 'readwrite');
    const store = tx.objectStore(STORE_NAME);
    const req = store.put(updated);

    req.onsuccess = () => resolve();
    req.onerror = () => reject(req.error ?? new Error('อัปเดตสถานะคิวไม่สำเร็จ'));
    tx.oncomplete = () => db.close();
  });
}

export interface FlushQueueResult {
  syncedCount: number;
  failedCount: number;
  remainingCount: number;
}

export async function flushOfflineQueue(
  auth: Omit<ApiRequestOptions, 'method' | 'body' | 'branchId'> & {
    defaultBranchId?: string | null;
  }
): Promise<FlushQueueResult> {
  if (typeof navigator !== 'undefined' && !navigator.onLine) {
    const current = await listQueuedOperations();
    return { syncedCount: 0, failedCount: 0, remainingCount: current.length };
  }

  const pending = await listQueuedOperations();
  let syncedCount = 0;
  let failedCount = 0;

  for (const item of pending) {
    try {
      await apiRequest(item.path, {
        ...auth,
        branchId: item.branchId ?? auth.defaultBranchId ?? null,
        method: item.method,
        body: JSON.stringify(item.body),
      });
      await removeQueuedOperation(item.id);
      syncedCount++;
    } catch (err) {
      const msg = err instanceof Error ? err.message : 'ซิงก์ล้มเหลว';
      await updateQueuedOperationError(item, msg);
      failedCount++;
    }
  }

  const remaining = await listQueuedOperations();
  return {
    syncedCount,
    failedCount,
    remainingCount: remaining.length,
  };
}