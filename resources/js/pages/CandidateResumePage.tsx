import { FormEvent, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { RotateCcw } from 'lucide-react';
import { api, storeCandidateSession } from '../lib/api';

export function CandidateResumePage() {
  const navigate = useNavigate();
  const [resumeToken, setResumeToken] = useState('');
  const [error, setError] = useState('');

  async function submit(event: FormEvent) {
    event.preventDefault();
    setError('');
    try {
      const { data } = await api.post('/candidate/resume', { resume_token: resumeToken });
      storeCandidateSession(data.attempt_id, data.session_key);
      navigate(`/candidate/exam/${data.attempt_id}`);
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Resume failed.');
    }
  }

  return (
    <section className="login-panel compact">
      <p className="eyebrow">Resume access</p>
      <h1>Resume an interrupted attempt</h1>
      <form onSubmit={submit} className="stack">
        <label>
          Resume token
          <input value={resumeToken} onChange={(event) => setResumeToken(event.target.value)} required />
        </label>
        {error ? <p className="error">{error}</p> : null}
        <button className="primary"><RotateCcw size={18} /> Resume</button>
      </form>
    </section>
  );
}
