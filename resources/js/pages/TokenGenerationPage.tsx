import { FormEvent, useState } from 'react';
import { Printer } from 'lucide-react';
import { PageHeader } from '../components/Layout';
import { api } from '../lib/api';

type TokenSlip = {
  candidate_id: number;
  candidate_number: string;
  candidate_name: string;
  exam_session_id: number;
  plain_token: string;
  expires_at?: string;
};

export function TokenGenerationPage() {
  const [sessionId, setSessionId] = useState('');
  const [candidateIds, setCandidateIds] = useState('');
  const [allCandidates, setAllCandidates] = useState(false);
  const [tokens, setTokens] = useState<TokenSlip[]>([]);

  async function submit(event: FormEvent) {
    event.preventDefault();
    const { data } = await api.post('/admin/tokens/generate', {
      exam_session_id: Number(sessionId),
      candidate_ids: allCandidates ? undefined : candidateIds.split(',').map((id) => Number(id.trim())).filter(Boolean),
      all_candidates: allCandidates,
    });
    setTokens(data.tokens);
  }

  return (
    <div>
      <PageHeader title="Token Generation" eyebrow="One-time hashed tokens" />
      <form className="toolbar" onSubmit={submit}>
        <input placeholder="Session ID" value={sessionId} onChange={(event) => setSessionId(event.target.value)} />
        <input placeholder="Candidate IDs, comma separated" value={candidateIds} disabled={allCandidates} onChange={(event) => setCandidateIds(event.target.value)} />
        <label className="inline-check"><input type="checkbox" checked={allCandidates} onChange={(event) => setAllCandidates(event.target.checked)} /> All candidates</label>
        <button className="primary">Generate</button>
        {tokens.length ? <button type="button" className="secondary" onClick={() => window.print()}><Printer size={18} /> Print slips</button> : null}
      </form>
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
    </div>
  );
}
