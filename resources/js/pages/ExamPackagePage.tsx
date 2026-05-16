import { FormEvent, useEffect, useState } from 'react';
import { DataTable } from '../components/DataTable';
import { PageHeader } from '../components/Layout';
import { api } from '../lib/api';

type PackageRow = {
  id: number;
  version: number;
  checksum: string;
  strict_mode: boolean;
  questions_count?: number;
  paper?: { id: number; code: string; title: string; exam?: { id: number; code: string; title: string } };
};

const sample = JSON.stringify({
  exam_paper_id: 1,
  version: 1,
  questions: [
    { external_id: 'Q1', type: 'objective', max_marks: 1, stem: { text: '2 + 2 = ?' }, options: [{ external_id: 'A', content: { text: '4' }, is_correct: true, marks: 1 }] },
  ],
}, null, 2);

export function ExamPackagePage() {
  const [rows, setRows] = useState<PackageRow[]>([]);
  const [json, setJson] = useState(sample);
  const [publish, setPublish] = useState({ package_id: '', exam_session_id: '' });
  const [result, setResult] = useState('');

  async function load() {
    const { data } = await api.get('/admin/exam-packages');
    setRows(data.data ?? []);
  }

  async function submit(event: FormEvent) {
    event.preventDefault();
    const { data } = await api.post('/admin/exam-packages/import', JSON.parse(json));
    setResult(`Imported package #${data.id} checksum ${data.checksum}`);
    await load();
  }

  async function publishPackage(event: FormEvent) {
    event.preventDefault();
    const { data } = await api.post(`/admin/exam-packages/${publish.package_id}/publish-session`, {
      exam_session_id: Number(publish.exam_session_id),
      status: 'active',
    });
    setResult(`Published package #${publish.package_id} to session #${data.id}`);
  }

  useEffect(() => {
    void load();
  }, []);

  return (
    <div>
      <PageHeader title="Exam Package" eyebrow="Snapshot source" />
      {result ? <p className="success">{result}</p> : null}
      <div className="split">
        <section>
          <h2>Packages</h2>
          <DataTable
            rows={rows}
            rowKey={(row) => row.id}
            searchPlaceholder="Search package..."
            initialSort={{ key: 'id' }}
            columns={[
              { key: 'id', header: 'ID', accessor: (row) => row.id },
              {
                key: 'exam',
                header: 'Exam / Paper',
                accessor: (row) => `${row.paper?.exam?.code ?? ''} ${row.paper?.exam?.id ?? ''} ${row.paper?.code ?? ''} ${row.paper?.id ?? ''}`,
                render: (row) => <>{row.paper?.exam?.code} #{row.paper?.exam?.id}<br /><span className="muted">{row.paper?.code} #{row.paper?.id}</span></>,
              },
              { key: 'version', header: 'Version', accessor: (row) => row.version, render: (row) => <>v{row.version} / {row.strict_mode ? 'strict' : 'tryout'}</> },
              { key: 'questions', header: 'Questions', accessor: (row) => row.questions_count ?? 0, render: (row) => row.questions_count ?? '-' },
              { key: 'checksum', header: 'Checksum', accessor: (row) => row.checksum, render: (row) => <code>{row.checksum.slice(0, 12)}</code> },
              { key: 'actions', header: '', sortable: false, searchable: false, render: (row) => <button onClick={() => setPublish({ ...publish, package_id: String(row.id) })}>Use</button> },
            ]}
          />
        </section>
        <section>
          <h2>Import / publish</h2>
          <form className="stack" onSubmit={submit}>
            <textarea className="json-box compact" value={json} onChange={(event) => setJson(event.target.value)} />
            <button className="primary">Import package JSON</button>
          </form>
          <form className="toolbar" onSubmit={publishPackage}>
            <input placeholder="Package ID" value={publish.package_id} onChange={(event) => setPublish({ ...publish, package_id: event.target.value })} />
            <input placeholder="Session ID" value={publish.exam_session_id} onChange={(event) => setPublish({ ...publish, exam_session_id: event.target.value })} />
            <button className="secondary">Publish to session</button>
          </form>
        </section>
      </div>
    </div>
  );
}
