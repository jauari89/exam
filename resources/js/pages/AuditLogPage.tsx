import { useEffect, useState } from 'react';
import { DataTable } from '../components/DataTable';
import { PageHeader } from '../components/Layout';
import { api } from '../lib/api';

type Audit = { id: number; action: string; actor_type: string; ip_address?: string; occurred_at: string };

export function AuditLogPage() {
  const [rows, setRows] = useState<Audit[]>([]);

  useEffect(() => {
    api.get('/audit-logs').then(({ data }) => setRows(data.data ?? []));
  }, []);

  return (
    <div>
      <PageHeader title="Audit Log" eyebrow="Sensitive actions" />
      <DataTable
        rows={rows}
        rowKey={(row) => row.id}
        searchPlaceholder="Search audit log..."
        initialSort={{ key: 'occurred_at' }}
        columns={[
          { key: 'occurred_at', header: 'Occurred', accessor: (row) => row.occurred_at },
          { key: 'actor_type', header: 'Actor', accessor: (row) => row.actor_type },
          { key: 'action', header: 'Action', accessor: (row) => row.action },
          { key: 'ip_address', header: 'IP Address', accessor: (row) => row.ip_address ?? '-' },
        ]}
      />
    </div>
  );
}
