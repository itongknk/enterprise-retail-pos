const DB_NAME = "erp_pos_offline_db";
const DB_VERSION = 1;

export function openPosDb() {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, DB_VERSION);

    request.onupgradeneeded = (event) => {
      const db = event.target.result;

      if (!db.objectStoreNames.contains("products")) {
        const productsStore = db.createObjectStore("products", { keyPath: "id" });
        productsStore.createIndex("by_sku", "sku", { unique: false });
        productsStore.createIndex("by_barcode", "_barcodes", { multiEntry: true });
      }

      if (!db.objectStoreNames.contains("inventory_balances")) {
        const balStore = db.createObjectStore("inventory_balances", { keyPath: "id" });
        balStore.createIndex("by_branch_product", ["branch_id", "product_id"], { unique: false });
      }

      if (!db.objectStoreNames.contains("sync_queue")) {
        const queueStore = db.createObjectStore("sync_queue", { keyPath: "event_id" });
        queueStore.createIndex("by_status", "status", { unique: false });
      }
    };

    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
}

function txDone(tx) {
  return new Promise((resolve, reject) => {
    tx.oncomplete = () => resolve();
    tx.onerror = () => reject(tx.error);
    tx.onabort = () => reject(tx.error);
  });
}

export async function cacheProducts(products = []) {
  const db = await openPosDb();
  const tx = db.transaction("products", "readwrite");
  const store = tx.objectStore("products");

  for (const item of products) {
    const barcodes = Array.isArray(item.barcodes)
      ? item.barcodes.map((b) => String(b.barcode ?? b).trim()).filter(Boolean)
      : [];

    store.put({
      ...item,
      selling_price: Number(item.selling_price ?? 0),
      tax_rate: Number(item.tax_rate ?? 0),
      _barcodes: barcodes,
    });
  }

  await txDone(tx);
}

export async function cacheInventoryBalances(balances = []) {
  const db = await openPosDb();
  const tx = db.transaction("inventory_balances", "readwrite");
  const store = tx.objectStore("inventory_balances");

  for (const bal of balances) {
    store.put({
      ...bal,
      quantity_on_hand: Number(bal.quantity_on_hand ?? 0),
      reserved_quantity: Number(bal.reserved_quantity ?? 0),
    });
  }

  await txDone(tx);
}

export async function findProductOffline(queryText) {
  const q = String(queryText ?? "").trim();
  if (!q) return [];

  const db = await openPosDb();
  const tx = db.transaction("products", "readonly");
  const store = tx.objectStore("products");

  // 1) ค้นหาตรงจากบาร์โค้ดก่อน (เหมาะกับการยิงบาร์โค้ด)
  const exactBarcode = await new Promise((resolve, reject) => {
    const req = store.index("by_barcode").getAll(q);
    req.onsuccess = () => resolve(req.result || []);
    req.onerror = () => reject(req.error);
  });
  if (exactBarcode.length > 0) return exactBarcode;

  // 2) ค้นหาจาก SKU หรือชื่อสินค้า (รองรับภาษาไทยและตัวพิมพ์เล็ก/ใหญ่)
  const lowerQ = q.toLowerCase();
  const allProducts = await new Promise((resolve, reject) => {
    const req = store.getAll();
    req.onsuccess = () => resolve(req.result || []);
    req.onerror = () => reject(req.error);
  });

  return allProducts.filter((p) => {
    const skuMatch = String(p.sku ?? "").toLowerCase().includes(lowerQ);
    const nameMatch = String(p.name ?? "").toLowerCase().includes(lowerQ);
    const barcodeMatch = Array.isArray(p._barcodes)
      && p._barcodes.some((bc) => String(bc).toLowerCase().includes(lowerQ));
    return skuMatch || nameMatch || barcodeMatch;
  });
}

export async function enqueueSaleEvent({
  organizationId,
  branchId,
  deviceId,
  items,
  discountAmount = 0,
  payments,
  note = null,
  shiftId = null,
}) {
  const eventId = typeof crypto !== "undefined" && crypto.randomUUID
    ? crypto.randomUUID()
    : "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;
        const v = c === "x" ? r : (r & 0x3) | 0x8;
        return v.toString(16);
      });

  const record = {
    event_id: eventId,
    event_type: "SALE_CREATED",
    occurred_at: new Date().toISOString(),
    organization_id: organizationId,
    branch_id: branchId,
    device_id: deviceId,
    payload: {
      items: items.map((i) => ({
        product_id: i.product_id,
        quantity: Number(i.quantity),
        unit_price: Number(i.unit_price),
      })),
      discount_amount: Number(discountAmount ?? 0),
      payments: payments.map((p) => ({
        payment_method: String(p.payment_method ?? "CASH").toUpperCase(),
        amount_tendered: Number(p.amount_tendered),
        ...(p.reference_no ? { reference_no: String(p.reference_no) } : {}),
      })),
      ...(note ? { note: String(note) } : {}),
      ...(shiftId ? { shift_id: String(shiftId) } : {}),
    },
    status: "PENDING",
    retry_count: 0,
    last_error: null,
    updated_at: new Date().toISOString(),
  };

  const db = await openPosDb();
  const tx = db.transaction(["sync_queue", "inventory_balances"], "readwrite");
  tx.objectStore("sync_queue").put(record);

  // ปรับยอดคงเหลือเบื้องต้นในเครื่อง (optimistic offline stock)
  const balStore = tx.objectStore("inventory_balances");
  const idx = balStore.index("by_branch_product");
  for (const line of record.payload.items) {
    const matches = await new Promise((resolve, reject) => {
      const req = idx.getAll([branchId, line.product_id]);
      req.onsuccess = () => resolve(req.result || []);
      req.onerror = () => reject(req.error);
    });
    for (const bal of matches) {
      bal.quantity_on_hand = Math.max(0, Number(bal.quantity_on_hand ?? 0) - Number(line.quantity));
      balStore.put(bal);
    }
  }

  await txDone(tx);
  return record;
}

export async function getQueuedEvents() {
  const db = await openPosDb();
  const tx = db.transaction("sync_queue", "readonly");
  return new Promise((resolve, reject) => {
    const req = tx.objectStore("sync_queue").getAll();
    req.onsuccess = () => resolve(req.result || []);
    req.onerror = () => reject(req.error);
  });
}

export async function applySyncResults(results = []) {
  const db = await openPosDb();
  const tx = db.transaction(["sync_queue", "inventory_balances"], "readwrite");
  const queueStore = tx.objectStore("sync_queue");
  const balStore = tx.objectStore("inventory_balances");
  const balIdx = balStore.index("by_branch_product");

  for (const res of results) {
    const eventId = res.event_id;
    const status = res.status;

    if (status === "PROCESSED" || status === "DUPLICATE") {
      // อัปเดต balance_after จาก stock_movements หากมีส่งกลับมา
      const movements = res?.data?.stock_movements;
      if (Array.isArray(movements)) {
        for (const mv of movements) {
          if (!mv.product_id || mv.balance_after === undefined) continue;
          const matches = await new Promise((resolve, reject) => {
            const req = balIdx.getAll([mv.product_id]);
            req.onsuccess = () => resolve(req.result || []);
            req.onerror = () => reject(req.error);
          });
          for (const bal of matches) {
            bal.quantity_on_hand = Number(mv.balance_after);
            balStore.put(bal);
          }
        }
      }
      queueStore.delete(eventId);
    } else if (status === "FAILED") {
      const existing = await new Promise((resolve, reject) => {
        const req = queueStore.get(eventId);
        req.onsuccess = () => resolve(req.result || null);
        req.onerror = () => reject(req.error);
      });
      if (existing) {
        existing.status = "FAILED";
        existing.retry_count = Number(existing.retry_count ?? 0) + 1;
        existing.last_error = res.error || "Sync failed";
        existing.updated_at = new Date().toISOString();
        queueStore.put(existing);
      }
    }
  }

  await txDone(tx);
}