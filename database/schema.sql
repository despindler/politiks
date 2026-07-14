PRAGMA foreign_keys = ON;
PRAGMA journal_mode = DELETE;

-- Reproducible import and source provenance ---------------------------------

CREATE TABLE import_run (
    id INTEGER PRIMARY KEY,
    snapshot_name TEXT NOT NULL,
    manifest_path TEXT NOT NULL,
    schema_version TEXT NOT NULL,
    started_at TEXT NOT NULL,
    completed_at TEXT,
    status TEXT NOT NULL CHECK (status IN ('running', 'completed', 'failed')),
    UNIQUE (snapshot_name, schema_version)
);

CREATE TABLE source_file (
    id INTEGER PRIMARY KEY,
    import_run_id INTEGER NOT NULL REFERENCES import_run(id) ON DELETE CASCADE,
    endpoint TEXT NOT NULL,
    local_path TEXT NOT NULL,
    requested_url TEXT,
    final_url TEXT,
    request_parameters_json TEXT,
    retrieved_at TEXT,
    http_status INTEGER,
    content_type TEXT,
    byte_count INTEGER NOT NULL CHECK (byte_count >= 0),
    sha256 TEXT NOT NULL CHECK (length(sha256) = 64),
    attribution TEXT,
    manifest_state TEXT NOT NULL,
    source_format TEXT NOT NULL,
    is_json INTEGER NOT NULL CHECK (is_json IN (0, 1)),
    UNIQUE (import_run_id, local_path)
);

CREATE TABLE source_record (
    id INTEGER PRIMARY KEY,
    source_file_id INTEGER NOT NULL REFERENCES source_file(id) ON DELETE CASCADE,
    record_index INTEGER NOT NULL CHECK (record_index >= 0),
    record_kind TEXT NOT NULL,
    source_identifier TEXT,
    raw_json TEXT NOT NULL,
    UNIQUE (source_file_id, record_index)
);

-- Country-neutral parliamentary reference model ----------------------------

CREATE TABLE country (
    id INTEGER PRIMARY KEY,
    iso2 TEXT NOT NULL UNIQUE CHECK (length(iso2) = 2),
    iso3 TEXT NOT NULL UNIQUE CHECK (length(iso3) = 3),
    name_de TEXT NOT NULL
);

CREATE TABLE legislature (
    id INTEGER PRIMARY KEY,
    country_id INTEGER NOT NULL REFERENCES country(id),
    source_system TEXT NOT NULL,
    source_identifier TEXT NOT NULL,
    name TEXT NOT NULL,
    valid_from TEXT,
    valid_to TEXT,
    UNIQUE (source_system, source_identifier)
);

CREATE TABLE chamber (
    id INTEGER PRIMARY KEY,
    legislature_id INTEGER NOT NULL REFERENCES legislature(id),
    source_system TEXT NOT NULL,
    source_identifier TEXT NOT NULL,
    code TEXT,
    abbreviation TEXT,
    name TEXT NOT NULL,
    chamber_type TEXT,
    UNIQUE (source_system, source_identifier)
);

CREATE TABLE legislative_period (
    id INTEGER PRIMARY KEY,
    legislature_id INTEGER NOT NULL REFERENCES legislature(id),
    source_system TEXT NOT NULL,
    source_identifier TEXT NOT NULL,
    code TEXT,
    name TEXT NOT NULL,
    date_from TEXT,
    date_to TEXT,
    UNIQUE (source_system, source_identifier)
);

CREATE TABLE parliamentary_session (
    id INTEGER PRIMARY KEY,
    legislature_id INTEGER NOT NULL REFERENCES legislature(id),
    legislative_period_id INTEGER REFERENCES legislative_period(id),
    source_system TEXT NOT NULL,
    source_identifier TEXT NOT NULL,
    code TEXT,
    name TEXT NOT NULL,
    date_from TEXT,
    date_to TEXT,
    UNIQUE (source_system, source_identifier)
);

CREATE TABLE subdivision (
    id INTEGER PRIMARY KEY,
    country_id INTEGER NOT NULL REFERENCES country(id),
    source_system TEXT NOT NULL,
    source_identifier TEXT NOT NULL,
    code TEXT,
    abbreviation TEXT,
    name TEXT NOT NULL,
    UNIQUE (source_system, source_identifier)
);

CREATE TABLE political_party (
    id INTEGER PRIMARY KEY,
    country_id INTEGER NOT NULL REFERENCES country(id),
    source_system TEXT NOT NULL,
    source_identifier TEXT NOT NULL,
    code TEXT,
    abbreviation TEXT,
    name TEXT NOT NULL,
    valid_from TEXT,
    valid_to TEXT,
    source_record_id INTEGER REFERENCES source_record(id),
    UNIQUE (source_system, source_identifier)
);

CREATE TABLE parliamentary_faction (
    id INTEGER PRIMARY KEY,
    legislature_id INTEGER NOT NULL REFERENCES legislature(id),
    source_system TEXT NOT NULL,
    source_identifier TEXT NOT NULL,
    code TEXT,
    abbreviation TEXT,
    short_name TEXT,
    name TEXT NOT NULL,
    valid_from TEXT,
    valid_to TEXT,
    source_record_id INTEGER REFERENCES source_record(id),
    UNIQUE (source_system, source_identifier)
);

CREATE TABLE committee (
    id INTEGER PRIMARY KEY,
    legislature_id INTEGER NOT NULL REFERENCES legislature(id),
    chamber_id INTEGER REFERENCES chamber(id),
    source_system TEXT NOT NULL,
    source_identifier TEXT NOT NULL,
    code TEXT,
    abbreviation TEXT,
    name TEXT NOT NULL,
    committee_type TEXT,
    valid_from TEXT,
    valid_to TEXT,
    source_record_id INTEGER REFERENCES source_record(id),
    UNIQUE (source_system, source_identifier)
);

CREATE TABLE person (
    id INTEGER PRIMARY KEY,
    country_id INTEGER NOT NULL REFERENCES country(id),
    first_name TEXT,
    last_name TEXT,
    display_name TEXT NOT NULL,
    gender TEXT,
    birth_date TEXT,
    death_date TEXT,
    is_placeholder INTEGER NOT NULL DEFAULT 0 CHECK (is_placeholder IN (0, 1)),
    source_record_id INTEGER REFERENCES source_record(id)
);

CREATE TABLE person_identifier (
    id INTEGER PRIMARY KEY,
    person_id INTEGER NOT NULL REFERENCES person(id) ON DELETE CASCADE,
    source_system TEXT NOT NULL,
    namespace TEXT NOT NULL,
    identifier TEXT NOT NULL,
    resolution_method TEXT NOT NULL DEFAULT 'direct',
    source_record_id INTEGER REFERENCES source_record(id),
    UNIQUE (source_system, namespace, identifier)
);

CREATE TABLE person_mandate (
    id INTEGER PRIMARY KEY,
    person_id INTEGER NOT NULL REFERENCES person(id) ON DELETE CASCADE,
    chamber_id INTEGER NOT NULL REFERENCES chamber(id),
    subdivision_id INTEGER REFERENCES subdivision(id),
    date_from TEXT,
    date_to TEXT,
    role_name TEXT,
    source_system TEXT NOT NULL,
    source_identifier TEXT,
    source_record_id INTEGER REFERENCES source_record(id),
    UNIQUE (person_id, chamber_id, date_from, date_to, source_system, source_identifier)
);

CREATE TABLE person_party_membership (
    id INTEGER PRIMARY KEY,
    person_id INTEGER NOT NULL REFERENCES person(id) ON DELETE CASCADE,
    party_id INTEGER NOT NULL REFERENCES political_party(id),
    date_from TEXT,
    date_to TEXT,
    is_inferred INTEGER NOT NULL DEFAULT 0 CHECK (is_inferred IN (0, 1)),
    evidence_basis TEXT NOT NULL,
    source_record_id INTEGER REFERENCES source_record(id),
    UNIQUE (person_id, party_id, date_from, date_to, evidence_basis)
);

CREATE TABLE person_faction_membership (
    id INTEGER PRIMARY KEY,
    person_id INTEGER NOT NULL REFERENCES person(id) ON DELETE CASCADE,
    faction_id INTEGER NOT NULL REFERENCES parliamentary_faction(id),
    date_from TEXT,
    date_to TEXT,
    is_inferred INTEGER NOT NULL DEFAULT 0 CHECK (is_inferred IN (0, 1)),
    evidence_basis TEXT NOT NULL,
    source_record_id INTEGER REFERENCES source_record(id),
    UNIQUE (person_id, faction_id, date_from, date_to, evidence_basis)
);

CREATE TABLE person_committee_membership (
    id INTEGER PRIMARY KEY,
    person_id INTEGER NOT NULL REFERENCES person(id) ON DELETE CASCADE,
    committee_id INTEGER NOT NULL REFERENCES committee(id),
    date_from TEXT,
    date_to TEXT,
    role_name TEXT,
    source_record_id INTEGER REFERENCES source_record(id),
    UNIQUE (person_id, committee_id, date_from, date_to, role_name)
);

-- Affairs and their official descriptive material --------------------------

CREATE TABLE matter_type (
    id INTEGER PRIMARY KEY,
    legislature_id INTEGER NOT NULL REFERENCES legislature(id),
    source_system TEXT NOT NULL,
    source_identifier TEXT NOT NULL,
    code TEXT,
    abbreviation TEXT,
    name TEXT NOT NULL,
    source_record_id INTEGER REFERENCES source_record(id),
    UNIQUE (source_system, source_identifier)
);

CREATE TABLE matter_state (
    id INTEGER PRIMARY KEY,
    legislature_id INTEGER NOT NULL REFERENCES legislature(id),
    source_system TEXT NOT NULL,
    source_identifier TEXT NOT NULL,
    code TEXT,
    name TEXT NOT NULL,
    source_record_id INTEGER REFERENCES source_record(id),
    UNIQUE (source_system, source_identifier)
);

CREATE TABLE official_topic (
    id INTEGER PRIMARY KEY,
    legislature_id INTEGER NOT NULL REFERENCES legislature(id),
    source_system TEXT NOT NULL,
    source_identifier TEXT NOT NULL,
    code TEXT,
    name TEXT NOT NULL,
    source_record_id INTEGER REFERENCES source_record(id),
    UNIQUE (source_system, source_identifier)
);

CREATE TABLE official_descriptor (
    id INTEGER PRIMARY KEY,
    legislature_id INTEGER NOT NULL REFERENCES legislature(id),
    source_system TEXT NOT NULL,
    source_identifier TEXT NOT NULL,
    descriptor_type TEXT,
    name TEXT NOT NULL,
    source_record_id INTEGER REFERENCES source_record(id),
    UNIQUE (source_system, source_identifier)
);

CREATE TABLE parliamentary_matter (
    id INTEGER PRIMARY KEY,
    legislature_id INTEGER NOT NULL REFERENCES legislature(id),
    matter_type_id INTEGER REFERENCES matter_type(id),
    matter_state_id INTEGER REFERENCES matter_state(id),
    submitted_chamber_id INTEGER REFERENCES chamber(id),
    submitted_session_id INTEGER REFERENCES parliamentary_session(id),
    submitted_period_id INTEGER REFERENCES legislative_period(id),
    source_system TEXT NOT NULL,
    source_identifier TEXT NOT NULL,
    formatted_identifier TEXT,
    title TEXT,
    submitted_at TEXT,
    source_updated_at TEXT,
    source_record_id INTEGER REFERENCES source_record(id),
    UNIQUE (source_system, source_identifier)
);

CREATE TABLE matter_text (
    id INTEGER PRIMARY KEY,
    matter_id INTEGER NOT NULL REFERENCES parliamentary_matter(id) ON DELETE CASCADE,
    language TEXT,
    text_type_identifier TEXT,
    text_type_name TEXT,
    body_html TEXT NOT NULL,
    source_record_id INTEGER REFERENCES source_record(id),
    UNIQUE (matter_id, language, text_type_identifier, body_html)
);

CREATE TABLE matter_summary (
    id INTEGER PRIMARY KEY,
    matter_id INTEGER NOT NULL REFERENCES parliamentary_matter(id) ON DELETE CASCADE,
    language TEXT,
    description_html TEXT,
    initial_situation_html TEXT,
    proceedings_html TEXT,
    source_record_id INTEGER REFERENCES source_record(id),
    UNIQUE (matter_id, language)
);

CREATE TABLE matter_topic (
    matter_id INTEGER NOT NULL REFERENCES parliamentary_matter(id) ON DELETE CASCADE,
    topic_id INTEGER NOT NULL REFERENCES official_topic(id),
    source_record_id INTEGER REFERENCES source_record(id),
    PRIMARY KEY (matter_id, topic_id)
);

CREATE TABLE matter_descriptor (
    matter_id INTEGER NOT NULL REFERENCES parliamentary_matter(id) ON DELETE CASCADE,
    descriptor_id INTEGER NOT NULL REFERENCES official_descriptor(id),
    source_record_id INTEGER REFERENCES source_record(id),
    PRIMARY KEY (matter_id, descriptor_id)
);

-- Recorded votes ------------------------------------------------------------

CREATE TABLE voting_event (
    id INTEGER PRIMARY KEY,
    matter_id INTEGER REFERENCES parliamentary_matter(id),
    chamber_id INTEGER REFERENCES chamber(id),
    session_id INTEGER REFERENCES parliamentary_session(id),
    source_system TEXT NOT NULL,
    source_identifier TEXT NOT NULL,
    registration_number TEXT,
    occurred_at TEXT NOT NULL,
    division_text TEXT,
    submission_text TEXT,
    meaning_yes TEXT,
    meaning_no TEXT,
    vote_type TEXT,
    vote_type_basis TEXT,
    overall_decision TEXT,
    chamber_resolution_basis TEXT,
    source_record_id INTEGER REFERENCES source_record(id),
    UNIQUE (source_system, source_identifier)
);

CREATE TABLE voting_aggregate (
    id INTEGER PRIMARY KEY,
    voting_event_id INTEGER NOT NULL REFERENCES voting_event(id) ON DELETE CASCADE,
    aggregate_scope TEXT NOT NULL CHECK (aggregate_scope IN ('total', 'filtered')),
    source_choice_code TEXT NOT NULL,
    normalized_choice TEXT CHECK (normalized_choice IN ('yes', 'no', 'abstain', 'not_participating', 'excused', 'presiding', 'other') OR normalized_choice IS NULL),
    vote_count INTEGER NOT NULL CHECK (vote_count >= 0),
    mapping_is_inferred INTEGER NOT NULL DEFAULT 1 CHECK (mapping_is_inferred IN (0, 1)),
    UNIQUE (voting_event_id, aggregate_scope, source_choice_code)
);

CREATE TABLE voting_choice (
    id INTEGER PRIMARY KEY,
    voting_event_id INTEGER NOT NULL REFERENCES voting_event(id) ON DELETE CASCADE,
    person_id INTEGER NOT NULL REFERENCES person(id),
    source_system TEXT NOT NULL,
    source_identifier TEXT NOT NULL,
    raw_decision TEXT NOT NULL,
    normalized_choice TEXT NOT NULL CHECK (normalized_choice IN ('yes', 'no', 'abstain', 'not_participating', 'excused', 'presiding', 'other')),
    source_record_id INTEGER REFERENCES source_record(id),
    UNIQUE (source_system, source_identifier),
    UNIQUE (voting_event_id, person_id)
);

CREATE INDEX idx_source_record_kind ON source_record(record_kind, source_identifier);
CREATE INDEX idx_person_identifier_person ON person_identifier(person_id);
CREATE INDEX idx_mandate_person_dates ON person_mandate(person_id, date_from, date_to);
CREATE INDEX idx_party_membership_person_dates ON person_party_membership(person_id, date_from, date_to);
CREATE INDEX idx_faction_membership_person_dates ON person_faction_membership(person_id, date_from, date_to);
CREATE INDEX idx_matter_identifier ON parliamentary_matter(formatted_identifier);
CREATE INDEX idx_voting_event_matter_date ON voting_event(matter_id, occurred_at);
CREATE INDEX idx_voting_event_chamber_date ON voting_event(chamber_id, occurred_at);
CREATE INDEX idx_voting_choice_event_choice ON voting_choice(voting_event_id, normalized_choice);
CREATE INDEX idx_voting_choice_person ON voting_choice(person_id);
