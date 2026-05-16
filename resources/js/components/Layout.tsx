import { ReactNode, useState } from 'react';
import { Link, NavLink, Outlet, useLocation } from 'react-router-dom';
import {
  CBadge,
  CButton,
  CCard,
  CCardBody,
  CContainer,
  CFooter,
  CHeader,
  CHeaderBrand,
  CHeaderNav,
  CHeaderToggler,
  CNavItem,
  CSidebar,
  CSidebarBrand,
  CSidebarHeader,
  CSidebarNav,
} from '@coreui/react';
import { Activity, BookOpenCheck, CalendarClock, ClipboardCheck, FileText, Gauge, KeyRound, Menu, ShieldCheck, Users } from 'lucide-react';

const nav = [
  { to: '/admin', label: 'Dashboard', icon: Gauge },
  { to: '/admin/series', label: 'Series', icon: FileText },
  { to: '/admin/exams', label: 'Exams', icon: ClipboardCheck },
  { to: '/admin/sessions', label: 'Sessions', icon: CalendarClock },
  { to: '/admin/question-banks', label: 'Questions', icon: BookOpenCheck },
  { to: '/admin/packages', label: 'Packages', icon: FileText },
  { to: '/admin/candidates', label: 'Candidates', icon: Users },
  { to: '/admin/tokens', label: 'Tokens', icon: KeyRound },
  { to: '/proctor/session', label: 'Proctor', icon: Activity },
  { to: '/marking/pending', label: 'Marking', icon: ShieldCheck },
  { to: '/reports/scores', label: 'Reports', icon: FileText },
];

export function PublicLayout() {
  return (
    <main className="public-shell coreui-public">
      <CHeader className="public-header">
        <CContainer fluid>
          <CHeaderBrand as={Link} to="/" className="brand">Secure Exam Platform</CHeaderBrand>
          <CHeaderNav className="gap-3">
            <Link to="/candidate/resume">Resume</Link>
            <Link to="/admin">Admin</Link>
          </CHeaderNav>
        </CContainer>
      </CHeader>
      <Outlet />
    </main>
  );
}

export function AdminLayout() {
  const [sidebarVisible, setSidebarVisible] = useState(true);
  const location = useLocation();

  function handleNavClick() {
    if (window.innerWidth < 992) {
      setSidebarVisible(false);
    }
  }

  return (
    <div className="coreui-admin-shell">
      <CSidebar className="admin-sidebar border-end" visible={sidebarVisible} onVisibleChange={setSidebarVisible} unfoldable={false}>
        <CSidebarHeader className="border-bottom">
          <CSidebarBrand as={Link} to="/admin" className="admin-brand">
            <ShieldCheck size={22} />
            <span>Secure Exam</span>
          </CSidebarBrand>
        </CSidebarHeader>
        <CSidebarNav>
          {nav.map((item) => {
            const Icon = item.icon;
            const active = item.to === '/admin'
              ? location.pathname === '/admin'
              : location.pathname.startsWith(item.to);

            return (
              <CNavItem key={item.to}>
                <NavLink to={item.to} className={`nav-link ${active ? 'active' : ''}`} onClick={handleNavClick}>
                  <Icon size={18} />
                  <span>{item.label}</span>
                </NavLink>
              </CNavItem>
            );
          })}
        </CSidebarNav>
      </CSidebar>
      <div className="coreui-main">
        <CHeader position="sticky" className="admin-header border-bottom">
          <CContainer fluid>
            <CHeaderToggler className="d-lg-none" onClick={() => setSidebarVisible(!sidebarVisible)}>
              <Menu size={22} />
            </CHeaderToggler>
            <CHeaderBrand className="d-lg-none">Secure Exam</CHeaderBrand>
            <CHeaderNav className="ms-auto align-items-center gap-2">
              <CBadge color="success" shape="rounded-pill">MVP Online</CBadge>
              <CButton as={Link} to="/" color="light" size="sm">Candidate</CButton>
            </CHeaderNav>
          </CContainer>
        </CHeader>
        <CContainer fluid className="work-surface">
          <Outlet />
        </CContainer>
        <CFooter className="admin-footer px-4">
          <span>Secure Exam Platform</span>
          <span className="ms-auto">Laravel + React + CoreUI</span>
        </CFooter>
      </div>
    </div>
  );
}

export function PageHeader({ title, eyebrow, actions }: { title: string; eyebrow?: string; actions?: ReactNode }) {
  return (
    <header className="page-header">
      <div>
        {eyebrow ? <span>{eyebrow}</span> : null}
        <h1>{title}</h1>
      </div>
      {actions ? <div className="page-actions">{actions}</div> : null}
    </header>
  );
}

export function Stat({ label, value }: { label: string; value: string | number }) {
  return (
    <CCard className="stat border-0 shadow-sm">
      <CCardBody>
        <span>{label}</span>
        <strong>{value}</strong>
      </CCardBody>
    </CCard>
  );
}
