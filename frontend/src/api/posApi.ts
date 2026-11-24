import { ApiRequestOptions, apiRequest } from './client';

export interface ShiftReconciliation {
  opening_cash: number;
  completed_sales_count: number;
  voided_sales_count: number;
  gross_sales_amount: number;
  voided_sales_amount: number;
  cash_received: number;
  cash_change_given: number;
  expected_cash: number;
  closing_cash_counted: number | null;
  cash_variance: number | null;
}

export interface ShiftRecord {
  id: string;
  organization_id: string;
  branch_id: string;
  shift_number: string;
  status: 'OPEN' | 'CLOSED' | string;
  opening_cash: string | number;
  expected_cash: string | number | null;
  closing_cash_counted: string | number | null;
  cash_variance: string | number | null;
}

export interface ShiftSummaryResponse {
  data: {
    shift: ShiftRecord;
    reconciliation: ShiftReconciliation;
  };
}

export interface StockTransferItemInput {
  product_id: string;
  quantity: number;
}

export interface CreateStockTransferPayload {
  organization_id: string;
  source_branch_id: string;
  destination_branch_id: string;
  items: StockTransferItemInput[];
  note?: string;
}

export interface SaleItemInput {
  product_id: string;
  quantity: number;
}

export interface SalePaymentInput {
  payment_method: 'CASH' | 'TRANSFER' | 'CARD' | string;
  amount_tendered: number;
  reference_no?: string;
}

export interface CreateSalePayload {
  organization_id: string;
  branch_id: string;
  items: SaleItemInput[];
  discount_amount?: number;
  payments: SalePaymentInput[];
  note?: string;
}

export interface AdjustInventoryPayload {
  organization_id: string;
  branch_id: string;
  product_id: string;
  movement_type: 'IN' | 'OUT' | 'ADJUST' | string;
  quantity_delta: number;
  note?: string;
}

export function openShift(
  payload: {
    organization_id: string;
    branch_id: string;
    opening_cash: number;
    note?: string;
  },
  auth: ApiRequestOptions
): Promise<ShiftSummaryResponse> {
  return apiRequest<ShiftSummaryResponse>('/shifts/open', {
    ...auth,
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function getShiftSummary(
  shiftId: string,
  auth: ApiRequestOptions
): Promise<ShiftSummaryResponse> {
  return apiRequest<ShiftSummaryResponse>(`/shifts/${shiftId}/summary`, {
    ...auth,
    method: 'GET',
  });
}

export function closeShift(
  shiftId: string,
  payload: {
    closing_cash_counted: number;
    note?: string;
  },
  auth: ApiRequestOptions
): Promise<ShiftSummaryResponse> {
  return apiRequest<ShiftSummaryResponse>(`/shifts/${shiftId}/close`, {
    ...auth,
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function createStockTransfer<T = unknown>(
  payload: CreateStockTransferPayload,
  auth: ApiRequestOptions
): Promise<T> {
  return apiRequest<T>('/stock-transfers', {
    ...auth,
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function shipStockTransfer<T = unknown>(
  transferId: string,
  auth: ApiRequestOptions
): Promise<T> {
  return apiRequest<T>(`/stock-transfers/${transferId}/ship`, {
    ...auth,
    method: 'POST',
  });
}

export function receiveStockTransfer<T = unknown>(
  transferId: string,
  auth: ApiRequestOptions
): Promise<T> {
  return apiRequest<T>(`/stock-transfers/${transferId}/receive`, {
    ...auth,
    method: 'POST',
  });
}

export function createSale<T = unknown>(
  payload: CreateSalePayload,
  auth: ApiRequestOptions
): Promise<T> {
  return apiRequest<T>('/sales', {
    ...auth,
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function voidSale<T = unknown>(
  saleId: string,
  payload: { reason: string },
  auth: ApiRequestOptions
): Promise<T> {
  return apiRequest<T>(`/sales/${saleId}/void`, {
    ...auth,
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function adjustInventory<T = unknown>(
  payload: AdjustInventoryPayload,
  auth: ApiRequestOptions
): Promise<T> {
  return apiRequest<T>('/inventory/adjust', {
    ...auth,
    method: 'POST',
    body: JSON.stringify(payload),
  });
}