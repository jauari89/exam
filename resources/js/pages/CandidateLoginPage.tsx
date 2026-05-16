import { FormEvent, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import axios from 'axios';
import { LogIn } from 'lucide-react';
import { ApiError, api, storeCandidateSession } from '../lib/api';

function errorMessage(error: unknown) {
  if (axios.isAxiosError<ApiError>(error)) {
    const firstField = Object.values(error.response?.data?.errors ?? {})[0]?.[0];
    return firstField ?? error.response?.data?.message ?? 'Login failed.';
  }

  return error instanceof Error ? error.message : 'Login failed.';
}

export function CandidateLoginPage() {
  const navigate = useNavigate();
  const [form, setForm] = useState({ exam_session_id: '', name: '', token: '' });
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  async function submit(event: FormEvent) {
    event.preventDefault();
    setBusy(true);
    setError('');
    try {
      const { data } = await api.post('/candidate/login', form);
      storeCandidateSession(data.attempt_id, data.session_key);
      navigate(`/candidate/exam/${data.attempt_id}`);
    } catch (err: unknown) {
      setError(errorMessage(err));
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="login-panel">
      <div>
        <p className="eyebrow">Candidate entry</p>
        <h1>Secure Exam Platform</h1>
      </div>
      <form onSubmit={submit} className="stack">
        <label>
          Session ID
          <input value={form.exam_session_id} onChange={(e) => setForm({ ...form, exam_session_id: e.target.value })} inputMode="numeric" required />
        </label>
        <label>
          Full name / candidate no.
          <input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} autoComplete="name" required />
        </label>
        <label>
          Exam token
          <input value={form.token} onChange={(e) => setForm({ ...form, token: e.target.value })} autoComplete="one-time-code" required />
        </label>
        {error ? <p className="error">{error}</p> : null}
        <button disabled={busy} className="primary"><LogIn size={18} /> Enter exam</button>
      </form>
    </section>
  );
}
