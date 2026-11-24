import React from 'react';
import { BranchItem } from './components/BranchSwitcher';
import { PosWorkspace } from './PosWorkspace';

const DEFAULT_BRANCHES: BranchItem[] = [
  { id: 'branch-hq', code: 'HQ', name: 'สำนักงานใหญ่', timezone: 'Asia/Bangkok' },
  { id: 'branch-01', code: 'B01', name: 'สาขา 01', timezone: 'Asia/Bangkok' },
];

export const App: React.FC = () => {
  const organizationId = import.meta.env.VITE_ORG_ID || 'default-org';
  const accessToken = import.meta.env.VITE_API_TOKEN || null;

  return (
    <PosWorkspace
      organizationId={organizationId}
      branches={DEFAULT_BRANCHES}
      accessToken={accessToken}
    />
  );
};

export default App;