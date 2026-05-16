import React from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Route, Routes } from 'react-router-dom';
import { AdminLayout, PublicLayout } from './components/Layout';
import { AdminDashboardPage } from './pages/AdminDashboardPage';
import { AuditLogPage } from './pages/AuditLogPage';
import { CandidateExamPage } from './pages/CandidateExamPage';
import { CandidateImportPage } from './pages/CandidateImportPage';
import { CandidateLoginPage } from './pages/CandidateLoginPage';
import { CandidateResumePage } from './pages/CandidateResumePage';
import { CandidateSubmittedPage } from './pages/CandidateSubmittedPage';
import { ExamPackagePage } from './pages/ExamPackagePage';
import { ExamSeriesPage } from './pages/ExamSeriesPage';
import { ExamSessionsPage } from './pages/ExamSessionsPage';
import { ExamsPage } from './pages/ExamsPage';
import { IncidentReportPage } from './pages/IncidentReportPage';
import { MarkingPendingPage } from './pages/MarkingPendingPage';
import { MarkingSubmissionPage } from './pages/MarkingSubmissionPage';
import { ProctorSessionPage } from './pages/ProctorSessionPage';
import { QuestionBankPage } from './pages/QuestionBankPage';
import { ScoreReportPage } from './pages/ScoreReportPage';
import { SecuritySettingsPage } from './pages/SecuritySettingsPage';
import { TokenGenerationPage } from './pages/TokenGenerationPage';
import '@coreui/coreui/dist/css/coreui.min.css';
import '../css/app.css';

createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <BrowserRouter>
      <Routes>
        <Route element={<PublicLayout />}>
          <Route path="/" element={<CandidateLoginPage />} />
          <Route path="/candidate/resume" element={<CandidateResumePage />} />
          <Route path="/candidate/exam/:attemptId" element={<CandidateExamPage />} />
          <Route path="/candidate/submitted" element={<CandidateSubmittedPage />} />
        </Route>
        <Route element={<AdminLayout />}>
          <Route path="/admin" element={<AdminDashboardPage />} />
          <Route path="/admin/series" element={<ExamSeriesPage />} />
          <Route path="/admin/exams" element={<ExamsPage />} />
          <Route path="/admin/sessions" element={<ExamSessionsPage />} />
          <Route path="/admin/packages" element={<ExamPackagePage />} />
          <Route path="/admin/question-banks" element={<QuestionBankPage />} />
          <Route path="/admin/question-banks/:bankId" element={<QuestionBankPage />} />
          <Route path="/admin/candidates" element={<CandidateImportPage />} />
          <Route path="/admin/tokens" element={<TokenGenerationPage />} />
          <Route path="/proctor/session" element={<ProctorSessionPage />} />
          <Route path="/incidents" element={<IncidentReportPage />} />
          <Route path="/marking/pending" element={<MarkingPendingPage />} />
          <Route path="/marking/answers/:answerId" element={<MarkingSubmissionPage />} />
          <Route path="/reports/scores" element={<ScoreReportPage />} />
          <Route path="/audit" element={<AuditLogPage />} />
          <Route path="/security" element={<SecuritySettingsPage />} />
        </Route>
      </Routes>
    </BrowserRouter>
  </React.StrictMode>,
);
