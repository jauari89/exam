import { Link } from 'react-router-dom';

export function CandidateSubmittedPage() {
  return (
    <section className="login-panel compact">
      <p className="eyebrow">Submission received</p>
      <h1>Your answers have been submitted</h1>
      <p className="muted">You may close this page. Any tryout feedback allowed by the exam settings will be available from your teacher or proctor.</p>
      <Link to="/" className="secondary">Back to login</Link>
    </section>
  );
}
