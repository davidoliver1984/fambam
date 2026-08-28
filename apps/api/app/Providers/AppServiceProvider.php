<?php

namespace App\Providers;

use App\FaceAnalysis\FaceAnalysisRequestPublisher;
use App\FaceAnalysis\FaceAnalysisResultAuthority;
use App\FaceAnalysis\FaceAnalysisResultQueue;
use App\FaceAnalysis\S3FaceAnalysisResultAuthority;
use App\FaceAnalysis\SqsFaceAnalysisTransport;
use App\FaceRecognition\PostgresSimilaritySearch;
use App\FaceRecognition\SimilaritySearch;
use App\Listeners\SecurityEventSubscriber;
use App\Media\CanonicalImageGenerator;
use App\Media\ClamAvMalwareScanner;
use App\Media\ExifToolMediaMetadataExtractor;
use App\Media\FamilyMediaStorageCleaner;
use App\Media\ImageDecoderValidator;
use App\Media\ImageMagickCanonicalImageGenerator;
use App\Media\ImageMagickDecoderValidator;
use App\Media\ImageMagickDifferenceHasher;
use App\Media\ImageMagickPresentationVariantGenerator;
use App\Media\MalwareScanner;
use App\Media\MediaDeliveryUrlSigner;
use App\Media\MediaMetadataExtractor;
use App\Media\MediaObjectStorage;
use App\Media\PerceptualHasher;
use App\Media\PresentationVariantGenerator;
use App\Media\S3FamilyMediaStorageCleaner;
use App\Media\S3MediaDeliveryUrlSigner;
use App\Media\S3MediaObjectStorage;
use App\Services\AuthenticateUser;
use App\Services\PwnedPasswordVerifier;
use App\Tenancy\DatabaseTenantContext;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Psr\Log\LoggerInterface;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(DatabaseTenantContext::class);
        $this->app->bind(MediaObjectStorage::class, S3MediaObjectStorage::class);
        $this->app->bind(MediaDeliveryUrlSigner::class, S3MediaDeliveryUrlSigner::class);
        $this->app->bind(FamilyMediaStorageCleaner::class, S3FamilyMediaStorageCleaner::class);
        $this->app->bind(ImageDecoderValidator::class, ImageMagickDecoderValidator::class);
        $this->app->bind(MalwareScanner::class, ClamAvMalwareScanner::class);
        $this->app->bind(MediaMetadataExtractor::class, ExifToolMediaMetadataExtractor::class);
        $this->app->bind(CanonicalImageGenerator::class, ImageMagickCanonicalImageGenerator::class);
        $this->app->bind(PresentationVariantGenerator::class, ImageMagickPresentationVariantGenerator::class);
        $this->app->bind(PerceptualHasher::class, ImageMagickDifferenceHasher::class);
        $this->app->singleton(SqsFaceAnalysisTransport::class);
        $this->app->bind(FaceAnalysisRequestPublisher::class, SqsFaceAnalysisTransport::class);
        $this->app->bind(FaceAnalysisResultQueue::class, SqsFaceAnalysisTransport::class);
        $this->app->bind(FaceAnalysisResultAuthority::class, S3FaceAnalysisResultAuthority::class);
        $this->app->bind(SimilaritySearch::class, PostgresSimilaritySearch::class);

        $this->app->singleton(
            AuthenticateUser::class,
            fn ($app): AuthenticateUser => new AuthenticateUser(
                $app->make(Hasher::class),
                (string) config('account-security.dummy_password_hash'),
            ),
        );

        $this->app->extend(
            UncompromisedVerifier::class,
            fn ($verifier, $app): PwnedPasswordVerifier => new PwnedPasswordVerifier(
                $app->make(Factory::class),
                $app->make(LoggerInterface::class),
                (float) config('account-security.compromised_password_check.timeout_seconds'),
            ),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Password::defaults(function (): Password {
            $rule = Password::min(15)->max(255);

            return config('account-security.compromised_password_check.enabled')
                ? $rule->uncompromised()
                : $rule;
        });

        Event::subscribe(SecurityEventSubscriber::class);
    }
}
