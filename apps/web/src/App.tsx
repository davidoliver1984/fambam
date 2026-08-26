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
import { PeoplePage } from "./features/people/pages/PeoplePage";
import { PersonPage } from "./features/people/pages/PersonPage";
import { MediaUploadPage } from "./features/media-uploads/pages/MediaUploadPage";
import { PhotoPage } from "./features/photos/pages/PhotoPage";
import { PhotosPage } from "./features/photos/pages/PhotosPage";
import { AlbumsPage } from "./features/albums/pages/AlbumsPage";
import { EventsPage } from "./features/events/pages/EventsPage";
import { EventPage } from "./features/events/pages/EventPage";
import { AlbumPage } from "./features/albums/pages/AlbumPage";
import { DuplicateReviewPage } from "./features/duplicates/pages/DuplicateReviewPage";

function WelcomePage() {
  return (
    <main className="welcome" aria-labelledby="page-title">
      <p className="eyebrow">fambam</p>
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
        <Route path="/families/:familySlug/people" element={<PeoplePage />} />
        <Route
          path="/families/:familySlug/uploads"
          element={<MediaUploadPage />}
        />
        <Route path="/families/:familySlug/photos" element={<PhotosPage />} />
        <Route
          path="/families/:familySlug/duplicates"
          element={<DuplicateReviewPage />}
        />
        <Route path="/families/:familySlug/albums" element={<AlbumsPage />} />
        <Route
          path="/families/:familySlug/albums/:albumId"
          element={<AlbumPage />}
        />
        <Route path="/families/:familySlug/events" element={<EventsPage />} />
        <Route
          path="/families/:familySlug/events/:eventId"
          element={<EventPage />}
        />
        <Route
          path="/families/:familySlug/photos/:photoId"
          element={<PhotoPage />}
        />
        <Route
          path="/families/:familySlug/people/:personId"
          element={<PersonPage />}
        />
      </Route>
      <Route path="*" element={<WelcomePage />} />
    </Routes>
  );
}
