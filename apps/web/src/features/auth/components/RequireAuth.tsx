import { Navigate, Outlet, useLocation } from "react-router";

import { useCurrentUserQuery } from "@/features/account/hooks/useCurrentUserQuery";

export function RequireAuth() {
  const currentUser = useCurrentUserQuery();
  const location = useLocation();

  if (currentUser.isPending) {
    return <p role="status">Checking your session…</p>;
  }

  if (currentUser.isError) {
    return <Navigate to="/login" replace state={{ from: location }} />;
  }

  return <Outlet />;
}
