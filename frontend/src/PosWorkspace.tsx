import React, { useState } from 'react';
import { BranchItem, BranchSwitcher } from './components/BranchSwitcher';
import { OfflineSyncBar } from './components/OfflineSyncBar';
import { SalesPanel } from './components/SalesPanel';
import { ShiftPanel } from './components/ShiftPanel';
import { StockTransferPanel } from './components/StockTransferPanel';

interface PosWorkspaceProps {
  organizationId: string;
  branches: BranchItem[];
  accessToken: string | null;
  initialBranchId?: string | null;
}

export const PosWorkspace: React.FC<PosWorkspaceProps> = ({
  organizationId,
  branches,
  accessToken,
  initialBranchId = null,
}) => {
  const [selectedBranchId, setSelectedBranchId] = useState<string | null>(
    initialBranchId ?? branches[0]?.id ?? null
  );

  return (
    <div style={{ display: 'grid', gap: '16px', padding: '16px', maxWidth: '960px', margin: '0 auto' }}>
      <header style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
        <h2 style={{ margin: 0 }}>Enterprise Retail POS</h2>
        <BranchSwitcher
          branches={branches}
          selectedBranchId={selectedBranchId}
          onChange={setSelectedBranchId}
        />
      </header>

      <OfflineSyncBar accessToken={accessToken} branchId={selectedBranchId} />

      <ShiftPanel
        organizationId={organizationId}
        branchId={selectedBranchId}
        accessToken={accessToken}
      />

      <SalesPanel
        organizationId={organizationId}
        branchId={selectedBranchId}
        accessToken={accessToken}
      />

      <StockTransferPanel
        organizationId={organizationId}
        branches={branches}
        sourceBranchId={selectedBranchId}
        accessToken={accessToken}
      />
    </div>
  );
};