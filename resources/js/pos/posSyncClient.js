import {
  applySyncResults,
  cacheInventoryBalances,
  cacheProducts,
  getQueuedEvents,
} from "./posOfflineDb.js";

export class PosSyncClient {
  constructor({ baseUrl = "", getAccessToken, organizationId, branchId, deviceId }) {
    this.baseUrl = baseUrl.replace(/\/$/, "");
    this.getAccessToken = getAccessToken;
    this.organizationId = organizationId;
    this.branchId = branchId;
    this.deviceId = deviceId;
    this.isSyncing = false;
  }

  headers(extra = {}) {
    const token = typeof this.getAccessToken === "function" ? this.getAccessToken() : "";
    return {
      Accept: "application/json",
      "Content-Type": "application/json",
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...(this.branchId ? { "X-Branch-Id": this.branchId } : {}),
      ...extra,
    };
  }

  async pullCatalog(perPage = 100) {
    const params = new URLSearchParams({
      organization_id: this.organizationId,
      per_page: String(perPage),
    });

    const resp = await fetch(`${this.baseUrl}/api/v1/products?${params.toString()}`, {
      method: "GET",
      headers: this.headers(),
    });

    if (!resp.ok) {
      throw new Error(`Catalog pull failed (HTTP ${resp.status})`);
    }

    const json = await resp.json();
    const list = Array.isArray(json?.data) ? json.data : [];
    await cacheProducts(list);
    return list;
  }

  async pullBalances() {
    const params = new URLSearchParams({
      organization_id: this.organizationId,
      branch_id: this.branchId,
    });

    const resp = await fetch(`${this.baseUrl}/api/v1/inventory/balances?${params.toString()}`, {
      method: "GET",
      headers: this.headers(),
    });

    if (!resp.ok) {
      throw new Error(`Balances pull failed (HTTP ${resp.status})`);
    }

    const json = await resp.json();
    const list = Array.isArray(json?.data) ? json.data : (Array.isArray(json) ? json : []);
    await cacheInventoryBalances(list);
    return list;
  }

  async flushQueue(batchSize = 20) {
    if (this.isSyncing) return { skipped: true, results: [] };
    if (typeof navigator !== "undefined" && navigator.onLine === false) {
      return { offline: true, results: [] };
    }

    this.isSyncing = true;
    try {
      const queued = await getQueuedEvents();
      const pending = queued
        .filter((e) => e.status === "PENDING" || e.status === "FAILED")
        .slice(0, batchSize);

      if (pending.length === 0) {
        return { empty: true, results: [] };
      }

      const body = {
        organization_id: this.organizationId,
        branch_id: this.branchId,
        device_id: this.deviceId,
        events: pending.map((e) => ({
          event_id: e.event_id,
          event_type: e.event_type,
          occurred_at: e.occurred_at,
          payload: e.payload,
        })),
      };

      const resp = await fetch(`${this.baseUrl}/api/v1/sync/push`, {
        method: "POST",
        headers: this.headers(),
        body: JSON.stringify(body),
      });

      if (!resp.ok) {
        throw new Error(`Sync push failed (HTTP ${resp.status})`);
      }

      const json = await resp.json();
      const results = Array.isArray(json?.data?.results) ? json.data.results : [];
      await applySyncResults(results);

      return { ok: true, results };
    } finally {
      this.isSyncing = false;
    }
  }
}