import React, { useState } from 'react';
import { enqueuePosOperation } from '../api/offlineQueue';
import {
  ShiftSummaryResponse,
  closeShift,
  getShiftSummary,
  openShift,
} from '../api/posApi';

interface ShiftPanelProps {
  organizationId: string;
  branchId: string | null;
  accessToken: string | null;
}

export const ShiftPanel: React.FC<ShiftPanelProps> = ({
  organizationId,
  branchId,
  accessToken,
}) => {
  const [openingCash, setOpeningCash] = useState<string>('1000');
  const [closingCash, setClosingCash] = useState<string>('0');
  const [note, setNote] = useState<string>('');
  const [shiftState, setShiftState] = useState<ShiftSummaryResponse['data'] | null>(null);
  const [loading, setLoading] = useState<boolean>(false);
  const [errorMsg, setErrorMsg] = useState<string | null>(null);
  const [queuedMsg, setQueuedMsg] = useState<string | null>(null);

  const auth = { accessToken, branchId };
  const currentShiftId = shiftState?.shift.id ?? null;

  const handleOpenShift = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!branchId) {
      setErrorMsg('กรุณาเลือกสาขาก่อนเปิดกะ');
      return;
    }

    const openPayload = {
      organization_id: organizationId,
      branch_id: branchId,
      opening_cash: Number(openingCash),
      note: note || undefined,
    };

    setLoading(true);
    setErrorMsg(null);
    setQueuedMsg(null);

    if (typeof navigator !== 'undefined' && !navigator.onLine) {
      try {
        const queued = await enqueuePosOperation({
          method: 'POST',
          path: '/shifts/open',
          body: openPayload,
          branchId,
        });
        setQueuedMsg(`ออฟไลน์: เก็บคำสั่งเปิดกะลงคิวรอซิงก์แล้ว (Queue ID: ${queued.id})`);
      } catch (err) {
        setErrorMsg(err instanceof Error ? err.message : 'บันทึกคิวเปิดกะไม่สำเร็จ');
      } finally {
        setLoading(false);
      }
      return;
    }

    try {
      const res = await openShift(openPayload, auth);
      setShiftState(res.data);
      setClosingCash(String(res.data.reconciliation.expected_cash));
    } catch (err) {
      try {
        const queued = await enqueuePosOperation({
          method: 'POST',
          path: '/shifts/open',
          body: openPayload,
          branchId,
        });
        setQueuedMsg(`เครือข่ายขัดข้อง: เก็บคำสั่งเปิดกะลงคิวรอซิงก์แล้ว (Queue ID: ${queued.id})`);
      } catch {
        setErrorMsg(err instanceof Error ? err.message : 'เปิดกะไม่สำเร็จ');
      }
    } finally {
      setLoading(false);
    }
  };

  const handleRefreshSummary = async () => {
    if (!currentShiftId) return;
    setLoading(true);
    setErrorMsg(null);
    setQueuedMsg(null);
    try {
      const res = await getShiftSummary(currentShiftId, auth);
      setShiftState(res.data);
    } catch (err) {
      setErrorMsg(err instanceof Error ? err.message : 'ดึงสรุปยอดกะไม่สำเร็จ');
    } finally {
      setLoading(false);
    }
  };

  const handleCloseShift = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!currentShiftId) return;

    const closePayload = {
      closing_cash_counted: Number(closingCash),
      note: note || undefined,
    };

    setLoading(true);
    setErrorMsg(null);
    setQueuedMsg(null);

    if (typeof navigator !== 'undefined' && !navigator.onLine) {
      try {
        const queued = await enqueuePosOperation({
          method: 'POST',
          path: `/shifts/${currentShiftId}/close`,
          body: closePayload,
          branchId,
        });
        setQueuedMsg(`ออฟไลน์: เก็บคำสั่งปิดกะลงคิวรอซิงก์แล้ว (Queue ID: ${queued.id})`);
      } catch (err) {
        setErrorMsg(err instanceof Error ? err.message : 'บันทึกคิวปิดกะไม่สำเร็จ');
      } finally {
        setLoading(false);
      }
      return;
    }

    try {
      const res = await closeShift(currentShiftId, closePayload, auth);
      setShiftState(res.data);
    } catch (err) {
      try {
        const queued = await enqueuePosOperation({
          method: 'POST',
          path: `/shifts/${currentShiftId}/close`,
          body: closePayload,
          branchId,
        });
        setQueuedMsg(`เครือข่ายขัดข้อง: เก็บคำสั่งปิดกะลงคิวรอซิงก์แล้ว (Queue ID: ${queued.id})`);
      } catch {
        setErrorMsg(err instanceof Error ? err.message : 'ปิดกะไม่สำเร็จ');
      }
    } finally {
      setLoading(false);
    }
  };

  return (
    <div style={{ border: '1px solid #d1d5db', borderRadius: '8px', padding: '16px' }}>
      <h3>จัดการกะและกระทบยอดเงินสด</h3>

      {errorMsg && (
        <div style={{ color: '#b91c1c', marginBottom: '12px' }}>{errorMsg}</div>
      )}

      {queuedMsg && (
        <div style={{ color: '#92400e', backgroundColor: '#fffbeb', padding: '8px 12px', borderRadius: '6px', marginBottom: '12px' }}>
          {queuedMsg}
        </div>
      )}

      {!shiftState || shiftState.shift.status !== 'OPEN' ? (
        <form onSubmit={handleOpenShift} style={{ display: 'grid', gap: '8px', maxWidth: '360px' }}>
          <label>
            เงินสดเปิดกะ:
            <input
              type="number"
              step="0.01"
              value={openingCash}
              onChange={(e) => setOpeningCash(e.target.value)}
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
          <button type="submit" disabled={loading || !branchId}>
            {loading ? 'กำลังเปิดกะ...' : 'เปิดกะ'}
          </button>
        </form>
      ) : (
        <div style={{ display: 'grid', gap: '12px' }}>
          <div>
            <strong>เลขที่กะ:</strong> {shiftState.shift.shift_number} ({shiftState.shift.status})
          </div>
          <div>
            <strong>เงินสดเปิดกะ:</strong> {shiftState.reconciliation.opening_cash} |{' '}
            <strong>บิลสำเร็จ:</strong> {shiftState.reconciliation.completed_sales_count} |{' '}
            <strong>บิลยกเลิก:</strong> {shiftState.reconciliation.voided_sales_count}
          </div>
          <div>
            <strong>ยอดขายรวม:</strong> {shiftState.reconciliation.gross_sales_amount} |{' '}
            <strong>เงินสดตามระบบ (Expected):</strong> {shiftState.reconciliation.expected_cash}
          </div>

          <div style={{ display: 'flex', gap: '8px' }}>
            <button type="button" onClick={handleRefreshSummary} disabled={loading}>
              รีเฟรชยอดกะ
            </button>
          </div>

          <form onSubmit={handleCloseShift} style={{ display: 'grid', gap: '8px', maxWidth: '360px' }}>
            <label>
              เงินสดนับจริงตอนปิดกะ:
              <input
                type="number"
                step="0.01"
                value={closingCash}
                onChange={(e) => setClosingCash(e.target.value)}
                required
              />
            </label>
            <button type="submit" disabled={loading}>
              {loading ? 'กำลังปิดกะ...' : 'ปิดกะและกระทบยอด'}
            </button>
          </form>
        </div>
      )}

      {shiftState && shiftState.shift.status === 'CLOSED' && (
        <div style={{ marginTop: '12px', paddingTop: '12px', borderTop: '1px solid #e5e7eb' }}>
          <div>
            <strong>ปิดกะเรียบร้อยแล้ว:</strong> {shiftState.shift.shift_number}
          </div>
          <div>
            <strong>เงินสดตามระบบ:</strong> {shiftState.reconciliation.expected_cash} |{' '}
            <strong>นับได้จริง:</strong> {shiftState.reconciliation.closing_cash_counted} |{' '}
            <strong>ผลต่าง (Variance):</strong> {shiftState.reconciliation.cash_variance}
          </div>
        </div>
      )}
    </div>
  );
};