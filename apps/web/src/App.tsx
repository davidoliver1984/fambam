import { lazy, Suspense } from "react";
import { Link, Route, Routes } from "react-router";

import { AlbumPage } from "@/features/albums/pages/AlbumPage";
import { AlbumsPage } from "@/features/albums/pages/AlbumsPage";
import { CollectionPage } from "@/features/collections/pages/CollectionPage";
import { CollectionsPage } from "@/features/collections/pages/CollectionsPage";
import { DuplicateReviewPage } from "@/features/duplicates/pages/DuplicateReviewPage";
import { EventPage } from "@/features/events/pages/EventPage";
import { EventsPage } from "@/features/events/pages/EventsPage";
import { FamilyExportsPage } from "@/features/exports/pages/FamilyExportsPage";
import { FaceClustersPage } from "@/features/face-recognition/pages/FaceClustersPage";
import { FaceRecognitionReviewPage } from "@/features/face-recognition/pages/FaceRecognitionReviewPage";
import { RequireAuth } from "@/features/auth/components/RequireAuth";
import { TwoFactorChallengePage } from "@/features/auth/pages/TwoFactorChallengePage";
import { FamilyShell } from "@/features/family-spaces/components/FamilyShell";
import { FamilySpacePage } from "@/features/family-spaces/pages/FamilySpacePage";
import { FamilyManagementPage } from "@/features/family-spaces/pages/FamilyManagementPage";
import { InvitationAcceptancePage } from "@/features/invitations/pages/InvitationAcceptancePage";
import { MediaUploadPage } from "@/features/media-uploads/pages/MediaUploadPage";
import { PeoplePage } from "@/features/people/pages/PeoplePage";
import { PersonPage } from "@/features/people/pages/PersonPage";
import { PhotoPage } from "@/features/photos/pages/PhotoPage";
import { PhotosPage } from "@/features/photos/pages/PhotosPage";
import { DiscoveryPage } from "@/features/search/pages/DiscoveryPage";
import { SearchPage } from "@/features/search/pages/SearchPage";
import { CreateStoryPage } from "@/features/stories/pages/CreateStoryPage";
import { StoriesPage } from "@/features/stories/pages/StoriesPage";
import { StoryPage } from "@/features/stories/pages/StoryPage";

import "./App.css";
import "./components/ui/ui.css";
import "./journey.css";
import "./explore.css";
import "./collaboration.css";
import "./product.css";
import {
  AccountPage,
  ForgotPasswordPage,
  LoginPage,
  ResetPasswordPage,
} from "./Auth";

const DevUiPlaygroundPage = import.meta.env.DEV
  ? lazy(() =>
      import("@/features/design-system/pages/UiPlaygroundPage").then(
        ({ UiPlaygroundPage }) => ({ default: UiPlaygroundPage }),
      ),
    )
  : null;

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
      {DevUiPlaygroundPage !== null && (
        <Route
          path="/ui-playground"
          element={
            <Suspense fallback={<p>Loading the UI playground…</p>}>
              <DevUiPlaygroundPage />
            </Suspense>
          }
        />
      )}
      <Route element={<RequireAuth />}>
        <Route path="/account" element={<AccountPage />} />
        <Route path="/families/:familySlug" element={<FamilyShell />}>
          <Route index element={<FamilySpacePage />} />
          <Route path="/families/:familySlug/people" element={<PeoplePage />} />
          <Route
            path="/families/:familySlug/uploads"
            element={<MediaUploadPage />}
          />
          <Route path="/families/:familySlug/photos" element={<PhotosPage />} />
          <Route path="/families/:familySlug/search" element={<SearchPage />} />
          <Route
            path="/families/:familySlug/stories"
            element={<StoriesPage />}
          />
          <Route
            path="/families/:familySlug/stories/new"
            element={<CreateStoryPage />}
          />
          <Route
            path="/families/:familySlug/stories/:storyId"
            element={<StoryPage />}
          />
          <Route
            path="/families/:familySlug/collections"
            element={<CollectionsPage />}
          />
          <Route
            path="/families/:familySlug/collections/:collectionId"
            element={<CollectionPage />}
          />
          <Route
            path="/families/:familySlug/settings"
            element={<FamilyManagementPage />}
          />
          <Route
            path="/families/:familySlug/exports"
            element={<FamilyExportsPage />}
          />
          <Route
            path="/families/:familySlug/discover/:type/:id"
            element={<DiscoveryPage />}
          />
          <Route
            path="/families/:familySlug/duplicates"
            element={<DuplicateReviewPage />}
          />
          <Route
            path="/families/:familySlug/face-recognition"
            element={<FaceRecognitionReviewPage />}
          />
          <Route
            path="/families/:familySlug/face-clusters"
            element={<FaceClustersPage />}
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
      </Route>
      <Route path="*" element={<WelcomePage />} />
    </Routes>
  );
}
