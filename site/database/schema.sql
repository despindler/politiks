-- Politiks MariaDB 10.6 application and immutable reference schema.
-- Apply only through the CLI bootstrap command outside the public site root.

CREATE TABLE IF NOT EXISTS reference_publication (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    publication_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_snapshot VARCHAR(191) NOT NULL,
    source_schema_version VARCHAR(32) NOT NULL,
    source_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    taxonomy_version VARCHAR(64) NULL,
    taxonomy_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    review_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    content_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    counts_json JSON NULL,
    status VARCHAR(16) NOT NULL,
    created_at DATETIME(6) NOT NULL,
    activated_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_reference_publication_key (publication_key),
    KEY idx_reference_publication_status (status),
    CONSTRAINT chk_reference_publication_status CHECK (status IN ('loading', 'active', 'retired')),
    CONSTRAINT chk_reference_publication_content CHECK (
        (status = 'loading' AND content_sha256 IS NULL AND counts_json IS NULL) OR
        (status IN ('active', 'retired') AND content_sha256 IS NOT NULL AND counts_json IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reference_state (
    singleton_id TINYINT UNSIGNED NOT NULL,
    active_publication_id BIGINT UNSIGNED NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (singleton_id),
    CONSTRAINT chk_reference_state_singleton CHECK (singleton_id = 1),
    CONSTRAINT fk_reference_state_publication FOREIGN KEY (active_publication_id)
        REFERENCES reference_publication (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ref_country (
    publication_id BIGINT UNSIGNED NOT NULL,
    source_id BIGINT NOT NULL,
    iso2 CHAR(2) NOT NULL,
    iso3 CHAR(3) NOT NULL,
    name_de VARCHAR(191) NOT NULL,
    PRIMARY KEY (publication_id, source_id),
    UNIQUE KEY uq_ref_country_iso2 (publication_id, iso2),
    CONSTRAINT fk_ref_country_publication FOREIGN KEY (publication_id)
        REFERENCES reference_publication (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ref_legislature (
    publication_id BIGINT UNSIGNED NOT NULL,
    source_id BIGINT NOT NULL,
    country_source_id BIGINT NOT NULL,
    source_system VARCHAR(191) NOT NULL,
    source_identifier VARCHAR(191) NOT NULL,
    name VARCHAR(255) NOT NULL,
    valid_from DATE NULL,
    valid_to DATE NULL,
    PRIMARY KEY (publication_id, source_id),
    UNIQUE KEY uq_ref_legislature_identifier (publication_id, source_system, source_identifier),
    CONSTRAINT fk_ref_legislature_publication FOREIGN KEY (publication_id)
        REFERENCES reference_publication (id) ON DELETE CASCADE,
    CONSTRAINT fk_ref_legislature_country FOREIGN KEY (publication_id, country_source_id)
        REFERENCES ref_country (publication_id, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ref_chamber (
    publication_id BIGINT UNSIGNED NOT NULL,
    source_id BIGINT NOT NULL,
    legislature_source_id BIGINT NOT NULL,
    source_system VARCHAR(191) NOT NULL,
    source_identifier VARCHAR(191) NOT NULL,
    code VARCHAR(64) NULL,
    abbreviation VARCHAR(32) NULL,
    name VARCHAR(255) NOT NULL,
    chamber_type VARCHAR(32) NULL,
    PRIMARY KEY (publication_id, source_id),
    UNIQUE KEY uq_ref_chamber_identifier (publication_id, source_system, source_identifier),
    KEY idx_ref_chamber_code (publication_id, code),
    CONSTRAINT fk_ref_chamber_publication FOREIGN KEY (publication_id)
        REFERENCES reference_publication (id) ON DELETE CASCADE,
    CONSTRAINT fk_ref_chamber_legislature FOREIGN KEY (publication_id, legislature_source_id)
        REFERENCES ref_legislature (publication_id, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ref_legislative_period (
    publication_id BIGINT UNSIGNED NOT NULL,
    source_id BIGINT NOT NULL,
    legislature_source_id BIGINT NOT NULL,
    source_system VARCHAR(191) NOT NULL,
    source_identifier VARCHAR(191) NOT NULL,
    code VARCHAR(64) NULL,
    name VARCHAR(255) NOT NULL,
    date_from DATE NULL,
    date_to DATE NULL,
    PRIMARY KEY (publication_id, source_id),
    UNIQUE KEY uq_ref_period_identifier (publication_id, source_system, source_identifier),
    CONSTRAINT fk_ref_period_publication FOREIGN KEY (publication_id)
        REFERENCES reference_publication (id) ON DELETE CASCADE,
    CONSTRAINT fk_ref_period_legislature FOREIGN KEY (publication_id, legislature_source_id)
        REFERENCES ref_legislature (publication_id, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ref_session (
    publication_id BIGINT UNSIGNED NOT NULL,
    source_id BIGINT NOT NULL,
    legislature_source_id BIGINT NOT NULL,
    period_source_id BIGINT NULL,
    source_system VARCHAR(191) NOT NULL,
    source_identifier VARCHAR(191) NOT NULL,
    code VARCHAR(64) NULL,
    name VARCHAR(255) NOT NULL,
    date_from DATE NULL,
    date_to DATE NULL,
    PRIMARY KEY (publication_id, source_id),
    UNIQUE KEY uq_ref_session_identifier (publication_id, source_system, source_identifier),
    CONSTRAINT fk_ref_session_publication FOREIGN KEY (publication_id)
        REFERENCES reference_publication (id) ON DELETE CASCADE,
    CONSTRAINT fk_ref_session_legislature FOREIGN KEY (publication_id, legislature_source_id)
        REFERENCES ref_legislature (publication_id, source_id),
    CONSTRAINT fk_ref_session_period FOREIGN KEY (publication_id, period_source_id)
        REFERENCES ref_legislative_period (publication_id, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ref_subdivision (
    publication_id BIGINT UNSIGNED NOT NULL,
    source_id BIGINT NOT NULL,
    country_source_id BIGINT NOT NULL,
    source_system VARCHAR(191) NOT NULL,
    source_identifier VARCHAR(191) NOT NULL,
    code VARCHAR(64) NULL,
    abbreviation VARCHAR(32) NULL,
    name VARCHAR(255) NOT NULL,
    subdivision_type VARCHAR(64) NULL,
    PRIMARY KEY (publication_id, source_id),
    UNIQUE KEY uq_ref_subdivision_identifier (publication_id, source_system, source_identifier),
    CONSTRAINT fk_ref_subdivision_publication FOREIGN KEY (publication_id)
        REFERENCES reference_publication (id) ON DELETE CASCADE,
    CONSTRAINT fk_ref_subdivision_country FOREIGN KEY (publication_id, country_source_id)
        REFERENCES ref_country (publication_id, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ref_committee (
    publication_id BIGINT UNSIGNED NOT NULL,
    source_id BIGINT NOT NULL,
    legislature_source_id BIGINT NOT NULL,
    chamber_source_id BIGINT NULL,
    source_system VARCHAR(191) NOT NULL,
    source_identifier VARCHAR(191) NOT NULL,
    code VARCHAR(64) NULL,
    abbreviation VARCHAR(64) NULL,
    name VARCHAR(255) NOT NULL,
    committee_type VARCHAR(64) NULL,
    valid_from DATE NULL,
    valid_to DATE NULL,
    PRIMARY KEY (publication_id, source_id),
    UNIQUE KEY uq_ref_committee_identifier (publication_id, source_system, source_identifier),
    CONSTRAINT fk_ref_committee_publication FOREIGN KEY (publication_id)
        REFERENCES reference_publication (id) ON DELETE CASCADE,
    CONSTRAINT fk_ref_committee_legislature FOREIGN KEY (publication_id, legislature_source_id)
        REFERENCES ref_legislature (publication_id, source_id),
    CONSTRAINT fk_ref_committee_chamber FOREIGN KEY (publication_id, chamber_source_id)
        REFERENCES ref_chamber (publication_id, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ref_party (
    publication_id BIGINT UNSIGNED NOT NULL,
    source_id BIGINT NOT NULL,
    country_source_id BIGINT NOT NULL,
    source_system VARCHAR(191) NOT NULL,
    source_identifier VARCHAR(191) NOT NULL,
    code VARCHAR(64) NULL,
    abbreviation VARCHAR(64) NULL,
    name VARCHAR(255) NOT NULL,
    valid_from DATE NULL,
    valid_to DATE NULL,
    PRIMARY KEY (publication_id, source_id),
    UNIQUE KEY uq_ref_party_identifier (publication_id, source_system, source_identifier),
    KEY idx_ref_party_name (publication_id, name),
    CONSTRAINT fk_ref_party_publication FOREIGN KEY (publication_id)
        REFERENCES reference_publication (id) ON DELETE CASCADE,
    CONSTRAINT fk_ref_party_country FOREIGN KEY (publication_id, country_source_id)
        REFERENCES ref_country (publication_id, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ref_faction (
    publication_id BIGINT UNSIGNED NOT NULL,
    source_id BIGINT NOT NULL,
    legislature_source_id BIGINT NOT NULL,
    source_system VARCHAR(191) NOT NULL,
    source_identifier VARCHAR(191) NOT NULL,
    code VARCHAR(64) NULL,
    abbreviation VARCHAR(64) NULL,
    name VARCHAR(255) NOT NULL,
    valid_from DATE NULL,
    valid_to DATE NULL,
    PRIMARY KEY (publication_id, source_id),
    UNIQUE KEY uq_ref_faction_identifier (publication_id, source_system, source_identifier),
    CONSTRAINT fk_ref_faction_publication FOREIGN KEY (publication_id)
        REFERENCES reference_publication (id) ON DELETE CASCADE,
    CONSTRAINT fk_ref_faction_legislature FOREIGN KEY (publication_id, legislature_source_id)
        REFERENCES ref_legislature (publication_id, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ref_person (
    publication_id BIGINT UNSIGNED NOT NULL,
    source_id BIGINT NOT NULL,
    display_name VARCHAR(255) NOT NULL,
    first_name VARCHAR(191) NULL,
    last_name VARCHAR(191) NULL,
    gender VARCHAR(32) NULL,
    birth_date DATE NULL,
    death_date DATE NULL,
    PRIMARY KEY (publication_id, source_id),
    KEY idx_ref_person_name (publication_id, last_name, first_name),
    CONSTRAINT fk_ref_person_publication FOREIGN KEY (publication_id)
        REFERENCES reference_publication (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ref_person_identifier (
    publication_id BIGINT UNSIGNED NOT NULL,
    source_id BIGINT NOT NULL,
    person_source_id BIGINT NOT NULL,
    source_system VARCHAR(191) NOT NULL,
    namespace VARCHAR(96) NOT NULL,
    identifier VARCHAR(191) NOT NULL,
    resolution_method VARCHAR(96) NOT NULL,
    PRIMARY KEY (publication_id, source_id),
    UNIQUE KEY uq_ref_person_identifier (publication_id, source_system, namespace, identifier),
    CONSTRAINT fk_ref_person_identifier_publication FOREIGN KEY (publication_id)
        REFERENCES reference_publication (id) ON DELETE CASCADE,
    CONSTRAINT fk_ref_person_identifier_person FOREIGN KEY (publication_id, person_source_id)
        REFERENCES ref_person (publication_id, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ref_person_mandate (
    publication_id BIGINT UNSIGNED NOT NULL,
    source_id BIGINT NOT NULL,
    person_source_id BIGINT NOT NULL,
    chamber_source_id BIGINT NOT NULL,
    subdivision_source_id BIGINT NULL,
    date_from DATE NULL,
    date_to DATE NULL,
    is_inferred TINYINT(1) NOT NULL,
    evidence_basis VARCHAR(191) NOT NULL,
    PRIMARY KEY (publication_id, source_id),
    KEY idx_ref_mandate_person_dates (publication_id, person_source_id, date_from, date_to),
    CONSTRAINT fk_ref_mandate_publication FOREIGN KEY (publication_id)
        REFERENCES reference_publication (id) ON DELETE CASCADE,
    CONSTRAINT fk_ref_mandate_person FOREIGN KEY (publication_id, person_source_id)
        REFERENCES ref_person (publication_id, source_id),
    CONSTRAINT fk_ref_mandate_chamber FOREIGN KEY (publication_id, chamber_source_id)
        REFERENCES ref_chamber (publication_id, source_id),
    CONSTRAINT fk_ref_mandate_subdivision FOREIGN KEY (publication_id, subdivision_source_id)
        REFERENCES ref_subdivision (publication_id, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ref_person_party_membership (
    publication_id BIGINT UNSIGNED NOT NULL,
    source_id BIGINT NOT NULL,
    person_source_id BIGINT NOT NULL,
    party_source_id BIGINT NOT NULL,
    date_from DATE NULL,
    date_to DATE NULL,
    is_inferred TINYINT(1) NOT NULL,
    evidence_basis VARCHAR(191) NOT NULL,
    PRIMARY KEY (publication_id, source_id),
    KEY idx_ref_party_membership_lookup (publication_id, party_source_id, date_from, date_to, person_source_id),
    KEY idx_ref_party_membership_person (publication_id, person_source_id, date_from, date_to),
    CONSTRAINT fk_ref_party_membership_publication FOREIGN KEY (publication_id)
        REFERENCES reference_publication (id) ON DELETE CASCADE,
    CONSTRAINT fk_ref_party_membership_person FOREIGN KEY (publication_id, person_source_id)
        REFERENCES ref_person (publication_id, source_id),
    CONSTRAINT fk_ref_party_membership_party FOREIGN KEY (publication_id, party_source_id)
        REFERENCES ref_party (publication_id, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ref_person_faction_membership (
    publication_id BIGINT UNSIGNED NOT NULL,
    source_id BIGINT NOT NULL,
    person_source_id BIGINT NOT NULL,
    faction_source_id BIGINT NOT NULL,
    date_from DATE NULL,
    date_to DATE NULL,
    is_inferred TINYINT(1) NOT NULL,
    evidence_basis VARCHAR(191) NOT NULL,
    PRIMARY KEY (publication_id, source_id),
    KEY idx_ref_faction_membership_person (publication_id, person_source_id, date_from, date_to),
    CONSTRAINT fk_ref_faction_membership_publication FOREIGN KEY (publication_id)
        REFERENCES reference_publication (id) ON DELETE CASCADE,
    CONSTRAINT fk_ref_faction_membership_person FOREIGN KEY (publication_id, person_source_id)
        REFERENCES ref_person (publication_id, source_id),
    CONSTRAINT fk_ref_faction_membership_faction FOREIGN KEY (publication_id, faction_source_id)
        REFERENCES ref_faction (publication_id, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ref_matter (
    publication_id BIGINT UNSIGNED NOT NULL,
    source_id BIGINT NOT NULL,
    legislature_source_id BIGINT NOT NULL,
    source_system VARCHAR(191) NOT NULL,
    source_identifier VARCHAR(191) NOT NULL,
    formatted_identifier VARCHAR(64) NULL,
    matter_type VARCHAR(255) NULL,
    matter_state VARCHAR(255) NULL,
    title TEXT NULL,
    submitted_at DATETIME NULL,
    source_updated_at DATETIME NULL,
    provenance_url TEXT NULL,
    provenance_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    PRIMARY KEY (publication_id, source_id),
    UNIQUE KEY uq_ref_matter_identifier (publication_id, source_system, source_identifier),
    KEY idx_ref_matter_formatted_identifier (publication_id, formatted_identifier),
    CONSTRAINT fk_ref_matter_publication FOREIGN KEY (publication_id)
        REFERENCES reference_publication (id) ON DELETE CASCADE,
    CONSTRAINT fk_ref_matter_legislature FOREIGN KEY (publication_id, legislature_source_id)
        REFERENCES ref_legislature (publication_id, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ref_matter_text (
    publication_id BIGINT UNSIGNED NOT NULL,
    source_id BIGINT NOT NULL,
    matter_source_id BIGINT NOT NULL,
    language VARCHAR(16) NULL,
    text_type_identifier VARCHAR(191) NULL,
    text_type_name VARCHAR(255) NULL,
    body_html LONGTEXT NOT NULL,
    PRIMARY KEY (publication_id, source_id),
    CONSTRAINT fk_ref_matter_text_publication FOREIGN KEY (publication_id)
        REFERENCES reference_publication (id) ON DELETE CASCADE,
    CONSTRAINT fk_ref_matter_text_matter FOREIGN KEY (publication_id, matter_source_id)
        REFERENCES ref_matter (publication_id, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ref_matter_summary (
    publication_id BIGINT UNSIGNED NOT NULL,
    source_id BIGINT NOT NULL,
    matter_source_id BIGINT NOT NULL,
    language VARCHAR(16) NULL,
    description_html LONGTEXT NULL,
    initial_situation_html LONGTEXT NULL,
    proceedings_html LONGTEXT NULL,
    PRIMARY KEY (publication_id, source_id),
    CONSTRAINT fk_ref_matter_summary_publication FOREIGN KEY (publication_id)
        REFERENCES reference_publication (id) ON DELETE CASCADE,
    CONSTRAINT fk_ref_matter_summary_matter FOREIGN KEY (publication_id, matter_source_id)
        REFERENCES ref_matter (publication_id, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ref_official_topic (
    publication_id BIGINT UNSIGNED NOT NULL,
    source_id BIGINT NOT NULL,
    legislature_source_id BIGINT NOT NULL,
    source_system VARCHAR(191) NOT NULL,
    source_identifier VARCHAR(191) NOT NULL,
    code VARCHAR(64) NULL,
    name VARCHAR(255) NOT NULL,
    PRIMARY KEY (publication_id, source_id),
    CONSTRAINT fk_ref_topic_publication FOREIGN KEY (publication_id)
        REFERENCES reference_publication (id) ON DELETE CASCADE,
    CONSTRAINT fk_ref_topic_legislature FOREIGN KEY (publication_id, legislature_source_id)
        REFERENCES ref_legislature (publication_id, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ref_official_descriptor (
    publication_id BIGINT UNSIGNED NOT NULL,
    source_id BIGINT NOT NULL,
    legislature_source_id BIGINT NOT NULL,
    source_system VARCHAR(191) NOT NULL,
    source_identifier VARCHAR(191) NOT NULL,
    descriptor_type VARCHAR(64) NULL,
    name VARCHAR(255) NOT NULL,
    PRIMARY KEY (publication_id, source_id),
    CONSTRAINT fk_ref_descriptor_publication FOREIGN KEY (publication_id)
        REFERENCES reference_publication (id) ON DELETE CASCADE,
    CONSTRAINT fk_ref_descriptor_legislature FOREIGN KEY (publication_id, legislature_source_id)
        REFERENCES ref_legislature (publication_id, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ref_matter_topic (
    publication_id BIGINT UNSIGNED NOT NULL,
    matter_source_id BIGINT NOT NULL,
    topic_source_id BIGINT NOT NULL,
    PRIMARY KEY (publication_id, matter_source_id, topic_source_id),
    CONSTRAINT fk_ref_matter_topic_publication FOREIGN KEY (publication_id)
        REFERENCES reference_publication (id) ON DELETE CASCADE,
    CONSTRAINT fk_ref_matter_topic_matter FOREIGN KEY (publication_id, matter_source_id)
        REFERENCES ref_matter (publication_id, source_id),
    CONSTRAINT fk_ref_matter_topic_topic FOREIGN KEY (publication_id, topic_source_id)
        REFERENCES ref_official_topic (publication_id, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ref_matter_descriptor (
    publication_id BIGINT UNSIGNED NOT NULL,
    matter_source_id BIGINT NOT NULL,
    descriptor_source_id BIGINT NOT NULL,
    PRIMARY KEY (publication_id, matter_source_id, descriptor_source_id),
    CONSTRAINT fk_ref_matter_descriptor_publication FOREIGN KEY (publication_id)
        REFERENCES reference_publication (id) ON DELETE CASCADE,
    CONSTRAINT fk_ref_matter_descriptor_matter FOREIGN KEY (publication_id, matter_source_id)
        REFERENCES ref_matter (publication_id, source_id),
    CONSTRAINT fk_ref_matter_descriptor_descriptor FOREIGN KEY (publication_id, descriptor_source_id)
        REFERENCES ref_official_descriptor (publication_id, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ref_voting_event (
    publication_id BIGINT UNSIGNED NOT NULL,
    source_id BIGINT NOT NULL,
    matter_source_id BIGINT NULL,
    chamber_source_id BIGINT NULL,
    session_source_id BIGINT NULL,
    source_system VARCHAR(191) NOT NULL,
    source_identifier VARCHAR(191) NOT NULL,
    registration_number VARCHAR(191) NULL,
    occurred_at DATETIME NOT NULL,
    division_text LONGTEXT NULL,
    submission_text LONGTEXT NULL,
    meaning_yes LONGTEXT NULL,
    meaning_no LONGTEXT NULL,
    vote_type VARCHAR(64) NULL,
    vote_type_basis VARCHAR(191) NULL,
    overall_decision VARCHAR(255) NULL,
    chamber_resolution_basis VARCHAR(191) NULL,
    provenance_url TEXT NULL,
    provenance_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    PRIMARY KEY (publication_id, source_id),
    UNIQUE KEY uq_ref_voting_event_identifier (publication_id, source_system, source_identifier),
    KEY idx_ref_voting_event_registration (publication_id, registration_number),
    KEY idx_ref_voting_event_date (publication_id, occurred_at),
    KEY idx_ref_voting_event_matter (publication_id, matter_source_id, occurred_at),
    CONSTRAINT fk_ref_voting_event_publication FOREIGN KEY (publication_id)
        REFERENCES reference_publication (id) ON DELETE CASCADE,
    CONSTRAINT fk_ref_voting_event_matter FOREIGN KEY (publication_id, matter_source_id)
        REFERENCES ref_matter (publication_id, source_id),
    CONSTRAINT fk_ref_voting_event_chamber FOREIGN KEY (publication_id, chamber_source_id)
        REFERENCES ref_chamber (publication_id, source_id),
    CONSTRAINT fk_ref_voting_event_session FOREIGN KEY (publication_id, session_source_id)
        REFERENCES ref_session (publication_id, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ref_voting_aggregate (
    publication_id BIGINT UNSIGNED NOT NULL,
    source_id BIGINT NOT NULL,
    voting_event_source_id BIGINT NOT NULL,
    aggregate_scope VARCHAR(32) NOT NULL,
    source_choice_code VARCHAR(96) NOT NULL,
    normalized_choice VARCHAR(32) NULL,
    vote_count INT UNSIGNED NOT NULL,
    mapping_is_inferred TINYINT(1) NOT NULL,
    PRIMARY KEY (publication_id, source_id),
    CONSTRAINT fk_ref_aggregate_publication FOREIGN KEY (publication_id)
        REFERENCES reference_publication (id) ON DELETE CASCADE,
    CONSTRAINT fk_ref_aggregate_event FOREIGN KEY (publication_id, voting_event_source_id)
        REFERENCES ref_voting_event (publication_id, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ref_voting_choice (
    publication_id BIGINT UNSIGNED NOT NULL,
    source_id BIGINT NOT NULL,
    voting_event_source_id BIGINT NOT NULL,
    person_source_id BIGINT NOT NULL,
    source_system VARCHAR(191) NOT NULL,
    source_identifier VARCHAR(255) NOT NULL,
    raw_decision VARCHAR(96) NOT NULL,
    normalized_choice VARCHAR(32) NOT NULL,
    PRIMARY KEY (publication_id, source_id),
    UNIQUE KEY uq_ref_choice_event_person (publication_id, voting_event_source_id, person_source_id),
    KEY idx_ref_choice_person (publication_id, person_source_id, voting_event_source_id),
    KEY idx_ref_choice_event_choice (publication_id, voting_event_source_id, normalized_choice),
    CONSTRAINT fk_ref_choice_publication FOREIGN KEY (publication_id)
        REFERENCES reference_publication (id) ON DELETE CASCADE,
    CONSTRAINT fk_ref_choice_event FOREIGN KEY (publication_id, voting_event_source_id)
        REFERENCES ref_voting_event (publication_id, source_id),
    CONSTRAINT fk_ref_choice_person FOREIGN KEY (publication_id, person_source_id)
        REFERENCES ref_person (publication_id, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ref_taxonomy_term (
    publication_id BIGINT UNSIGNED NOT NULL,
    source_id BIGINT NOT NULL,
    taxonomy_version VARCHAR(64) NOT NULL,
    dimension VARCHAR(64) NOT NULL,
    code VARCHAR(96) NOT NULL,
    parent_code VARCHAR(96) NULL,
    name_de VARCHAR(255) NOT NULL,
    description_de TEXT NOT NULL,
    sort_order INT NOT NULL,
    PRIMARY KEY (publication_id, source_id),
    UNIQUE KEY uq_ref_taxonomy_term (publication_id, taxonomy_version, dimension, code),
    CONSTRAINT fk_ref_taxonomy_term_publication FOREIGN KEY (publication_id)
        REFERENCES reference_publication (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ref_reviewed_classification (
    publication_id BIGINT UNSIGNED NOT NULL,
    suggestion_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    classification_run_source_id BIGINT NOT NULL,
    source_snapshot VARCHAR(191) NOT NULL,
    classification_method VARCHAR(32) NOT NULL,
    target_kind VARCHAR(32) NOT NULL,
    matter_source_id BIGINT NULL,
    voting_event_source_id BIGINT NULL,
    taxonomy_term_source_id BIGINT NOT NULL,
    relationship VARCHAR(32) NOT NULL,
    effect_direction VARCHAR(32) NOT NULL,
    directness VARCHAR(32) NOT NULL,
    confidence DECIMAL(6,5) NOT NULL,
    evidence_field VARCHAR(96) NOT NULL,
    evidence_passage TEXT NOT NULL,
    reviewer VARCHAR(191) NOT NULL,
    reviewed_at DATETIME(6) NOT NULL,
    review_decision VARCHAR(16) NOT NULL,
    notes TEXT NULL,
    PRIMARY KEY (publication_id, suggestion_key),
    KEY idx_ref_reviewed_target (publication_id, target_kind, matter_source_id, voting_event_source_id),
    CONSTRAINT fk_ref_reviewed_publication FOREIGN KEY (publication_id)
        REFERENCES reference_publication (id) ON DELETE CASCADE,
    CONSTRAINT fk_ref_reviewed_matter FOREIGN KEY (publication_id, matter_source_id)
        REFERENCES ref_matter (publication_id, source_id),
    CONSTRAINT fk_ref_reviewed_event FOREIGN KEY (publication_id, voting_event_source_id)
        REFERENCES ref_voting_event (publication_id, source_id),
    CONSTRAINT fk_ref_reviewed_term FOREIGN KEY (publication_id, taxonomy_term_source_id)
        REFERENCES ref_taxonomy_term (publication_id, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ref_vote_search_document (
    publication_id BIGINT UNSIGNED NOT NULL,
    voting_event_source_id BIGINT NOT NULL,
    affair_identifier VARCHAR(191) NULL,
    affair_source_identifier VARCHAR(191) NULL,
    voting_identifier VARCHAR(191) NOT NULL,
    registration_number VARCHAR(191) NULL,
    occurred_on DATE NOT NULL,
    title TEXT NULL,
    exact_question LONGTEXT NULL,
    meaning_yes LONGTEXT NULL,
    meaning_no LONGTEXT NULL,
    official_metadata LONGTEXT NOT NULL,
    reviewed_classifications LONGTEXT NOT NULL,
    full_text LONGTEXT NOT NULL,
    PRIMARY KEY (publication_id, voting_event_source_id),
    KEY idx_ref_search_affair (publication_id, affair_identifier),
    KEY idx_ref_search_affair_source (publication_id, affair_source_identifier),
    KEY idx_ref_search_vote (publication_id, voting_identifier),
    KEY idx_ref_search_registration (publication_id, registration_number),
    KEY idx_ref_search_date (publication_id, occurred_on),
    FULLTEXT KEY ftx_ref_search_full_text (full_text),
    CONSTRAINT fk_ref_search_publication FOREIGN KEY (publication_id)
        REFERENCES reference_publication (id) ON DELETE CASCADE,
    CONSTRAINT fk_ref_search_event FOREIGN KEY (publication_id, voting_event_source_id)
        REFERENCES ref_voting_event (publication_id, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Application-owned records ------------------------------------------------

CREATE TABLE IF NOT EXISTS app_user (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    google_sub VARCHAR(191) NULL,
    email VARCHAR(320) NOT NULL,
    display_name VARCHAR(255) NOT NULL,
    avatar_url TEXT NULL,
    role VARCHAR(32) NOT NULL DEFAULT 'user',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    last_login_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_app_user_google_sub (google_sub),
    UNIQUE KEY uq_app_user_email (email),
    CONSTRAINT chk_app_user_role CHECK (role IN ('user', 'reviewer', 'admin'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS insight (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    owner_user_id BIGINT UNSIGNED NOT NULL,
    reference_publication_id BIGINT UNSIGNED NOT NULL,
    country_source_id BIGINT NULL,
    legislature_source_id BIGINT NULL,
    chamber_source_id BIGINT NULL,
    party_source_id BIGINT NULL,
    period_from DATE NULL,
    period_to DATE NULL,
    title VARCHAR(255) NULL,
    claim_text TEXT NULL,
    explanatory_notes LONGTEXT NULL,
    visibility VARCHAR(16) NOT NULL DEFAULT 'draft',
    share_token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    published_at DATETIME(6) NULL,
    archived_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_insight_public_id (public_id),
    UNIQUE KEY uq_insight_share_token (share_token_hash),
    UNIQUE KEY uq_insight_publication_pair (id, reference_publication_id),
    KEY idx_insight_public_list (visibility, published_at, id),
    KEY idx_insight_owner (owner_user_id, updated_at),
    CONSTRAINT chk_insight_visibility CHECK (visibility IN ('draft', 'unlisted', 'public')),
    CONSTRAINT fk_insight_owner FOREIGN KEY (owner_user_id) REFERENCES app_user (id),
    CONSTRAINT fk_insight_publication FOREIGN KEY (reference_publication_id)
        REFERENCES reference_publication (id),
    CONSTRAINT fk_insight_country FOREIGN KEY (reference_publication_id, country_source_id)
        REFERENCES ref_country (publication_id, source_id),
    CONSTRAINT fk_insight_legislature FOREIGN KEY (reference_publication_id, legislature_source_id)
        REFERENCES ref_legislature (publication_id, source_id),
    CONSTRAINT fk_insight_chamber FOREIGN KEY (reference_publication_id, chamber_source_id)
        REFERENCES ref_chamber (publication_id, source_id),
    CONSTRAINT fk_insight_party FOREIGN KEY (reference_publication_id, party_source_id)
        REFERENCES ref_party (publication_id, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS insight_member (
    insight_id BIGINT UNSIGNED NOT NULL,
    reference_publication_id BIGINT UNSIGNED NOT NULL,
    person_source_id BIGINT NOT NULL,
    position INT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (insight_id, person_source_id),
    UNIQUE KEY uq_insight_member_position (insight_id, position),
    CONSTRAINT fk_insight_member_insight FOREIGN KEY (insight_id, reference_publication_id)
        REFERENCES insight (id, reference_publication_id) ON DELETE CASCADE,
    CONSTRAINT fk_insight_member_person FOREIGN KEY (reference_publication_id, person_source_id)
        REFERENCES ref_person (publication_id, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS insight_vote_evidence (
    insight_id BIGINT UNSIGNED NOT NULL,
    reference_publication_id BIGINT UNSIGNED NOT NULL,
    voting_event_source_id BIGINT NOT NULL,
    position INT UNSIGNED NOT NULL,
    explanatory_note TEXT NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (insight_id, voting_event_source_id),
    UNIQUE KEY uq_insight_vote_position (insight_id, position),
    CONSTRAINT fk_insight_vote_insight FOREIGN KEY (insight_id, reference_publication_id)
        REFERENCES insight (id, reference_publication_id) ON DELETE CASCADE,
    CONSTRAINT fk_insight_vote_event FOREIGN KEY (reference_publication_id, voting_event_source_id)
        REFERENCES ref_voting_event (publication_id, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS insight_campaign_context (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    insight_id BIGINT UNSIGNED NOT NULL,
    context_type VARCHAR(16) NOT NULL,
    position INT UNSIGNED NOT NULL,
    label VARCHAR(255) NULL,
    description TEXT NULL,
    source_url TEXT NULL,
    youtube_video_id VARCHAR(32) NULL,
    storage_key VARCHAR(255) NULL,
    original_filename VARCHAR(255) NULL,
    media_type VARCHAR(96) NULL,
    byte_count BIGINT UNSIGNED NULL,
    sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    created_at DATETIME(6) NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_insight_context_position (insight_id, position),
    CONSTRAINT chk_insight_context_type CHECK (context_type IN ('image', 'youtube', 'link')),
    CONSTRAINT fk_insight_context_insight FOREIGN KEY (insight_id)
        REFERENCES insight (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO reference_state (singleton_id, active_publication_id, updated_at)
VALUES (1, NULL, UTC_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE singleton_id = VALUES(singleton_id);
