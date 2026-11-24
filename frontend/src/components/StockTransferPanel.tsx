import React, { useState } from 'react';
import { enqueuePosOperation } from '../api/offlineQueue';
import {
  createStockTransfer,
  receiveStockTransfer,
  shipStockTransfer,
} from '../api/posApi';
import { BranchItem } from './BranchSwitcher';

interface StockTransferPanelProps {
  organizationId: string;
  branches: BranchItem[];
  sourceBranchId: string | null;
  accessToken: string | null;
}

interface TransferResultData {
  transfer?: {
    id?: string;
    transfer_number?: string;
    status?: string;
  };
  id?: string;
  transfer_number?: string;
  status?: string;
}

export const StockTransferPanel: React.FC<StockTransferPanelProps> = ({
  organizationId,
  branches,
  sourceBranchId,
  accessToken,
}) => {
  const [destinationBranchId, setDestinationBranchId] = useState<string>('');
  const [productId, setProductId] = useState<string>('');
  const [quantity, setQuantity] = useState<string>('1');
  const [note, setNote] = useState<string>('');
  const [transferId, setTransferId] = useState<string>('');
  const [transferInfo, setTransferInfo] = useState<TransferResultData | null>(null);
  const [loading, setLoading] = useState<boolean>(false);
  const [errorMsg, setErrorMsg] = useState<string | null>(null);
  const [queuedMsg, setQueuedMsg] = useState<string | null>(null);

  const extractId = (res: unknown): string => {
    const obj = res as { data?: TransferResultData } & TransferResultData;
    return (
      obj?.data?.transfer?.id ||
      obj?.data?.id ||
      obj?.transfer?.id ||
      obj?.id ||
      ''
    );
  };

  const handleCreate = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!sourceBranchId || !destinationBranchId) {
      setErrorMsg('กรุณาเลือกสาขาต้นทางและสาขาปลายทาง');
      return;
    }
    if (sourceBranchId === destinationBranchId) {
      setErrorMsg('สาขาปลายทางต้องไม่ซ้ำกับสาขาต้นทาง');
      return;
    }

    const createPayload = {
      organization_id: organizationId,
      source_branch_id: sourceBranchId,
      destination_branch_id: destinationBranchId,
      items: [{ product_id: productId.trim(), quantity: Number(quantity) }],
      note: note || undefined,
    };

    setLoading(true);
    setErrorMsg(null);
    setQueuedMsg(null);

    if (typeof navigator !== 'undefined' && !navigator.onLine) {
      try {
        const queued = await enqueuePosOperation({
          method: 'POST',
          path: '/stock-transfers',
          body: createPayload,
          branchId: sourceBranchId,
        });
        setQueuedMsg(`ออฟไลน์: เก็บคำสั่งสร้างใบโอนย้ายลงคิวแล้ว (Queue ID: ${queued.id})`);
      } catch (err) {
        setErrorMsg(err instanceof Error ? err.message : 'บันทึกคิวโอนย้ายไม่สำเร็จ');
      } finally {
        setLoading(false);
      }
      return;
    }

    try {
      const res = await createStockTransfer(createPayload, {
        accessToken,
        branchId: sourceBranchId,
      });
      const newId = extractId(res);
      if (newId) setTransferId(newId);
      setTransferInfo((res as { data?: TransferResultData }).data ?? (res as TransferResultData));
    } catch (err) {
      try {
        const queued = await enqueuePosOperation({
          method: 'POST',
          path: '/stock-transfers',
          body: createPayload,
          branchId: sourceBranchId,
        });
        setQueuedMsg(`เครือข่ายขัดข้อง: เก็บคำสั่งสร้างใบโอนย้ายลงคิวแล้ว (Queue ID: ${queued.id})`);
      } catch {
        setErrorMsg(err instanceof Error ? err.message : 'สร้างใบโอนย้ายไม่สำเร็จ');
      }
    } finally {
      setLoading(false);
    }
  };

  const handleShip = async () => {
    if (!transferId.trim() || !sourceBranchId) return;
    setLoading(true);
    setErrorMsg(null);
    setQueuedMsg(null);

    if (typeof navigator !== 'undefined' && !navigator.onLine) {
      try {
        const queued = await enqueuePosOperation({
          method: 'POST',
          path: `/stock-transfers/${transferId.trim()}/ship`,
          body: {},
          branchId: sourceBranchId,
        });
        setQueuedMsg(`ออฟไลน์: เก็บคำสั่งส่งออกใบโอนย้ายลงคิวแล้ว (Queue ID: ${queued.id})`);
      } catch (err) {
        setErrorMsg(err instanceof Error ? err.message : 'บันทึกคิวส่งออกไม่สำเร็จ');
      } finally {
        setLoading(false);
      }
      return;
    }

    try {
      const res = await shipStockTransfer(transferId.trim(), {
        accessToken,
        branchId: sourceBranchId,
      });
      setTransferInfo((res as { data?: TransferResultData }).data ?? (res as TransferResultData));
    } catch (err) {
      try {
        const queued = await enqueuePosOperation({
          method: 'POST',
          path: `/stock-transfers/${transferId.trim()}/ship`,
          body: {},
          branchId: sourceBranchId,
        });
        setQueuedMsg(`เครือข่ายขัดข้อง: เก็บคำสั่งส่งออกใบโอนย้ายลงคิวแล้ว (Queue ID: ${queued.id})`);
      } catch {
        setErrorMsg(err instanceof Error ? err.message : 'ส่งออกใบโอนย้ายไม่สำเร็จ');
      }
    } finally {
      setLoading(false);
    }
  };

  const handleReceive = async () => {
    const targetBranch = destinationBranchId || sourceBranchId;
    if (!transferId.trim() || !targetBranch) return;
    setLoading(true);
    setErrorMsg(null);
    setQueuedMsg(null);

    if (typeof navigator !== 'undefined' && !navigator.onLine) {
      try {
        const queued = await enqueuePosOperation({
          method: 'POST',
          path: `/stock-transfers/${transferId.trim()}/receive`,
          body: {},
          branchId: targetBranch,
        });
        setQueuedMsg(`ออฟไลน์: เก็บคำสั่งรับเข้าใบโอนย้ายลงคิวแล้ว (Queue ID: ${queued.id})`);
      } catch (err) {
        setErrorMsg(err instanceof Error ? err.message : 'บันทึกคิวรับเข้าไม่สำเร็จ');
      } finally {
        setLoading(false);
      }
      return;
    }

    try {
      const res = await receiveStockTransfer(transferId.trim(), {
        accessToken,
        branchId: targetBranch,
      });
      setTransferInfo((res as { data?: TransferResultData }).data ?? (res as TransferResultData));
    } catch (err) {
      try {
        const queued = await enqueuePosOperation({
          method: 'POST',
          path: `/stock-transfers/${transferId.trim()}/receive`,
          body: {},
          branchId: targetBranch,
        });
        setQueuedMsg(`เครือข่ายขัดข้อง: เก็บคำสั่งรับเข้าใบโอนย้ายลงคิวแล้ว (Queue ID: ${queued.id})`);
      } catch {
        setErrorMsg(err instanceof Error ? err.message : 'รับเข้าใบโอนย้ายไม่สำเร็จ');
      }
    } finally {
      setLoading(false);
    }
  };

  const activeTransfer = transferInfo?.transfer ?? transferInfo;

  return (
    <div style={{ border: '1px solid #d1d5db', borderRadius: '8px', padding: '16px' }}>
      <h3>โอนย้ายสต็อกระหว่างสาขา</h3>

      {errorMsg && (
        <div style={{ color: '#b91c1c', marginBottom: '12px' }}>{errorMsg}</div>
      )}

      {queuedMsg && (
        <div style={{ color: '#92400e', backgroundColor: '#fffbeb', padding: '8px 12px', borderRadius: '6px', marginBottom: '12px' }}>
          {queuedMsg}
        </div>
      )}

      <form onSubmit={handleCreate} style={{ display: 'grid', gap: '8px', maxWidth: '420px' }}>
        <label>
          สาขาปลายทาง:
          <select
            value={destinationBranchId}
            onChange={(e) => setDestinationBranchId(e.target.value)}
            required
          >
            <option value="" disabled>
              -- เลือกสาขาปลายทาง --
            </option>
            {branches
              .filter((b) => b.id !== sourceBranchId)
              .map((b) => (
                <option key={b.id} value={b.id}>
                  [{b.code}] {b.name}
                </option>
              ))}
          </select>
        </label>

        <label>
          รหัสสินค้า (Product ID):
          <input
            type="text"
            value={productId}
            onChange={(e) => setProductId(e.target.value)}
            required
          />
        </label>

        <label>
          จำนวน:
          <input
            type="number"
            min="1"
            step="1"
            value={quantity}
            onChange={(e) => setQuantity(e.target.value)}
            required
          />
        </label>

        <label>
          หมายเหตุ:
          <input
            type="text"
            value={note}
            onChange={(e) => setNote(e.target.value)}
          />
        </label>

        <button type="submit" disabled={loading || !sourceBranchId}>
          {loading ? 'กำลังสร้าง...' : 'สร้างใบโอนย้าย (DRAFT)'}
        </button>
      </form>

      <div style={{ marginTop: '16px', display: 'grid', gap: '8px', maxWidth: '420px' }}>
        <label>
          รหัสใบโอนย้าย (Transfer ID):
          <input
            type="text"
            value={transferId}
            onChange={(e) => setTransferId(e.target.value)}
            placeholder="กรอกหรือรับอัตโนมัติหลังสร้าง"
          />
        </label>

        <div style={{ display: 'flex', gap: '8px' }}>
          <button type="button" onClick={handleShip} disabled={loading || !transferId.trim()}>
            ส่งออก (SHIPPED)
          </button>
          <button type="button" onClick={handleReceive} disabled={loading || !transferId.trim()}>
            รับเข้า (RECEIVED)
          </button>
        </div>
      </div>

      {activeTransfer && (
        <div style={{ marginTop: '12px', paddingTop: '12px', borderTop: '1px solid #e5e7eb' }}>
          <div>
            <strong>เลขที่ใบโอน:</strong> {activeTransfer.transfer_number ?? '-'} |{' '}
            <strong>สถานะ:</strong> {activeTransfer.status ?? '-'}
          </div>
        </div>
      )}
    </div>
  );
};