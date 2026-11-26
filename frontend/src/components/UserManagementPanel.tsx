import React, { useCallback, useEffect, useState } from 'react';
import { ApiRequestOptions } from '../api/client';
import {
  CreateUserPayload,
  ManagedUser,
  UpdateUserPayload,
  UserStatus,
  createUser,
  listUsers,
  updateUser,
} from '../api/usersApi';
import { BranchItem } from './BranchSwitcher';

interface UserManagementPanelProps {
  organizationId: string;
  branches: BranchItem[];
  branchId: string | null;
  accessToken: string | null;
}

function normalizeUsersList(raw: unknown): ManagedUser[] {
  if (Array.isArray(raw)) {
    return raw as ManagedUser[];
  }
  if (raw && typeof raw === 'object') {
    const obj = raw as { data?: unknown; items?: unknown };
    if (Array.isArray(obj.data)) return obj.data as ManagedUser[];
    if (Array.isArray(obj.items)) return obj.items as ManagedUser[];
  }
  return [];
}

export const UserManagementPanel: React.FC<UserManagementPanelProps> = ({
  organizationId,
  branches,
  branchId,
  accessToken,
}) => {
  const [users, setUsers] = useState<ManagedUser[]>([]);
  const [loading, setLoading] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [errorMsg, setErrorMsg] = useState<string | null>(null);
  const [noticeMsg, setNoticeMsg] = useState<string | null>(null);

  const [employeeCode, setEmployeeCode] = useState('');
  const [email, setEmail] = useState('');
  const [fullName, setFullName] = useState('');
  const [password, setPassword] = useState('');
  const [pin, setPin] = useState('');
  const [status, setStatus] = useState<UserStatus>('ACTIVE');
  const [isOrgAdmin, setIsOrgAdmin] = useState(false);
  const [roleId, setRoleId] = useState('');

  const [editingUserId, setEditingUserId] = useState<string | null>(null);
  const [editFullName, setEditFullName] = useState('');
  const [editEmail, setEditEmail] = useState('');
  const [editStatus, setEditStatus] = useState<UserStatus>('ACTIVE');
  const [editIsOrgAdmin, setEditIsOrgAdmin] = useState(false);
  const [editPassword, setEditPassword] = useState('');
  const [editPin, setEditPin] = useState('');

  const authOptions: ApiRequestOptions = {
    accessToken,
    branchId,
  };

  const fetchUsers = useCallback(async () => {
    setLoading(true);
    setErrorMsg(null);
    try {
      const res = await listUsers(authOptions);
      setUsers(normalizeUsersList(res));
    } catch (err) {
      setErrorMsg(err instanceof Error ? err.message : 'ไม่สามารถโหลดรายชื่อผู้ใช้ได้');
    } finally {
      setLoading(false);
    }
  }, [accessToken, branchId]);

  useEffect(() => {
    if (accessToken) {
      void fetchUsers();
    } else {
      setUsers([]);
    }
  }, [accessToken, branchId, fetchUsers]);

  const handleCreate = async (e: React.FormEvent) => {
    e.preventDefault();
    setErrorMsg(null);
    setNoticeMsg(null);
    setSubmitting(true);

    const payload: CreateUserPayload = {
      employee_code: employeeCode.trim(),
      email: email.trim(),
      full_name: fullName.trim(),
      password,
      pin: pin.trim() ? pin.trim() : null,
      status,
      is_org_admin: isOrgAdmin,
      branch_id: branchId,
      role_id: roleId.trim() ? roleId.trim() : null,
    };

    try {
      await createUser(payload, authOptions);
      setNoticeMsg('สร้างผู้ใช้งานสำเร็จ');
      setEmployeeCode('');
      setEmail('');
      setFullName('');
      setPassword('');
      setPin('');
      setStatus('ACTIVE');
      setIsOrgAdmin(false);
      setRoleId('');
      await fetchUsers();
    } catch (err) {
      setErrorMsg(err instanceof Error ? err.message : 'ไม่สามารถสร้างผู้ใช้งานได้');
    } finally {
      setSubmitting(false);
    }
  };

  const startEdit = (u: ManagedUser) => {
    setEditingUserId(u.id);
    setEditFullName(u.full_name);
    setEditEmail(u.email ?? '');
    setEditStatus(u.status);
    setEditIsOrgAdmin(Boolean(u.is_org_admin));
    setEditPassword('');
    setEditPin('');
    setErrorMsg(null);
    setNoticeMsg(null);
  };

  const handleUpdate = async (userId: string) => {
    setErrorMsg(null);
    setNoticeMsg(null);
    setSubmitting(true);

    const payload: UpdateUserPayload = {
      full_name: editFullName.trim(),
      email: editEmail.trim(),
      status: editStatus,
      is_org_admin: editIsOrgAdmin,
    };
    if (editPassword.trim()) {
      payload.password = editPassword;
    }
    if (editPin.trim()) {
      payload.pin = editPin.trim();
    }

    try {
      await updateUser(userId, payload, authOptions);
      setNoticeMsg('อัปเดตข้อมูลผู้ใช้งานสำเร็จ');
      setEditingUserId(null);
      await fetchUsers();
    } catch (err) {
      setErrorMsg(err instanceof Error ? err.message : 'ไม่สามารถอัปเดตข้อมูลผู้ใช้งานได้');
    } finally {
      setSubmitting(false);
    }
  };

  const activeBranchName =
    branches.find((b) => b.id === branchId)?.name ?? branchId ?? 'ไม่ได้เลือกสาขา';

  return (
    <section
      style={{
        border: '1px solid #d1d5db',
        borderRadius: '8px',
        padding: '16px',
        display: 'grid',
        gap: '12px',
      }}
    >
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
        <div>
          <h3 style={{ margin: 0 }}>User Management (/api/v1/users)</h3>
          <small style={{ color: '#4b5563' }}>
            Org: {organizationId} | Branch: {activeBranchName}
          </small>
        </div>
        <button type="button" onClick={() => void fetchUsers()} disabled={loading || submitting}>
          {loading ? 'กำลังโหลด...' : 'รีเฟรชรายการ'}
        </button>
      </div>

      {!accessToken && (
        <div style={{ color: '#92400e', background: '#fef3c7', padding: '8px 12px', borderRadius: '6px' }}>
          ยังไม่มี Access Token — กรุณาตรวจสอบการยืนยันตัวตนก่อนใช้งาน API ผู้ใช้
        </div>
      )}

      {errorMsg && (
        <div role="alert" style={{ color: '#991b1b', background: '#fee2e2', padding: '8px 12px', borderRadius: '6px' }}>
          {errorMsg}
        </div>
      )}

      {noticeMsg && (
        <div style={{ color: '#065f46', background: '#d1fae5', padding: '8px 12px', borderRadius: '6px' }}>
          {noticeMsg}
        </div>
      )}

      <form
        onSubmit={(e) => void handleCreate(e)}
        style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: '8px' }}
      >
        <input
          type="text"
          placeholder="รหัสพนักงาน (employee_code)"
          value={employeeCode}
          onChange={(e) => setEmployeeCode(e.target.value)}
          required
        />
        <input
          type="email"
          placeholder="อีเมล (email)"
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          required
        />
        <input
          type="text"
          placeholder="ชื่อ-นามสกุล (full_name)"
          value={fullName}
          onChange={(e) => setFullName(e.target.value)}
          required
        />
        <input
          type="password"
          placeholder="รหัสผ่าน (password, อย่างน้อย 6 ตัวอักษร)"
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          minLength={6}
          required
        />
        <input
          type="text"
          placeholder="PIN (4-12 ตัวเลข, ไม่บังคับ)"
          value={pin}
          onChange={(e) => setPin(e.target.value)}
        />
        <select value={status} onChange={(e) => setStatus(e.target.value as UserStatus)}>
          <option value="ACTIVE">ACTIVE</option>
          <option value="INACTIVE">INACTIVE</option>
          <option value="LOCKED">LOCKED</option>
        </select>
        <input
          type="text"
          placeholder="Role UUID (role_id, ไม่บังคับ)"
          value={roleId}
          onChange={(e) => setRoleId(e.target.value)}
        />
        <label style={{ display: 'flex', alignItems: 'center', gap: '6px' }}>
          <input
            type="checkbox"
            checked={isOrgAdmin}
            onChange={(e) => setIsOrgAdmin(e.target.checked)}
          />
          Org Admin
        </label>
        <button type="submit" disabled={submitting || !accessToken}>
          {submitting ? 'กำลังบันทึก...' : 'เพิ่มผู้ใช้งาน'}
        </button>
      </form>

      <div style={{ overflowX: 'auto' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: '14px' }}>
          <thead>
            <tr style={{ textAlign: 'left', borderBottom: '1px solid #e5e7eb' }}>
              <th style={{ padding: '6px' }}>รหัส</th>
              <th style={{ padding: '6px' }}>ชื่อ-นามสกุล</th>
              <th style={{ padding: '6px' }}>อีเมล</th>
              <th style={{ padding: '6px' }}>สถานะ</th>
              <th style={{ padding: '6px' }}>Org Admin</th>
              <th style={{ padding: '6px' }}>จัดการ</th>
            </tr>
          </thead>
          <tbody>
            {users.length === 0 ? (
              <tr>
                <td colSpan={6} style={{ padding: '8px', color: '#6b7280' }}>
                  {loading ? 'กำลังโหลดข้อมูล...' : 'ไม่มีข้อมูลผู้ใช้งาน'}
                </td>
              </tr>
            ) : (
              users.map((u) => {
                const isEditing = editingUserId === u.id;
                return (
                  <tr key={u.id} style={{ borderBottom: '1px solid #f3f4f6' }}>
                    <td style={{ padding: '6px' }}>{u.employee_code}</td>
                    <td style={{ padding: '6px' }}>
                      {isEditing ? (
                        <input
                          type="text"
                          value={editFullName}
                          onChange={(e) => setEditFullName(e.target.value)}
                        />
                      ) : (
                        u.full_name
                      )}
                    </td>
                    <td style={{ padding: '6px' }}>
                      {isEditing ? (
                        <input
                          type="email"
                          value={editEmail}
                          onChange={(e) => setEditEmail(e.target.value)}
                        />
                      ) : (
                        u.email ?? '-'
                      )}
                    </td>
                    <td style={{ padding: '6px' }}>
                      {isEditing ? (
                        <select
                          value={editStatus}
                          onChange={(e) => setEditStatus(e.target.value as UserStatus)}
                        >
                          <option value="ACTIVE">ACTIVE</option>
                          <option value="INACTIVE">INACTIVE</option>
                          <option value="LOCKED">LOCKED</option>
                        </select>
                      ) : (
                        u.status
                      )}
                    </td>
                    <td style={{ padding: '6px' }}>
                      {isEditing ? (
                        <input
                          type="checkbox"
                          checked={editIsOrgAdmin}
                          onChange={(e) => setEditIsOrgAdmin(e.target.checked)}
                        />
                      ) : u.is_org_admin ? (
                        'Yes'
                      ) : (
                        'No'
                      )}
                    </td>
                    <td style={{ padding: '6px' }}>
                      {isEditing ? (
                        <div style={{ display: 'flex', flexWrap: 'wrap', gap: '4px' }}>
                          <input
                            type="password"
                            placeholder="รหัสผ่านใหม่ (ถ้าเปลี่ยน)"
                            value={editPassword}
                            onChange={(e) => setEditPassword(e.target.value)}
                            style={{ width: '130px' }}
                          />
                          <input
                            type="text"
                            placeholder="PIN ใหม่ (ถ้าเปลี่ยน)"
                            value={editPin}
                            onChange={(e) => setEditPin(e.target.value)}
                            style={{ width: '110px' }}
                          />
                          <button
                            type="button"
                            disabled={submitting}
                            onClick={() => void handleUpdate(u.id)}
                          >
                            บันทึก
                          </button>
                          <button
                            type="button"
                            disabled={submitting}
                            onClick={() => setEditingUserId(null)}
                          >
                            ยกเลิก
                          </button>
                        </div>
                      ) : (
                        <button type="button" onClick={() => startEdit(u)}>
                          แก้ไข
                        </button>
                      )}
                    </td>
                  </tr>
                );
              })
            )}
          </tbody>
        </table>
      </div>
    </section>
  );
};