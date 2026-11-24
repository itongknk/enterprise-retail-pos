import React, { useState } from 'react';
import { enqueuePosOperation } from '../api/offlineQueue';
import { createSale, voidSale } from '../api/posApi';

interface SalesPanelProps {
  organizationId: string;
  branchId: string | null;
  accessToken: string | null;
}

interface SaleResultData {
  sale?: {
    id?: string;
    sale_number?: string;
    status?: string;
    total_amount?: string | number;
    shift_id?: string | null;
  };
  id?: string;
  sale_number?: string;
  status?: string;
  total_amount?: string | number;
  shift_id?: string | null;
}

export const SalesPanel: React.FC<SalesPanelProps> = ({
  organizationId,
  branchId,
  accessToken,
}) => {
  const [productId, setProductId] = useState<string>('');
  const [quantity, setQuantity] = useState<string>('1');
  const [discountAmount, setDiscountAmount] = useState<string>('0');
  const [paymentMethod, setPaymentMethod] = useState<string>('CASH');
  const [amountTendered, setAmountTendered] = useState<string>('0');
  const [note, setNote] = useState<string>('');

  const [saleId, setSaleId] = useState<string>('');
  const [voidReason, setVoidReason] = useState<string>('');
  const [saleInfo, setSaleInfo] = useState<SaleResultData | null>(null);
  const [loading, setLoading] = useState<boolean>(false);
  const [errorMsg, setErrorMsg] = useState<string | null>(null);
  const [queuedMsg, setQueuedMsg] = useState<string | null>(null);

  const auth = { accessToken, branchId };

  const extractSaleId = (res: unknown): string => {
    const obj = res as { data?: SaleResultData } & SaleResultData;
    return obj?.data?.sale?.id || obj?.data?.id || obj?.sale?.id || obj?.id || '';
  };

  const handleCreateSale = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!branchId) {
      setErrorMsg('กรุณาเลือกสาขาก่อนบันทึกการขาย');
      return;
    }

    const salePayload = {
      organization_id: organizationId,
      branch_id: branchId,
      items: [{ product_id: productId.trim(), quantity: Number(quantity) }],
      discount_amount: Number(discountAmount) || 0,
      payments: [
        {
          payment_method: paymentMethod,
          amount_tendered: Number(amountTendered),
        },
      ],
      note: note || undefined,
    };

    setLoading(true);
    setErrorMsg(null);
    setQueuedMsg(null);

    const isOfflineNow = typeof navigator !== 'undefined' && !navigator.onLine;
    if (isOfflineNow) {
      try {
        const queued = await enqueuePosOperation({
          method: 'POST',
          path: '/sales',
          body: salePayload,
          branchId,
        });
        setQueuedMsg(`ออฟไลน์: บันทึกคำสั่งขายลงคิวรอซิงก์แล้ว (Queue ID: ${queued.id})`);
      } catch (err) {
        setErrorMsg(err instanceof Error ? err.message : 'บันทึกคิวออฟไลน์ไม่สำเร็จ');
      } finally {
        setLoading(false);
      }
      return;
    }

    try {
      const res = await createSale(salePayload, auth);
      const newId = extractSaleId(res);
      if (newId) setSaleId(newId);
      setSaleInfo((res as { data?: SaleResultData }).data ?? (res as SaleResultData));
    } catch (err) {
      try {
        const queued = await enqueuePosOperation({
          method: 'POST',
          path: '/sales',
          body: salePayload,
          branchId,
        });
        setQueuedMsg(`เครือข่ายขัดข้อง: เก็บคำสั่งขายลงคิวรอซิงก์แล้ว (Queue ID: ${queued.id})`);
      } catch {
        setErrorMsg(err instanceof Error ? err.message : 'บันทึกการขายไม่สำเร็จ');
      }
    } finally {
      setLoading(false);
    }
  };

  const handleVoidSale = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!saleId.trim() || !voidReason.trim()) {
      setErrorMsg('กรุณาระบุรหัสบิลและเหตุผลการยกเลิก');
      return;
    }

    const targetSaleId = saleId.trim();
    const voidPayload = { reason: voidReason.trim() };

    setLoading(true);
    setErrorMsg(null);
    setQueuedMsg(null);

    if (typeof navigator !== 'undefined' && !navigator.onLine) {
      try {
        const queued = await enqueuePosOperation({
          method: 'POST',
          path: `/sales/${targetSaleId}/void`,
          body: voidPayload,
          branchId,
        });
        setQueuedMsg(`ออฟไลน์: เก็บคำสั่งยกเลิกบิลลงคิวรอซิงก์แล้ว (Queue ID: ${queued.id})`);
      } catch (err) {
        setErrorMsg(err instanceof Error ? err.message : 'บันทึกคิวยกเลิกบิลไม่สำเร็จ');
      } finally {
        setLoading(false);
      }
      return;
    }

    try {
      const res = await voidSale(targetSaleId, voidPayload, auth);
      setSaleInfo((res as { data?: SaleResultData }).data ?? (res as SaleResultData));
    } catch (err) {
      try {
        const queued = await enqueuePosOperation({
          method: 'POST',
          path: `/sales/${targetSaleId}/void`,
          body: voidPayload,
          branchId,
        });
        setQueuedMsg(`เครือข่ายขัดข้อง: เก็บคำสั่งยกเลิกบิลลงคิวรอซิงก์แล้ว (Queue ID: ${queued.id})`);
      } catch {
        setErrorMsg(err instanceof Error ? err.message : 'ยกเลิกบิลไม่สำเร็จ');
      }
    } finally {
      setLoading(false);
    }
  };

  const activeSale = saleInfo?.sale ?? saleInfo;

  return (
    <div style={{ border: '1px solid #d1d5db', borderRadius: '8px', padding: '16px' }}>
      <h3>ขายสินค้าและยกเลิกบิล</h3>

      {errorMsg && (
        <div style={{ color: '#b91c1c', marginBottom: '12px' }}>{errorMsg}</div>
      )}

      {queuedMsg && (
        <div style={{ color: '#92400e', backgroundColor: '#fffbeb', padding: '8px 12px', borderRadius: '6px', marginBottom: '12px' }}>
          {queuedMsg}
        </div>
      )}

      <form onSubmit={handleCreateSale} style={{ display: 'grid', gap: '8px', maxWidth: '420px' }}>
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
          ส่วนลด (บาท):
          <input
            type="number"
            min="0"
            step="0.01"
            value={discountAmount}
            onChange={(e) => setDiscountAmount(e.target.value)}
          />
        </label>

        <label>
          วิธีชำระเงิน:
          <select value={paymentMethod} onChange={(e) => setPaymentMethod(e.target.value)}>
            <option value="CASH">เงินสด (CASH)</option>
            <option value="TRANSFER">เงินโอน (TRANSFER)</option>
            <option value="CARD">บัตร (CARD)</option>
          </select>
        </label>

        <label>
          ยอดรับชำระ (บาท):
          <input
            type="number"
            min="0"
            step="0.01"
            value={amountTendered}
            onChange={(e) => setAmountTendered(e.target.value)}
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
          {loading ? 'กำลังบันทึก...' : 'ชำระเงินและออกบิล'}
        </button>
      </form>

      <form
        onSubmit={handleVoidSale}
        style={{ marginTop: '16px', display: 'grid', gap: '8px', maxWidth: '420px' }}
      >
        <label>
          รหัสบิล (Sale ID):
          <input
            type="text"
            value={saleId}
            onChange={(e) => setSaleId(e.target.value)}
            placeholder="กรอกหรือรับอัตโนมัติหลังขาย"
          />
        </label>

        <label>
          เหตุผลการยกเลิกบิล (Void Reason):
          <input
            type="text"
            value={voidReason}
            onChange={(e) => setVoidReason(e.target.value)}
            placeholder="เช่น ลูกค้าเปลี่ยนรายการสินค้า"
          />
        </label>

        <button type="submit" disabled={loading || !saleId.trim()}>
          {loading ? 'กำลังยกเลิก...' : 'ยกเลิกบิล (Void Sale)'}
        </button>
      </form>

      {activeSale && (
        <div style={{ marginTop: '12px', paddingTop: '12px', borderTop: '1px solid #e5e7eb' }}>
          <div>
            <strong>เลขที่บิล:</strong> {activeSale.sale_number ?? '-'} |{' '}
            <strong>สถานะ:</strong> {activeSale.status ?? '-'}
          </div>
          <div>
            <strong>ยอดสุทธิ:</strong> {activeSale.total_amount ?? '-'} |{' '}
            <strong>ผูกกะ (Shift ID):</strong> {activeSale.shift_id ?? 'ไม่มีกะเปิดอยู่'}
          </div>
        </div>
      )}
    </div>
  );
};