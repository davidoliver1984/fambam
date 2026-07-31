Private Family Photo Archive — Project Definition

Project purpose

Build a private, family-centred photo and memory platform for relatives who want to share photographs without relying on traditional social media.

The project exists because much of the family lives internationally and still wants an easy way to share photographs, events and memories. However, many family members no longer enjoy or trust mainstream social platforms and primarily continue using them because they lack a suitable private alternative.

This is not intended to become another public social network.

It is a private digital family home: a place where relatives can upload photographs, preserve their history, tell the stories behind old images and stay connected across countries.

The project should feel more like opening a shared family photo box than scrolling through an algorithmic feed.

⸻

Product philosophy

Most existing photo and social platforms are user-centred.

This platform is family-centred.

A user owns an account, but the family space provides the shared context in which photographs, people, relationships, events and stories are organised.

The application should prioritise:

* privacy;
* family ownership;
* meaningful memories;
* simple sharing;
* historical preservation;
* human confirmation over unreliable automation;
* long-term portability;
* clear and understandable permissions.

It should avoid:

* public follower systems;
* popularity metrics;
* advertising;
* algorithmic engagement;
* public profiles;
* infinite scrolling designed to maximise attention;
* treating family photographs as content for platform training or monetisation.

⸻

Core product model

Family spaces

The principal organisational boundary is a family space.

A person may belong to one or more family spaces.

Examples:

* Oliver Family;
* Mum’s Immediate Family;
* Extended Oliver Family;
* Wedding Group;
* International Relatives;
* Private Sibling Space.

A family space contains:

* members;
* people;
* relationships;
* photographs;
* events;
* stories;
* comments;
* memories;
* permissions;
* recognition data;
* audit history.

The family space is also the primary security and tenancy boundary.

⸻

Family circles

Families naturally contain different circles.

For example:

Oliver Family
│
├── Immediate Family
│   ├── Mum
│   ├── David
│   ├── Brothers
│   └── Sisters
│
├── Grandparents
├── Aunts and Uncles
├── Cousins
├── Partners
└── Close Family Friends

These circles are useful for presentation, filtering and optional sharing.

They should not replace the underlying permissions system.

Relationships describe how people are connected.

Roles and permissions determine what an account is allowed to access or change.

Those concepts must remain separate.

⸻

Accounts, people and relationships

A user account and a person are not the same thing.

A user account represents someone who can log into the application.

A person represents an individual who exists within the family archive.

A person may:

* have a linked user account;
* not yet have an account;
* be a child;
* be an elderly relative who does not use technology;
* be deceased;
* appear only in historical photographs;
* belong to several family branches.

Example:

Person
├── Name
├── Date of birth
├── Date of death
├── Profile photograph
├── Biography
├── Linked user account, if any
└── Relationships

Relationships should be represented independently:

David
├── Mother → Mum
├── Brother → James
├── Sister → Sarah
├── Grandmother → Joan
└── Aunt → Susan

The system should be able to present relationships from the perspective of the current user.

For example, the same person may appear as:

* Mum to David;
* Gran to David’s children;
* Sister to David’s aunt;
* Daughter to David’s grandmother.

⸻

Photo ownership

A photograph has several different concepts of ownership that must not be conflated.

A photograph may have:

* an uploader;
* an original photographer;
* an original physical owner;
* a contributor who scanned it;
* a family space responsible for storing it;
* one or more people appearing in it.

The platform should preserve this provenance.

Example:

Uploaded by: David
Original photographer: Grandad
Physical source: Mum’s family album
Scanned by: Sarah
Estimated date: Summer 1987
Date confidence: Approximate

This information may eventually become as valuable as the image itself.

⸻

Photo organisation

Albums should not be the only or even primary way photographs are organised.

Many useful collections can be generated dynamically from metadata.

Examples:

* Mum;
* Mum and David;
* Christmas 1998;
* Blackpool holidays;
* weddings;
* photographs containing all siblings;
* photographs from the 1980s;
* recently scanned photographs;
* photographs without confirmed dates;
* photographs awaiting identity review.

Traditional albums should still exist for deliberate collections such as:

* Mum’s 70th Birthday;
* Spain 2027;
* Sarah and James’s Wedding;
* Grandad’s Physical Photo Album;
* Christmas 2026.

The same photograph may appear in many views without being duplicated.

⸻

Family archive features

People pages

Every person should have a meaningful archive page, regardless of whether they have an account.

Example:

Grandad
Born: 1928
Died: 2014
Appears in:
438 photographs
Stories:
26
Voice recordings:
4
Timeline:
1928–2014

A deceased relative remains a first-class person in the archive.

The application is not merely about current active users.

⸻

Photo stories

Each photograph should support family knowledge in addition to technical metadata.

Technical metadata may include:

* original filename;
* capture date;
* camera;
* orientation;
* dimensions;
* GPS location;
* checksum;
* MIME type;
* file size.

Family metadata may include:

* who appears in the photograph;
* what was happening;
* where it was taken;
* an approximate date;
* who contributed it;
* who originally owned it;
* confidence in the supplied information;
* personal stories;
* corrections;
* voice memories;
* source album or collection.

⸻

Memories

The platform should surface memories in a gentle, meaningful way.

Examples:

* 15 years ago today;
* Christmas through the years;
* Mum through the years;
* photographs of you and Grandad;
* this month across previous decades;
* newly discovered photographs containing a relative;
* photographs whose story was recently updated.

The homepage should feel personal rather than algorithmic.

Example:

Good afternoon, Mum.

David uploaded 18 holiday photographs yesterday.

Here is one from exactly 25 years ago today.

Grandad appears in three newly scanned photographs.

Sarah added the story behind your wedding photograph.

⸻

International family sharing

The application exists partly because the family is spread across different countries.

It should therefore make remote sharing simple.

Useful features include:

* invite-only registration;
* family-specific invitations;
* event upload links;
* shared event albums;
* timezone-aware dates;
* reliable mobile uploads;
* email notifications;
* optional digest emails;
* downloadable originals;
* bulk exports;
* low-bandwidth image variants;
* accessible interfaces for less technical relatives.

The platform must remain easy enough for elderly and non-technical family members.

⸻

Image recognition

The initial preferred face-recognition implementation is a self-hosted provider based on InsightFace or another suitable open model.

The provider name does not need to appear in the user interface.

Within the application it should be hidden behind a neutral abstraction such as:

FaceRecognitionProvider

Possible implementations:

FaceRecognitionProvider
├── LocalFaceRecognitionProvider
├── AwsRekognitionProvider
└── FutureFaceRecognitionProvider

The system should not tightly couple photographs, people or confirmed identities to one machine-learning provider.

Recognition outputs must be treated as versioned derived data.

The system should retain:

* provider name;
* model name;
* model version;
* processing configuration;
* source image checksum;
* processed date;
* detected face bounding boxes;
* embeddings;
* similarity scores;
* cluster assignments;
* confidence scores.

Human-confirmed identity must remain separate from model-generated suggestions.

The distinction is:

Machine observation:
This face resembles Person Cluster 17.
Human knowledge:
This is definitely Mum.

Human knowledge must survive provider changes, model upgrades and reprocessing.

⸻

Recognition workflow

A photograph may pass through the following stages:

Original upload
    ↓
Validation
    ↓
Metadata extraction
    ↓
Canonical image generation
    ↓
Thumbnail and display variant generation
    ↓
Duplicate detection
    ↓
Face detection
    ↓
Face embedding generation
    ↓
Face clustering
    ↓
Suggested identity matching
    ↓
Human confirmation

A stable canonical image should be used for analysis.

Changing website thumbnail dimensions should not trigger recognition again.

Recognition should be rerun when:

* the original image changes;
* the canonical image changes materially;
* the recognition provider changes;
* the recognition model changes;
* the embedding format changes.

Recognition should not be rerun when:

* a caption changes;
* an album changes;
* a comment is added;
* a person is renamed;
* a confirmed person assignment changes;
* presentation thumbnails change.

Changing providers requires regeneration of provider-specific embeddings because different providers do not produce compatible facial representations.

⸻

Recognition behaviour

False automatic merges are more harmful than missed matches.

The recognition system should therefore initially favour precision over recall.

Suggested behaviour:

High confidence
→ automatically associate or strongly recommend
Medium confidence
→ request human review
Low confidence
→ create a separate unnamed person cluster

The interface must make it easy to:

* confirm a person;
* reject a suggestion;
* merge person clusters;
* split incorrect clusters;
* mark a face as unknown;
* mark a face as not relevant;
* exclude a person from recognition;
* correct multiple photographs in bulk.

The product workflow should assume that AI is fallible.

⸻

Semantic image search

Face recognition and semantic photo search are separate capabilities.

Semantic search may eventually allow queries such as:

* dog on the beach;
* Dad wearing a red shirt;
* Christmas dinner;
* Mum holding a baby;
* old black-and-white wedding photographs;
* photographs taken in a garden;
* photographs of all the siblings together.

This may use a model such as OpenCLIP, SigLIP or another replaceable image-embedding provider.

As with face recognition, semantic embeddings must be versioned and reproducible.

⸻

Duplicate detection

The system should identify:

* exact duplicate files;
* visually identical images with changed filenames;
* resized copies;
* recompressed copies;
* lightly edited versions;
* burst photographs that are extremely similar.

Possible techniques include:

* cryptographic checksums;
* perceptual hashes;
* image embeddings;
* metadata comparisons.

The system should never delete duplicates automatically.

It should provide review and consolidation tools.

⸻

Events

Events provide intentional collections around real family occasions.

Examples:

* birthdays;
* weddings;
* holidays;
* Christmas;
* anniversaries;
* funerals and memorials;
* reunions;
* graduations.

An event may contain:

* photographs;
* videos;
* attendees;
* location;
* start and end dates;
* descriptions;
* stories;
* comments;
* invitations;
* upload permissions.

Members should be able to contribute to the same event from different countries and devices.

⸻

Privacy and security

Privacy is a core product feature.

The platform should use:

* invite-only access;
* family-space isolation;
* role-based permissions;
* encrypted transport;
* private object storage;
* signed media URLs;
* audit logging;
* strong account recovery;
* optional multi-factor authentication;
* secure session management;
* export tools;
* deletion tools;
* retention policies;
* backup and restoration procedures.

Face embeddings and recognition data require particular care.

The system should support:

* explicit recognition consent;
* recognition exclusion;
* deletion of face embeddings;
* disabling recognition for a person;
* guardian-controlled settings for children;
* no public face search;
* no cross-family identity matching;
* no use of family photographs for external model training;
* clear disclosure of how recognition works.

Relationships must never be used as a substitute for permissions.

For example:

Mother
→ not automatically permitted to view everything

Instead:

Family role
├── Owner
├── Administrator
├── Member
├── Contributor
└── Guest

⸻

Suggested technical architecture

A likely architecture is:

React or Next.js frontend
             │
             ▼
         Laravel API
             │
     ┌───────┼──────────┐
     │       │          │
PostgreSQL  Redis   Object Storage
 + pgvector queues   S3-compatible
     │
     ▼
Python image-analysis service
     │
     ├── face detection
     ├── face embeddings
     ├── semantic embeddings
     ├── duplicate analysis
     └── optional captioning

Laravel remains the business authority for:

* authentication;
* family spaces;
* people;
* relationships;
* memberships;
* permissions;
* albums;
* events;
* comments;
* stories;
* audit history;
* upload orchestration;
* retention;
* deletion.

The Python service performs machine-learning inference only.

This preserves clear service boundaries and keeps model providers replaceable.

⸻

Initial domain model

A possible first domain model includes:

User
FamilySpace
FamilyMembership
Person
UserPersonLink
PersonRelationship
FamilyCircle
FamilyCircleMembership
Photo
PhotoAsset
PhotoVariant
PhotoContribution
PhotoPerson
PhotoStory
PhotoComment
Album
AlbumPhoto
Event
EventPhoto
PhotoMetadata
PhotoLocation
PhotoAnalysis
DetectedFace
FaceEmbedding
FaceCluster
FaceIdentityAssignment
SemanticEmbedding
DuplicateCandidate
Invitation
Notification
ConsentRecord
AuditEvent
ExportRequest
DeletionRequest

This is an initial design subject to ADR review.

The schema should not be finalised before the tenancy, ownership and permission models are agreed.

⸻

V1 scope

The first version should solve the actual family problem without becoming an endless platform project.

V1 should include:

* invite-only accounts;
* family spaces;
* membership roles;
* people and relationships;
* responsive photograph upload;
* original image preservation;
* thumbnails and display variants;
* metadata extraction;
* albums;
* events;
* comments;
* simple reactions;
* photograph stories;
* exact duplicate detection;
* face detection;
* face grouping;
* manual person naming;
* search by person;
* search by date;
* search by event;
* search by location;
* privacy controls;
* exports;
* backup and restore documentation;
* accessible family homepage;
* audit trail for important actions.

Semantic image search may be included in V1 if it does not delay the core sharing experience.

⸻

Later phases

Potential later features include:

* mobile applications;
* automatic camera backup;
* semantic natural-language search;
* generated memory collections;
* voice memories;
* video support;
* video face recognition;
* family tree visualisation;
* historical timelines;
* AI-assisted date estimation;
* AI-assisted location estimation;
* image restoration;
* scratch and damage repair;
* colourisation;
* automatic captions;
* print ordering;
* collaborative family history;
* conversational archive search;
* end-to-end encrypted private collections;
* temporary event guest access.

⸻

Explicit non-goals

The application is not intended to become:

* a public social network;
* an advertising platform;
* a follower-based platform;
* a creator platform;
* a popularity contest;
* an infinite engagement feed;
* a marketplace;
* an AI training-data business;
* a generic cloud-drive clone.

The project succeeds when the family enjoys using it and trusts it with its memories.

⸻

Engineering standards

The project should follow the same engineering discipline as the RAG platform.

It should include:

* PROJECT_ROADMAP.md;
* docs/IMPLEMENTATION_GUIDE.md;
* tasks.json;
* docs/adr/;
* docs/journal/;
* root README.md;
* CONTRIBUTING.md;
* CLAUDE.md;
* clear service boundaries;
* phase and stage identifiers;
* verification commands;
* focused commit boundaries;
* architectural decision records;
* implementation journals;
* explicit documentation ownership;
* reproducible local development;
* automated tests;
* security review;
* operational documentation.

Each implementation stage should contain:

1. Objective
2. Engineering rationale
3. Prerequisites
4. Commands
5. Expected changes
6. Verification
7. Risks and edge cases
8. Documentation updates
9. Commit boundary

⸻

Suggested AI-assisted workflow

The project should use multiple AI systems deliberately rather than interchangeably.

ChatGPT

Primary responsibilities:

* product definition;
* architecture;
* domain modelling;
* roadmap design;
* implementation-guide structure;
* ADR drafting;
* design reviews;
* security analysis;
* phase reviews;
* explaining concepts and trade-offs.

Claude

Primary responsibilities:

* architectural critique;
* alternative designs;
* ADR review;
* wording refinement;
* contradiction detection;
* missing-case analysis;
* documentation review;
* challenging assumptions.

Codex

Primary responsibilities:

* repository inspection;
* implementation;
* code changes;
* test execution;
* scaffolding;
* validation;
* maintaining task state;
* producing focused commits;
* reporting actual repository status.

No model should silently redefine the roadmap, architecture or phase boundaries.

Material changes should be discussed and recorded through an ADR or explicit roadmap revision.

⸻

Working cycle

A typical project session may follow:

1. Review roadmap and current task
2. Discuss the concept and architecture
3. Confirm or update ADRs
4. Give Codex one bounded implementation stage
5. Review the resulting code and tests
6. Ask Claude to challenge or review where valuable
7. Resolve findings
8. Run verification
9. Update implementation guide
10. Update tasks.json
11. Write journal entry
12. Commit at the agreed boundary

The project should preserve the same sense of control, clarity and progress that has made the RAG platform enjoyable to build.

⸻

Project statement

This project is a private family photo and memory archive created for a real family need.

It allows relatives living across different countries to share photographs without depending on mainstream social media.

It combines thoughtful product design, secure multi-user architecture, image processing, machine learning, search, storage, privacy and long-term digital preservation.

Its value as a portfolio project comes from the fact that it was not invented merely to demonstrate technologies.

It exists because the family genuinely needs it.