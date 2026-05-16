import { FormEvent, useEffect, useState } from 'react';
import { KeyRound, Printer, Upload } from 'lucide-react';
import { DataTable } from '../components/DataTable';
import { PageHeader } from '../components/Layout';
import { api } from '../lib/api';

type Candidate = { id: number; candidate_number: string; name: string; email?: string };
type SessionRow = { id: number; name: string; status: string; exam?: { code: string; title: string } };
type TokenSlip = {
  candidate_id: number;
  candidate_number: string;
  candidate_name: string;
  exam_session_id: number;
  plain_token: string;
  expires_at?: string;
};

export function CandidateImportPage() {
  const [rows, setRows] = useState<Candidate[]>([]);
  const [sessions, setSessions] = useState<SessionRow[]>([]);
  const [selectedIds, setSelectedIds] = useState<number[]>([]);
  const [selectedSessionId, setSelectedSessionId] = useState('');
  const [tokens, setTokens] = useState<TokenSlip[]>([]);
  const [form, setForm] = useState({ candidate_number: '', name: '', email: '' });
  const [file, setFile] = useState<File | null>(null);
  const [message, setMessage] = useState('');

  async function load() {
    const allRows: Candidate[] = [];
    let page = 1;
    let lastPage = 1;

    do {
      const { data } = await api.get('/admin/candidates', { params: { page } });
      allRows.push(...(data.data ?? []));
      lastPage = data.last_page ?? 1;
      page += 1;
    } while (page <= lastPage);

    setRows(allRows);
  }

  async function loadSessions() {
    const allRows: SessionRow[] = [];
    let page = 1;
    let lastPage = 1;

    do {
      const { data } = await api.get('/admin/exam-sessions', { params: { page } });
      allRows.push(...(data.data ?? []));
      lastPage = data.last_page ?? 1;
      page += 1;
    } while (page <= lastPage);

    setSessions(allRows);
  }

  function toggleCandidate(id: number) {
    setSelectedIds((current) => current.includes(id) ? current.filter((candidateId) => candidateId !== id) : [...current, id]);
  }

  function selectAllCandidates() {
    setSelectedIds(rows.map((row) => row.id));
  }

  function clearSelection() {
    setSelectedIds([]);
    setTokens([]);
  }

  async function submit(event: FormEvent) {
    event.preventDefault();
    await api.post('/admin/candidates', form);
    setForm({ candidate_number: '', name: '', email: '' });
    setMessage('Candidate saved.');
    await load();
  }

  async function importFile(event: FormEvent) {
    event.preventDefault();
    if (!file) return;
    const payload = new FormData();
    payload.append('file', file);
    const { data } = await api.post('/admin/candidates/import', payload, { headers: { 'Content-Type': 'multipart/form-data' } });
    setMessage(`${data.imported} candidates imported.`);
    setFile(null);
    await load();
  }

  async function generateTokens() {
    if (!selectedSessionId) {
      setMessage('Select exam session first.');
      return;
    }

    if (!selectedIds.length) {
      setMessage('Select at least one candidate.');
      return;
    }

    const { data } = await api.post('/admin/tokens/generate', {
      exam_session_id: Number(selectedSessionId),
      candidate_ids: selectedIds,
    });
    setTokens(data.tokens ?? []);
    setMessage(`${data.tokens?.length ?? 0} tokens generated for selected candidates.`);
  }

  useEffect(() => {
    void load();
    void loadSessions();
  }, []);

  return (
    <div>
      <PageHeader title="Candidates" eyebrow="Import and roster" />
      {message ? <p className="success">{message}</p> : null}
      <form className="toolbar" onSubmit={submit}>
        <input placeholder="Candidate no." value={form.candidate_number} onChange={(event) => setForm({ ...form, candidate_number: event.target.value })} />
        <input placeholder="Name" value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} />
        <input placeholder="Email" value={form.email} onChange={(event) => setForm({ ...form, email: event.target.value })} />
        <button className="primary">Save</button>
      </form>
      <form className="toolbar" onSubmit={importFile}>
        <input type="file" accept=".csv,.tsv,.txt,.xlsx" onChange={(event) => setFile(event.target.files?.[0] ?? null)} />
        <button className="secondary"><Upload size={18} /> Import CSV/XLSX</button>
      </form>
      <section className="content-panel candidate-bulk-panel">
        <div className="section-title">
          <h2>Bulk token action</h2>
          <span className="muted">{selectedIds.length} selected</span>
        </div>
        <div className="toolbar">
          <select value={selectedSessionId} onChange={(event) => setSelectedSessionId(event.target.value)}>
            <option value="">Select exam session</option>
            {sessions.map((session) => (
              <option key={session.id} value={session.id}>{session.name} #{session.id} / {session.exam?.code ?? '-'} / {session.status}</option>
            ))}
          </select>
          <button type="button" className="secondary" onClick={selectAllCandidates}>Select all loaded</button>
          <button type="button" className="secondary" onClick={clearSelection}>Clear</button>
          <button type="button" className="primary" onClick={() => void generateTokens()}><KeyRound size={18} /> Generate token for selected</button>
          {tokens.length ? <button type="button" className="secondary" onClick={() => window.print()}><Printer size={18} /> Print slips</button> : null}
        </div>
      </section>
      <DataTable
        rows={rows}
        rowKey={(row) => row.id}
        searchPlaceholder="Search candidate..."
        initialSort={{ key: 'id' }}
        columns={[
          {
            key: 'select',
            header: <input type="checkbox" checked={rows.length > 0 && selectedIds.length === rows.length} onChange={(event) => event.target.checked ? selectAllCandidates() : clearSelection()} />,
            sortable: false,
            searchable: false,
            render: (row) => <input type="checkbox" checked={selectedIds.includes(row.id)} onChange={() => toggleCandidate(row.id)} />,
          },
          { key: 'id', header: 'ID', accessor: (row) => row.id },
          { key: 'candidate_number', header: 'Candidate No.', accessor: (row) => row.candidate_number },
          { key: 'name', header: 'Name', accessor: (row) => row.name },
          { key: 'email', header: 'Email', accessor: (row) => row.email ?? '-' },
        ]}
      />
      {tokens.length ? (
        <div className="token-slip-grid">
          {tokens.map((token) => (
            <section className="token-slip" key={`${token.candidate_id}-${token.plain_token}`}>
              <span>Secure Exam Token</span>
              <h2>{token.candidate_name}</h2>
              <p>{token.candidate_number} / Session {token.exam_session_id}</p>
              <strong>{token.plain_token}</strong>
              <small>Token is one-time use. Expires: {token.expires_at ?? '-'}</small>
            </section>
          ))}
        </div>
      ) : null}
    </div>
  );
}
