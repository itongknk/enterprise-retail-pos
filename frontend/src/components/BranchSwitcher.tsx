import React from 'react';

export interface BranchItem {
  id: string;
  code: string;
  name: string;
  timezone: string;
}

interface BranchSwitcherProps {
  branches: BranchItem[];
  selectedBranchId: string | null;
  onChange: (branchId: string) => void;
}

export const BranchSwitcher: React.FC<BranchSwitcherProps> = ({
  branches,
  selectedBranchId,
  onChange,
}) => {
  return (
    <div style={{ display: 'inline-flex', alignItems: 'center', gap: '8px' }}>
      <label htmlFor="branch-switcher">สาขา:</label>
      <select
        id="branch-switcher"
        value={selectedBranchId ?? ''}
        onChange={(e) => onChange(e.target.value)}
      >
        <option value="" disabled>
          -- เลือกสาขา --
        </option>
        {branches.map((branch) => (
          <option key={branch.id} value={branch.id}>
            [{branch.code}] {branch.name}
          </option>
        ))}
      </select>
    </div>
  );
};