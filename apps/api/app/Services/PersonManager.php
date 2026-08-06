<?php

namespace App\Services;

use App\Enums\DatePrecision;
use App\Enums\PersonIdentityStatus;
use App\Enums\PersonProposalStatus;
use App\Models\FamilySpace;
use App\Models\Person;
use App\Models\PersonDetailProposal;
use App\Models\User;
use App\People\UncertainDate;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PersonManager
{
    private const DETAIL_FIELDS = [
        'preferred_name',
        'alternate_names',
        'birth_date',
        'is_deceased',
        'death_date',
        'biography',
    ];

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $tenantContext,
    ) {}

    /** @param array<string, mixed> $input */
    public function create(FamilySpace $familySpace, User $actor, array $input, Request $request): Person
    {
        return DB::transaction(function () use ($familySpace, $actor, $input, $request): Person {
            $authoritative = $this->tenantContext->membership()->role->canManageMembers();
            $person = new Person($this->normalizedAttributes($input, [
                'preferred_name' => '',
                'alternate_names' => [],
                'birth_date' => ['precision' => DatePrecision::Unknown->value, 'value' => null],
                'is_deceased' => false,
                'death_date' => ['precision' => DatePrecision::Unknown->value, 'value' => null],
                'biography' => null,
            ]));
            $person->family_space_id = $familySpace->id;
            $person->created_by = $actor->id;
            $person->identity_status = $authoritative
                ? PersonIdentityStatus::Confirmed
                : PersonIdentityStatus::Provisional;

            if ($authoritative) {
                $person->confirmed_by = $actor->id;
                $person->confirmed_at = CarbonImmutable::now();
            }

            $person->save();
            $this->audit->record('person.created', $person, $actor, $request, [
                'identity_status' => $person->identity_status->value,
            ]);

            return $person;
        });
    }

    /** @param array<string, mixed> $input */
    public function update(Person $person, User $actor, array $input, Request $request): Person
    {
        return DB::transaction(function () use ($person, $actor, $input, $request): Person {
            $locked = Person::query()->lockForUpdate()->findOrFail($person->id);
            $beforeDeceased = $locked->is_deceased;
            $attributes = $this->normalizedAttributes($input, $this->currentDetails($locked));
            $status = isset($input['identity_status'])
                ? PersonIdentityStatus::from((string) $input['identity_status'])
                : PersonIdentityStatus::Confirmed;
            $attributes['identity_status'] = $status;

            if ($status === PersonIdentityStatus::Confirmed) {
                $attributes['confirmed_by'] = $actor->id;
                $attributes['confirmed_at'] = CarbonImmutable::now();
            }

            $locked->update($attributes);
            $changedFields = array_values(array_intersect(
                array_keys($locked->getChanges()),
                [...self::DETAIL_FIELDS, 'birth_date_precision', 'death_date_precision', 'identity_status'],
            ));
            $this->audit->record('person.identity_changed', $locked, $actor, $request, [
                'changed_fields' => $changedFields,
            ]);

            if ($beforeDeceased !== $locked->is_deceased
                || in_array('death_date', $changedFields, true)
                || in_array('death_date_precision', $changedFields, true)) {
                $this->audit->record('person.deceased_changed', $locked, $actor, $request, [
                    'is_deceased' => $locked->is_deceased,
                    'death_date_precision' => $locked->death_date_precision->value,
                ]);
            }

            return $locked;
        });
    }

    /** @param array<string, mixed> $input */
    public function propose(Person $person, User $actor, array $input, Request $request): PersonDetailProposal
    {
        return DB::transaction(function () use ($person, $actor, $input, $request): PersonDetailProposal {
            $locked = Person::query()->lockForUpdate()->findOrFail($person->id);
            $changes = Arr::only($input, self::DETAIL_FIELDS);
            $this->normalizedAttributes($changes, $this->currentDetails($locked));

            $proposal = PersonDetailProposal::query()->create([
                'family_space_id' => $locked->family_space_id,
                'person_id' => $locked->id,
                'changes' => $this->normalizedProposalChanges($changes),
                'status' => PersonProposalStatus::Pending,
                'proposed_by' => $actor->id,
            ]);
            $this->audit->record('person.detail_proposed', $proposal, $actor, $request, [
                'person_id' => $locked->id,
                'changed_fields' => array_keys($changes),
            ]);

            return $proposal;
        });
    }

    public function resolveProposal(
        Person $person,
        PersonDetailProposal $proposal,
        User $actor,
        PersonProposalStatus $resolution,
        Request $request,
    ): PersonDetailProposal {
        if ($resolution === PersonProposalStatus::Pending) {
            throw ValidationException::withMessages(['proposal' => ['A pending proposal must be approved or rejected.']]);
        }

        return DB::transaction(function () use ($person, $proposal, $actor, $resolution, $request): PersonDetailProposal {
            $lockedPerson = Person::query()->lockForUpdate()->findOrFail($person->id);
            $lockedProposal = PersonDetailProposal::query()->lockForUpdate()->findOrFail($proposal->id);

            if ($lockedProposal->person_id !== $lockedPerson->id
                || $lockedProposal->status !== PersonProposalStatus::Pending) {
                throw ValidationException::withMessages(['proposal' => ['This proposal can no longer be resolved.']]);
            }

            if ($resolution === PersonProposalStatus::Approved) {
                $attributes = $this->normalizedAttributes(
                    $lockedProposal->changes,
                    $this->currentDetails($lockedPerson),
                );
                $attributes['identity_status'] = PersonIdentityStatus::Confirmed;
                $attributes['confirmed_by'] = $actor->id;
                $attributes['confirmed_at'] = CarbonImmutable::now();
                $lockedPerson->update($attributes);
            }

            $lockedProposal->update([
                'status' => $resolution,
                'resolved_by' => $actor->id,
                'resolved_at' => now(),
            ]);
            $this->audit->record(
                $resolution === PersonProposalStatus::Approved
                    ? 'person.detail_proposal_approved'
                    : 'person.detail_proposal_rejected',
                $lockedProposal,
                $actor,
                $request,
                ['person_id' => $lockedPerson->id],
            );

            return $lockedProposal;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    private function normalizedAttributes(array $input, array $current): array
    {
        $details = [...$current, ...Arr::only($input, self::DETAIL_FIELDS)];
        /** @var array{precision: string, value?: string|null} $birthInput */
        $birthInput = $details['birth_date'];
        /** @var array{precision: string, value?: string|null} $deathInput */
        $deathInput = $details['death_date'];
        $birth = UncertainDate::fromInput($birthInput);
        $death = UncertainDate::fromInput($deathInput);
        $isDeceased = (bool) $details['is_deceased'];

        if (! $isDeceased && $death->precision !== DatePrecision::Unknown) {
            throw ValidationException::withMessages([
                'death_date' => ['Death information requires the Person to be marked as deceased.'],
            ]);
        }

        if ($birth->date !== null && $death->date !== null && $death->date->lessThan($birth->date)) {
            throw ValidationException::withMessages([
                'death_date' => ['Death information cannot be earlier than birth information.'],
            ]);
        }

        /** @var list<string> $alternateNames */
        $alternateNames = $details['alternate_names'];

        return [
            'preferred_name' => trim((string) $details['preferred_name']),
            'alternate_names' => array_values(array_unique(array_map('trim', $alternateNames))),
            'birth_date' => $birth->storageDate(),
            'birth_date_precision' => $birth->precision,
            'is_deceased' => $isDeceased,
            'death_date' => $death->storageDate(),
            'death_date_precision' => $death->precision,
            'biography' => $details['biography'] === null ? null : trim((string) $details['biography']),
        ];
    }

    /** @return array<string, mixed> */
    private function currentDetails(Person $person): array
    {
        return [
            'preferred_name' => $person->preferred_name,
            'alternate_names' => $person->alternate_names ?? [],
            'birth_date' => UncertainDate::fromStorage(
                $person->birth_date_precision,
                $person->birth_date?->format('Y-m-d'),
            )->toPayload(),
            'is_deceased' => $person->is_deceased,
            'death_date' => UncertainDate::fromStorage(
                $person->death_date_precision,
                $person->death_date?->format('Y-m-d'),
            )->toPayload(),
            'biography' => $person->biography,
        ];
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function normalizedProposalChanges(array $changes): array
    {
        if (isset($changes['alternate_names']) && is_array($changes['alternate_names'])) {
            $changes['alternate_names'] = array_values(array_unique(array_map(
                fn (mixed $name): string => trim((string) $name),
                $changes['alternate_names'],
            )));
        }

        if (array_key_exists('preferred_name', $changes)) {
            $changes['preferred_name'] = trim((string) $changes['preferred_name']);
        }

        if (array_key_exists('biography', $changes) && $changes['biography'] !== null) {
            $changes['biography'] = trim((string) $changes['biography']);
        }

        return $changes;
    }
}
