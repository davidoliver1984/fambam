import { Component, type ReactNode } from "react";

type Props = { children: ReactNode };
type State = { failed: boolean };

export class AppErrorBoundary extends Component<Props, State> {
  state: State = { failed: false };

  static getDerivedStateFromError(): State {
    return { failed: true };
  }

  componentDidCatch(): void {
    // A production reporter may receive normalized, non-sensitive details later.
  }

  render() {
    if (this.state.failed) {
      return (
        <main className="auth" aria-labelledby="error-title">
          <p className="eyebrow">fambam</p>
          <h1 id="error-title">Something went wrong</h1>
          <p>Please reload the page and try again.</p>
        </main>
      );
    }

    return this.props.children;
  }
}
