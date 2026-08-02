import "./App.css";
import {
  AccountPage,
  ForgotPasswordPage,
  LoginPage,
  ResetPasswordPage,
} from "./Auth";

type AppProps = {
  path?: string;
};

export function App({ path = window.location.pathname }: AppProps) {
  if (path === "/health") {
    return (
      <main className="health" aria-labelledby="health-title">
        <p className="eyebrow">Service status</p>
        <h1 id="health-title">Web application healthy</h1>
      </main>
    );
  }

  if (path === "/login") return <LoginPage />;
  if (path === "/forgot-password") return <ForgotPasswordPage />;
  if (path === "/reset-password") return <ResetPasswordPage />;
  if (path === "/account") return <AccountPage />;

  return (
    <main className="welcome" aria-labelledby="page-title">
      <p className="eyebrow">Family Photo Archive</p>
      <h1 id="page-title">A private home for family memories.</h1>
      <p>
        The web application foundation is ready. Private family sharing, people,
        stories and photographs will arrive in later roadmap stages.
      </p>
      <a href="/login">Sign in</a>
    </main>
  );
}

export default App;
