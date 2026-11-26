import React, { useCallback, useEffect, useState } from 'react';
import { flushOfflineQueue, listQueuedOperations } from '../api/offlineQueue';

interface OfflineSyncBarProps {
  accessToken: string | null;
  branchId: string | null;
}

export const OfflineSyncBar: React.FC<OfflineSyncBarProps> = ({
  accessToken,
  branchId,
}) => {
  const [isOnline, setIsOnline] = useState<boolean>(
    typeof navigator !== 'undefined' ? navigator.onLine : true
  );
  const [pendingCount, setPendingCount] = useState<number>(0);
  const [syncing, setSyncing] = useState<boolean>(false);
  const [statusMsg, setStatusMsg] = useState<string | null>(null);

  const refreshCount = useCallback(async () => {
    try {
      const rows = await listQueuedOperations();
      setPendingCount(rows.length);
    } catch {
      // Ignore if IndexedDB is unavailable in non-browser environment
    }
  }, []);

  const handleSyncNow = useCallback(async () => {
    if (syncing) return;
    setSyncing(true);
    setStatusMsg(null);
    try {
      const result = await flushOfflineQueue({
        accessToken,
        defaultBranchId: branchId,
      });
      setPendingCount(result.remainingCount);
      if (result.syncedCount > 0 || result.failedCount > 0) {
        setStatusMsg(
          `Synced ${result.syncedCount} | Failed ${result.failedCount} | Remaining ${result.remainingCount}`
        );
      }
    } catch (err) {
      setStatusMsg(err instanceof Error ? err.message : 'Sync failed');
    } finally {
      setSyncing(false);
    }
  }, [accessToken, branchId, syncing]);

  useEffect(() => {
    refreshCount();

    const handleOnline = () => {
      setIsOnline(true);
      handleSyncNow();
    };
    const handleOffline = () => {
      setIsOnline(false);
    };

    window.addEventListener('online', handleOnline);
    window.addEventListener('offline', handleOffline);
    return () => {
      window.removeEventListener('online', handleOnline);
      window.removeEventListener('offline', handleOffline);
    };
  }, [refreshCount, handleSyncNow]);

  return (
    <div
      style={{
        display: 'flex',
        justifyContent: 'space-between',
        alignItems: 'center',
        padding: '10px 14px',
        borderRadius: '8px',
        backgroundColor: isOnline ? '#f0fdf4' : '#fef2f2',
        border: `1px solid ${isOnline ? '#86efac' : '#fca5a5'}`,
      }}
    >
      <div>
        <strong>Connection:</strong> {isOnline ? 'Online' : 'Offline'} |{' '}
        <strong>Pending Queue (IndexedDB):</strong> {pendingCount}
        {statusMsg && <div style={{ marginTop: '4px', fontSize: '0.9em' }}>{statusMsg}</div>}
      </div>

      <button
        type="button"
        onClick={handleSyncNow}
        disabled={syncing || !isOnline || pendingCount === 0}
      >
        {syncing ? 'Syncing...' : 'Sync Now'}
      </button>
    </div>
  );
};