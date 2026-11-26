import React, { useState } from 'react';
import { enqueuePosOperation } from '../api/offlineQueue';
import { createSale, CreateSalePayload, voidSale } from '../api/posApi';

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
  const [scanQuery, setScanQuery] = useState<string>('');
  const [lookupHint, setLookupHint] = useState<string | null>(null);
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

  const handleLookupInput = (rawValue: string) => {
    setScanQuery(rawValue);
    const cleaned = rawValue.trim();
    if (!cleaned) {
      setLookupHint(null);
      return;
    }
    setProductId(cleaned);
    setLookupHint(`Matched input "${cleaned}" (barcode -> SKU/code) into Product ID.`);
  };

  const handleScanKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Enter') {
      const cleaned = scanQuery.trim();
      if (cleaned) {
        setProductId(cleaned);
        setLookupHint(`Scanned/entered "${cleaned}" -> ready for sale.`);
      }
    }
  };

  const extractSaleId = (res: unknown): string => {
    const obj = res as { data?: SaleResultData } & SaleResultData;
    return obj?.data?.sale?.id || obj?.data?.id || obj?.sale?.id || obj?.id || '';
  };

  const handleCreateSale = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!branchId) {
      setErrorMsg('Please select a branch before creating a sale.');
      return;
    }

    const trimmedProductId = productId.trim();
    const parsedQty = Number(quantity);
    const parsedDiscount = Number(discountAmount || '0');
    const parsedTendered = Number(amountTendered || '0');

    if (!trimmedProductId || !Number.isFinite(parsedQty) || parsedQty <= 0) {
      setErrorMsg('Please enter a valid Product ID and quantity greater than 0.');
      return;
    }

    const normalizedDiscount =
      Number.isFinite(parsedDiscount) && parsedDiscount >= 0 ? parsedDiscount : 0;
    const normalizedTendered =
      Number.isFinite(parsedTendered) && parsedTendered >= 0 ? parsedTendered : 0;
    const trimmedNote = note.trim();

    const salePayload: CreateSalePayload = {
      organization_id: organizationId,
      branch_id: branchId,
      items: [
        {
          product_id: trimmedProductId,
          quantity: parsedQty,
        },
      ],
      discount_amount: normalizedDiscount,
      payments: [
        {
          payment_method: paymentMethod,
          amount_tendered: normalizedTendered,
        },
      ],
      ...(trimmedNote ? { note: trimmedNote } : {}),
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
        setQueuedMsg(`Offline mode: Sale queued for sync (Queue ID: ${queued.id})`);
      } catch (err) {
        setErrorMsg(err instanceof Error ? err.message : 'Failed to queue offline sale');
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
        setQueuedMsg(`Request failed, queued for sync: ${err instanceof Error ? err.message : 'Network error'} (Queue ID: ${queued.id})`);
      } catch {
        setErrorMsg(err instanceof Error ? err.message : 'Failed to create sale');
      }
    } finally {
      setLoading(false);
    }
  };

  const handleVoidSale = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!saleId.trim() || !voidReason.trim()) {
      setErrorMsg('Please enter both Sale ID and Void Reason.');
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
        setQueuedMsg(`Offline mode: Void sale queued for sync (Queue ID: ${queued.id})`);
      } catch (err) {
        setErrorMsg(err instanceof Error ? err.message : 'Failed to queue void sale');
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
        setQueuedMsg(`Request failed, queued for sync: ${err instanceof Error ? err.message : 'Network error'} (Queue ID: ${queued.id})`);
      } catch {
        setErrorMsg(err instanceof Error ? err.message : 'Failed to void sale');
      }
    } finally {
      setLoading(false);
    }
  };

  const activeSale = saleInfo?.sale ?? saleInfo;

  return (
    <div style={{ border: '1px solid #d1d5db', borderRadius: '8px', padding: '16px' }}>
      <h3>Sales & Void</h3>

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
          Scan Barcode / Search SKU or Name:
          <input
            type="text"
            value={scanQuery}
            onChange={(e) => handleLookupInput(e.target.value)}
            onKeyDown={handleScanKeyDown}
            placeholder="Scan barcode or enter SKU / product code"
          />
        </label>
        {lookupHint && (
          <div style={{ fontSize: '0.85em', color: '#374151' }}>{lookupHint}</div>
        )}

        <label>
          Product ID:
          <input
            type="text"
            value={productId}
            onChange={(e) => setProductId(e.target.value)}
            required
          />
        </label>

        <label>
          Quantity:
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
          Discount Amount:
          <input
            type="number"
            min="0"
            step="0.01"
            value={discountAmount}
            onChange={(e) => setDiscountAmount(e.target.value)}
          />
        </label>

        <label>
          Payment Method:
          <select value={paymentMethod} onChange={(e) => setPaymentMethod(e.target.value)}>
            <option value="CASH">Cash (CASH)</option>
            <option value="TRANSFER">Transfer (TRANSFER)</option>
            <option value="CARD">Card (CARD)</option>
          </select>
        </label>

        <label>
          Amount Tendered:
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
          Note:
          <input
            type="text"
            value={note}
            onChange={(e) => setNote(e.target.value)}
          />
        </label>

        <button type="submit" disabled={loading || !branchId}>
          {loading ? 'Processing...' : 'Create Sale'}
        </button>
      </form>

      <form
        onSubmit={handleVoidSale}
        style={{ marginTop: '16px', display: 'grid', gap: '8px', maxWidth: '420px' }}
      >
        <label>
          Sale ID:
          <input
            type="text"
            value={saleId}
            onChange={(e) => setSaleId(e.target.value)}
            placeholder="Enter Sale ID"
          />
        </label>

        <label>
          Void Reason:
          <input
            type="text"
            value={voidReason}
            onChange={(e) => setVoidReason(e.target.value)}
            placeholder="Enter void reason"
          />
        </label>

        <button type="submit" disabled={loading || !saleId.trim()}>
          {loading ? 'Processing...' : 'Void Sale'}
        </button>
      </form>

      {activeSale && (
        <div style={{ marginTop: '12px', paddingTop: '12px', borderTop: '1px solid #e5e7eb' }}>
          <div>
            <strong>Sale Number:</strong> {activeSale.sale_number ?? '-'} {' | '}
            <strong>Status:</strong> {activeSale.status ?? '-'}
          </div>
          <div>
            <strong>Total Amount:</strong> {activeSale.total_amount ?? '-'} {' | '}
            <strong>Shift ID:</strong> {activeSale.shift_id ?? '-'}
          </div>
        </div>
      )}
    </div>
  );
};