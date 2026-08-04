import "./App.css";
import { Link, Route, Routes } from "react-router";

import {
  AccountPage,
  ForgotPasswordPage,
  LoginPage,
  ResetPasswordPage,
} from "./Auth";
import { InvitationAcceptancePage } from "./features/invitations/pages/InvitationAcceptancePage";
import { TwoFactorChallengePage } from "./features/auth/pages/TwoFactorChallengePage";
import { RequireAuth } from "./features/auth/components/RequireAuth";
import { FamilySpacePage } from "./features/family-spaces/pages/FamilySpacePage";

function WelcomePage() {
  return (
    <main className="welcome" aria-labelledby="page-title">
      <p className="eyebrow">Family Photo Archive</p>
      <h1 id="page-title">A private home for family memories.</h1>
      <p>
        The web application foundation is ready. Private family sharing, people,
        stories and photographs will arrive in later roadmap stages.
      </p>
      <Link to="/login">Sign in</Link>
    </main>
  );
}

function HealthPage() {
  return (
    <main className="health" aria-labelledby="health-title">
      <p className="eyebrow">Service status</p>
      <h1 id="health-title">Web application healthy</h1>
    </main>
  );
}

export function App() {
  return (
    <Routes>
      <Route path="/" element={<WelcomePage />} />
      <Route path="/health" element={<HealthPage />} />
      <Route path="/login" element={<LoginPage />} />
      <Route path="/forgot-password" element={<ForgotPasswordPage />} />
      <Route path="/reset-password" element={<ResetPasswordPage />} />
      <Route path="/accept-invitation" element={<InvitationAcceptancePage />} />
      <Route
        path="/two-factor-challenge"
        element={<TwoFactorChallengePage />}
      />
      <Route element={<RequireAuth />}>
        <Route path="/account" element={<AccountPage />} />
        <Route path="/families/:familySlug" element={<FamilySpacePage />} />
      </Route>
      <Route path="*" element={<WelcomePage />} />
    </Routes>
  );
}
